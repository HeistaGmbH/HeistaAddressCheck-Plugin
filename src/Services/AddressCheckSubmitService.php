<?php

namespace HeistaAddressCheck\Services;

use HeistaAddressCheck\Models\PendingAddressCheck;
use HeistaAddressCheck\PlatformEnvironment;
use HeistaAddressCheck\Repositories\PendingAddressCheckRepository;
use Plenty\Modules\Account\Address\Models\Address;
use Plenty\Modules\Account\Address\Models\AddressRelationType;
use Plenty\Modules\Authorization\Services\AuthHelper;
use Plenty\Modules\Order\Address\Contracts\OrderAddressRepositoryContract;
use Plenty\Modules\Order\Contracts\OrderRepositoryContract;
use Plenty\Modules\Order\Models\Order;
use Plenty\Modules\Order\Property\Models\OrderPropertyType;
use Plenty\Modules\Order\Shipping\Countries\Contracts\CountryRepositoryContract;
use Plenty\Plugin\Application;
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
    private OrderRepositoryContract $orderRepo;
    private CountryRepositoryContract $countryRepo;
    private PendingAddressCheckRepository $pendingRepo;
    private AuthHelper $authHelper;
    private ConfigRepository $config;

    public function __construct(
        SaasClient $saasClient,
        OrderAddressRepositoryContract $orderAddressRepo,
        OrderRepositoryContract $orderRepo,
        CountryRepositoryContract $countryRepo,
        PendingAddressCheckRepository $pendingRepo,
        AuthHelper $authHelper,
        ConfigRepository $config
    ) {
        $this->saasClient       = $saasClient;
        $this->orderAddressRepo = $orderAddressRepo;
        $this->orderRepo        = $orderRepo;
        $this->countryRepo      = $countryRepo;
        $this->pendingRepo      = $pendingRepo;
        $this->authHelper       = $authHelper;
        $this->config           = $config;
    }

    public function submitForOrder(Order $order): void
    {
        $orderId = (int) $order->id;

        $environment = (string) $this->config->get('HeistaAddressCheck.environment', PlatformEnvironment::PRODUCTION);
        $apiKey      = trim((string) $this->config->get('HeistaAddressCheck.apiKey'));

        if ($apiKey === '') {
            $this->getLogger(__METHOD__)->warning('HeistaAddressCheck::log.missingConfig', ['orderId' => $orderId]);
            return;
        }

        // Per-job callback token, derived from the API key the merchant already
        // configured — there is no separate callback secret to paste anymore.
        // The platform echoes this verbatim in the X-Heista-Secret header on the
        // result webhook; CallbackController re-derives and verifies it.
        $callbackSecret = CallbackToken::issue($apiKey);

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

        // Customer email for carrier completeness checks (DPD requires one).
        // Delivery address first, order billing address as fallback.
        $email = $this->resolveEmail($orderId, $address);

        $callbackUrl = $this->buildCallbackUrl();
        $this->getLogger(__METHOD__)->debug('HeistaAddressCheck::log.callbackUrlResolved', [
            'orderId'     => $orderId,
            'plentyId'    => (int) pluginApp(Application::class)->getPlentyId(),
            'callbackUrl' => $callbackUrl,
        ]);
        $payload     = $this->buildPayload($orderId, $address, $callbackUrl, $callbackSecret, $n8nEndpointOverride, $shippingProvider, $email);

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
            // The check never reached the SaaS (bad/revoked key, network, 5xx).
            // Route the order to the configured "processing error" status so the
            // merchant sees it didn't run, instead of it silently staying put.
            $this->applyErrorStatus($orderId);
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
        // The callback is a PUBLIC REST route served by the PlentyONE REST layer
        // at /rest/heista/address-check/callback (see AddressCheckRouteServiceProvider).
        // The URL is auto-derived from this system's plentyId — the merchant never
        // configures it. The *.my.plentysystems.com system host always serves
        // /rest/ regardless of storefront, so this works even on headless PWA
        // shops. plentyId == the `p<N>` number in the system URL
        // (verified: plentyId 15950 -> p15950.my.plentysystems.com).
        $plentyId = (int) pluginApp(Application::class)->getPlentyId();
        if ($plentyId <= 0) {
            // No plenty context -> can't build an absolute host. Return empty so
            // the SaaS skips the push callback and falls back to its cron-poll.
            $this->getLogger(__METHOD__)->warning('HeistaAddressCheck::log.callbackPlentyIdMissing', []);
            return '';
        }

        return 'https://p' . $plentyId . '.my.plentysystems.com/rest/heista/address-check/callback';
    }

    private function buildPayload(int $orderId, Address $address, string $callbackUrl, string $callbackSecret, string $n8nEndpointOverride = '', string $shippingProvider = '', string $email = ''): array
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
            'email'         => $email,
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
     * Resolve the customer email for the delivery. Prefers the delivery
     * address's own email option ($address->email, backed by
     * AddressOption::TYPE_EMAIL); falls back to the order's billing address
     * email when the delivery address has none. Returns '' when neither has
     * one — the backend then flags DPD orders as email_required.
     */
    private function resolveEmail(int $orderId, Address $deliveryAddress): string
    {
        $email = trim((string) ($deliveryAddress->email ?? ''));
        if ($email !== '') {
            return $email;
        }

        try {
            $billing = $this->orderAddressRepo->findAddressByType($orderId, AddressRelationType::BILLING_ADDRESS);
            return trim((string) ($billing->email ?? ''));
        } catch (Throwable $e) {
            return '';
        }
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

    /**
     * Route the order to the configured "processing error" status
     * (Config.statusOnError, "Status bei Verarbeitungsfehler") after a submit
     * failure. Soft-fail and best-effort: a status problem must never bubble
     * out of the order-creation event procedure. Empty/non-numeric config means
     * "leave the order alone" rather than failing.
     *
     * Mirrors the status-id parsing + processUnguarded write in
     * AddressCheckApplyService::resolveTargetStatusId / updateOrderStatus — the
     * submit path is intentionally decoupled from the apply path; keep the two
     * in sync if the parsing rules change. Only the 'error' bucket applies here
     * (the SaaS never ran), so there's no outputStatus switch.
     */
    private function applyErrorStatus(int $orderId): void
    {
        $configKey = 'HeistaAddressCheck.statusOnError';
        $raw       = trim((string) $this->config->get($configKey));
        if ($raw === '') {
            return;
        }

        // Plenty status IDs are decimals like "5.1", "7", "8.4". Reject anything
        // else with a warning so a typo'd config can't corrupt order routing.
        if (!is_numeric($raw)) {
            $this->getLogger(__METHOD__)->warning('HeistaAddressCheck::log.statusIdInvalid', [
                'configKey' => $configKey,
                'value'     => $raw,
            ]);
            return;
        }

        $statusId = (float) $raw;
        if ($statusId <= 0) {
            return;
        }

        try {
            $this->authHelper->processUnguarded(function () use ($orderId, $statusId): void {
                $this->orderRepo->updateOrder(['statusId' => $statusId], $orderId);
            });
            $this->report(__METHOD__, 'HeistaAddressCheck::log.errorStatusApplied', [
                'orderId'  => $orderId,
                'statusId' => $statusId,
            ]);
        } catch (Throwable $e) {
            $this->getLogger(__METHOD__)->warning('HeistaAddressCheck::log.errorStatusUpdateFailed', [
                'orderId'        => $orderId,
                'targetStatusId' => $statusId,
                'error'          => $e->getMessage(),
            ]);
        }
    }
}
