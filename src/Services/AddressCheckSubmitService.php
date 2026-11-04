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
     * gap. After the retry window since the first attempt the plugin stops and
     * tells the merchant, so no order waits forever without anyone knowing.
     *
     * The window is the merchant's choice since 1.9.0 (config retryWindowHours,
     * 24 hours fixed before): a shop that ships the same day wants the order in
     * the error status soon, others would rather have it checked by itself once
     * the platform is back. 8 hours by default, because our outages so far fell
     * on a Sunday evening (6 hours, 2026-10-18) and at night (2026-11-03), and
     * 8 hours get such an order checked before the morning shift.
     */
    private const RETRY_WINDOW_HOURS_ALLOWED = [1, 2, 4, 8, 12, 24];
    private const RETRY_WINDOW_HOURS_DEFAULT = 8;
    private const RETRY_BASE_DELAY_SECONDS   = 300;
    private const RETRY_MAX_DELAY_SECONDS    = 3600;

    /**
     * Duplicate guard (1.9.0). Plenty runs every event action whose filter matches, so a
     * merchant whose two filters overlap submits one order through both procedures: two
     * jobs, two notes, two status writes racing, billed twice. Inside this window a submit
     * from the other procedure is resolved instead of sent (see submitForOrder). Ten
     * minutes: two procedures on one event fire within a second, a merchant's deliberate
     * re-run after fixing an address comes minutes to hours later. A constant, not a config
     * key, because nobody has a reason to tune it.
     */
    private const DUPLICATE_WINDOW_SECONDS = PendingAddressCheck::DUPLICATE_WINDOW_SECONDS;

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
     * Entry point of both event procedures. An order that already sits in the
     * retry queue (the merchant re-ran the action by hand) is attempted now on
     * its existing row instead of getting a second one that would submit the
     * same address twice later.
     *
     * $options['b2bCheck'] is set by the business customer procedure (1.9.0).
     *
     * Before that, the duplicate guard: when the OTHER procedure submitted this
     * order inside DUPLICATE_WINDOW_SECONDS, the business customer check wins and
     * only one job exists. A plain submit after a business customer one is dropped
     * (the order already gets the superset). A business customer submit after a plain one
     * upgrades a still-queued RETRY row, and otherwise writes a note on the order,
     * because the plain job is already at the platform and cannot be given the
     * flag afterwards. The same procedure twice behaves exactly as 1.8.0, so a
     * merchant with one procedure never meets the guard. The cron path
     * (resubmit) never comes through here, so a queued row cannot block itself.
     *
     * A RETRY row that carries a job id (its create went through, its confirm did
     * not) counts as submitted: that job exists without the flag, so the business
     * customer request gets the note instead of an upgrade, exactly as for a row
     * already waiting for its result.
     */
    public function submitForOrder(Order $order, array $options = []): void
    {
        $orderId   = (int) $order->id;
        $requested = !empty($options['b2bCheck']);

        $recent = $this->findRecentSubmit($orderId);
        if ($recent !== null && $this->rowRequestsB2bCheck($recent) !== $requested) {
            if (!$requested) {
                $this->getLogger(__METHOD__)->info('HeistaAddressCheck::log.duplicateSubmitSkipped', [
                    'orderId' => $orderId,
                    'jobId'   => (string) $recent->jobId,
                    'status'  => (string) $recent->status,
                ]);
                return;
            }

            if ($recent->status === PendingAddressCheck::STATUS_RETRY && (string) $recent->jobId === '') {
                $recent = $this->pendingRepo->markB2bRequested($recent);
                $this->report(__METHOD__, 'HeistaAddressCheck::log.duplicateSubmitUpgraded', [
                    'orderId' => $orderId,
                ]);
                $this->attempt($order, $recent, ['b2bCheck' => true]);
                return;
            }

            $this->report(__METHOD__, 'HeistaAddressCheck::log.duplicateSubmitNoted', [
                'orderId' => $orderId,
                'jobId'   => (string) $recent->jobId,
                'status'  => (string) $recent->status,
            ]);
            $this->apply->writeB2bOverlapNote($orderId, (string) $recent->jobId);
            return;
        }

        $retryRow = $this->pendingRepo->findRetryByOrderId($orderId);

        // A RETRY row older than the window, from the other procedure: the row is still the
        // one submit this order gets, so it carries the business customer check if either side
        // asked for it. Never reached when both sides are the same procedure, which is the
        // only case 1.8.0 knew. A row that carries a job id is not upgraded: that job exists
        // without the flag and may already be confirmed, so creating a flagged one could
        // bill the order twice. The merchant gets the note instead, and the row is attempted
        // as it is.
        if ($retryRow !== null && $requested && !$this->rowRequestsB2bCheck($retryRow)) {
            if ((string) $retryRow->jobId === '') {
                $retryRow = $this->pendingRepo->markB2bRequested($retryRow);
            } else {
                $this->report(__METHOD__, 'HeistaAddressCheck::log.duplicateSubmitNoted', [
                    'orderId' => $orderId,
                    'jobId'   => (string) $retryRow->jobId,
                    'status'  => (string) $retryRow->status,
                ]);
                $this->apply->writeB2bOverlapNote($orderId, (string) $retryRow->jobId);
            }
        }
        $b2b = $retryRow !== null && (string) $retryRow->jobId !== ''
            ? $this->rowRequestsB2bCheck($retryRow)
            : $requested || ($retryRow !== null && $this->rowRequestsB2bCheck($retryRow));

        $this->attempt($order, $retryRow, ['b2bCheck' => $b2b]);
    }

    /**
     * The guard's read, failing open: a repository error is logged and the order is
     * submitted as it would be without the guard. An unchecked address is worse than a
     * double one.
     */
    private function findRecentSubmit(int $orderId): ?PendingAddressCheck
    {
        try {
            return $this->pendingRepo->findRecentByOrderId($orderId, self::DUPLICATE_WINDOW_SECONDS);
        } catch (Throwable $e) {
            $this->getLogger(__METHOD__)->error('HeistaAddressCheck::log.duplicateGuardFailed', [
                'orderId' => $orderId,
                'error'   => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * The stored flag as a bool. The database may hand it back as 0/1 or "0"/"1"
     * rather than a PHP bool, and "0" is truthy, so no plain cast.
     */
    private function rowRequestsB2bCheck(PendingAddressCheck $row): bool
    {
        return filter_var($row->b2bCheck, FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * Entry point of FallbackPollCron for a RETRY row whose nextAttemptAt has
     * passed. Loads the order itself; the event that carried it is long gone.
     * A row whose order cannot be loaded is closed without a status write: there
     * is no order to write to.
     *
     * Returns the failure when the platform could not be reached (1.9.0), null
     * otherwise. The cron passes it back in as $platformDown for the rest of the
     * run: such a row is not requested, so one run does not wait out a timeout per
     * queued row. A row whose retry window has run out is still closed with the
     * note and the error status, so the window holds however long the outage is;
     * any other row stays due as it is and comes up in the next run.
     */
    public function resubmit(PendingAddressCheck $row, ?SaasRequestException $platformDown = null): ?SaasRequestException
    {
        // Both actions failed at the same moment and queued one row each (two flows on one
        // trigger, parallel processes, p18857 2026-11-05). The cron runs the rows one after the
        // other, so here the plain one can still give way: the flagged row is the one submit
        // this order gets, and no second job is created or billed. Local only, no request.
        if (!$this->rowRequestsB2bCheck($row)) {
            $sibling = $this->pendingRepo->findB2bSibling($row, self::DUPLICATE_WINDOW_SECONDS);
            if ($sibling !== null) {
                $this->pendingRepo->markFailed($row, 'set aside: business customer row ' . (int) $sibling->id . ' of the same order goes instead');
                $this->apply->writeRetrySetAsideNote((int) $row->orderId, (string) $row->jobId);
                $this->report(__METHOD__, 'HeistaAddressCheck::log.retrySetAside', [
                    'orderId'  => (int) $row->orderId,
                    'jobId'    => (string) $row->jobId,
                    'b2bRowId' => (int) $sibling->id,
                ]);
                return null;
            }
        }

        if ($platformDown !== null && time() - $this->firstAttemptAt($row) < $this->retryWindowSeconds()) {
            return $platformDown;
        }

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
            return null;
        }

        // The row remembers which procedure queued it, so the retry sends what the first
        // attempt would have sent.
        return $this->attempt($order, $row, ['b2bCheck' => $this->rowRequestsB2bCheck($row)], $platformDown);
    }

    /**
     * One submit attempt. $row is the queue row this attempt belongs to, null on
     * a fresh submit from the event procedure. The early exits that have nothing
     * to do with the platform (no key, delivery orders, no address) close or park
     * the row so the cron does not pick it up every five minutes for the same
     * reason.
     *
     * $options['b2bCheck'] puts the flag on the item and on every row this
     * attempt creates, so a retry and the duplicate guard both see it.
     *
     * Returns the failure when the platform could not be reached
     * (SaasRequestException::platformUnavailable), null otherwise, including the
     * early exits that never called it. With $platformDown set (cron, a row past
     * its window) nothing is sent: the row fails as that request did, which closes it.
     */
    private function attempt(Order $order, ?PendingAddressCheck $row, array $options = [], ?SaasRequestException $platformDown = null): ?SaasRequestException
    {
        $orderId     = (int) $order->id;
        $b2b = !empty($options['b2bCheck']);

        $environment = (string) $this->config->get('HeistaAddressCheck.environment', PlatformEnvironment::PRODUCTION);
        $apiKey      = trim((string) $this->config->get('HeistaAddressCheck.apiKey'));

        if ($apiKey === '') {
            $this->getLogger(__METHOD__)->warning('HeistaAddressCheck::log.missingConfig', ['orderId' => $orderId]);
            if ($row !== null) {
                // The key may be on its way; look again in an hour rather than every tick.
                $nextAttemptAt = date('Y-m-d H:i:s', time() + self::RETRY_MAX_DELAY_SECONDS);
                $this->pendingRepo->scheduleRetry($row, (int) $row->attempts, $nextAttemptAt, 'API key missing', (string) $row->jobId);
                // error, not warning: a warning never shows in the Plenty log unless the
                // merchant switches the plugin's log on, and a missing key must be seen.
                $this->getLogger(__METHOD__)->error('HeistaAddressCheck::log.retryWaitingForConfig', [
                    'orderId'       => $orderId,
                    'nextAttemptAt' => $nextAttemptAt,
                ]);
            }
            return null;
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
            return null;
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
            return null;
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
        $payload     = $this->buildPayload($orderId, $address, $callbackUrl, $callbackSecret, $endpointOverride, $shippingProvider, $email, $phone, $b2b);

        $item = $payload['items'][0];

        if ($platformDown !== null) {
            return $this->handleSubmitFailure($orderId, $address, $row, $this->notAttempted($platformDown, $row), $item);
        }

        $attempts = $row !== null ? (int) $row->attempts + 1 : 1;

        // A job from an earlier attempt whose confirm failed (1.9.0): confirm that job
        // instead of creating another, because the first confirm may have landed and
        // only its answer got lost. The platform says whether the job is still usable;
        // the plugin keeps no clock for it.
        $openJobId = $row !== null ? (string) $row->jobId : '';
        if ($openJobId !== '') {
            try {
                $confirmedStatus = $this->saasClient->confirmJob($apiBaseUrl, $apiKey, $openJobId);
                $jobGone         = $confirmedStatus === 'CANCELLED';
            } catch (SaasRequestException $e) {
                $jobGone = $this->confirmFoundJobGone($e);
                if (!$jobGone) {
                    return $this->handleSubmitFailure($orderId, $address, $row, $e->withJobId($openJobId), $item);
                }
            } catch (Throwable $e) {
                $this->rejectSubmit($orderId, $address, $row, $e, $item);
                return null;
            }

            if (!$jobGone) {
                $this->pendingRepo->markPending($row, $openJobId, $attempts);
                $this->report(__METHOD__, 'HeistaAddressCheck::log.confirmRetried', [
                    'orderId'  => $orderId,
                    'jobId'    => $openJobId,
                    'attempts' => $attempts,
                ]);
                return null;
            }

            // Cancelled after 30 minutes unconfirmed, or unknown to this key: nothing
            // was charged for it, so this attempt creates a new job right away.
            $this->report(__METHOD__, 'HeistaAddressCheck::log.confirmRetryJobGone', [
                'orderId' => $orderId,
                'jobId'   => $openJobId,
            ]);
        }

        try {
            $jobId = $this->saasClient->submitJob($apiBaseUrl, $apiKey, $payload);
        } catch (Throwable $e) {
            return $this->handleSubmitFailure($orderId, $address, $row, $e, $item);
        }

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
                'b2bCheck'  => $b2b,
            ]);
        }

        $this->report(__METHOD__, $attempts > 1 ? 'HeistaAddressCheck::log.resubmitted' : 'HeistaAddressCheck::log.submitted', [
            'orderId'  => $orderId,
            'jobId'    => $jobId,
            'attempts' => $attempts,
        ]);

        return null;
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

    private function buildPayload(int $orderId, Address $address, string $callbackUrl, string $callbackSecret, string $endpointOverride = '', string $shippingProvider = '', string $email = '', string $phone = '', bool $b2bCheck = false): array
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

        // Only from the business customer procedure (1.9.0). An item key, not a config key: the
        // platform accepts unknown item keys and stores them, while an unknown config key is
        // a 400 this plugin treats as a refusal. Omitted otherwise, so a plain submit is
        // byte-identical to 1.8.0.
        if ($b2bCheck) {
            $item['b2bCheck'] = true;
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
     * Whether the platform's answer to a confirm of an earlier job says that job is
     * gone: 404 (another tenant's key, another environment) or 409 (cancelled after
     * 30 minutes unconfirmed, the only conflict a plugin job can meet), both from
     * the platform itself. Anything else is handled like any submit failure.
     */
    private function confirmFoundJobGone(SaasRequestException $e): bool
    {
        return $e->platformAnswered()
            && ($e->getHttpStatus() === 404 || $e->getHttpStatus() === 409);
    }

    /**
     * Unreachable, 5xx, "wait", or an answer from something in front of the
     * platform: the address is fine as far as anyone knows, so the order keeps its
     * status and the cron tries again. A refusal (bad key, empty wallet, invalid DHL
     * credentials) will not change by asking again; the merchant has to act, and the
     * note says what on.
     *
     * Returns the failure when the platform could not be reached, the signal attempt()
     * passes on; null otherwise.
     */
    private function handleSubmitFailure(int $orderId, Address $address, ?PendingAddressCheck $row, Throwable $e, array $item): ?SaasRequestException
    {
        if ($e instanceof SaasRequestException && $e->isRetryable()) {
            $this->deferSubmit($orderId, $address, $row, $e, $item);
            return $e->platformUnavailable() ? $e : null;
        }

        $this->rejectSubmit($orderId, $address, $row, $e, $item);
        return null;
    }

    /** The configured retry window in seconds; an unknown value falls back to the default. */
    private function retryWindowSeconds(): int
    {
        $hours = (int) $this->config->get('HeistaAddressCheck.retryWindowHours', (string) self::RETRY_WINDOW_HOURS_DEFAULT);
        if (!in_array($hours, self::RETRY_WINDOW_HOURS_ALLOWED, true)) {
            $hours = self::RETRY_WINDOW_HOURS_DEFAULT;
        }

        return $hours * 3600;
    }

    /** Unix time of the row's first submit attempt; now for a new row or an unreadable date. */
    private function firstAttemptAt(?PendingAddressCheck $row): int
    {
        $since = $row !== null && $row->submittedAt !== '' ? (int) strtotime($row->submittedAt) : 0;

        return $since > 0 ? $since : time();
    }

    /**
     * The failure a cron row is closed with when an earlier request of the same run
     * found the platform out of reach: the same status and answer, the row's own job
     * id, and a message that says it was not sent.
     */
    private function notAttempted(SaasRequestException $seen, ?PendingAddressCheck $row): SaasRequestException
    {
        // Plenty's validator refuses `new` everywhere except directly after `throw` ("clone or
        // new are not allowed"), and this path runs only during an outage, so the exception is
        // built by throwing it rather than through pluginApp()'s positional arguments.
        try {
            throw new SaasRequestException(
                'not sent, platform unreachable earlier in this cron run: ' . $seen->getMessage(),
                $seen->getHttpStatus(),
                $seen->getRetryAfterSeconds(),
                $seen->getErrorCode(),
                $seen->platformAnswered()
            );
        } catch (SaasRequestException $e) {
            return $e->withJobId($row !== null ? (string) $row->jobId : '');
        }
    }

    /**
     * The platform could not be reached, failed on its side or asked us to wait.
     * Park the order for another attempt; its status stays untouched, because
     * nothing is known about the address yet. Once the retry window is used up,
     * give up the way a refusal is handled: FAILED row, error status, and a note
     * that names the attempts and the last cause, with the way to re-run it.
     *
     * When only the confirm failed, the exception carries the created job's id
     * (1.9.0); it goes onto the row, so the next attempt confirms that job instead
     * of creating another.
     */
    private function deferSubmit(int $orderId, Address $address, ?PendingAddressCheck $row, SaasRequestException $e, array $item): void
    {
        $keepJobId      = $e->getJobId();
        $attempts       = ($row !== null ? (int) $row->attempts : 0) + 1;
        $firstAttemptAt = $this->firstAttemptAt($row);

        $windowSeconds = $this->retryWindowSeconds();

        if (time() - $firstAttemptAt >= $windowSeconds) {
            $this->getLogger(__METHOD__)->error('HeistaAddressCheck::log.submitGivenUp', [
                'orderId'        => $orderId,
                'attempts'       => $attempts,
                'firstAttemptAt' => date('Y-m-d H:i:s', $firstAttemptAt),
                'error'          => $e->getMessage(),
            ]);
            $this->closeRow($orderId, $address, $row, $e->getMessage(), !empty($item['b2bCheck']));
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
                'jobId'             => $keepJobId,
                'deliveryAddressId' => (int) $address->id,
                'status'            => PendingAddressCheck::STATUS_RETRY,
                'submittedAt'       => date('Y-m-d H:i:s'),
                'lastError'         => substr($e->getMessage(), 0, 1000),
                'attempts'          => 1,
                'nextAttemptAt'     => $nextAttemptAt,
                // The item is what was actually sent, so the retry sends the same thing.
                'b2bCheck'  => !empty($item['b2bCheck']),
            ]);
            // First deferral of this order (1.9.0): until now the order sat in its
            // status with nothing on it, and the merchant could not tell it was waiting.
            // Only here, so an outage writes one comment per order, not one per attempt.
            $this->apply->writeSubmitDeferredNote($orderId, date('d.m.Y H:i', $firstAttemptAt + $windowSeconds));
        } else {
            $this->pendingRepo->scheduleRetry($row, $attempts, $nextAttemptAt, $e->getMessage(), $keepJobId);
        }

        // report(), not warning(): a warning is invisible in the Plenty log until the
        // merchant activates it, and "why was this order not checked yet" is exactly
        // the question this line answers. `phase` says which call failed: a failed
        // confirm leaves a job at the platform, a failed create does not.
        $this->report(__METHOD__, 'HeistaAddressCheck::log.submitDeferred', [
            'orderId'       => $orderId,
            'attempt'       => $attempts,
            'phase'         => $keepJobId !== '' ? 'confirm' : 'create',
            'jobId'         => $keepJobId,
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
        $this->closeRow($orderId, $address, $row, $e->getMessage(), !empty($item['b2bCheck']));
        $this->apply->writeSubmitFailure($orderId, $item, 'Die Plattform hat die Prüfung abgelehnt: ' . $this->refusalText($status, $code, $e));
    }

    /**
     * Mark the queue row FAILED, creating it when this was the first attempt. A created row
     * keeps the procedure's flag, so the duplicate guard still knows which one it was.
     */
    private function closeRow(int $orderId, Address $address, ?PendingAddressCheck $row, string $error, bool $b2bCheck = false): void
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
            'b2bCheck'  => $b2bCheck,
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
        if ($status >= 400 && !$e->platformAnswered()) {
            return 'die Plattform hat nicht geantwortet (HTTP ' . $status . ', Antwort kam nicht von der Plattform)';
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

        // The platform's auth layer answers an invalid key with a bare 401 and no
        // error code (seen live 2026-10-19), so the status has to carry the meaning.
        switch ($status) {
            case 401:
                return 'API-Schlüssel ungültig oder widerrufen (HTTP 401). Plugin-Konfiguration prüfen.';
            case 402:
                return 'Guthaben reicht nicht aus (HTTP 402). Im Heista-Portal aufladen.';
            case 403:
                return 'Zugriff verweigert (HTTP 403). Abo und Berechtigung des API-Schlüssels im Heista-Portal prüfen.';
        }

        if ($status > 0) {
            return 'HTTP ' . $status . ($code !== '' ? ' ' . $code : '') . '.';
        }

        return substr($e->getMessage(), 0, 200);
    }
}
