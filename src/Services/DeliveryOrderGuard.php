<?php

namespace HeistaAddressCheck\Services;

use Plenty\Modules\Authorization\Services\AuthHelper;
use Plenty\Modules\Order\Contracts\OrderRepositoryContract;
use Plenty\Modules\Order\Models\Order;
use Plenty\Modules\Order\Models\OrderType;
use Plenty\Plugin\ConfigRepository;
use Plenty\Plugin\Log\Loggable;
use Throwable;

/**
 * Keeps the check off a Hauptauftrag that already has Lieferaufträge.
 *
 * Why this exists: the plugin writes address, comment and status to exactly the order the
 * event procedure handed it, and knows nothing about the order's relatives. A merchant who
 * runs the check on the sales order *and* on its delivery order therefore gets two checks
 * (two jobs, billed twice) and, seconds later, a status write onto the sales order that
 * overwrites whatever "(LA) Niedrigster Status aller Lieferaufträge" had just derived there.
 * Confirmed live on 2026-09-18 against a merchant system: one shipment, two jobs 430 ms apart,
 * results landing 5 s apart, each job identified on its own order by the Job-ID in the note the
 * plugin writes. The sales order's result landed second and reset the status the delivery
 * order's run had already driven forward.
 *
 * The delivery orders on that sales order were **two weeks old** at the time of the check, which
 * is what makes the submit-time half worth having: this is not a race, the relation is long
 * settled by the time anything submits.
 *
 * A Flow filter cannot express this. At the moment a sales order is created it usually has no
 * delivery orders yet, so the merchant's event procedure has nothing to filter on. The plugin
 * gets a second look when the result comes back, which is why the guard has two halves:
 *
 * - {@see skipSubmit()} blocks the check entirely when the delivery orders already exist at
 *   submit time. That saves the job and the money.
 * - {@see skipStatusWrite()} blocks only the *status* write at apply time, for the case where
 *   the delivery order appeared while the check was running. Address and comment still land,
 *   because correcting the address is never the harmful part. With the cron fallback that
 *   window is up to five minutes.
 *
 * **Opt-in per plugin set** (`skipSalesOrdersWithDeliveryOrders`, default off). Enabling it by
 * default would silently stop checking orders on installs whose process relies on the sales
 * order being checked, and a silently unchecked address is worse than an overwritten status.
 *
 * **Fails open.** Every failure path returns false, i.e. behave exactly as before the guard
 * existed. Failing closed would stop address checks on unknown ground.
 */
class DeliveryOrderGuard
{
    use Loggable;

    const CONFIG_KEY = 'HeistaAddressCheck.skipSalesOrdersWithDeliveryOrders';

    private OrderRepositoryContract $orderRepo;
    private AuthHelper $authHelper;
    private ConfigRepository $config;

    public function __construct(
        OrderRepositoryContract $orderRepo,
        AuthHelper $authHelper,
        ConfigRepository $config
    ) {
        $this->orderRepo  = $orderRepo;
        $this->authHelper = $authHelper;
        $this->config     = $config;
    }

    /**
     * True when this plugin set opted into the guard. Plenty returns a checkBox as the
     * STRING "true"/"false", and "false" is truthy, so a (bool) cast reads every unchecked
     * box as enabled.
     */
    public function isEnabled(): bool
    {
        return filter_var($this->config->get(self::CONFIG_KEY), FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * Submit-time half: don't hand this order to the platform at all.
     *
     * @param Order $order The order from the event procedure. Used for its typeId and as a
     *                     second source for the delivery-order flag; the authoritative read
     *                     is a fresh fetch, because the event's order is a snapshot taken
     *                     before any delivery order in the same Flow run existed.
     */
    public function skipSubmit(Order $order): bool
    {
        if (!$this->isEnabled()) {
            return false;
        }

        return $this->salesOrderHasDeliveryOrders((int) ($order->id ?? 0), $order);
    }

    /**
     * Apply-time half: write the address and the comment, but not the status.
     *
     * Called from AddressCheckApplyService::updateOrderStatus(), i.e. already inside a
     * processUnguarded closure. The nested processUnguarded in the fetch below is
     * deliberate: this class must work from both paths and cannot assume its caller.
     */
    public function skipStatusWrite(int $orderId): bool
    {
        if (!$this->isEnabled()) {
            return false;
        }

        return $this->salesOrderHasDeliveryOrders($orderId, null);
    }

    /**
     * True only for a SALES order (typeId 1) that has delivery orders (typeId 2).
     *
     * A Lieferauftrag is never guarded — it is the order the merchant actually wants checked.
     * Neither is a Retoure, Gutschrift or any other type; those are left exactly as before.
     *
     * ⚠️ `OrderType::TYPE_DELIVERY_ORDER` is **2**, not 7. The constants do not match the
     * numbering the backend UI shows.
     */
    private function salesOrderHasDeliveryOrders(int $orderId, ?Order $known): bool
    {
        if ($orderId <= 0) {
            return false;
        }

        $fresh  = $this->fetchOrder($orderId);
        $typeId = (int) ($fresh->typeId ?? ($known->typeId ?? 0));

        if ($typeId !== OrderType::TYPE_SALES_ORDER) {
            return false;
        }

        [$has, $source] = $this->detect($fresh, $known);

        if ($has === null) {
            // The guard is enabled and cannot answer, so it silently does nothing. That is
            // the failure mode this log line exists to make loud: a guard that never fires
            // and a guard that fires correctly look identical from the outside. Plenty does
            // not surface `warning` in the log UI, hence `error`.
            $this->getLogger(__METHOD__)->error('HeistaAddressCheck::log.deliveryOrderLookupInconclusive', [
                'orderId' => $orderId,
                'fetched' => $fresh !== null,
            ]);
            return false;
        }

        $this->getLogger(__METHOD__)->debug('HeistaAddressCheck::log.deliveryOrderLookupResult', [
            'orderId'           => $orderId,
            'hasDeliveryOrders' => $has ? 'true' : 'false',
            'source'            => $source,
        ]);

        return $has;
    }

    /**
     * Re-read the order. Deliberately without a `with` list: an unknown relation key throws,
     * and `hasDeliveryOrders` is a column on the order itself. Returns null on any failure,
     * which the caller treats as "cannot answer".
     */
    private function fetchOrder(int $orderId): ?Order
    {
        $order = null;

        try {
            $this->authHelper->processUnguarded(function () use ($orderId, &$order): void {
                $order = $this->orderRepo->findOrderById($orderId);
            });
        } catch (Throwable $e) {
            $this->getLogger(__METHOD__)->warning('HeistaAddressCheck::log.deliveryOrderLookupFailed', [
                'orderId' => $orderId,
                'error'   => $e->getMessage(),
            ]);
            return null;
        }

        return $order instanceof Order ? $order : null;
    }

    /**
     * Ask each source in turn and stop at the first conclusive answer.
     *
     * ⚠️ Which source actually answers on a live system is **unverified**. `hasDeliveryOrders`
     * is declared on `Plenty\Modules\Order\Models\Order`, but whether it is populated on a
     * repository fetch, on the event procedure's order, or on neither, has not been observed.
     * The `source` in the debug log above is there to settle it on the first day of rollout.
     * Once it is settled, delete the sources that never answer rather than leaving three
     * code paths where one is real.
     *
     * @return array{0: ?bool, 1: string} [answer or null, which source answered]
     */
    private function detect(?Order $fresh, ?Order $known): array
    {
        $flag = $this->readFlag($fresh);
        if ($flag !== null) {
            return [$flag, 'hasDeliveryOrders:fetched'];
        }

        $flag = $this->readFlag($known);
        if ($flag !== null) {
            return [$flag, 'hasDeliveryOrders:event'];
        }

        $children = $this->hasDeliveryChild($fresh);
        if ($children !== null) {
            return [$children, 'childOrders'];
        }

        return [null, 'none'];
    }

    /**
     * `hasDeliveryOrders` as a tri-state. Plenty is inconsistent about how booleans arrive
     * from the model layer, so accept bool, int and numeric string, and treat anything else
     * (including null and an absent property) as "no answer" rather than as false.
     */
    private function readFlag(?Order $order): ?bool
    {
        if ($order === null) {
            return null;
        }

        $raw = $order->hasDeliveryOrders ?? null;

        if (is_bool($raw)) {
            return $raw;
        }

        if (is_int($raw) || (is_string($raw) && is_numeric($raw))) {
            return ((int) $raw) === 1;
        }

        return null;
    }

    /**
     * Fallback: any child order of type delivery.
     *
     * An unset property means "not loaded" and gets no answer. A present collection, empty
     * or not, is read as loaded and does answer. That second reading can be wrong if Plenty
     * initialises the relation to an empty collection without loading it, in which case the
     * guard reads "no delivery orders" and behaves exactly as it did before the guard
     * existed. That is the direction the error has to point.
     */
    private function hasDeliveryChild(?Order $order): ?bool
    {
        if ($order === null) {
            return null;
        }

        $children = $order->childOrders ?? null;
        if (!is_array($children) && !is_object($children)) {
            return null;
        }

        foreach ($children as $child) {
            if (is_object($child)) {
                $typeId = $child->typeId ?? null;
            } elseif (is_array($child)) {
                $typeId = $child['typeId'] ?? null;
            } else {
                continue;
            }

            if ((int) $typeId === OrderType::TYPE_DELIVERY_ORDER) {
                return true;
            }
        }

        return false;
    }
}
