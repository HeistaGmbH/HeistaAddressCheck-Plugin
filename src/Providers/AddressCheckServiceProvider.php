<?php

namespace HeistaAddressCheck\Providers;

use HeistaAddressCheck\Crons\FallbackPollCron;
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
        $eventProceduresService->registerProcedure(
            'submitAddressCheck',
            ProcedureEntry::EVENT_TYPE_ORDER,
            [
                'de' => 'Adresse via Heista SaaS prüfen',
                'en' => 'Validate address via Heista SaaS',
            ],
            SubmitAddressCheckProcedure::class . '@run'
        );

        $cronContainer->add(CronContainer::EVERY_FIVE_MINUTES, FallbackPollCron::class);
    }
}
