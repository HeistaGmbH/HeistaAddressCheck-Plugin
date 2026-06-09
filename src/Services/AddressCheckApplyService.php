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
 * Shared apply path for both the webhook controller and the fallback cron.
 *
 * Idempotent: if the pending row is already APPLIED or FAILED, apply() does
 * nothing, so whichever of the two paths arrives second just exits.
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
     * @param string $jobId  Job id (UUID).
     * @param array  $body   Job-completion body. Either the GET /api/v1/jobs/{id}
     *                       response (cron path) or the webhook body (controller
     *                       path); both share { jobId, status, externalRef, items }.
     */
    public function apply(string $jobId, array $body): void
    {
        $row = $this->pendingRepo->findByJobId($jobId);
        if ($row === null) {
            $this->getLogger(__METHOD__)->info('HeistaAddressCheck::log.applyUnknownJob', ['jobId' => $jobId]);
            return;
        }

        if ($row->status !== PendingAddressCheck::STATUS_PENDING) {
            // Already settled. Webhook and cron racing here is expected; just exit.
            return;
        }

        $jobStatus = (string) ($body['status'] ?? '');
        $item      = $body['items'][0] ?? null;
        $orderId   = (int) $row->orderId;

        // Job-level failure: leave the order status alone (a backend bug
        // shouldn't reroute the merchant's order) and just mark the row failed.
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

        // Resolve the configured target status for this outcome up front,
        // whether or not we apply the address. Empty/non-numeric is skipped.
        $targetStatusId = $this->resolveTargetStatusId($outputStatus);

        // Apply the correction whenever the result is valid and a structured
        // corrected payload came back. Covers both 'corrected' and
        // 'manual_review' (cleaned but flagged for a human). We still set the
        // configured status and keep the original address as an internal
        // comment so the merchant can revert a bad correction.
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

                    // Comment is supplementary: if it throws, don't roll back
                    // the address apply. Logged at error level so it shows up
                    // without enabling debug logging.
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

                    // Status update in the same processUnguarded to avoid
                    // re-auth. Address is already saved, so a bad statusId
                    // shouldn't roll it back.
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

        // No usable correction (invalid, or no corrected payload). Leave the
        // address as-is but still set the configured status so the merchant
        // can route it to a review queue. Row is marked FAILED to reflect that
        // the address wasn't applied.
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
     * Save the original delivery address as an internal order comment (hidden
     * from the customer) so the merchant can see what was overwritten and
     * revert a bad correction. Caller wraps this in processUnguarded.
     *
     * Matches the /rest/comments contract: referenceValue as int, userId a
     * positive integer (0 or missing both fail validation), text as HTML.
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
     * Configured author user id for plugin-created comments. Returns null when
     * unset or non-positive; the caller then skips the comment instead of
     * sending userId=0, which the comment endpoint rejects.
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
     * Build the HTML comment body. Plenty stores comments as HTML and validates
     * them that way, so we emit HTML. Empty fields are skipped. Labels are
     * German on purpose (comments aren't translated and the audience is German
     * merchants). Values are escaped in case a customer entered angle brackets.
     */
    private function formatOriginalAddressComment(array $input, string $outputStatus, string $jobId): string
    {
        // postnumber is intentionally not in this list: Plenty keeps a post
        // number on every address, but it only matters for Packstation/
        // Postfiliale. It's rendered below, gated on those flags.
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
     * Configured target order status for a given outcome. Returns null when the
     * field is empty, missing, or not a positive number; that means "leave the
     * status alone" rather than failing.
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
        // anything else with a warning so a typo'd config doesn't quietly
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
        // The backend order view reads the legacy numbered columns (name1..4,
        // address1..4), not the named ones (companyName, firstName, ...), so
        // write the numbered set. Mirrors the working PUT to
        // /rest/orders/{id}/addresses/{addrId}.
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
