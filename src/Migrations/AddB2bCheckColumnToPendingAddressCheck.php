<?php

namespace HeistaAddressCheck\Migrations;

use Plenty\Modules\Plugin\DataBase\Contracts\Migrate;
use HeistaAddressCheck\Models\PendingAddressCheck;

/**
 * 1.9.0: `b2bCheck`, set when the order came through the business customer
 * procedure. Without it a queued order would lose the check on its retry, and
 * the duplicate guard could not tell the two procedures apart. updateTable adds
 * the column and leaves existing rows alone; rows from before read as false,
 * which is what they were.
 */
class AddB2bCheckColumnToPendingAddressCheck
{
    public function run(Migrate $migrate): void
    {
        $migrate->updateTable(PendingAddressCheck::class);
    }
}
