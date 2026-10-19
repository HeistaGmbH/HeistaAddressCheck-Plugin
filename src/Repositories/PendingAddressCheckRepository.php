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
        $row->attempts          = (int)    ($attributes['attempts']          ?? 0);
        $row->nextAttemptAt     = (string) ($attributes['nextAttemptAt']     ?? '');
        return $this->db->save($row);
    }

    /**
     * The RETRY row of an order, if it has one. A merchant who re-runs the
     * event action on a queued order gets that row attempted now, never a
     * second row that would submit the same address twice later.
     */
    public function findRetryByOrderId(int $orderId): ?PendingAddressCheck
    {
        $rows = $this->db->query(PendingAddressCheck::class)
            ->where('orderId', '=', $orderId)
            ->where('status', '=', PendingAddressCheck::STATUS_RETRY)
            ->get();

        return $rows[0] ?? null;
    }

    /** RETRY rows whose nextAttemptAt has passed, oldest first by insertion. */
    public function findRetryDue(int $limit): array
    {
        $now = date('Y-m-d H:i:s');

        return $this->db->query(PendingAddressCheck::class)
            ->where('status', '=', PendingAddressCheck::STATUS_RETRY)
            ->where('nextAttemptAt', '<=', $now)
            ->limit($limit)
            ->get();
    }

    /** Park the row for another attempt at nextAttemptAt. submittedAt keeps the first attempt. */
    public function scheduleRetry(PendingAddressCheck $row, int $attempts, string $nextAttemptAt, string $error): PendingAddressCheck
    {
        $row->status        = PendingAddressCheck::STATUS_RETRY;
        $row->jobId         = '';
        $row->attempts      = $attempts;
        $row->nextAttemptAt = $nextAttemptAt;
        $row->lastError     = substr($error, 0, 1000);
        return $this->db->save($row);
    }

    /** A retried submit went through: the row now waits for its result like any other. */
    public function markPending(PendingAddressCheck $row, string $jobId, int $attempts): PendingAddressCheck
    {
        $row->status        = PendingAddressCheck::STATUS_PENDING;
        $row->jobId         = $jobId;
        $row->attempts      = $attempts;
        $row->nextAttemptAt = '';
        $row->lastError     = '';
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
