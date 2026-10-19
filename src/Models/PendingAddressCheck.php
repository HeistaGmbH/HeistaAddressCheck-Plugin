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
 */
class PendingAddressCheck extends Model
{
    const STATUS_PENDING = 'PENDING';
    const STATUS_APPLIED = 'APPLIED';
    const STATUS_FAILED  = 'FAILED';
    /**
     * The submit did not reach the platform (or it asked us to wait) and the
     * cron will try again at nextAttemptAt. No jobId yet. Terminal states are
     * PENDING (submitted), FAILED (given up or refused).
     */
    const STATUS_RETRY   = 'RETRY';

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

    public function getTableName(): string
    {
        return 'HeistaAddressCheck::PendingAddressCheck';
    }
}
