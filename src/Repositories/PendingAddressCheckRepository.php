<?php

namespace HeistaAddressCheck\Repositories;

use Plenty\Modules\Plugin\DataBase\Contracts\DataBase;
use HeistaAddressCheck\Models\PendingAddressCheck;

class PendingAddressCheckRepository
{
    private DataBase $db;

    public function __construct(DataBase $db)
    {
        $this->db = $db;
    }

    public function create(array $attributes): PendingAddressCheck
    {
        $row = pluginApp(PendingAddressCheck::class);
        $row->orderId           = (int)    ($attributes['orderId']           ?? 0);
        $row->jobId             = (string) ($attributes['jobId']             ?? '');
        $row->deliveryAddressId = (int)    ($attributes['deliveryAddressId'] ?? 0);
        $row->status            = (string) ($attributes['status']            ?? PendingAddressCheck::STATUS_PENDING);
        $row->submittedAt       = (string) ($attributes['submittedAt']       ?? '');
        $row->appliedAt         = (string) ($attributes['appliedAt']         ?? '');
        $row->lastError         = (string) ($attributes['lastError']         ?? '');
        return $this->db->save($row);
    }

    public function findByJobId(string $jobId): ?PendingAddressCheck
    {
        $rows = $this->db->query(PendingAddressCheck::class)
            ->where('jobId', '=', $jobId)
            ->get();

        return $rows[0] ?? null;
    }

    public function findStalePending(int $graceSeconds, int $limit): array
    {
        $cutoff = date('Y-m-d H:i:s', time() - $graceSeconds);

        return $this->db->query(PendingAddressCheck::class)
            ->where('status', '=', PendingAddressCheck::STATUS_PENDING)
            ->where('submittedAt', '<', $cutoff)
            ->limit($limit)
            ->get();
    }

    public function markApplied(PendingAddressCheck $row): PendingAddressCheck
    {
        $row->status    = PendingAddressCheck::STATUS_APPLIED;
        $row->appliedAt = date('Y-m-d H:i:s');
        $row->lastError = '';
        return $this->db->save($row);
    }

    public function markFailed(PendingAddressCheck $row, string $error): PendingAddressCheck
    {
        $row->status    = PendingAddressCheck::STATUS_FAILED;
        $row->lastError = substr($error, 0, 1000);
        return $this->db->save($row);
    }
}
