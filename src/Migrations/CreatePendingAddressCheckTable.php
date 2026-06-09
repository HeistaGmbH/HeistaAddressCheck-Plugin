<?php

namespace HeistaAddressCheck\Migrations;

use Plenty\Modules\Plugin\DataBase\Contracts\Migrate;
use HeistaAddressCheck\Models\PendingAddressCheck;

class CreatePendingAddressCheckTable
{
    public function run(Migrate $migrate): void
    {
        $migrate->createTable(PendingAddressCheck::class);
    }
}
