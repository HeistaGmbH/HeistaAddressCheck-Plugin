<?php

namespace HeistaAddressCheck\Services;

use HeistaAddressCheck\Models\PendingAddressCheck;
use HeistaAddressCheck\PlatformEnvironment;
use HeistaAddressCheck\Repositories\PendingAddressCheckRepository;
use Plenty\Modules\Account\Address\Models\Address;
use Plenty\Modules\Account\Address\Models\AddressRelationType;
use Plenty\Modules\Order\Address\Contracts\OrderAddressRepositoryContract;
use Plenty\Modules\Order\Models\Order;
use Plenty\Modules\Order\Property\Models\OrderPropertyType;
use Plenty\Modules\Order\Shipping\Countries\Contracts\CountryRepositoryContract;
use Plenty\Plugin\ConfigRepository;
use Plenty\Plugin\Log\Loggable;
use Plenty\Plugin\Log\Reportable;
use Throwable;

class AddressCheckSubmitService
{
    use Loggable;
    use Reportable;

    private SaasClient $saasClient;
    private OrderAddressRepositoryContract $orderAddressRepo;
    private CountryRepositoryContract $countryRepo;
    private PendingAddressCheckRepository $pendingRepo;
    private ConfigRepository $config;

    public function __construct(
        SaasClient $saasClient,
        OrderAddressRepositoryContract $orderAddressRepo,
        CountryRepositoryContract $countryRepo,
        PendingAddressCheckRepository $pendingRepo,
        ConfigRepository $config
    ) {
        $this->saasClient       = $saasClient;
        $this->orderAddressRepo = $orderAddressRepo;
        $this->countryRepo      = $countryRepo;
        $this->pendingRepo      = $pendingRepo;
        $this->config           = $config;
    }

    public function submitForOrder(Order $order): void
    {
        $orderId = (int) $order->id;

        $environment    = (string) $this->config->get('HeistaAddressCheck.environment', PlatformEnvironment::PRODUCTION);
        $apiKey         = trim((string) $this->config->get('HeistaAddressCheck.apiKey'));
        $callbackSecret = trim((string) $this->config->get('HeistaAddressCheck.callbackSecret'));

        if ($apiKey === '' || $callbackSecret === '') {
            $this->getLogger(__METHOD__)->warning('HeistaAddressCheck::log.missingConfig', ['orderId' => $orderId]);
            return;
        }

        $devOverride = trim((string) $this->config->get('HeistaAddressCheck.devApiBaseUrlOverride'));
        $apiBaseUrl  = PlatformEnvironment::baseUrlFor($environment, $devOverride);

        // Optional internal n8n endpoint override — sent to the platform as
        // `config._n8nEndpoint` so it routes this shop's checks to an
        // alternate n8n webhook (e.g. "address-check-2") instead of the
        // default workflow. Leave empty in production.
        $n8nEndpointOverride = trim((string) $this->config->get('HeistaAddressCheck.devN8nEndpointOverride'));

        try {
            $address = $this->orderAddressRepo->findAddressByType($orderId, AddressRelationType::DELIVERY_ADDRESS);
        } catch (Throwable $e) {
            $this->getLogger(__METHOD__)->warning('HeistaAddressCheck::log.deliveryAddressNotFound', [
                'orderId' => $orderId,
                'error'   => $e->getMessage(),
            ]);
            return;
        }

        // Resolve which Heista-defined shipping provider this order ships with,
        // so the platform can route it to a carrier-specific n8n cleanup prompt.
        // Unmapped profiles resolve to '' → platform uses the generic prompt.
        $shippingProfileId = $this->resolveShippingProfileId($order);
        $shippingProvider  = $this->resolveShippingProviderKey($shippingProfileId);

        $this->getLogger(__METHOD__)->debug('HeistaAddressCheck::log.shippingProviderResolved', [
            'orderId'   => $orderId,
            'profileId' => $shippingProfileId,
            'provider'  => $shippingProvider !== '' ? $shippingProvider : '(none)',
        ]);

        $callbackUrl = $this->buildCallbackUrl();
        $payload     = $this->buildPayload($orderId, $address, $callbackUrl, $callbackSecret, $n8nEndpointOverride, $shippingProvider);

        try {
            $jobId = $this->saasClient->submitJob($apiBaseUrl, $apiKey, $payload);
        } catch (Throwable $e) {
            $this->getLogger(__METHOD__)->error('HeistaAddressCheck::log.submitFailed', [
                'orderId' => $orderId,
                'error'   => $e->getMessage(),
            ]);
            $this->pendingRepo->create([
                'orderId'           => $orderId,
                'jobId'             => '',
                'deliveryAddressId' => (int) $address->id,
                'status'            => PendingAddressCheck::STATUS_FAILED,
                'submittedAt'       => date('Y-m-d H:i:s'),
                'lastError'         => substr($e->getMessage(), 0, 1000),
            ]);
            return;
        }

        $this->pendingRepo->create([
            'orderId'           => $orderId,
            'jobId'             => $jobId,
            'deliveryAddressId' => (int) $address->id,
            'status'            => PendingAddressCheck::STATUS_PENDING,
            'submittedAt'       => date('Y-m-d H:i:s'),
        ]);

        $this->report(__METHOD__, 'HeistaAddressCheck::log.submitted', [
            'orderId' => $orderId,
            'jobId'   => $jobId,
        ]);
    }

    private function buildCallbackUrl(): string
    {
        $configured = trim((string) $this->config->get('HeistaAddressCheck.pluginCallbackBaseUrl'));
        if ($configured !== '') {
            return rtrim($configured, '/') . '/address-check/callback';
        }
        // Fallback: relative path. The platform requires https, so the operator
        // must set pluginCallbackBaseUrl for the fast-path webhook to work.
        return '/address-check/callback';
    }

    private function buildPayload(int $orderId, Address $address, string $callbackUrl, string $callbackSecret, string $n8nEndpointOverride = '', string $shippingProvider = ''): array
    {
        $countryIso = '';
        if (!empty($address->countryId)) {
            try {
                $countryIso = strtoupper($this->countryRepo->findIsoCode((int) $address->countryId, 'isoCode2'));
            } catch (Throwable $e) {
                $countryIso = '';
            }
        }

        // Plenty's Address model exposes the same logical fields under two
        // parallel columns: typed (street/companyName/firstName/...) and
        // numbered (address1..4 / name1..4). Different shops populate
        // different sets, so read both. `?:` (truthy) not `??` (null-only)
        // because Plenty stores "" rather than null for unset fields.
        $item = [
            'addressId'     => (string) ($address->id ?? ''),
            'street'        => (string) ($address->street      ?: ($address->address1 ?: '')),
            'houseNumber'   => (string) ($address->houseNumber ?: ($address->address2 ?: '')),
            'postalCode'    => (string) ($address->postalCode  ?: ''),
            'city'          => (string) ($address->town        ?: ''),
            'country'       => $countryIso,
            'company'       => (string) ($address->companyName ?: ($address->name1    ?: '')),
            'firstName'     => (string) ($address->firstName   ?: ($address->name2    ?: '')),
            'lastName'      => (string) ($address->lastName    ?: ($address->name3    ?: '')),
            'nameAddition'  => (string) ($address->name4       ?: ''),
            'addressLine1'  => (string) ($address->additional  ?: ($address->address3 ?: '')),
            'addressLine2'  => (string) ($address->address4    ?: ''),
            'isPackstation' => (bool)   ($address->isPackstation ?? false),
            'isPostfiliale' => (bool)   ($address->isPostfiliale ?? false),
            'postnumber'    => (string) ($address->packstationNo ?: ''),
            // Carrier routing key (dhl/dpd/…) resolved from the order's shipping
            // profile. Empty when unmapped → platform falls back to the generic
            // n8n cleanup prompt. Must match the n8n Provider Router keys.
            'shippingProvider' => $shippingProvider,
        ];

        $payload = [
            'serviceKey'     => 'address_check',
            'callbackUrl'    => $callbackUrl,
            'callbackSecret' => $callbackSecret,
            'items'          => [$item],
        ];

        if ($n8nEndpointOverride !== '') {
            $payload['config'] = ['_n8nEndpoint' => $n8nEndpointOverride];
        }

        return $payload;
    }

    /**
     * Read the order's shipping profile ID.
     *
     * Prefers the direct `shippingProfileId` field; falls back to the order
     * property of type SHIPPING_PROFILE (typeId 2), which is where Plenty also
     * stores it and which is sometimes the only populated source at the
     * order-created event. Returns 0 when neither yields a positive integer.
     */
    private function resolveShippingProfileId(Order $order): int
    {
        $direct = (int) ($order->shippingProfileId ?? 0);
        if ($direct > 0) {
            return $direct;
        }

        $properties = $order->properties ?? [];
        foreach ($properties as $property) {
            if (is_object($property)) {
                $typeId = $property->typeId ?? null;
                $value  = $property->value ?? '';
            } elseif (is_array($property)) {
                $typeId = $property['typeId'] ?? null;
                $value  = $property['value'] ?? '';
            } else {
                continue;
            }

            if ((int) $typeId === OrderPropertyType::SHIPPING_PROFILE && is_numeric($value)) {
                return (int) $value;
            }
        }

        return 0;
    }

    /**
     * Map a Plenty shipping-profile ID to a Heista-defined provider routing key.
     *
     * Providers are defined by us and must match the n8n workflow's Provider
     * Router keys. Precedence is fixed (dhl, then dpd): the first carrier whose
     * configured ID list contains the profile wins. Returns '' when the profile
     * is unmapped, so the platform falls back to the generic cleanup prompt.
     */
    private function resolveShippingProviderKey(int $profileId): string
    {
        if ($profileId <= 0) {
            return '';
        }

        $map = [
            'dhl' => $this->parseProfileIds((string) $this->config->get('HeistaAddressCheck.dhlProfileIds')),
            'dpd' => $this->parseProfileIds((string) $this->config->get('HeistaAddressCheck.dpdProfileIds')),
        ];

        foreach ($map as $provider => $ids) {
            if (in_array($profileId, $ids, true)) {
                return $provider;
            }
        }

        return '';
    }

    /**
     * Parse a comma-separated profile-ID config string into a list of ints.
     * Non-numeric / empty entries are dropped.
     */
    private function parseProfileIds(string $csv): array
    {
        $ids = [];
        foreach (explode(',', $csv) as $part) {
            $part = trim($part);
            if ($part !== '' && is_numeric($part)) {
                $ids[] = (int) $part;
            }
        }
        return $ids;
    }
}
