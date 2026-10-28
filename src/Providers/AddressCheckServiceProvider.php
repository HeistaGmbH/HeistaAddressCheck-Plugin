<?php

namespace HeistaAddressCheck\Providers;

use HeistaAddressCheck\Crons\FallbackPollCron;
use HeistaAddressCheck\Procedures\SubmitAddressCheckB2bProcedure;
use HeistaAddressCheck\Procedures\SubmitAddressCheckProcedure;
use Plenty\Modules\Cron\Services\CronContainer;
use Plenty\Modules\EventProcedures\Services\Entries\ProcedureEntry;
use Plenty\Modules\EventProcedures\Services\EventProceduresService;
use Plenty\Plugin\ServiceProvider;

class AddressCheckServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->getApplication()->register(AddressCheckRouteServiceProvider::class);
    }

    public function boot(
        EventProceduresService $eventProceduresService,
        CronContainer $cronContainer
    ): void {
        // 1st arg is the "module name". Plenty renders it ucfirst'd as the group header
        // above the procedure in the Flow action picker, so the merchant sees the group
        // "SubmitAddressCheck" with the real label nested inside. Ugly, and deliberately
        // left alone: it is unverified whether Plenty also persists this string in an
        // already-configured event action's reference to the procedure. If it does,
        // changing it orphans every merchant's configured action on the next plugin-set
        // build, with no error and no symptom beyond addresses quietly going unchecked.
        // A nicer group header does not buy that risk. Change it only after proving on a
        // test plugin set that an existing configured action survives the rename.
        $eventProceduresService->registerProcedure(
            'submitAddressCheck',
            ProcedureEntry::EVENT_TYPE_ORDER,
            [
                'de' => 'Adresse via Heista SaaS prüfen',
                'en' => 'Validate address via Heista SaaS',
            ],
            SubmitAddressCheckProcedure::class . '@run'
        );

        // 1.9.0. Same rule for the module name as above: it is never renamed once shipped.
        // The merchant binds this procedure to the orders that need the business customer
        // check through the filters of a flow (or a classic event action); everything else
        // keeps using submitAddressCheck. The duplicate guard in AddressCheckSubmitService
        // handles an order that matches both. The label starts like the plain one's on
        // purpose, so the two read as siblings in the action picker.
        $eventProceduresService->registerProcedure(
            'submitAddressCheckB2b',
            ProcedureEntry::EVENT_TYPE_ORDER,
            [
                'de' => 'Adresse via Heista SaaS prüfen, mit Geschäftskundenprüfung',
                'en' => 'Validate address via Heista SaaS, with business customer check',
            ],
            SubmitAddressCheckB2bProcedure::class . '@run'
        );

        $cronContainer->add(CronContainer::EVERY_FIVE_MINUTES, FallbackPollCron::class);
    }
}
