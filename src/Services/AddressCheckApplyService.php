<?php

namespace HeistaAddressCheck\Services;

use HeistaAddressCheck\Models\PendingAddressCheck;
use HeistaAddressCheck\Repositories\PendingAddressCheckRepository;
use Plenty\Modules\Account\Address\Models\AddressRelationType;
use Plenty\Modules\Authorization\Services\AuthHelper;
use Plenty\Exceptions\ValidationException;
use Plenty\Modules\Comment\Contracts\CommentRepositoryContract;
use Plenty\Modules\Comment\Models\Comment;
use Plenty\Modules\Order\Address\Contracts\OrderAddressRepositoryContract;
use Plenty\Modules\Order\Contracts\OrderRepositoryContract;
use Plenty\Modules\Order\Shipping\Countries\Contracts\CountryRepositoryContract;
use Plenty\Plugin\ConfigRepository;
use Plenty\Plugin\Log\Loggable;
use Plenty\Plugin\Log\Reportable;
use Throwable;

/**
 * Single seam used by both the webhook controller and the fallback cron.
 *
 * Idempotency: if the row is already APPLIED or FAILED, apply() is a no-op.
 * Whichever path arrives second silently exits without re-writing the address.
 */
class AddressCheckApplyService
{
    use Loggable;
    use Reportable;

    private PendingAddressCheckRepository $pendingRepo;
    private OrderAddressRepositoryContract $orderAddressRepo;
    private OrderRepositoryContract $orderRepo;
    private CountryRepositoryContract $countryRepo;
    private CommentRepositoryContract $commentRepo;
    private AuthHelper $authHelper;
    private ConfigRepository $config;

    public function __construct(
        PendingAddressCheckRepository $pendingRepo,
        OrderAddressRepositoryContract $orderAddressRepo,
        OrderRepositoryContract $orderRepo,
        CountryRepositoryContract $countryRepo,
        CommentRepositoryContract $commentRepo,
        AuthHelper $authHelper,
        ConfigRepository $config
    ) {
        $this->pendingRepo      = $pendingRepo;
        $this->orderAddressRepo = $orderAddressRepo;
        $this->orderRepo        = $orderRepo;
        $this->countryRepo      = $countryRepo;
        $this->commentRepo      = $commentRepo;
        $this->authHelper       = $authHelper;
        $this->config           = $config;
    }

    /**
     * @param string $jobId  SaaS job id (UUID).
     * @param array  $body   The platform's job-completion body. Either the V1 GET
     *                       /api/v1/jobs/{id} response (cron path) or the inbound
     *                       webhook body (controller path); both share shape:
     *                       { jobId, status, externalRef, items: [...] }.
     */
    public function apply(string $jobId, array $body): void
    {
        $row = $this->pendingRepo->findByJobId($jobId);
        if ($row === null) {
            $this->getLogger(__METHOD__)->info('HeistaAddressCheck::log.applyUnknownJob', ['jobId' => $jobId]);
            return;
        }

        if ($row->status !== PendingAddressCheck::STATUS_PENDING) {
            // Already settled — webhook + cron racing is normal, this is the no-op leg.
            return;
        }

        $jobStatus = (string) ($body['status'] ?? '');
        $item      = $body['items'][0] ?? null;
        $orderId   = (int) $row->orderId;

        // Job-level failures: don't touch order status (a SaaS-side bug shouldn't
        // silently route the merchant's order somewhere). Just record the
        // pending row as failed so the operator can see what happened.
        if ($jobStatus !== 'COMPLETED') {
            $itemError = is_array($item) ? (string) ($item['error'] ?? '') : '';
            $reason    = 'Job ended with status ' . $jobStatus;
            if ($itemError !== '') {
                $reason .= ' (' . $itemError . ')';
            }
            $this->pendingRepo->markFailed($row, $reason);
            return;
        }

        $output = is_array($item) ? ($item['output'] ?? null) : null;
        if (!is_array($output)) {
            $this->pendingRepo->markFailed($row, 'Missing output payload');
            return;
        }

        $outputStatus = (string) ($output['status'] ?? '');
        $isValid      = !empty($output['isValid']);
        $corrected    = $output['corrected'] ?? null;
        $input        = is_array($item) ? ($item['input'] ?? null) : null;

        // Resolve the merchant's configured target order-status for this
        // outcome up-front, regardless of whether we end up applying the
        // address. Empty / non-numeric values are skipped silently.
        $targetStatusId = $this->resolveTargetStatusId($outputStatus);

        // Apply the corrected address whenever Heista flagged the result as
        // valid AND returned a structured corrected payload. This covers both
        // 'corrected' (clean fix) and 'manual_review' (LLM cleaned the address
        // but Google flagged it for human review). The configured review
        // status still routes the order into the merchant's triage queue, and
        // the original address is preserved as an internal order comment so
        // the operator can revert if Heista's correction is wrong.
        $shouldApplyAddress = $isValid && is_array($corrected);

        if ($shouldApplyAddress) {
            try {
                $this->authHelper->processUnguarded(function () use ($row, $corrected, $input, $orderId, $outputStatus, $jobId, $targetStatusId): void {
                    $update = $this->mapCorrectedToPlentyFields($corrected);
                    $this->orderAddressRepo->updateOrderAddress(
                        $update,
                        (int) $row->deliveryAddressId,
                        $orderId,
                        AddressRelationType::DELIVERY_ADDRESS
                    );

                    // Soft-fail: comment is supplementary, don't roll the
                    // address apply back if comment creation throws. Logged
                    // at error level so the failure is visible without
                    // having to activate plugin debug logging.
                    $authorUserId = $this->resolveCommentAuthorUserId();
                    if (!is_array($input)) {
                        $this->getLogger(__METHOD__)->error('HeistaAddressCheck::log.commentSkippedNoInput', [
                            'jobId'   => $jobId,
                            'orderId' => $orderId,
                        ]);
                    } elseif ($authorUserId === null) {
                        $this->getLogger(__METHOD__)->error('HeistaAddressCheck::log.commentSkippedNoUser', [
                            'jobId'   => $jobId,
                            'orderId' => $orderId,
                        ]);
                    } else {
                        try {
                            $commentId = $this->createOriginalAddressComment($orderId, $input, $outputStatus, $jobId, $authorUserId);
                            $this->report(__METHOD__, 'HeistaAddressCheck::log.commentCreated', [
                                'jobId'     => $jobId,
                                'orderId'   => $orderId,
                                'commentId' => $commentId,
                                'userId'    => $authorUserId,
                            ]);
                        } catch (Throwable $e) {
                            $validationDetails = '';
                            if ($e instanceof ValidationException) {
                                try {
                                    $bag = $e->getMessageBag();
                                    $validationDetails = is_object($bag)
                                        ? (string) json_encode($bag->toArray(), JSON_UNESCAPED_UNICODE)
                                        : '';
                                } catch (Throwable $inner) {
                                    $validationDetails = 'unreadable: ' . $inner->getMessage();
                                }
                            }
                            $this->getLogger(__METHOD__)->error('HeistaAddressCheck::log.commentCreateFailed', [
                                'jobId'             => $jobId,
                                'orderId'           => $orderId,
                                'userId'            => $authorUserId,
                                'error'             => $e->getMessage(),
                                'class'             => get_class($e),
                                'validationDetails' => $validationDetails,
                            ]);
                        }
                    }

                    // Status update inside the same processUnguarded so we don't
                    // re-auth. Soft-fail: address was already saved, so a bad
                    // statusId shouldn't roll the apply back.
                    if ($targetStatusId !== null) {
                        $this->updateOrderStatus($orderId, $targetStatusId);
                    }
                });
            } catch (Throwable $e) {
                $this->getLogger(__METHOD__)->error('HeistaAddressCheck::log.applyFailed', [
                    'jobId'   => $jobId,
                    'orderId' => $orderId,
                    'error'   => $e->getMessage(),
                ]);
                $this->pendingRepo->markFailed($row, 'updateOrderAddress: ' . $e->getMessage());
                return;
            }

            $this->pendingRepo->markApplied($row);

            $this->report(__METHOD__, 'HeistaAddressCheck::log.applied', [
                'jobId'           => $jobId,
                'orderId'         => $orderId,
                'outputStatus'    => $outputStatus,
                'googleStatus'    => (string) ($output['googleStatus']    ?? ''),
                'creditsConsumed' => (int)    ($output['creditsConsumed'] ?? 0),
                'targetStatusId'  => $targetStatusId,
            ]);
            return;
        }

        // No usable correction: either Heista marked the address invalid, or
        // no corrected payload was returned. Don't touch the address, but
        // still apply the configured status so the merchant can route the
        // order into a review queue. Pending row is marked FAILED to reflect
        // "address not applied".
        if ($targetStatusId !== null) {
            try {
                $this->authHelper->processUnguarded(function () use ($orderId, $targetStatusId): void {
                    $this->updateOrderStatus($orderId, $targetStatusId);
                });
            } catch (Throwable $e) {
                $this->getLogger(__METHOD__)->warning('HeistaAddressCheck::log.statusUpdateFailed', [
                    'jobId'          => $jobId,
                    'orderId'        => $orderId,
                    'targetStatusId' => $targetStatusId,
                    'outputStatus'   => $outputStatus,
                    'error'          => $e->getMessage(),
                ]);
            }
        }

        $this->pendingRepo->markFailed($row, 'Address output status: ' . $outputStatus . ' (isValid=' . ($isValid ? '1' : '0') . ')');
    }

    /**
     * Persist the originally submitted delivery address as an internal order
     * comment (not visible to the customer) so the merchant can see what was
     * overwritten and revert if Heista's correction is wrong. Caller wraps in
     * processUnguarded.
     *
     * Payload shape mirrors the REST POST /rest/comments contract:
     *   - `referenceValue` is sent as **int** (despite the model's untyped
     *     property), `userId` must be a positive integer (the merchant
     *     configures this — `0` and absence both trigger "validation error
     *     found"), `text` is **HTML** (Plenty wraps plain text in `<p>` tags
     *     elsewhere; we send the same shape).
     */
    private function createOriginalAddressComment(int $orderId, array $input, string $outputStatus, string $jobId, int $authorUserId): int
    {
        $created = $this->commentRepo->createComment([
            'referenceType'       => Comment::REFERENCE_TYPE_ORDER,
            'referenceValue'      => $orderId,
            'userId'              => $authorUserId,
            'text'                => $this->formatOriginalAddressComment($input, $outputStatus, $jobId),
            'isVisibleForContact' => false,
        ]);

        return (int) ($created->id ?? 0);
    }

    /**
     * Read the merchant-configured author user id for plugin-generated order
     * comments. Returns null when unconfigured or non-positive — the caller
     * skips comment creation rather than send an invalid payload (Plenty's
     * comment endpoint rejects userId=0 with "validation error found").
     */
    private function resolveCommentAuthorUserId(): ?int
    {
        $raw = trim((string) $this->config->get('HeistaAddressCheck.commentAuthorUserId'));
        if ($raw === '' || !is_numeric($raw)) {
            return null;
        }
        $id = (int) $raw;
        return $id > 0 ? $id : null;
    }

    /**
     * HTML comment body — Plenty stores the rich-text editor's output as
     * HTML and validates accordingly, so we wrap our snapshot the same way.
     * Empty fields are skipped so the comment stays readable. German labels —
     * order comments aren't translated by Plenty and the plugin's audience is
     * German merchants. Field values are HTML-escaped in case the merchant's
     * customer entered angle brackets.
     */
    private function formatOriginalAddressComment(array $input, string $outputStatus, string $jobId): string
    {
        // postnumber is intentionally omitted from this list — Plenty stores
        // a per-customer "post number" alongside every address but it only
        // makes sense for Packstation / Postfiliale deliveries. We render it
        // below, gated on the relevant flag.
        $labels = [
            'company'      => 'Firma',
            'firstName'    => 'Vorname',
            'lastName'     => 'Nachname',
            'nameAddition' => 'Namenszusatz',
            'street'       => 'Straße',
            'houseNumber'  => 'Hausnummer',
            'addressLine1' => 'Adresszusatz 1',
            'addressLine2' => 'Adresszusatz 2',
            'postalCode'   => 'PLZ',
            'city'         => 'Ort',
            'country'      => 'Land',
        ];

        $rows = [];
        foreach ($labels as $key => $label) {
            $value = trim((string) ($input[$key] ?? ''));
            if ($value === '') {
                continue;
            }
            $rows[] = '<strong>' . htmlspecialchars($label, ENT_QUOTES | ENT_HTML5, 'UTF-8') . ':</strong> '
                    . htmlspecialchars($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }

        $isPackstationOrPostfiliale = !empty($input['isPackstation']) || !empty($input['isPostfiliale']);
        if ($isPackstationOrPostfiliale) {
            if (!empty($input['isPackstation'])) {
                $rows[] = '<strong>Packstation:</strong> ja';
            }
            if (!empty($input['isPostfiliale'])) {
                $rows[] = '<strong>Postfiliale:</strong> ja';
            }
            $postnumber = trim((string) ($input['postnumber'] ?? ''));
            if ($postnumber !== '') {
                $rows[] = '<strong>Postnummer:</strong> ' . htmlspecialchars($postnumber, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            }
        }

        $body  = '<p><strong>Heista Adressprüfung – Originaladresse vor Korrektur</strong><br>';
        $body .= 'Status: ' . htmlspecialchars($outputStatus, ENT_QUOTES | ENT_HTML5, 'UTF-8') . '</p>';
        $body .= '<p>' . implode('<br>', $rows) . '</p>';
        $body .= '<p><em>Job-ID: ' . htmlspecialchars($jobId, ENT_QUOTES | ENT_HTML5, 'UTF-8') . '</em></p>';

        return $body;
    }

    /**
     * Look up the merchant's configured order-status target for the given
     * address-check outcome. Returns null when the field is empty, missing, or
     * not a positive number — empty / unparseable values mean "leave the
     * order's status alone" rather than "fail loudly".
     */
    private function resolveTargetStatusId(string $outputStatus): ?float
    {
        $configKey = '';
        switch ($outputStatus) {
            case 'corrected':     $configKey = 'HeistaAddressCheck.statusOnCorrected';    break;
            case 'manual_review': $configKey = 'HeistaAddressCheck.statusOnManualReview'; break;
            case 'invalid':       $configKey = 'HeistaAddressCheck.statusOnInvalid';      break;
            case 'not_found':     $configKey = 'HeistaAddressCheck.statusOnNotFound';     break;
            default:              return null;
        }

        $raw = trim((string) $this->config->get($configKey));
        if ($raw === '') {
            return null;
        }

        // Plenty status IDs are decimals like "5.1", "7", "8.4". Reject
        // anything else with a warning so a typo'd config doesn't silently
        // corrupt order routing.
        if (!is_numeric($raw)) {
            $this->getLogger(__METHOD__)->warning('HeistaAddressCheck::log.statusIdInvalid', [
                'configKey' => $configKey,
                'value'     => $raw,
            ]);
            return null;
        }

        $parsed = (float) $raw;
        if ($parsed <= 0) {
            return null;
        }

        return $parsed;
    }

    /**
     * Soft-fail order-status update. Caller wraps in processUnguarded.
     */
    private function updateOrderStatus(int $orderId, float $statusId): void
    {
        $this->orderRepo->updateOrder(['statusId' => $statusId], $orderId);
    }

    private function mapCorrectedToPlentyFields(array $corrected): array
    {
        // Plenty stores the displayed address in the LEGACY numbered columns
        // (name1..4, address1..4). The modern named columns (companyName,
        // firstName, street, houseNumber, additional) live alongside but the
        // backend order view reads the legacy ones, so we have to write those.
        // Mirrors the proven n8n PUT to /rest/orders/{id}/addresses/{addrId}.
        $update = [
            'name1'         => (string) ($corrected['company']      ?? ''),
            'name2'         => (string) ($corrected['firstName']    ?? ''),
            'name3'         => (string) ($corrected['lastName']     ?? ''),
            'name4'         => (string) ($corrected['nameAddition'] ?? ''),
            'address1'      => (string) ($corrected['street']       ?? ''),
            'address2'      => (string) ($corrected['houseNumber']  ?? ''),
            'address3'      => (string) ($corrected['addressLine1'] ?? ''),
            'address4'      => (string) ($corrected['addressLine2'] ?? ''),
            'postalCode'    => (string) ($corrected['postalCode']   ?? ''),
            'town'          => (string) ($corrected['city']         ?? ''),
            'isPackstation' => !empty($corrected['isPackstation']),
            'isPostfiliale' => !empty($corrected['isPostfiliale']),
        ];

        $iso = strtoupper(trim((string) ($corrected['country'] ?? '')));
        if ($iso !== '') {
            try {
                $country = $this->countryRepo->getCountryByIso($iso, 'isoCode2');
                if ($country !== null && !empty($country->id)) {
                    $update['countryId'] = (int) $country->id;
                }
            } catch (Throwable $e) {
                // Leave countryId untouched if lookup fails.
            }
        }

        return $update;
    }
}
