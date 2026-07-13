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

        // Job-level failure: the check never produced a verdict. The ADDRESS stays
        // untouched (a backend bug must not rewrite a customer's address), but the order
        // must not sit in the queue looking checked when it never was — note what happened
        // and route it to the error status, the same treatment a submit failure gets.
        if ($jobStatus !== 'COMPLETED') {
            $itemError = is_array($item) ? (string) ($item['error'] ?? '') : '';
            $reason    = 'Job ended with status ' . $jobStatus;
            if ($itemError !== '') {
                $reason .= ' (' . $itemError . ')';
            }

            $this->writeFailureToOrder($orderId, $jobId, is_array($item) ? ($item['input'] ?? null) : null, $reason);
            $this->pendingRepo->markFailed($row, $reason);
            return;
        }

        $output = is_array($item) ? ($item['output'] ?? null) : null;
        if (!is_array($output)) {
            $this->writeFailureToOrder($orderId, $jobId, is_array($item) ? ($item['input'] ?? null) : null, 'Missing output payload');
            $this->pendingRepo->markFailed($row, 'Missing output payload');
            return;
        }

        $outputStatus  = (string) ($output['status'] ?? '');
        $outputReason  = (string) ($output['reason'] ?? '');
        $isValid       = !empty($output['isValid']);
        $corrected     = $output['corrected'] ?? null;
        $input         = is_array($item) ? ($item['input'] ?? null) : null;
        $changedFields = is_array($output['changedFields'] ?? null) ? $output['changedFields'] : [];

        // DHL's own validation messages. Empty unless a DHL mode ran — and in dhl_only
        // they are the ONLY explanation for a rejection (no Google check runs on that
        // path), so the comment must surface them.
        $dhlMessages = is_array($output['dhlMessages'] ?? null) ? $output['dhlMessages'] : [];

        // Resolve the configured target status for this outcome up front,
        // whether or not we apply the address. Empty/non-numeric is skipped.
        $targetStatusId = $this->resolveTargetStatusId($outputStatus);

        // Apply the correction whenever the result is valid and a structured
        // corrected payload came back. Covers 'verified', 'corrected' and
        // 'review_suggested' (cleaned but Google flagged for a human). We still
        // set the configured status and keep the original address as an internal
        // comment so the merchant can revert a bad correction.
        $shouldApplyAddress = $isValid && is_array($corrected);

        if ($shouldApplyAddress) {
            try {
                $this->authHelper->processUnguarded(function () use ($row, $corrected, $input, $orderId, $outputStatus, $outputReason, $changedFields, $dhlMessages, $jobId, $targetStatusId): void {
                    $update = $this->mapCorrectedToPlentyFields($corrected);
                    $this->orderAddressRepo->updateOrderAddress(
                        $update,
                        (int) $row->deliveryAddressId,
                        $orderId,
                        AddressRelationType::DELIVERY_ADDRESS
                    );

                    // Always leave an internal note with the outcome + next step (the
                    // original address doubles as a revert reference) — 'verified'
                    // included: every order must show that the check ran and what it
                    // found, and on a DHL mode the note carries DHL's own messages.
                    // Soft-fail: tryPostResultComment never throws, so a comment problem
                    // can't roll back the address apply.
                    $this->tryPostResultComment($orderId, $input, $outputStatus, $outputReason, $changedFields, true, $jobId, $dhlMessages);

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

        // No usable correction (undeliverable, postnumber_invalid, error). Leave
        // the address untouched, but post an internal note explaining what Heista
        // found and the recommended next step, and set the configured status so
        // the merchant can route it to a review queue. Row is marked FAILED to
        // reflect that the address wasn't applied.
        try {
            $this->authHelper->processUnguarded(function () use ($orderId, $targetStatusId, $input, $outputStatus, $outputReason, $changedFields, $dhlMessages, $jobId): void {
                $this->tryPostResultComment($orderId, $input, $outputStatus, $outputReason, $changedFields, false, $jobId, $dhlMessages);

                if ($targetStatusId !== null) {
                    $this->updateOrderStatus($orderId, $targetStatusId);
                }
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

        $this->pendingRepo->markFailed($row, 'Address output status: ' . $outputStatus . ' (isValid=' . ($isValid ? '1' : '0') . ')');
    }

    /**
     * A job that never produced a verdict (job status != COMPLETED, or an item with no
     * output payload). The address is left untouched — a backend failure must never
     * rewrite a customer's address — but the order still gets an internal note and the
     * configured error status, so it can't pass through the queue looking checked when
     * it never was. Mirrors what AddressCheckSubmitService does when the submit itself
     * fails; `statusOnError` therefore covers both "never submitted" and "submitted but
     * no verdict". Soft-fail: never throws into the caller.
     */
    private function writeFailureToOrder(int $orderId, string $jobId, $input, string $failureDetail): void
    {
        $targetStatusId = $this->resolveTargetStatusId('error');

        try {
            $this->authHelper->processUnguarded(function () use ($orderId, $jobId, $input, $failureDetail, $targetStatusId): void {
                $this->tryPostResultComment($orderId, $input, 'error', '', [], false, $jobId, [], $failureDetail);

                if ($targetStatusId !== null) {
                    $this->updateOrderStatus($orderId, $targetStatusId);
                }
            });
        } catch (Throwable $e) {
            $this->getLogger(__METHOD__)->warning('HeistaAddressCheck::log.statusUpdateFailed', [
                'jobId'          => $jobId,
                'orderId'        => $orderId,
                'targetStatusId' => $targetStatusId,
                'outputStatus'   => 'error',
                'error'          => $e->getMessage(),
            ]);
        }
    }

    /**
     * Best-effort internal order comment summarizing the address-check outcome
     * and the recommended next step. Never throws — a comment is supplementary
     * and must not roll back an address apply or status update. Caller wraps
     * this in processUnguarded.
     *
     * @param bool   $applied       true when the corrected address was written (the
     *                              comment then frames the snapshot as "before
     *                              correction"); false for the not-applied outcomes
     *                              (address left untouched).
     * @param array  $dhlMessages   DHL's own validation lines, rendered as-is when present.
     * @param string $failureDetail set only for the no-verdict path (job failed / no output).
     */
    private function tryPostResultComment(int $orderId, $input, string $outputStatus, string $outputReason, array $changedFields, bool $applied, string $jobId, array $dhlMessages = [], string $failureDetail = ''): void
    {
        $authorUserId = $this->resolveCommentAuthorUserId();
        if ($authorUserId === null) {
            $this->getLogger(__METHOD__)->error('HeistaAddressCheck::log.commentSkippedNoUser', [
                'jobId'   => $jobId,
                'orderId' => $orderId,
            ]);
            return;
        }

        $safeInput = is_array($input) ? $input : [];

        try {
            $commentId = $this->createComment(
                $orderId,
                $this->formatResultComment($safeInput, $outputStatus, $outputReason, $changedFields, $applied, $jobId, $dhlMessages, $failureDetail),
                $authorUserId
            );
            $this->report(__METHOD__, 'HeistaAddressCheck::log.commentCreated', [
                'jobId'     => $jobId,
                'orderId'   => $orderId,
                'commentId' => $commentId,
                'userId'    => $authorUserId,
                'applied'   => $applied ? '1' : '0',
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

    /**
     * Create an internal (customer-hidden) order comment with the given HTML
     * body. Matches the /rest/comments contract: referenceValue as int, userId
     * a positive integer (0 or missing both fail validation), text as HTML.
     */
    private function createComment(int $orderId, string $html, int $authorUserId): int
    {
        $created = $this->commentRepo->createComment([
            'referenceType'       => Comment::REFERENCE_TYPE_ORDER,
            'referenceValue'      => $orderId,
            'userId'              => $authorUserId,
            'text'                => $html,
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
     *
     * Leads with the outcome + a one-line next step so the operator knows what
     * to do at a glance, then DHL's own messages (the only explanation a dhl_only
     * merchant gets), then the changed fields (when applied), then the submitted
     * address snapshot (revert reference / what we received).
     */
    private function formatResultComment(array $input, string $outputStatus, string $outputReason, array $changedFields, bool $applied, string $jobId, array $dhlMessages = [], string $failureDetail = ''): string
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

        // "vor Korrektur" would be a lie on a verified address, where nothing moved.
        if (!$applied) {
            $snapshotLabel = 'Eingegangene Adresse (unverändert)';
        } elseif (empty($changedFields)) {
            $snapshotLabel = 'Geprüfte Adresse (unverändert)';
        } else {
            $snapshotLabel = 'Originaladresse vor Korrektur';
        }

        $body  = '<p><strong>Heista Adressprüfung</strong><br>';
        $body .= 'Ergebnis: ' . htmlspecialchars($this->statusLabel($outputStatus, $outputReason), ENT_QUOTES | ENT_HTML5, 'UTF-8') . '<br>';
        $body .= 'Nächster Schritt: ' . htmlspecialchars($this->nextStepText($outputStatus, $outputReason), ENT_QUOTES | ENT_HTML5, 'UTF-8') . '</p>';

        // DHL's verbatim validation lines. For a dhl_only merchant this is the entire
        // explanation of the verdict — nothing else ran on that path.
        $dhlRows = [];
        foreach ($dhlMessages as $message) {
            $text = trim((string) $message);
            if ($text === '') {
                continue;
            }
            $dhlRows[] = htmlspecialchars($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
        if (!empty($dhlRows)) {
            $body .= '<p><strong>DHL-Meldungen:</strong><br>' . implode('<br>', $dhlRows) . '</p>';
        }

        if ($failureDetail !== '') {
            $body .= '<p><strong>Details:</strong> '
                   . htmlspecialchars($failureDetail, ENT_QUOTES | ENT_HTML5, 'UTF-8')
                   . '<br>Die Adresse wurde NICHT verändert.</p>';
        }

        if ($applied && !empty($changedFields)) {
            $body .= '<p><strong>Geänderte Felder:</strong> '
                   . htmlspecialchars($this->changedFieldsLabel($changedFields), ENT_QUOTES | ENT_HTML5, 'UTF-8') . '</p>';
        }

        if (!empty($rows)) {
            $body .= '<p><strong>' . htmlspecialchars($snapshotLabel, ENT_QUOTES | ENT_HTML5, 'UTF-8') . ':</strong><br>';
            $body .= implode('<br>', $rows) . '</p>';
        }

        $body .= '<p><em>Job-ID: ' . htmlspecialchars($jobId, ENT_QUOTES | ENT_HTML5, 'UTF-8') . '</em></p>';

        return $body;
    }

    /**
     * Human-readable German label for an outcome status, shown in the order
     * comment. A reason code can refine the label where a status bucket covers
     * several distinct causes (e.g. undeliverable = not-found vs no house
     * number). Falls back to the raw key for unknown values.
     */
    private function statusLabel(string $outputStatus, string $outputReason = ''): string
    {
        switch ($outputReason) {
            // undeliverable — the status alone would just say "nicht gefunden", which is
            // wrong for most of these: the address WAS found, one component failed.
            case 'house_number_missing':       return 'Nicht zustellbar (Hausnummer fehlt)';
            case 'street_not_confirmed':       return 'Nicht zustellbar (Straße nicht bestätigt)';
            case 'postal_code_not_confirmed':  return 'Nicht zustellbar (PLZ nicht bestätigt)';
            case 'dhl_rejected':               return 'Nicht zustellbar (von DHL abgelehnt)';

            // review_suggested — the correction IS applied; say which field moved.
            case 'postal_code_changed_review': return 'Prüfung empfohlen (PLZ geändert)';
            case 'town_corrected_review':      return 'Prüfung empfohlen (Ort geändert)';
            case 'partially_confirmed_review': return 'Prüfung empfohlen (Hausnummer nicht bestätigt)';

            // verified
            case 'dhl_confirmed':              return 'Bestätigt (DHL)';
        }

        switch ($outputStatus) {
            case 'verified':           return 'Bestätigt (keine Änderung)';
            case 'corrected':          return 'Korrigiert';
            case 'review_suggested':   return 'Prüfung empfohlen';
            case 'undeliverable':      return 'Nicht zustellbar';
            case 'postnumber_invalid': return 'Postnummer ungültig';
            case 'email_required':     return 'E-Mail-Adresse fehlt';
            case 'error':              return 'Verarbeitungsfehler';
            default:                   return $outputStatus;
        }
    }

    /**
     * One-line German next-step hint per outcome, so the operator knows what to
     * do without interpreting the status themselves. A reason code can override
     * the status-level hint where the same status covers several causes.
     */
    private function nextStepText(string $outputStatus, string $outputReason = ''): string
    {
        switch ($outputReason) {
            case 'house_number_missing':
                return 'Hausnummer fehlt – beim Kunden anfordern und ergänzen.';
            case 'street_not_confirmed':
                return 'Straße konnte nicht bestätigt werden – beim Kunden prüfen (PLZ/Ort stimmen, die Straße vermutlich nicht).';
            case 'postal_code_not_confirmed':
                return 'PLZ konnte nicht bestätigt werden – Straße und Ort stimmen, die korrekte PLZ beim Kunden erfragen.';
            case 'dhl_rejected':
                return 'DHL hat die Adresse abgelehnt – DHL-Meldungen unten prüfen und die Adresse mit dem Kunden klären.';
            case 'postal_code_changed_review':
                return 'PLZ wurde automatisch korrigiert – bitte prüfen, ob der neue Zustellort stimmt (Original siehe unten, ggf. zurücksetzen).';
            case 'town_corrected_review':
                return 'Ort wurde automatisch geändert – bitte prüfen, ob der neue Ort stimmt (Original siehe unten, ggf. zurücksetzen).';
            case 'partially_confirmed_review':
                return 'Straße und Ort sind bestätigt, die Hausnummer nicht – vor Versand kurz prüfen.';
            case 'dhl_confirmed':
                return 'Keine Aktion nötig – DHL hat die Adresse bestätigt.';
        }

        switch ($outputStatus) {
            case 'verified':           return 'Keine Aktion nötig – Adresse bestätigt.';
            case 'corrected':          return 'Optional prüfen – Felder wurden korrigiert (Original siehe unten).';
            case 'review_suggested':   return 'Vor Versand kurz prüfen – die Adresse konnte nicht vollständig bestätigt werden.';
            case 'undeliverable':      return 'Manuell prüfen oder Kunden kontaktieren – Adresse wurde nicht gefunden.';
            case 'postnumber_invalid': return 'Gültige Postnummer beim Kunden anfordern (Packstation/Postfiliale).';
            case 'email_required':     return 'E-Mail-Adresse beim Kunden anfordern – für den DPD-Versand erforderlich.';
            case 'error':              return 'Erneut versuchen – die Prüfung ist fehlgeschlagen (kein Ergebnis).';
            default:                   return 'Bitte manuell prüfen.';
        }
    }

    /**
     * Map the machine field keys Heista returns in changedFields to the German
     * labels used elsewhere in the comment; join for display.
     */
    private function changedFieldsLabel(array $changedFields): string
    {
        $map = [
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
            'postnumber'   => 'Postnummer',
        ];

        $out = [];
        foreach ($changedFields as $field) {
            $key   = (string) $field;
            $out[] = $map[$key] ?? $key;
        }

        return implode(', ', $out);
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
            case 'verified':           $configKey = 'HeistaAddressCheck.statusOnVerified';          break;
            case 'corrected':          $configKey = 'HeistaAddressCheck.statusOnCorrected';         break;
            case 'review_suggested':   $configKey = 'HeistaAddressCheck.statusOnReviewSuggested';   break;
            case 'undeliverable':      $configKey = 'HeistaAddressCheck.statusOnUndeliverable';     break;
            case 'postnumber_invalid': $configKey = 'HeistaAddressCheck.statusOnPostnumberInvalid'; break;
            case 'email_required':     $configKey = 'HeistaAddressCheck.statusOnEmailRequired';     break;
            case 'error':              $configKey = 'HeistaAddressCheck.statusOnError';             break;
            default:                   return null;
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
