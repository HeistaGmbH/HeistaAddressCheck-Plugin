<?php

namespace HeistaAddressCheck\Migrations;

use Plenty\Modules\Plugin\DataBase\Contracts\Migrate;
use HeistaAddressCheck\Models\PendingAddressCheck;

/**
 * 1.8.0: `attempts` and `nextAttemptAt` for the submit retry queue. updateTable
 * adds the columns the model declares and leaves existing rows and columns as
 * they are; rows from before carry attempts=0 and an empty nextAttemptAt, which
 * the queue never selects.
 */
class AddRetryColumnsToPendingAddressCheck
{
    public function run(Migrate $migrate): void
    {
        $migrate->updateTable(PendingAddressCheck::class);
    }
}
