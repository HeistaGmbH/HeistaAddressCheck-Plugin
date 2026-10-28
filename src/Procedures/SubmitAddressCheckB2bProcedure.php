<?php

namespace HeistaAddressCheck\Procedures;

use HeistaAddressCheck\Services\AddressCheckSubmitService;
use Plenty\Modules\EventProcedures\Events\EventProceduresTriggered;
use Plenty\Plugin\Log\Loggable;
use Throwable;

/**
 * The address check plus the business customer check (1.9.0). Same submit as
 * SubmitAddressCheckProcedure, with the item flag `b2bCheck` set. Which
 * orders run through this one instead of the plain procedure is decided by the
 * merchant's own Flow conditions (payment method, customer class, tag), so a
 * shop with private and business orders gets its B2B gate without any rule
 * code here. Whether the platform honours the flag is the tenant's setting in
 * the Heista dashboard.
 */
class SubmitAddressCheckB2bProcedure
{
    use Loggable;

    private AddressCheckSubmitService $submitService;

    public function __construct(AddressCheckSubmitService $submitService)
    {
        $this->submitService = $submitService;
    }

    public function run(EventProceduresTriggered $event): void
    {
        try {
            $order = $event->getOrder();
            $this->submitService->submitForOrder($order, ['b2bCheck' => true]);
        } catch (Throwable $e) {
            $this->getLogger(__METHOD__)->error('HeistaAddressCheck::log.procedureFailed', [
                'orderId' => isset($order) ? (int) $order->id : 0,
                'error'   => $e->getMessage(),
            ]);
        }
    }
}
