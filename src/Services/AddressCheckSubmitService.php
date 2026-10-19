<?php

namespace HeistaAddressCheck\Services;

use HeistaAddressCheck\Models\PendingAddressCheck;
use HeistaAddressCheck\PlatformEnvironment;
use HeistaAddressCheck\Repositories\PendingAddressCheckRepository;
use Plenty\Modules\Account\Address\Contracts\AddressRepositoryContract;
use Plenty\Modules\Account\Address\Models\Address;
use Plenty\Modules\Account\Address\Models\AddressOption;
use Plenty\Modules\Account\Address\Models\AddressRelationType;
use Plenty\Modules\Account\Contact\Contracts\ContactRepositoryContract;
use Plenty\Modules\Account\Contact\Models\ContactOption;
use Plenty\Modules\Authorization\Services\AuthHelper;
use Plenty\Modules\Order\Address\Contracts\OrderAddressRepositoryContract;
use Plenty\Modules\Order\Contracts\OrderRepositoryContract;
use Plenty\Modules\Order\Models\Order;
use Plenty\Modules\Order\Property\Models\OrderPropertyType;
use Plenty\Modules\Order\RelationReference\Models\OrderRelationReference;
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
    private AddressRepositoryContract $addressRepo;
    private OrderRepositoryContract $orderRepo;
    private CountryRepositoryContract $countryRepo;
    private PendingAddressCheckRepository $pendingRepo;
    private AuthHelper $authHelper;
    private ConfigRepository $config;
    private DeliveryOrderGuard $deliveryOrderGuard;
    private ContactRepositoryContract $contactRepo;
    private AddressCheckApplyService $apply;

    /**
     * Retry cadence for a submit that could not reach the platform (1.8.0).
     * The first retry comes with the next cron tick (5 minutes), then the gap
     * doubles up to an hour, and a Retry-After from the platform overrides the
     * gap. After RETRY_WINDOW_SECONDS since the first attempt the plugin stops
     * and tells the merchant, so no order waits forever without anyone knowing.
     * 24 hours covers a platform-side interruption of several hours with room
     * to spare; a weekend one may take longer, and then the note says so.
     */
    private const RETRY_WINDOW_SECONDS     = 24 * 3600;
    private const RETRY_BASE_DELAY_SECONDS = 300;
    private const RETRY_MAX_DELAY_SECONDS  = 3600;

    public function __construct(
        SaasClient $saasClient,
        OrderAddressRepositoryContract $orderAddressRepo,
        AddressRepositoryContract $addressRepo,
        OrderRepositoryContract $orderRepo,
        CountryRepositoryContract $countryRepo,
        PendingAddressCheckRepository $pendingRepo,
        AuthHelper $authHelper,
        ConfigRepository $config,
        DeliveryOrderGuard $deliveryOrderGuard,
        ContactRepositoryContract $contactRepo,
        AddressCheckApplyService $apply
    ) {
        $this->saasClient         = $saasClient;
        $this->orderAddressRepo   = $orderAddressRepo;
        $this->addressRepo        = $addressRepo;
        $this->orderRepo          = $orderRepo;
        $this->countryRepo        = $countryRepo;
        $this->pendingRepo        = $pendingRepo;
        $this->authHelper         = $authHelper;
        $this->config             = $config;
        $this->deliveryOrderGuard = $deliveryOrderGuard;
        $this->contactRepo        = $contactRepo;
        $this->apply              = $apply;
    }

    /**
     * Entry point of the event procedure. An order that already sits in the
     * retry queue (the merchant re-ran the action by hand) is attempted now on
     * its existing row instead of getting a second one that would submit the
     * same address twice later.
     */
    public function submitForOrder(Order $order): void
    {
        $this->attempt($order, $this->pendingRepo->findRetryByOrderId((int) $order->id));
    }

    /**
     * Entry point of FallbackPollCron for a RETRY row whose nextAttemptAt has
     * passed. Loads the order itself; the event that carried it is long gone.
     * A row whose order cannot be loaded is closed without a status write: there
     * is no order to write to.
     */
    public function resubmit(PendingAddressCheck $row): void
    {
        $orderId   = (int) $row->orderId;
        $loadError = '';

        try {
            $order = $this->authHelper->processUnguarded(function () use ($orderId) {
                return $this->orderRepo->findOrderById($orderId);
            });
        } catch (Throwable $e) {
            $order     = null;
            $loadError = $e->getMessage();
        }

        if (!$order instanceof Order) {
            $this->getLogger(__METHOD__)->error('HeistaAddressCheck::log.retryOrderLoadFailed', [
                'orderId' => $orderId,
                'error'   => $loadError !== '' ? $loadError : 'order not found',
            ]);
            $this->pendingRepo->markFailed($row, 'retry: order could not be loaded');
            return;
        }

        $this->attempt($order, $row);
    }

    /**
     * One submit attempt. $row is the queue row this attempt belongs to, null on
     * a fresh submit from the event procedure. The early exits that have nothing
     * to do with the platform (no key, delivery orders, no address) close or park
     * the row so the cron does not pick it up every five minutes for the same
     * reason.
     */
    private function attempt(Order $order, ?PendingAddressCheck $row): void
    {
        $orderId = (int) $order->id;

        $environment = (string) $this->config->get('HeistaAddressCheck.environment', PlatformEnvironment::PRODUCTION);
        $apiKey      = trim((string) $this->config->get('HeistaAddressCheck.apiKey'));

        if ($apiKey === '') {
            $this->getLogger(__METHOD__)->warning('HeistaAddressCheck::log.missingConfig', ['orderId' => $orderId]);
            if ($row !== null) {
                // The key may be on its way; look again in an hour rather than every tick.
                $nextAttemptAt = date('Y-m-d H:i:s', time() + self::RETRY_MAX_DELAY_SECONDS);
                $this->pendingRepo->scheduleRetry($row, (int) $row->attempts, $nextAttemptAt, 'API key missing');
                // error, not warning: a warning never shows in the Plenty log unless the
                // merchant switches the plugin's log on, and a missing key must be seen.
                $this->getLogger(__METHOD__)->error('HeistaAddressCheck::log.retryWaitingForConfig', [
                    'orderId'       => $orderId,
                    'nextAttemptAt' => $nextAttemptAt,
                ]);
            }
            return;
        }

        // Opt-in: a Hauptauftrag that already has Lieferaufträge is not checked, because the
        // Lieferauftrag is what ships and the Hauptauftrag's status is derived from it. Sits
        // before everything that costs money. Off by default, see DeliveryOrderGuard.
        if ($this->deliveryOrderGuard->skipSubmit($order)) {
            $this->getLogger(__METHOD__)->info('HeistaAddressCheck::log.skippedHasDeliveryOrders', [
                'orderId' => $orderId,
            ]);
            if ($row !== null) {
                $this->pendingRepo->markFailed($row, 'retry: skipped, order has delivery orders');
            }
            return;
        }

        // Per-job callback token, derived from the API key the merchant already
        // configured — there is no separate callback secret to paste anymore.
        // The platform echoes this verbatim in the X-Heista-Secret header on the
        // result webhook; CallbackController re-derives and verifies it.
        $callbackSecret = CallbackToken::issue($apiKey);

        $devOverride = trim((string) $this->config->get('HeistaAddressCheck.devApiBaseUrlOverride'));
        $apiBaseUrl  = PlatformEnvironment::baseUrlFor($environment, $devOverride);

        // Optional internal endpoint override, routing this shop to an alternate backend
        // workflow instead of the default. Leave empty in production. The payload key below
        // is a legacy contract field name the platform still expects — keep it out of
        // merchant-facing text.
        $endpointOverride = trim((string) $this->config->get('HeistaAddressCheck.devN8nEndpointOverride'));

        try {
            $address = $this->orderAddressRepo->findAddressByType($orderId, AddressRelationType::DELIVERY_ADDRESS);
        } catch (Throwable $e) {
            $this->getLogger(__METHOD__)->warning('HeistaAddressCheck::log.deliveryAddressNotFound', [
                'orderId' => $orderId,
                'error'   => $e->getMessage(),
            ]);
            if ($row !== null) {
                $this->pendingRepo->markFailed($row, 'retry: delivery address not found');
            }
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

        // Checked against DHL's phone rule by the platform, so a label is not refused for it.
        $phone = $this->resolvePhone($order, $address);

        $callbackUrl = $this->buildCallbackUrl();
        $this->getLogger(__METHOD__)->debug('HeistaAddressCheck::log.callbackUrlResolved', [
            'orderId'     => $orderId,
            'plentyId'    => (int) pluginApp(Application::class)->getPlentyId(),
            'callbackUrl' => $callbackUrl,
        ]);
        $payload     = $this->buildPayload($orderId, $address, $callbackUrl, $callbackSecret, $endpointOverride, $shippingProvider, $email, $phone);

        $item = $payload['items'][0];

        try {
            $jobId = $this->saasClient->submitJob($apiBaseUrl, $apiKey, $payload);
        } catch (SaasRequestException $e) {
            // Unreachable, 5xx or "wait": the address is fine as far as anyone knows,
            // so the order keeps its status and the cron tries again. A refusal
            // (bad key, empty wallet, invalid DHL credentials) will not change by
            // asking again; the merchant has to act, and the note says what on.
            if ($e->isRetryable()) {
                $this->deferSubmit($orderId, $address, $row, $e, $item);
            } else {
                $this->rejectSubmit($orderId, $address, $row, $e, $item);
            }
            return;
        } catch (Throwable $e) {
            $this->rejectSubmit($orderId, $address, $row, $e, $item);
            return;
        }

        $attempts = $row !== null ? (int) $row->attempts + 1 : 1;
        if ($row !== null) {
            $this->pendingRepo->markPending($row, $jobId, $attempts);
        } else {
            $this->pendingRepo->create([
                'orderId'           => $orderId,
                'jobId'             => $jobId,
                'deliveryAddressId' => (int) $address->id,
                'status'            => PendingAddressCheck::STATUS_PENDING,
                'submittedAt'       => date('Y-m-d H:i:s'),
                'attempts'          => 1,
            ]);
        }

        $this->report(__METHOD__, $attempts > 1 ? 'HeistaAddressCheck::log.resubmitted' : 'HeistaAddressCheck::log.submitted', [
            'orderId'  => $orderId,
            'jobId'    => $jobId,
            'attempts' => $attempts,
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
        // (verified live against a real system: getPlentyId() N -> pN.my.plentysystems.com).
        $plentyId = (int) pluginApp(Application::class)->getPlentyId();
        if ($plentyId <= 0) {
            // No plenty context -> can't build an absolute host. Return empty so
            // the SaaS skips the push callback and falls back to its cron-poll.
            $this->getLogger(__METHOD__)->warning('HeistaAddressCheck::log.callbackPlentyIdMissing', []);
            return '';
        }

        return 'https://p' . $plentyId . '.my.plentysystems.com/rest/heista/address-check/callback';
    }

    private function buildPayload(int $orderId, Address $address, string $callbackUrl, string $callbackSecret, string $endpointOverride = '', string $shippingProvider = '', string $email = '', string $phone = ''): array
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
            // NOT $address->packstationNo — that accessor mirrors address2, i.e. the
            // Packstation STATION number, which we already send as houseNumber. The real
            // Postnummer lives in AddressOption type 6. See resolvePostNumber().
            'postnumber'    => $this->resolvePostNumber((int) $address->id, $address),
            // Carrier key (dhl/dpd/...) from the shipping profile; empty when
            // unmapped. Must match the provider keys the backend expects.
            'shippingProvider' => $shippingProvider,
        ];

        // Only sent when there is one: no phone means nothing to check, and nothing to hand over.
        if ($phone !== '') {
            $item['phone'] = $phone;
        }

        $payload = [
            'serviceKey'     => 'address_check',
            'callbackUrl'    => $callbackUrl,
            'callbackSecret' => $callbackSecret,
            // The Plenty order id, so a job can be traced back to its order from our side.
            // Without it the only way to match a job to an order is by timestamp against the
            // merchant's own order history, which is how the 2026-09-18 double-submit report
            // had to be answered. Stored as `jobs.external_ref` and echoed back in the
            // callback body; the platform gives it no other meaning (no uniqueness, no
            // deduplication), so sending it changes nothing else.
            'externalRef'    => (string) $orderId,
            'items'          => [$item],
        ];

        if ($endpointOverride !== '') {
            $payload['config'] = ['_n8nEndpoint' => $endpointOverride];
        }

        return $payload;
    }

    /**
     * Resolve the customer's DHL Postnummer from AddressOption TYPE_POST_NUMBER (6).
     *
     * **Do NOT use `$address->packstationNo` for this.** That accessor mirrors the
     * `address2` column, which on a Packstation holds the STATION number (which locker,
     * ~3 digits) — the very value we already send as `houseNumber`. Verified live
     * 2026-07-14: a Packstation order submitted `postnumber: "209"` (the station) while
     * the customer's real 9-digit Postnummer sat untouched in option 6. Downstream, DHL
     * answered `The given Post number is not valid` — because it wasn't a post number.
     *
     * The two are different fields with different rules: the station number goes in
     * DHL's `lockerID`, the Postnummer in `postNumber` (`^[0-9]{6,10}$`).
     *
     * Prefers the options already loaded on the model and falls back to an explicit
     * lookup. Returns '' when the address has no post number — the backend then lets DHL
     * say so ("Please enter a Postnummer"), which is the correct verdict for a locker
     * delivery that cannot be made.
     */
    private function resolvePostNumber(int $addressId, Address $address): string
    {
        $fromModel = $this->postNumberFromOptions($address->options ?? []);
        if ($fromModel !== '') {
            return $fromModel;
        }

        if ($addressId <= 0) {
            return '';
        }

        try {
            $options = $this->addressRepo->findAddressOptions($addressId, AddressOption::TYPE_POST_NUMBER);
            return $this->postNumberFromOptions($options);
        } catch (Throwable $e) {
            $this->getLogger(__METHOD__)->warning('HeistaAddressCheck::log.postNumberLookupFailed', [
                'addressId' => $addressId,
                'error'     => $e->getMessage(),
            ]);
            return '';
        }
    }

    /**
     * Pick the TYPE_POST_NUMBER value out of an address-option list. Iterates directly so
     * it works for both arrays and Plenty Collections, and tolerates array- or
     * object-shaped entries.
     */
    private function postNumberFromOptions($options): string
    {
        foreach (($options ?: []) as $option) {
            if (is_object($option)) {
                $typeId = $option->typeId ?? null;
                $value  = $option->value ?? '';
            } elseif (is_array($option)) {
                $typeId = $option['typeId'] ?? null;
                $value  = $option['value'] ?? '';
            } else {
                continue;
            }

            if ((int) $typeId === AddressOption::TYPE_POST_NUMBER) {
                $value = trim((string) $value);
                if ($value !== '') {
                    return $value;
                }
            }
        }

        return '';
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
     * Returns the recipient's phone: the delivery address (AddressOption type 4), else the
     * billing address, else the receiving contact. That is the order the merchant expects
     * Plenty's DHL integration to read it in; which one the label really uses is not verified
     * yet, so the debug line names the source that answered. '' when none has one.
     */
    private function resolvePhone(Order $order, Address $deliveryAddress): string
    {
        $orderId = (int) $order->id;

        $source = 'delivery';
        $phone  = trim((string) ($deliveryAddress->phone ?? ''));
        if ($phone === '') {
            $source = 'billing';
            $phone  = $this->billingAddressPhone($orderId);
        }
        if ($phone === '') {
            $source = 'contact';
            $phone  = $this->contactPhone($this->receiverContactId($order));
        }
        if ($phone === '') {
            $source = 'none';
        }

        $this->getLogger(__METHOD__)->debug('HeistaAddressCheck::log.phoneSourceResolved', [
            'orderId' => $orderId,
            'source'  => $source,
        ]);

        return $phone;
    }

    private function billingAddressPhone(int $orderId): string
    {
        try {
            $billing = $this->orderAddressRepo->findAddressByType($orderId, AddressRelationType::BILLING_ADDRESS);
            return trim((string) ($billing->phone ?? ''));
        } catch (Throwable $e) {
            return '';
        }
    }

    /**
     * The receiving contact's id: `contactReceiverId` when the order model carries it, else
     * the receiver relation. 0 for a guest order without a contact.
     */
    private function receiverContactId(Order $order): int
    {
        $direct = (int) ($order->contactReceiverId ?? 0);
        if ($direct > 0) {
            return $direct;
        }

        foreach (($order->relations ?? []) as $relation) {
            if (is_object($relation)) {
                $type = $relation->referenceType ?? '';
                $role = $relation->relation ?? '';
                $id   = $relation->referenceId ?? 0;
            } elseif (is_array($relation)) {
                $type = $relation['referenceType'] ?? '';
                $role = $relation['relation'] ?? '';
                $id   = $relation['referenceId'] ?? 0;
            } else {
                continue;
            }

            if ($type === OrderRelationReference::REFERENCE_TYPE_CONTACT
                && $role === OrderRelationReference::RELATION_TYPE_RECEIVER
                && (int) $id > 0) {
                return (int) $id;
            }
        }

        return 0;
    }

    /**
     * The contact's private phone, else private mobile, else any other phone option.
     * Soft-fail: a contact we cannot read means no phone, never a failed submit.
     */
    private function contactPhone(int $contactId): string
    {
        if ($contactId <= 0) {
            return '';
        }

        try {
            $contact = $this->authHelper->processUnguarded(function () use ($contactId) {
                return $this->contactRepo->findContactById($contactId);
            });
        } catch (Throwable $e) {
            $this->getLogger(__METHOD__)->warning('HeistaAddressCheck::log.contactLookupFailed', [
                'contactId' => $contactId,
                'error'     => $e->getMessage(),
            ]);
            return '';
        }

        if (!is_object($contact)) {
            return '';
        }

        foreach ([$contact->privatePhone ?? '', $contact->privateMobile ?? ''] as $candidate) {
            $value = trim((string) $candidate);
            if ($value !== '') {
                return $value;
            }
        }

        foreach (($contact->options ?? []) as $option) {
            if (is_object($option)) {
                $typeId = $option->typeId ?? null;
                $value  = $option->value ?? '';
            } elseif (is_array($option)) {
                $typeId = $option['typeId'] ?? null;
                $value  = $option['value'] ?? '';
            } else {
                continue;
            }

            if ((int) $typeId === ContactOption::TYPE_PHONE) {
                $value = trim((string) $value);
                if ($value !== '') {
                    return $value;
                }
            }
        }

        return '';
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
     * The platform could not be reached, failed on its side or asked us to wait.
     * Park the order for another attempt; its status stays untouched, because
     * nothing is known about the address yet. Once the retry window is used up,
     * give up the way a refusal is handled: FAILED row, error status, and a note
     * that names the attempts and the last cause, with the way to re-run it.
     */
    private function deferSubmit(int $orderId, Address $address, ?PendingAddressCheck $row, SaasRequestException $e, array $item): void
    {
        $attempts       = ($row !== null ? (int) $row->attempts : 0) + 1;
        $firstAttemptAt = $row !== null && $row->submittedAt !== '' ? (int) strtotime($row->submittedAt) : 0;
        if ($firstAttemptAt <= 0) {
            $firstAttemptAt = time();
        }

        if (time() - $firstAttemptAt >= self::RETRY_WINDOW_SECONDS) {
            $this->getLogger(__METHOD__)->error('HeistaAddressCheck::log.submitGivenUp', [
                'orderId'        => $orderId,
                'attempts'       => $attempts,
                'firstAttemptAt' => date('Y-m-d H:i:s', $firstAttemptAt),
                'error'          => $e->getMessage(),
            ]);
            $this->closeRow($orderId, $address, $row, $e->getMessage());
            $this->apply->writeSubmitFailure($orderId, $item, sprintf(
                'Die Plattform war nicht erreichbar: %d Versuche seit %s, zuletzt: %s. Ereignisaktion erneut ausführen, um die Prüfung neu anzustoßen.',
                $attempts,
                date('d.m.Y H:i', $firstAttemptAt),
                $this->unreachableText($e)
            ));
            return;
        }

        $retryAfter = $e->getRetryAfterSeconds();
        $delay      = $retryAfter > 0
            ? max(60, $retryAfter)
            : (int) min(self::RETRY_MAX_DELAY_SECONDS, self::RETRY_BASE_DELAY_SECONDS * (2 ** ($attempts - 1)));
        $nextAttemptAt = date('Y-m-d H:i:s', time() + $delay);

        if ($row === null) {
            $this->pendingRepo->create([
                'orderId'           => $orderId,
                'jobId'             => '',
                'deliveryAddressId' => (int) $address->id,
                'status'            => PendingAddressCheck::STATUS_RETRY,
                'submittedAt'       => date('Y-m-d H:i:s'),
                'lastError'         => substr($e->getMessage(), 0, 1000),
                'attempts'          => 1,
                'nextAttemptAt'     => $nextAttemptAt,
            ]);
        } else {
            $this->pendingRepo->scheduleRetry($row, $attempts, $nextAttemptAt, $e->getMessage());
        }

        // report(), not warning(): a warning is invisible in the Plenty log until the
        // merchant activates it, and "why was this order not checked yet" is exactly
        // the question this line answers.
        $this->report(__METHOD__, 'HeistaAddressCheck::log.submitDeferred', [
            'orderId'       => $orderId,
            'attempt'       => $attempts,
            'httpStatus'    => $e->getHttpStatus(),
            'nextAttemptAt' => $nextAttemptAt,
            'error'         => $e->getMessage(),
        ]);
    }

    /**
     * The platform answered, and the answer will not change by asking again:
     * FAILED row, configured error status, and an internal note that says what
     * was refused and where to fix it. Before 1.8.0 the status was set and no
     * note was written, so a merchant could not tell an unchecked order from a
     * bad address.
     */
    private function rejectSubmit(int $orderId, Address $address, ?PendingAddressCheck $row, Throwable $e, array $item): void
    {
        $status = $e instanceof SaasRequestException ? $e->getHttpStatus() : 0;
        $code   = $e instanceof SaasRequestException ? $e->getErrorCode() : '';

        $this->getLogger(__METHOD__)->error('HeistaAddressCheck::log.submitRejected', [
            'orderId'    => $orderId,
            'httpStatus' => $status,
            'code'       => $code,
            'error'      => $e->getMessage(),
        ]);
        $this->closeRow($orderId, $address, $row, $e->getMessage());
        $this->apply->writeSubmitFailure($orderId, $item, 'Die Plattform hat die Prüfung abgelehnt: ' . $this->refusalText($status, $code, $e));
    }

    /** Mark the queue row FAILED, creating it when this was the first attempt. */
    private function closeRow(int $orderId, Address $address, ?PendingAddressCheck $row, string $error): void
    {
        if ($row !== null) {
            $this->pendingRepo->markFailed($row, $error);
            return;
        }

        $this->pendingRepo->create([
            'orderId'           => $orderId,
            'jobId'             => '',
            'deliveryAddressId' => (int) $address->id,
            'status'            => PendingAddressCheck::STATUS_FAILED,
            'submittedAt'       => date('Y-m-d H:i:s'),
            'lastError'         => substr($error, 0, 1000),
            'attempts'          => 1,
        ]);
    }

    /** Cause of an unreachable submit, for the order note. German like every note. */
    private function unreachableText(SaasRequestException $e): string
    {
        $status = $e->getHttpStatus();
        if ($status === 0) {
            return 'keine Verbindung zur Plattform';
        }
        if ($status === 429) {
            return 'die Plattform hat um Wartezeit gebeten (zu viele Anfragen)';
        }
        if ($status >= 500) {
            return 'Fehler auf der Plattform (HTTP ' . $status . ')';
        }

        return 'HTTP ' . $status;
    }

    /**
     * What a refusal means for the merchant and where to fix it, keyed on the
     * platform's error code. Codes are the published v1 contract; anything else
     * falls back to the bare status so the note still says something true.
     */
    private function refusalText(int $status, string $code, Throwable $e): string
    {
        switch ($code) {
            case 'UNAUTHORIZED':
                return 'API-Schlüssel ungültig oder widerrufen. Plugin-Konfiguration prüfen.';
            case 'FORBIDDEN':
                return 'Zugriff verweigert (HTTP 403). Abo und Berechtigung des API-Schlüssels im Heista-Portal prüfen.';
            case 'INSUFFICIENT_BALANCE':
                return 'Guthaben reicht nicht aus. Im Heista-Portal aufladen.';
            case 'DHL_CREDENTIALS_INVALID':
                return 'DHL-Zugangsdaten ungültig. Im Heista-Portal unter Adressprüfung prüfen.';
            case 'VALIDATION_ERROR':
                return 'Anfrage ungültig (HTTP 400). Bitte den Heista-Support informieren.';
        }

        if ($status > 0) {
            return 'HTTP ' . $status . ($code !== '' ? ' ' . $code : '') . '.';
        }

        return substr($e->getMessage(), 0, 200);
    }
}
