<?php

namespace HeistaAddressCheck\Models;

use Plenty\Modules\Plugin\DataBase\Contracts\Model;

/**
 * @property int    $id
 * @property int    $orderId
 * @property string $jobId
 * @property int    $deliveryAddressId
 * @property string $status
 * @property string $submittedAt   first submit attempt; the retry window counts from here
 * @property string $appliedAt
 * @property string $lastError
 * @property int    $attempts      submit attempts made so far (1.8.0)
 * @property string $nextAttemptAt when a RETRY row is due again, '' otherwise (1.8.0)
 * @property bool   $b2bCheck      submitted through the business customer procedure (1.9.0).
 *                  A retry sends the flag again, and the duplicate guard reads it.
 */
class PendingAddressCheck extends Model
{
    const STATUS_PENDING = 'PENDING';
    const STATUS_APPLIED = 'APPLIED';
    const STATUS_FAILED  = 'FAILED';
    /**
     * The submit did not reach the platform (or it asked us to wait) and the
     * cron will try again at nextAttemptAt. Usually no jobId; since 1.9.0 a row
     * whose create went through and whose confirm failed keeps the job's id, and
     * the next attempt confirms that job. Terminal states are PENDING
     * (submitted), FAILED (given up or refused).
     */
    const STATUS_RETRY   = 'RETRY';

    /**
     * How close two submits of one order must be to count as the same event firing both
     * actions (1.9.0). Read by the duplicate guard at submit and by apply() for the
     * parallel case the guard cannot see.
     */
    const DUPLICATE_WINDOW_SECONDS = 600;

    public $id                = 0;
    public $orderId           = 0;
    public $jobId             = '';
    public $deliveryAddressId = 0;
    public $status            = self::STATUS_PENDING;
    public $submittedAt       = '';
    public $appliedAt         = '';
    public $lastError         = '';
    public $attempts          = 0;
    public $nextAttemptAt     = '';
    public $b2bCheck  = false;

    public function getTableName(): string
    {
        return 'HeistaAddressCheck::PendingAddressCheck';
    }
}
