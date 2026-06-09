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

        // Optional endpoint override, sent as config._n8nEndpoint to route this
        // shop to an alternate backend workflow instead of the default. Leave
        // empty in production.
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

        // Resolve the order's shipping carrier so the backend can pick a
        // carrier-specific cleanup prompt. Unmapped profiles send '' and get
        // the generic prompt.
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
        // Relative fallback. The API requires https, so pluginCallbackBaseUrl
        // has to be set for the webhook callback to actually work.
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

        // Plenty's Address has two parallel column sets for the same fields:
        // named (street/companyName/firstName/...) and numbered (address1..4/
        // name1..4). Shops populate different sets, so fall back across both.
        // Use ?: not ?? because Plenty stores "" (not null) for empty fields.
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
            // Carrier key (dhl/dpd/...) from the shipping profile; empty when
            // unmapped. Must match the provider keys the backend expects.
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
     * Get the order's shipping profile ID.
     *
     * Prefers $order->shippingProfileId, falls back to the SHIPPING_PROFILE
     * order property (typeId 2), which is sometimes the only one populated at
     * the order-created event. Returns 0 if neither is a positive int.
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
     * Map a shipping-profile ID to a carrier key (must match what the backend
     * expects). Fixed precedence, dhl then dpd: the first carrier whose
     * configured ID list contains the profile wins. Returns '' when unmapped.
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
