<?php

namespace HeistaAddressCheck\Models;

use Plenty\Modules\Plugin\DataBase\Contracts\Model;

/**
 * @property int    $id
 * @property int    $orderId
 * @property string $jobId
 * @property int    $deliveryAddressId
 * @property string $status
 * @property string $submittedAt
 * @property string $appliedAt
 * @property string $lastError
 */
class PendingAddressCheck extends Model
{
    const STATUS_PENDING = 'PENDING';
    const STATUS_APPLIED = 'APPLIED';
    const STATUS_FAILED  = 'FAILED';

    public $id                = 0;
    public $orderId           = 0;
    public $jobId             = '';
    public $deliveryAddressId = 0;
    public $status            = self::STATUS_PENDING;
    public $submittedAt       = '';
    public $appliedAt         = '';
    public $lastError         = '';

    public function getTableName(): string
    {
        return 'HeistaAddressCheck::PendingAddressCheck';
    }
}
