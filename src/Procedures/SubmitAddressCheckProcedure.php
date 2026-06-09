<?php

namespace HeistaAddressCheck\Procedures;

use HeistaAddressCheck\Services\AddressCheckSubmitService;
use Plenty\Modules\EventProcedures\Events\EventProceduresTriggered;
use Plenty\Plugin\Log\Loggable;
use Throwable;

class SubmitAddressCheckProcedure
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
            $this->submitService->submitForOrder($order);
        } catch (Throwable $e) {
            $this->getLogger(__METHOD__)->error('HeistaAddressCheck::log.procedureFailed', [
                'orderId' => isset($order) ? (int) $order->id : 0,
                'error'   => $e->getMessage(),
            ]);
        }
    }
}
