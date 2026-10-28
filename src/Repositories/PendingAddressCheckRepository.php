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
        $row->b2bCheck  = !empty($attributes['b2bCheck']);
        return $this->db->save($row);
    }

    /**
     * The newest row of an order whose first submit lies inside the last
     * $withinSeconds, whatever its status, or null. Read by the duplicate guard
     * (1.9.0), which has to know whether the other procedure already submitted
     * this order a moment ago. `submittedAt` is the same `Y-m-d H:i:s` string
     * every write uses, so the bound is computed the way findRetryDue computes
     * `now`. Newest is picked here rather than in the query: an order has a
     * handful of rows at most, and this needs no ordering support from the
     * query builder.
     */
    public function findRecentByOrderId(int $orderId, int $withinSeconds): ?PendingAddressCheck
    {
        $since = date('Y-m-d H:i:s', time() - $withinSeconds);

        $rows = $this->db->query(PendingAddressCheck::class)
            ->where('orderId', '=', $orderId)
            ->where('submittedAt', '>=', $since)
            ->get();

        $newest = null;
        foreach ($rows as $row) {
            if ($newest === null) {
                $newest = $row;
                continue;
            }
            $cmp = strcmp((string) $row->submittedAt, (string) $newest->submittedAt);
            if ($cmp > 0 || ($cmp === 0 && (int) $row->id > (int) $newest->id)) {
                $newest = $row;
            }
        }

        return $newest;
    }

    /**
     * A queued plain submit is upgraded when the business customer procedure fires for
     * the same order: the one job that results carries the flag, and every later
     * retry of the row sends it again.
     */
    public function markB2bRequested(PendingAddressCheck $row): PendingAddressCheck
    {
        $row->b2bCheck = true;
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
