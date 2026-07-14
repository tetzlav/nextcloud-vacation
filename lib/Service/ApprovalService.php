<?php

declare(strict_types=1);

namespace OCA\NextcloudVacation\Service;

use DateTimeImmutable;
use DateTimeZone;
use Exception;
use OCA\NextcloudVacation\AppInfo\Application;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserManager;
use OCP\L10N\IFactory as IL10NFactory;
use OCP\Mail\IMailer;
use Throwable;

class ApprovalService
{
    public const STATUS_PENDING_DETECTION = 'pending_detection';
    public const STATUS_PENDING_APPROVAL = 'pending_approval';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_CHANGED_AFTER_APPROVAL = 'changed_after_approval';
    public const STATUS_CANCELLATION_PENDING = 'cancellation_pending';
    public const STATUS_APPROVED_MISSING = 'approved_missing';
    public const STATUS_CANCELLED = 'cancelled';

    private const DEFAULT_APPROVAL_WAIT_MINUTES = 60;
    private const MAIL_QUEUE_LIMIT = 25;
    private const MAIL_QUEUE_MAX_ATTEMPTS = 5;
    private const MAIL_KIND_APPROVER = 'approver';
    private const MAIL_KIND_EMPLOYEE = 'employee';

    public function __construct(
        private IDBConnection $db,
        private IConfig $config,
        private IGroupManager $groupManager,
        private IUserManager $userManager,
        private IURLGenerator $urlGenerator,
        private IMailer $mailer,
        private IL10N $l,
        private IL10NFactory $l10nFactory,
        private VacationReportService $reportService,
        private VacationRevisionService $revisionService
    ) {
    }

    public function syncOpenRequests(?array $years = null): void
    {
        $now = time();
        $years ??= [(int)date('Y'), (int)date('Y') + 1];
        $years = array_values(array_unique(array_map('intval', $years)));
        $seenFingerprints = [];

        foreach ($years as $year) {
            foreach ($this->reportService->reportForStaff($year, false, false, false) as $row) {
                foreach ($row['dayRanges'] as $range) {
                    $days = $this->dayValuesForRange($row['dayValues'] ?? [], $range['start'], $range['end']);
                    if (count($days) === 0) {
                        continue;
                    }

                    $sourceKey = (string)($range['sourceKey'] ?? '');
                    $fingerprint = $this->fingerprint($row['userId'], $year, $range['start'], $range['end'], $days, $sourceKey);
                    $seenFingerprints[] = $fingerprint;
                    $this->upsertRequest($row['userId'], $year, $range['start'], $range['end'], $days, $sourceKey, $fingerprint, $now);
                }
            }
        }

        $this->markMissingRequests($years, $seenFingerprints, $now);
        $this->repairPendingDetectionTimestamps($now);
        $this->repairPrematureAutoApprovals($now);
        $this->promoteStableRequests($now);
    }

    public function approvalWaitMinutes(): int
    {
        return max(
            0,
            (int)$this->config->getAppValue(
                Application::APP_ID,
                'approval_wait_minutes',
                (string)self::DEFAULT_APPROVAL_WAIT_MINUTES
            )
        );
    }

    public function approverUsers(): array
    {
        return $this->csvConfig($this->config->getAppValue(Application::APP_ID, 'approver_users', ''));
    }

    public function employeeNotificationsEnabled(): bool
    {
        return $this->config->getAppValue(Application::APP_ID, 'employee_notifications_enabled', '1') === '1';
    }

    public function autoApprovalGroups(): array
    {
        return $this->csvConfig($this->config->getAppValue(Application::APP_ID, 'auto_approval_groups', ''));
    }

    public function autoApprovalUsers(): array
    {
        return $this->csvConfig($this->config->getAppValue(Application::APP_ID, 'auto_approval_users', ''));
    }

    public function autoApprovalReasonForUser(string $userId): ?string
    {
        return $this->autoApprovalReason($userId);
    }

    public function approverCandidates(): array
    {
        $users = [];

        foreach ($this->reportService->adminGroups() as $groupId) {
            $group = $this->groupManager->get($groupId);
            if ($group === null) {
                continue;
            }

            foreach ($group->getUsers() as $user) {
                $users[$user->getUID()] = $user->getDisplayName() ?: $user->getUID();
            }
        }

        foreach ($this->approverUsers() as $userId) {
            if (!isset($users[$userId]) && $this->reportService->isCalendarAdmin($userId)) {
                $user = $this->userManager->get($userId);
                $users[$userId] = $user === null ? $userId : ($user->getDisplayName() ?: $userId);
            }
        }

        asort($users, SORT_NATURAL | SORT_FLAG_CASE);

        return $users;
    }

    public function approvalMapForYear(int $year): array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('requests.*', 'revision.snapshot_hash')
            ->from('vacation_requests', 'requests')
            ->leftJoin(
                'requests',
                'vacation_request_revisions',
                'revision',
                $qb->expr()->andX(
                    $qb->expr()->eq('revision.request_id', 'requests.id'),
                    $qb->expr()->eq('revision.revision', 'requests.current_revision')
                )
            )
            ->where($qb->expr()->eq('requests.year', $qb->createNamedParameter($year, IQueryBuilder::PARAM_INT)));

        $result = $qb->executeQuery();
        try {
            $map = [];
            while (($row = $result->fetch()) !== false) {
                $normalized = $this->normalizeRow($row);
                $userId = (string)$row['user_id'];
                $key = $this->approvalMapKey((string)$row['date_start'], (string)$row['date_end'], (string)($row['source_key'] ?? ''));
                if (
                    !isset($map[$userId][$key])
                    || $this->approvalStatusPriority($normalized['status']) > $this->approvalStatusPriority($map[$userId][$key]['status'])
                ) {
                    $map[$userId][$key] = $normalized;
                }

                $legacyKey = $this->approvalMapKey((string)$row['date_start'], (string)$row['date_end'], '');
                if (
                    !isset($map[$userId][$legacyKey])
                    || $this->approvalStatusPriority($normalized['status']) > $this->approvalStatusPriority($map[$userId][$legacyKey]['status'])
                ) {
                    $map[$userId][$legacyKey] = $normalized;
                }
            }

            return $map;
        } finally {
            $result->closeCursor();
        }
    }

    public function approve(int $requestId, string $approverId): string
    {
        if (!$this->reportService->isCalendarAdmin($approverId)) {
            return 'not_admin';
        }

        $request = $this->requestById($requestId);
        if ($request === null || $request['status'] === self::STATUS_CANCELLED) {
            return 'not_found_or_cancelled';
        }
        if ((string)$request['status'] === self::STATUS_APPROVED) {
            return 'already_approved';
        }

        $now = time();
        if ((string)$request['status'] === self::STATUS_CHANGED_AFTER_APPROVAL) {
            $request = $this->refreshRequestFromCurrentCalendar($request, $now);
            $requestId = (int)$request['id'];
        }

        $this->runInTransaction(function () use ($requestId, $approverId, $now): void {
            $qb = $this->db->getQueryBuilder();
            $qb->update('vacation_requests')
                ->set('status', $qb->createNamedParameter(self::STATUS_APPROVED))
                ->set('approved_by', $qb->createNamedParameter($approverId))
                ->set('approved_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT))
                ->set('rejected_by', $qb->createNamedParameter(null))
                ->set('rejected_at', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT))
                ->set('rejection_reason', $qb->createNamedParameter(null))
                ->set('auto_approved', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT))
                ->set('auto_approval_reason', $qb->createNamedParameter(null))
                ->set('updated_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT))
                ->where($qb->expr()->eq('id', $qb->createNamedParameter($requestId, IQueryBuilder::PARAM_INT)));
            $qb->executeStatement();
            $this->revisionService->recordApproval($requestId, $now);
            $this->recordAudit($requestId, 'approved', $approverId, null, $now);
        });

        $request['approved_by'] = $approverId;
        $request['approved_at'] = $now;
        $request['status'] = self::STATUS_APPROVED;
        $this->notifyRequesterApproved($request);
        return 'approved';
    }

    public function confirmCancellation(int $requestId, string $actorId, string $reason = '', bool $notify = true): bool
    {
        return $this->resolveCancellation($requestId, $actorId, self::STATUS_CANCELLED, 'cancellation_confirmed', $reason, $notify);
    }

    public function keepBooking(int $requestId, string $actorId, string $reason = ''): bool
    {
        return $this->resolveCancellation($requestId, $actorId, self::STATUS_APPROVED_MISSING, 'booking_retained', $reason);
    }

    public function applyBookedDaysToReport(array $report, int $year): array
    {
        $bookedStatuses = [
            self::STATUS_APPROVED,
            self::STATUS_CHANGED_AFTER_APPROVAL,
            self::STATUS_CANCELLATION_PENDING,
            self::STATUS_APPROVED_MISSING,
        ];
        $rowsByUser = [];
        foreach ($report as $index => $row) {
            $rowsByUser[(string)$row['userId']] = $index;
        }

        foreach ($this->requestsForYears([$year]) as $request) {
            if (!in_array((string)$request['status'], $bookedStatuses, true)) {
                continue;
            }

            $userId = (string)$request['user_id'];
            if (!isset($rowsByUser[$userId])) {
                continue;
            }

            $index = $rowsByUser[$userId];
            $sourceKey = (string)($request['source_key'] ?? '');
            $hasCurrentRange = false;
            foreach ($report[$index]['dayRanges'] as $range) {
                if (
                    (string)$range['start'] === (string)$request['date_start']
                    && (string)$range['end'] === (string)$request['date_end']
                    && (string)($range['sourceKey'] ?? '') === $sourceKey
                ) {
                    $currentDays = array_filter(
                        $report[$index]['calendarDayValues'] ?? $report[$index]['dayValues'],
                        static fn (mixed $value, string $day): bool => $day >= (string)$range['start'] && $day <= (string)$range['end'],
                        ARRAY_FILTER_USE_BOTH
                    );
                    ksort($currentDays);
                    $bookedDays = $request['days'];
                    ksort($bookedDays);
                    $hasCurrentRange = json_encode($currentDays) === json_encode($bookedDays);
                    break;
                }
            }
            if ($hasCurrentRange) {
                continue;
            }

            foreach ($request['days'] as $day => $value) {
                $report[$index]['dayValues'][(string)$day] = max(
                    (float)($report[$index]['dayValues'][(string)$day] ?? 0.0),
                    (float)$value
                );
            }
            $report[$index]['dayRanges'][] = [
                'start' => (string)$request['date_start'],
                'end' => (string)$request['date_end'],
                'sourceKey' => $sourceKey,
                'lastModified' => (int)$request['updated_at'],
                'ledgerOnly' => true,
                'bookedDayValues' => $request['days'],
                'approval' => $request,
            ];
        }

        foreach ($report as &$row) {
            ksort($row['dayValues']);
            $row['days'] = array_keys($row['dayValues']);
            usort($row['dayRanges'], static fn (array $a, array $b): int => [$a['start'], $a['end']] <=> [$b['start'], $b['end']]);
            $row['vacationDays'] = array_sum(array_map('floatval', $row['dayValues']));
            $row['remainingDays'] = (float)$row['entitlement'] - (float)$row['vacationDays'];
        }
        unset($row);

        return $report;
    }

    private function refreshRequestFromCurrentCalendar(array $request, int $now): array
    {
        $userId = (string)$request['user_id'];
        $year = (int)$request['year'];
        $start = (string)$request['date_start'];
        $end = (string)$request['date_end'];
        $sourceKey = (string)($request['source_key'] ?? '');
        $report = $this->reportService->reportForUser($userId, $year, false, false, false);

        foreach ($report as $row) {
            foreach ($row['dayRanges'] as $range) {
                if (
                    (string)$range['start'] !== $start
                    || (string)$range['end'] !== $end
                    || (string)($range['sourceKey'] ?? '') !== $sourceKey
                ) {
                    continue;
                }

                $days = $this->dayValuesForRange($row['dayValues'], $start, $end);
                $fingerprint = $this->fingerprint($userId, $year, $start, $end, $days, $sourceKey);
                $currentRequest = $this->requestByFingerprint($fingerprint);
                if ($currentRequest !== null && (int)$currentRequest['id'] !== (int)$request['id']) {
                    return $currentRequest;
                }

                $dayListJson = json_encode($days, JSON_THROW_ON_ERROR);
                $daysCountHundredths = $this->dayValuesToHundredths($days);

                $qb = $this->db->getQueryBuilder();
                $qb->update('vacation_requests')
                    ->set('fingerprint', $qb->createNamedParameter($fingerprint))
                    ->set('days_count', $qb->createNamedParameter((int)round($daysCountHundredths / 100), IQueryBuilder::PARAM_INT))
                    ->set('days_count_hundredths', $qb->createNamedParameter($daysCountHundredths, IQueryBuilder::PARAM_INT))
                    ->set('day_list_json', $qb->createNamedParameter($dayListJson))
                    ->set('first_seen_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT))
                    ->set('last_seen_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT))
                    ->set('updated_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT))
                    ->where($qb->expr()->eq('id', $qb->createNamedParameter((int)$request['id'], IQueryBuilder::PARAM_INT)));
                $qb->executeStatement();

                $request['fingerprint'] = $fingerprint;
                $request['days_count'] = (int)round($daysCountHundredths / 100);
                $request['days_count_hundredths'] = $daysCountHundredths;
                $request['day_list_json'] = $dayListJson;
                $request['first_seen_at'] = $now;
                $request['last_seen_at'] = $now;
                $request['updated_at'] = $now;

                return $request;
            }
        }

        return $request;
    }

    public function approveOpenRequestsForYear(int $year, string $approverId, bool $notify = true): int
    {
        if (!$this->reportService->isCalendarAdmin($approverId)) {
            return 0;
        }

        $now = time();
        $requestIds = $this->openRequestIdsForYear($year);
        $updated = $this->runInTransaction(function () use ($year, $approverId, $now, $requestIds): int {
            $qb = $this->db->getQueryBuilder();
            $qb->update('vacation_requests')
                ->set('status', $qb->createNamedParameter(self::STATUS_APPROVED))
                ->set('approved_by', $qb->createNamedParameter($approverId))
                ->set('approved_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT))
                ->set('rejected_by', $qb->createNamedParameter(null))
                ->set('rejected_at', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT))
                ->set('rejection_reason', $qb->createNamedParameter(null))
                ->set('auto_approved', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT))
                ->set('auto_approval_reason', $qb->createNamedParameter(null))
                ->set('updated_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT))
                ->where($qb->expr()->eq('year', $qb->createNamedParameter($year, IQueryBuilder::PARAM_INT)))
                ->andWhere(
                    $qb->expr()->orX(
                        $qb->expr()->eq('status', $qb->createNamedParameter(self::STATUS_PENDING_DETECTION)),
                        $qb->expr()->eq('status', $qb->createNamedParameter(self::STATUS_PENDING_APPROVAL)),
                        $qb->expr()->eq('status', $qb->createNamedParameter(self::STATUS_CHANGED_AFTER_APPROVAL))
                    )
                );

            $updated = $qb->executeStatement();
            foreach ($requestIds as $requestId) {
                $request = $this->requestById($requestId);
                if (
                    $request === null
                    || (string)$request['status'] !== self::STATUS_APPROVED
                    || (string)$request['approved_by'] !== $approverId
                    || (int)$request['approved_at'] !== $now
                ) {
                    continue;
                }
                $this->revisionService->recordApproval($requestId, $now);
                $this->recordAudit($requestId, 'approved_bulk', $approverId, null, $now);
            }

            return $updated;
        });

        if ($notify) {
            foreach ($requestIds as $requestId) {
                $request = $this->requestById($requestId);
                if (
                    $request !== null
                    && (string)$request['status'] === self::STATUS_APPROVED
                    && (string)$request['approved_by'] === $approverId
                    && (int)$request['approved_at'] === $now
                ) {
                    $this->notifyRequesterApproved($request);
                }
            }
        }

        return $updated;
    }

    public function confirmCancellationsForYear(int $year, string $actorId, bool $notify = true): int
    {
        if (!$this->reportService->isCalendarAdmin($actorId)) {
            return 0;
        }

        $requests = array_values(array_filter(
            $this->requestsForYears([$year]),
            static fn (array $request): bool => (string)$request['status'] === self::STATUS_CANCELLATION_PENDING
        ));
        $confirmed = 0;
        foreach ($requests as $request) {
            if ($this->confirmCancellation((int)$request['id'], $actorId, '', $notify)) {
                $confirmed++;
            }
        }

        return $confirmed;
    }

    public function attachApprovalsToReport(array $report, int $year): array
    {
        $approvalMap = $this->approvalMapForYear($year);
        foreach ($report as &$row) {
            foreach ($row['dayRanges'] as &$range) {
                if (isset($range['approval']) && is_array($range['approval'])) {
                    continue;
                }

                $key = $range['start'] . '|' . $range['end'] . '|' . (string)($range['sourceKey'] ?? '');
                $legacyKey = $range['start'] . '|' . $range['end'] . '|';
                $exactApproval = $approvalMap[$row['userId']][$key] ?? null;
                $legacyApproval = $approvalMap[$row['userId']][$legacyKey] ?? null;
                $range['approval'] = $this->selectApprovalForRange(
                    $exactApproval,
                    $legacyApproval,
                    $row['calendarDayValues'] ?? $row['dayValues'],
                    $range
                );
            }
            unset($range);
        }
        unset($row);

        return $report;
    }

    public function requestsForYear(int $year): array
    {
        return $this->requestsForYears([$year]);
    }

    public function sendQueuedMails(int $limit = self::MAIL_QUEUE_LIMIT): int
    {
        $sent = 0;

        foreach ($this->pendingQueuedMails($limit) as $mail) {
            try {
                if (($mail['kind'] ?? self::MAIL_KIND_EMPLOYEE) === self::MAIL_KIND_EMPLOYEE && !$this->employeeNotificationsEnabled()) {
                    $this->markQueuedMailSkipped((int)$mail['id'], 'Employee notifications disabled');
                    continue;
                }

                $to = [(string)$mail['recipient_email'] => (string)($mail['recipient_name'] ?: $mail['recipient_email'])];
                $message = $this->mailer->createMessage();
                $message->setTo($to);
                $message->setSubject((string)$mail['subject']);
                $message->setPlainBody((string)$mail['body']);
                $this->mailer->send($message);
                $this->markQueuedMailSent((int)$mail['id']);
                $sent++;
            } catch (Throwable $e) {
                $this->markQueuedMailFailed((int)$mail['id'], $e->getMessage());
            }
        }

        return $sent;
    }

    public function autoApprovalUserCandidates(): array
    {
        $users = [];
        $group = $this->groupManager->get($this->reportService->staffGroup());
        if ($group !== null) {
            foreach ($group->getUsers() as $user) {
                $users[$user->getUID()] = $user->getDisplayName() ?: $user->getUID();
            }
        }

        foreach ($this->autoApprovalUsers() as $userId) {
            if (!isset($users[$userId])) {
                $user = $this->userManager->get($userId);
                $users[$userId] = $user === null ? $userId : ($user->getDisplayName() ?: $userId);
            }
        }

        asort($users, SORT_NATURAL | SORT_FLAG_CASE);

        return $users;
    }

    public function reject(int $requestId, string $rejecterId, string $reason = ''): void
    {
        if (!$this->reportService->isCalendarAdmin($rejecterId)) {
            return;
        }

        $request = $this->requestById($requestId);
        if ($request === null || $request['status'] === self::STATUS_CANCELLED) {
            return;
        }

        $now = time();
        $reason = trim($reason);
        $this->runInTransaction(function () use ($requestId, $rejecterId, $reason, $now): void {
            $qb = $this->db->getQueryBuilder();
            $qb->update('vacation_requests')
                ->set('status', $qb->createNamedParameter(self::STATUS_REJECTED))
                ->set('approved_by', $qb->createNamedParameter(null))
                ->set('approved_at', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT))
                ->set('rejected_by', $qb->createNamedParameter($rejecterId))
                ->set('rejected_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT))
                ->set('rejection_reason', $qb->createNamedParameter($reason === '' ? null : $reason))
                ->set('auto_approved', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT))
                ->set('auto_approval_reason', $qb->createNamedParameter(null))
                ->set('updated_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT))
                ->where($qb->expr()->eq('id', $qb->createNamedParameter($requestId, IQueryBuilder::PARAM_INT)));
            $qb->executeStatement();
            $this->recordAudit($requestId, 'rejected', $rejecterId, $reason === '' ? null : $reason, $now);
        });

        $request['rejected_by'] = $rejecterId;
        $request['rejected_at'] = $now;
        $request['rejection_reason'] = $reason;
        $request['status'] = self::STATUS_REJECTED;
        $this->notifyRequesterRejected($request);
    }

    private function upsertRequest(string $userId, int $year, string $start, string $end, array $days, string $sourceKey, string $fingerprint, int $now): void
    {
        $existing = $this->requestByFingerprint($fingerprint);
        $dayListJson = json_encode($days, JSON_THROW_ON_ERROR);
        $daysCountHundredths = $this->dayValuesToHundredths($days);

        if ($existing === null) {
            $qb = $this->db->getQueryBuilder();
            $qb->insert('vacation_requests')
                ->values([
                    'user_id' => $qb->createNamedParameter($userId),
                    'year' => $qb->createNamedParameter($year, IQueryBuilder::PARAM_INT),
                    'fingerprint' => $qb->createNamedParameter($fingerprint),
                    'summary' => $qb->createNamedParameter('Urlaub'),
                    'source_key' => $qb->createNamedParameter($sourceKey),
                    'date_start' => $qb->createNamedParameter($start),
                    'date_end' => $qb->createNamedParameter($end),
                    'days_count' => $qb->createNamedParameter((int)round($daysCountHundredths / 100), IQueryBuilder::PARAM_INT),
                    'days_count_hundredths' => $qb->createNamedParameter($daysCountHundredths, IQueryBuilder::PARAM_INT),
                    'day_list_json' => $qb->createNamedParameter($dayListJson),
                    'status' => $qb->createNamedParameter(self::STATUS_PENDING_DETECTION),
                    'first_seen_at' => $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT),
                    'last_seen_at' => $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT),
                    'notified_at' => $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT),
                    'approved_at' => $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT),
                    'auto_approved' => $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT),
                    'auto_approval_reason' => $qb->createNamedParameter(null),
                    'updated_at' => $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT),
                ]);
            $qb->executeStatement();
            return;
        }

        $autoApprovalReason = $this->autoApprovalReason($userId);
        if ($autoApprovalReason !== null && (string)$existing['status'] === self::STATUS_PENDING_APPROVAL) {
            $this->autoApproveRequest($existing, $autoApprovalReason, $now);
            return;
        }

        $status = (string)$existing['status'];
        if (in_array($status, [self::STATUS_CANCELLATION_PENDING, self::STATUS_APPROVED_MISSING, self::STATUS_CHANGED_AFTER_APPROVAL], true)) {
            $requestId = (int)$existing['id'];
            $this->runInTransaction(function () use ($requestId, $now): void {
                $qb = $this->db->getQueryBuilder();
                $qb->update('vacation_requests')
                    ->set('status', $qb->createNamedParameter(self::STATUS_APPROVED))
                    ->set('last_seen_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT))
                    ->set('updated_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT))
                    ->where($qb->expr()->eq('id', $qb->createNamedParameter($requestId, IQueryBuilder::PARAM_INT)));
                $qb->executeStatement();
                $this->recordAudit($requestId, 'calendar_entry_restored', null, null, $now);
            });
            return;
        }

        $reactivated = $status === self::STATUS_CANCELLED;
        if ($reactivated) {
            $status = self::STATUS_PENDING_DETECTION;
        }

        if (!$reactivated) {
            if ($status === self::STATUS_PENDING_DETECTION) {
                $qb = $this->db->getQueryBuilder();
                $qb->update('vacation_requests')
                    ->set('last_seen_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT))
                    ->where($qb->expr()->eq('id', $qb->createNamedParameter((int)$existing['id'], IQueryBuilder::PARAM_INT)));
                $qb->executeStatement();
            }

            return;
        }

        $qb = $this->db->getQueryBuilder();
        $qb->update('vacation_requests')
            ->set('days_count', $qb->createNamedParameter((int)round($daysCountHundredths / 100), IQueryBuilder::PARAM_INT))
            ->set('days_count_hundredths', $qb->createNamedParameter($daysCountHundredths, IQueryBuilder::PARAM_INT))
            ->set('day_list_json', $qb->createNamedParameter($dayListJson))
            ->set('source_key', $qb->createNamedParameter($sourceKey))
            ->set('status', $qb->createNamedParameter($status))
            ->set('first_seen_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT))
            ->set('last_seen_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT))
            ->set('approved_at', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT))
            ->set('notified_at', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT))
            ->set('auto_approved', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT))
            ->set('auto_approval_reason', $qb->createNamedParameter(null))
            ->set('updated_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT))
            ->where($qb->expr()->eq('id', $qb->createNamedParameter((int)$existing['id'], IQueryBuilder::PARAM_INT)));
        $qb->executeStatement();
    }

    private function markMissingRequests(array $years, array $seenFingerprints, int $now): void
    {
        $seen = array_flip($seenFingerprints);

        foreach ($this->requestsForYears($years) as $request) {
            if (isset($seen[$request['fingerprint']])) {
                continue;
            }

            if (in_array($request['status'], [self::STATUS_CANCELLED, self::STATUS_CANCELLATION_PENDING, self::STATUS_APPROVED_MISSING], true)) {
                continue;
            }

            $status = in_array($request['status'], [self::STATUS_APPROVED, self::STATUS_CHANGED_AFTER_APPROVAL], true)
                ? self::STATUS_CANCELLATION_PENDING
                : self::STATUS_CANCELLED;

            $requestId = (int)$request['id'];
            $this->runInTransaction(function () use ($requestId, $status, $now): void {
                $qb = $this->db->getQueryBuilder();
                $qb->update('vacation_requests')
                    ->set('status', $qb->createNamedParameter($status))
                    ->set('updated_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT))
                    ->where($qb->expr()->eq('id', $qb->createNamedParameter($requestId, IQueryBuilder::PARAM_INT)));
                $qb->executeStatement();
                if ($status === self::STATUS_CANCELLATION_PENDING) {
                    $this->recordAudit($requestId, 'calendar_entry_missing', null, null, $now);
                }
            });
            if ($status === self::STATUS_CANCELLATION_PENDING) {
                $request['status'] = self::STATUS_CANCELLATION_PENDING;
                $request['updated_at'] = $now;
                $this->notifyApproversMissingBooking($request);
            }
        }
    }

    private function resolveCancellation(int $requestId, string $actorId, string $status, string $action, string $reason, bool $notify = true): bool
    {
        if (!$this->reportService->isCalendarAdmin($actorId)) {
            return false;
        }

        $request = $this->requestById($requestId);
        if ($request === null || !in_array((string)$request['status'], [self::STATUS_CANCELLATION_PENDING, self::STATUS_CHANGED_AFTER_APPROVAL, self::STATUS_APPROVED_MISSING], true)) {
            return false;
        }

        $now = time();
        $reason = trim($reason);
        $this->runInTransaction(function () use ($requestId, $status, $action, $actorId, $reason, $now): void {
            $qb = $this->db->getQueryBuilder();
            $qb->update('vacation_requests')
                ->set('status', $qb->createNamedParameter($status))
                ->set('updated_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT))
                ->where($qb->expr()->eq('id', $qb->createNamedParameter($requestId, IQueryBuilder::PARAM_INT)));
            $qb->executeStatement();
            $this->recordAudit($requestId, $action, $actorId, $reason === '' ? null : $reason, $now);
        });
        if ($action === 'cancellation_confirmed') {
            $request['status'] = self::STATUS_CANCELLED;
            $request['cancelled_by'] = $actorId;
            $request['cancelled_at'] = $now;
            $request['cancellation_reason'] = $reason;
            if ($notify) {
                $this->notifyCancellationConfirmed($request);
            }
        }

        return true;
    }

    private function recordAudit(int $requestId, string $action, ?string $actorId, ?string $reason, int $createdAt): void
    {
        $qb = $this->db->getQueryBuilder();
        $qb->insert('vacation_request_audit')->values([
            'request_id' => $qb->createNamedParameter($requestId, IQueryBuilder::PARAM_INT),
            'action' => $qb->createNamedParameter($action),
            'actor_id' => $qb->createNamedParameter($actorId),
            'reason' => $qb->createNamedParameter($reason),
            'created_at' => $qb->createNamedParameter($createdAt, IQueryBuilder::PARAM_INT),
        ]);
        $qb->executeStatement();
    }

    private function repairPendingDetectionTimestamps(int $now): void
    {
        foreach ($this->pendingDetectionRequestsByStatus() as $request) {
            $firstSeenAt = (int)($request['first_seen_at'] ?? 0);
            $lastSeenAt = (int)($request['last_seen_at'] ?? 0);
            $updatedAt = (int)($request['updated_at'] ?? 0);
            $candidates = array_filter([$firstSeenAt, $lastSeenAt, $updatedAt], static fn (int $value): bool => $value > 0);
            $stableSince = count($candidates) === 0 ? $now : min($candidates);

            if ($firstSeenAt > 0 || $firstSeenAt === $stableSince) {
                continue;
            }

            $qb = $this->db->getQueryBuilder();
            $qb->update('vacation_requests')
                ->set('first_seen_at', $qb->createNamedParameter($stableSince, IQueryBuilder::PARAM_INT))
                ->where($qb->expr()->eq('id', $qb->createNamedParameter((int)$request['id'], IQueryBuilder::PARAM_INT)));
            $qb->executeStatement();
        }
    }

    private function promoteStableRequests(int $now): void
    {
        $threshold = $now - ($this->approvalWaitMinutes() * 60);

        foreach ($this->pendingDetectionRequests($threshold) as $request) {
            $autoApprovalReason = $this->autoApprovalReason((string)$request['user_id']);
            if ($autoApprovalReason !== null) {
                $this->autoApproveRequest($request, $autoApprovalReason, $now);
                continue;
            }

            $qb = $this->db->getQueryBuilder();
            $qb->update('vacation_requests')
                ->set('status', $qb->createNamedParameter(self::STATUS_PENDING_APPROVAL))
                ->set('notified_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT))
                ->set('updated_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT))
                ->where($qb->expr()->eq('id', $qb->createNamedParameter((int)$request['id'], IQueryBuilder::PARAM_INT)));
            $qb->executeStatement();

            $request['status'] = self::STATUS_PENDING_APPROVAL;
            $request['notified_at'] = $now;
            $request['updated_at'] = $now;
            $this->notifyApprovers($request);
            $this->notifyRequesterPendingApproval($request);
        }
    }

    private function repairPrematureAutoApprovals(int $now): void
    {
        $threshold = $now - ($this->approvalWaitMinutes() * 60);

        foreach ($this->prematureAutoApprovedRequests($threshold) as $request) {
            $qb = $this->db->getQueryBuilder();
            $qb->update('vacation_requests')
                ->set('status', $qb->createNamedParameter(self::STATUS_PENDING_DETECTION))
                ->set('notified_at', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT))
                ->set('approved_at', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT))
                ->set('auto_approved', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT))
                ->set('auto_approval_reason', $qb->createNamedParameter(null))
                ->set('updated_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT))
                ->where($qb->expr()->eq('id', $qb->createNamedParameter((int)$request['id'], IQueryBuilder::PARAM_INT)));
            $qb->executeStatement();
        }
    }

    private function notifyApprovers(array $request): void
    {
        foreach ($this->effectiveApproverUsers() as $userId => $displayName) {
            $user = $this->userManager->get($userId);
            if ($user === null || $user->getEMailAddress() === null) {
                continue;
            }

            $l = $this->l10nForUser($user);
            $this->sendMail(
                [$user->getEMailAddress() => $displayName],
                $l->t('Vacation awaiting approval'),
                $this->approvalMailBody($request, $userId, $l),
                self::MAIL_KIND_APPROVER
            );
        }
    }

    private function notifyApproversMissingBooking(array $request): void
    {
        $requester = $this->userManager->get((string)$request['user_id']);
        $requesterName = $requester === null
            ? (string)$request['user_id']
            : ($requester->getDisplayName() ?: (string)$request['user_id']);

        foreach ($this->effectiveApproverUsers() as $userId => $displayName) {
            $user = $this->userManager->get($userId);
            if ($user === null || $user->getEMailAddress() === null) {
                continue;
            }

            $l = $this->l10nForUser($user);
            $period = $this->formatMailDateRange((string)$request['date_start'], (string)$request['date_end'], $userId);
            $this->sendMail(
                [$user->getEMailAddress() => $displayName],
                $l->t('Approved vacation entry missing'),
                $l->t(
                    "An approved vacation entry for %1\$s is missing from the calendar. The booked days remain reserved until a calendar manager confirms the cancellation.\n\nPeriod: %2\$s\nDays: %3\$s\n\nReview at: %4\$s",
                    [$requesterName, $period, $this->formatDayAmount($this->requestDaysCount($request), $userId), $this->approvalsAppUrl($request)]
                ),
                self::MAIL_KIND_APPROVER
            );
        }
    }

    private function notifyCancellationConfirmed(array $request): void
    {
        $requester = $this->userManager->get((string)$request['user_id']);
        $requesterName = $requester === null
            ? (string)$request['user_id']
            : ($requester->getDisplayName() ?: (string)$request['user_id']);
        $actor = $this->userManager->get((string)$request['cancelled_by']);
        $actorName = $actor === null
            ? (string)$request['cancelled_by']
            : ($actor->getDisplayName() ?: (string)$request['cancelled_by']);
        $reason = trim((string)($request['cancellation_reason'] ?? ''));

        if ($this->employeeNotificationsEnabled() && $requester !== null && $requester->getEMailAddress() !== null) {
            $l = $this->l10nForUser($requester);
            $period = $this->formatMailDateRange((string)$request['date_start'], (string)$request['date_end'], (string)$request['user_id']);
            $confirmedAt = $this->formatMailTimestamp((int)$request['cancelled_at'], (string)$request['user_id'], $l);
            $body = $reason === ''
                ? $l->t(
                    "Your vacation cancellation was confirmed.\n\nPeriod: %1\$s\nDays: %2\$s\nConfirmed by: %3\$s\nConfirmed at: %4\$s\n\n%5\$s",
                    [$period, $this->formatDayAmount($this->requestDaysCount($request), (string)$request['user_id']), $actorName, $confirmedAt, $this->personalAppUrl($request)]
                )
                : $l->t(
                    "Your vacation cancellation was confirmed.\n\nPeriod: %1\$s\nDays: %2\$s\nConfirmed by: %3\$s\nConfirmed at: %4\$s\nReason: %5\$s\n\n%6\$s",
                    [$period, $this->formatDayAmount($this->requestDaysCount($request), (string)$request['user_id']), $actorName, $confirmedAt, $reason, $this->personalAppUrl($request)]
                );
            $this->sendMail(
                [$requester->getEMailAddress() => $requesterName],
                $l->t('Vacation cancellation confirmed'),
                $body,
                self::MAIL_KIND_EMPLOYEE
            );
        }

        foreach ($this->effectiveApproverUsers() as $userId => $displayName) {
            $user = $this->userManager->get($userId);
            if ($user === null || $user->getEMailAddress() === null) {
                continue;
            }

            $l = $this->l10nForUser($user);
            $period = $this->formatMailDateRange((string)$request['date_start'], (string)$request['date_end'], $userId);
            $confirmedAt = $this->formatMailTimestamp((int)$request['cancelled_at'], $userId, $l);
            $body = $reason === ''
                ? $l->t(
                    "The vacation cancellation for %1\$s was confirmed.\n\nPeriod: %2\$s\nDays: %3\$s\nConfirmed by: %4\$s\nConfirmed at: %5\$s\n\n%6\$s",
                    [$requesterName, $period, $this->formatDayAmount($this->requestDaysCount($request), $userId), $actorName, $confirmedAt, $this->approvalsAppUrl($request)]
                )
                : $l->t(
                    "The vacation cancellation for %1\$s was confirmed.\n\nPeriod: %2\$s\nDays: %3\$s\nConfirmed by: %4\$s\nConfirmed at: %5\$s\nReason: %6\$s\n\n%7\$s",
                    [$requesterName, $period, $this->formatDayAmount($this->requestDaysCount($request), $userId), $actorName, $confirmedAt, $reason, $this->approvalsAppUrl($request)]
                );
            $this->sendMail(
                [$user->getEMailAddress() => $displayName],
                $l->t('Vacation cancellation confirmed'),
                $body,
                self::MAIL_KIND_APPROVER
            );
        }
    }

    private function notifyRequesterApproved(array $request): void
    {
        if (!$this->employeeNotificationsEnabled()) {
            return;
        }

        $user = $this->userManager->get($request['user_id']);
        if ($user === null || $user->getEMailAddress() === null) {
            return;
        }

        $approver = $this->userManager->get((string)$request['approved_by']);
        $approverName = $approver === null ? (string)$request['approved_by'] : ($approver->getDisplayName() ?: (string)$request['approved_by']);
        $l = $this->l10nForUser($user);
        $approvedAt = $this->formatMailTimestamp((int)($request['approved_at'] ?? 0), (string)$request['user_id'], $l);
        $period = $this->formatMailDateRange((string)$request['date_start'], (string)$request['date_end'], (string)$request['user_id']);

        $this->sendMail(
            [$user->getEMailAddress() => $user->getDisplayName() ?: $request['user_id']],
            $l->t('Vacation approved'),
            $l->t(
                "Your vacation was approved.\n\nPeriod: %1\$s\nDays: %2\$s\nApproved by: %3\$s\nApproved at: %4\$s\n\n%5\$s",
                [$period, $this->formatDayAmount($this->requestDaysCount($request), (string)$request['user_id']), $approverName, $approvedAt, $this->personalAppUrl($request)]
            ),
            self::MAIL_KIND_EMPLOYEE
        );
    }

    private function notifyRequesterAutoApproved(array $request): void
    {
        if (!$this->employeeNotificationsEnabled()) {
            return;
        }

        $user = $this->userManager->get($request['user_id']);
        if ($user === null || $user->getEMailAddress() === null) {
            return;
        }

        $l = $this->l10nForUser($user);
        $approvedAt = $this->formatMailTimestamp((int)($request['approved_at'] ?? 0), (string)$request['user_id'], $l);
        $period = $this->formatMailDateRange((string)$request['date_start'], (string)$request['date_end'], (string)$request['user_id']);
        $reason = $this->localizedAutoApprovalReason((string)($request['auto_approval_reason'] ?? ''), $l);

        $this->sendMail(
            [$user->getEMailAddress() => $user->getDisplayName() ?: $request['user_id']],
            $l->t('Vacation automatically approved'),
            $l->t(
                "Your vacation was automatically approved.\n\nPeriod: %1\$s\nDays: %2\$s\nReason: %3\$s\nApproved at: %4\$s\n\n%5\$s",
                [$period, $this->formatDayAmount($this->requestDaysCount($request), (string)$request['user_id']), $reason, $approvedAt, $this->personalAppUrl($request)]
            ),
            self::MAIL_KIND_EMPLOYEE
        );
    }

    private function notifyRequesterPendingApproval(array $request): void
    {
        if (!$this->employeeNotificationsEnabled()) {
            return;
        }

        $user = $this->userManager->get($request['user_id']);
        if ($user === null || $user->getEMailAddress() === null) {
            return;
        }

        $l = $this->l10nForUser($user);
        $detectedAt = $this->formatMailTimestamp((int)($request['notified_at'] ?? time()), (string)$request['user_id'], $l);
        $period = $this->formatMailDateRange((string)$request['date_start'], (string)$request['date_end'], (string)$request['user_id']);

        $this->sendMail(
            [$user->getEMailAddress() => $user->getDisplayName() ?: $request['user_id']],
            $l->t('Vacation awaiting approval'),
            $l->t(
                "Your vacation is now awaiting approval.\n\nPeriod: %1\$s\nDays: %2\$s\nSubmitted for approval at: %3\$s\n\n%4\$s",
                [$period, $this->formatDayAmount($this->requestDaysCount($request), (string)$request['user_id']), $detectedAt, $this->personalAppUrl($request)]
            ),
            self::MAIL_KIND_EMPLOYEE
        );
    }

    private function notifyRequesterRejected(array $request): void
    {
        if (!$this->employeeNotificationsEnabled()) {
            return;
        }

        $user = $this->userManager->get($request['user_id']);
        if ($user === null || $user->getEMailAddress() === null) {
            return;
        }

        $rejecter = $this->userManager->get((string)$request['rejected_by']);
        $rejecterName = $rejecter === null ? (string)$request['rejected_by'] : ($rejecter->getDisplayName() ?: (string)$request['rejected_by']);
        $l = $this->l10nForUser($user);
        $rejectedAt = $this->formatMailTimestamp((int)($request['rejected_at'] ?? 0), (string)$request['user_id'], $l);
        $period = $this->formatMailDateRange((string)$request['date_start'], (string)$request['date_end'], (string)$request['user_id']);
        $reason = trim((string)($request['rejection_reason'] ?? ''));

        $body = $reason === ''
            ? $l->t(
                "Your vacation was rejected.\n\nPeriod: %1\$s\nDays: %2\$s\nRejected by: %3\$s\nRejected at: %4\$s\n\n%5\$s",
                [$period, $this->formatDayAmount($this->requestDaysCount($request), (string)$request['user_id']), $rejecterName, $rejectedAt, $this->personalAppUrl($request)]
            )
            : $l->t(
                "Your vacation was rejected.\n\nPeriod: %1\$s\nDays: %2\$s\nRejected by: %3\$s\nRejected at: %4\$s\nReason: %5\$s\n\n%6\$s",
                [$period, $this->formatDayAmount($this->requestDaysCount($request), (string)$request['user_id']), $rejecterName, $rejectedAt, $reason, $this->personalAppUrl($request)]
            );

        $this->sendMail(
            [$user->getEMailAddress() => $user->getDisplayName() ?: $request['user_id']],
            $l->t('Vacation rejected'),
            $body,
            self::MAIL_KIND_EMPLOYEE
        );
    }

    private function sendMail(array $to, string $subject, string $body, string $kind): void
    {
        $now = time();

        foreach ($to as $email => $name) {
            try {
                $qb = $this->db->getQueryBuilder();
                $qb->insert('vacation_mail_queue')
                    ->values([
                        'recipient_email' => $qb->createNamedParameter((string)$email),
                        'recipient_name' => $qb->createNamedParameter((string)$name),
                        'subject' => $qb->createNamedParameter($subject),
                        'body' => $qb->createNamedParameter($body),
                        'kind' => $qb->createNamedParameter($kind),
                        'attempts' => $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT),
                        'created_at' => $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT),
                        'sent_at' => $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT),
                    ]);
                $qb->executeStatement();
            } catch (Throwable) {
                // Mail queueing must not break cron or approval actions.
            }
        }
    }

    private function pendingQueuedMails(int $limit): array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from('vacation_mail_queue')
            ->where($qb->expr()->eq('sent_at', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->lt('attempts', $qb->createNamedParameter(self::MAIL_QUEUE_MAX_ATTEMPTS, IQueryBuilder::PARAM_INT)))
            ->orderBy('id', 'ASC')
            ->setMaxResults(max(1, $limit));

        $result = $qb->executeQuery();
        try {
            $mails = [];
            while (($row = $result->fetch()) !== false) {
                $mails[] = $row;
            }

            return $mails;
        } finally {
            $result->closeCursor();
        }
    }

    private function markQueuedMailSent(int $id): void
    {
        $qb = $this->db->getQueryBuilder();
        $qb->update('vacation_mail_queue')
            ->set('sent_at', $qb->createNamedParameter(time(), IQueryBuilder::PARAM_INT))
            ->set('last_error', $qb->createNamedParameter(null))
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
        $qb->executeStatement();
    }

    private function markQueuedMailSkipped(int $id, string $reason): void
    {
        $qb = $this->db->getQueryBuilder();
        $qb->update('vacation_mail_queue')
            ->set('sent_at', $qb->createNamedParameter(time(), IQueryBuilder::PARAM_INT))
            ->set('last_error', $qb->createNamedParameter($reason))
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
        $qb->executeStatement();
    }

    private function markQueuedMailFailed(int $id, string $error): void
    {
        $attempts = $this->queuedMailAttempts($id) + 1;
        $qb = $this->db->getQueryBuilder();
        $qb->update('vacation_mail_queue')
            ->set('attempts', $qb->createNamedParameter($attempts, IQueryBuilder::PARAM_INT))
            ->set('last_error', $qb->createNamedParameter(mb_substr($error, 0, 1000)))
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
        $qb->executeStatement();
    }

    private function queuedMailAttempts(int $id): int
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('attempts')
            ->from('vacation_mail_queue')
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
            ->setMaxResults(1);

        $result = $qb->executeQuery();
        try {
            $attempts = $result->fetchOne();
            return $attempts === false ? 0 : (int)$attempts;
        } finally {
            $result->closeCursor();
        }
    }


    private function dayValuesToHundredths(array $dayValues): int
    {
        return (int)round(array_sum(array_map('floatval', $dayValues)) * 100);
    }

    private function requestDaysCount(array $request): float
    {
        $hundredths = (int)($request['days_count_hundredths'] ?? 0);
        if ($hundredths === 0 && isset($request['days_count'])) {
            $hundredths = (int)$request['days_count'] * 100;
        }

        return $hundredths / 100;
    }

    private function formatDayAmount(float $value, string $userId): string
    {
        if (class_exists(\NumberFormatter::class)) {
            $formatter = new \NumberFormatter($this->localeForUser($userId), \NumberFormatter::DECIMAL);
            $formatter->setAttribute(\NumberFormatter::MIN_FRACTION_DIGITS, 0);
            $formatter->setAttribute(\NumberFormatter::MAX_FRACTION_DIGITS, 2);
            $formatted = $formatter->format($value);
            if ($formatted !== false) {
                return $formatted;
            }
        }

        $separator = $this->isGermanLocale($userId) ? ',' : '.';
        $formatted = number_format($value, 2, $separator, '');

        return rtrim(rtrim($formatted, '0'), $separator);
    }

    private function approvalStatusPriority(string $status): int
    {
        return match ($status) {
            self::STATUS_APPROVED,
            self::STATUS_REJECTED => 60,
            self::STATUS_PENDING_APPROVAL => 50,
            self::STATUS_PENDING_DETECTION => 30,
            self::STATUS_CANCELLATION_PENDING,
            self::STATUS_APPROVED_MISSING => 25,
            self::STATUS_CHANGED_AFTER_APPROVAL => 20,
            self::STATUS_CANCELLED => 10,
            default => 0,
        };
    }

    private function formatMailTimestamp(int $timestamp, string $userId, IL10N $l): string
    {
        if ($timestamp <= 0) {
            return '-';
        }

        $timeZone = $this->timeZoneForUser($userId);
        $date = (new DateTimeImmutable('@' . $timestamp))->setTimezone($timeZone);
        $formatter = class_exists(\IntlDateFormatter::class)
            ? $this->mailDateFormatter($userId, $timeZone, \IntlDateFormatter::MEDIUM, \IntlDateFormatter::SHORT)
            : null;
        if ($formatter === null) {
            return $this->isGermanLocale($userId)
                ? $l->t("%s o'clock", [$date->format('d.m.Y H:i')])
                : $date->format('Y-m-d H:i T');
        }

        $formatted = $formatter->format($date);

        if ($formatted === false) {
            return $this->isGermanLocale($userId)
                ? $l->t("%s o'clock", [$date->format('d.m.Y H:i')])
                : $date->format('Y-m-d H:i T');
        }

        return $this->isGermanLocale($userId) && !str_contains($formatted, 'Uhr')
            ? $l->t("%s o'clock", [$formatted])
            : $formatted;
    }

    private function formatMailDateRange(string $start, string $end, string $userId): string
    {
        $formattedStart = $this->formatMailDate($start, $userId);
        $formattedEnd = $this->formatMailDate($end, $userId);

        if ($start === $end) {
            return $formattedStart;
        }

        return $formattedStart . ' - ' . $formattedEnd;
    }

    private function formatMailDate(string $date, string $userId): string
    {
        $timeZone = $this->timeZoneForUser($userId);
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date, $timeZone);
        if ($parsed === false) {
            return $date;
        }

        $formatter = class_exists(\IntlDateFormatter::class)
            ? $this->mailDateFormatter($userId, $timeZone, \IntlDateFormatter::MEDIUM, \IntlDateFormatter::NONE)
            : null;
        if ($formatter === null) {
            return $parsed->format('Y-m-d');
        }

        $formatted = $formatter->format($parsed);

        return $formatted === false ? $parsed->format('Y-m-d') : $formatted;
    }

    private function mailDateFormatter(string $userId, DateTimeZone $timeZone, int $dateType, int $timeType): ?\IntlDateFormatter
    {
        if (!class_exists(\IntlDateFormatter::class)) {
            return null;
        }

        return new \IntlDateFormatter(
            $this->localeForUser($userId),
            $dateType,
            $timeType,
            $timeZone->getName()
        );
    }

    private function l10nForUser(IUser $user): IL10N
    {
        try {
            return $this->l10nFactory->get(
                Application::APP_ID,
                $this->l10nFactory->getUserLanguage($user)
            );
        } catch (Throwable) {
            return $this->l;
        }
    }

    private function localeForUser(string $userId): string
    {
        $locale = $this->config->getUserValue($userId, 'core', 'locale', '');
        if (is_string($locale) && $locale !== '') {
            return $locale;
        }

        $language = $this->config->getUserValue($userId, 'core', 'lang', '');
        if (is_string($language) && $language !== '') {
            return $language;
        }

        return class_exists(\Locale::class) ? \Locale::getDefault() : 'en';
    }

    private function isGermanLocale(string $userId): bool
    {
        return str_starts_with(strtolower(str_replace('_', '-', $this->localeForUser($userId))), 'de');
    }

    private function timeZoneForUser(string $userId): DateTimeZone
    {
        $candidates = [
            $this->config->getUserValue($userId, 'core', 'timezone', ''),
            $this->config->getAppValue(Application::APP_ID, 'display_timezone', ''),
            (string)$this->config->getSystemValue('logtimezone', ''),
            date_default_timezone_get(),
            'UTC',
        ];

        foreach ($candidates as $candidate) {
            if (!is_string($candidate) || $candidate === '') {
                continue;
            }

            try {
                return new DateTimeZone($candidate);
            } catch (Exception) {
                continue;
            }
        }

        return new DateTimeZone('UTC');
    }

    private function approvalMailBody(array $request, string $recipientUserId, IL10N $l): string
    {
        $user = $this->userManager->get($request['user_id']);
        $displayName = $user === null ? $request['user_id'] : ($user->getDisplayName() ?: $request['user_id']);
        $observedAt = $this->formatMailTimestamp((int)($request['first_seen_at'] ?? 0), $recipientUserId, $l);
        $period = $this->formatMailDateRange((string)$request['date_start'], (string)$request['date_end'], $recipientUserId);

        return $l->t(
            "%1\$s entered vacation.\n\nPeriod: %2\$s\nDays: %3\$s\nDetected at: %4\$s\n\nApprove at: %5\$s",
            [$displayName, $period, $this->formatDayAmount($this->requestDaysCount($request), $recipientUserId), $observedAt, $this->approvalsAppUrl($request)]
        );
    }

    private function effectiveApproverUsers(): array
    {
        $configured = $this->approverUsers();
        if (count($configured) === 0) {
            return $this->approverCandidates();
        }

        $users = [];
        foreach ($configured as $userId) {
            if (!$this->reportService->isCalendarAdmin($userId)) {
                continue;
            }

            $user = $this->userManager->get($userId);
            $users[$userId] = $user === null ? $userId : ($user->getDisplayName() ?: $userId);
        }

        return $users;
    }

    private function requestByFingerprint(string $fingerprint): ?array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from('vacation_requests')
            ->where($qb->expr()->eq('fingerprint', $qb->createNamedParameter($fingerprint)))
            ->setMaxResults(1);

        $result = $qb->executeQuery();
        try {
            $row = $result->fetch();
            return $row === false ? null : $this->normalizeRow($row);
        } finally {
            $result->closeCursor();
        }
    }

    private function requestById(int $id): ?array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from('vacation_requests')
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
            ->setMaxResults(1);

        $result = $qb->executeQuery();
        try {
            $row = $result->fetch();
            return $row === false ? null : $this->normalizeRow($row);
        } finally {
            $result->closeCursor();
        }
    }

    private function requestsForYears(array $years): array
    {
        if (count($years) === 0) {
            return [];
        }

        $qb = $this->db->getQueryBuilder();
        $or = $qb->expr()->orX();
        foreach ($years as $year) {
            $or->add($qb->expr()->eq('year', $qb->createNamedParameter($year, IQueryBuilder::PARAM_INT)));
        }

        $qb->select('*')->from('vacation_requests')->where($or);
        $result = $qb->executeQuery();
        try {
            $requests = [];
            while (($row = $result->fetch()) !== false) {
                $requests[] = $this->normalizeRow($row);
            }

            return $requests;
        } finally {
            $result->closeCursor();
        }
    }

    private function pendingDetectionRequestsByStatus(): array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from('vacation_requests')
            ->where($qb->expr()->eq('status', $qb->createNamedParameter(self::STATUS_PENDING_DETECTION)));

        $result = $qb->executeQuery();
        try {
            $requests = [];
            while (($row = $result->fetch()) !== false) {
                $requests[] = $this->normalizeRow($row);
            }

            return $requests;
        } finally {
            $result->closeCursor();
        }
    }

    private function pendingDetectionRequests(int $threshold): array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from('vacation_requests')
            ->where($qb->expr()->eq('status', $qb->createNamedParameter(self::STATUS_PENDING_DETECTION)))
            ->andWhere($qb->expr()->lte('first_seen_at', $qb->createNamedParameter($threshold, IQueryBuilder::PARAM_INT)));

        $result = $qb->executeQuery();
        try {
            $requests = [];
            while (($row = $result->fetch()) !== false) {
                $requests[] = $this->normalizeRow($row);
            }

            return $requests;
        } finally {
            $result->closeCursor();
        }
    }

    private function normalizeRow(array $row): array
    {
        foreach (['id', 'year', 'days_count', 'days_count_hundredths', 'first_seen_at', 'last_seen_at', 'notified_at', 'approved_at', 'rejected_at', 'updated_at', 'auto_approved', 'current_revision'] as $key) {
            if (isset($row[$key])) {
                $row[$key] = (int)$row[$key];
            }
        }

        $row['approvedDisplayName'] = '';
        if (isset($row['approved_by']) && $row['approved_by'] !== null && $row['approved_by'] !== '') {
            $approvedBy = (string)$row['approved_by'];
            $approvedUser = $this->userManager->get($approvedBy);
            $row['approvedDisplayName'] = $approvedUser === null ? $approvedBy : ($approvedUser->getDisplayName() ?: $approvedBy);
        }

        $row['rejectedDisplayName'] = '';
        if (isset($row['rejected_by']) && $row['rejected_by'] !== null && $row['rejected_by'] !== '') {
            $rejectedBy = (string)$row['rejected_by'];
            $rejectedUser = $this->userManager->get($rejectedBy);
            $row['rejectedDisplayName'] = $rejectedUser === null ? $rejectedBy : ($rejectedUser->getDisplayName() ?: $rejectedBy);
        }

        $row['days'] = [];
        if (isset($row['day_list_json']) && $row['day_list_json'] !== null && $row['day_list_json'] !== '') {
            $decoded = json_decode((string)$row['day_list_json'], true);
            $row['days'] = is_array($decoded) ? $decoded : [];
        }

        return $row;
    }

    private function prematureAutoApprovedRequests(int $threshold): array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from('vacation_requests')
            ->where($qb->expr()->eq('status', $qb->createNamedParameter(self::STATUS_APPROVED)))
            ->andWhere($qb->expr()->eq('auto_approved', $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->gt('first_seen_at', $qb->createNamedParameter($threshold, IQueryBuilder::PARAM_INT)));

        $result = $qb->executeQuery();
        try {
            $requests = [];
            while (($row = $result->fetch()) !== false) {
                $requests[] = $this->normalizeRow($row);
            }

            return $requests;
        } finally {
            $result->closeCursor();
        }
    }

    private function autoApprovalReason(string $userId): ?string
    {
        if (in_array($userId, $this->autoApprovalUsers(), true)) {
            return 'configured_user';
        }

        foreach ($this->autoApprovalGroups() as $groupId) {
            if ($this->groupManager->isInGroup($userId, $groupId)) {
                return 'group:' . $groupId;
            }
        }

        return null;
    }

    private function openRequestIdsForYear(int $year): array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('id')
            ->from('vacation_requests')
            ->where($qb->expr()->eq('year', $qb->createNamedParameter($year, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->orX(
                $qb->expr()->eq('status', $qb->createNamedParameter(self::STATUS_PENDING_DETECTION)),
                $qb->expr()->eq('status', $qb->createNamedParameter(self::STATUS_PENDING_APPROVAL)),
                $qb->expr()->eq('status', $qb->createNamedParameter(self::STATUS_CHANGED_AFTER_APPROVAL))
            ));
        $result = $qb->executeQuery();
        try {
            $ids = [];
            while (($id = $result->fetchOne()) !== false) {
                $ids[] = (int)$id;
            }
            return $ids;
        } finally {
            $result->closeCursor();
        }
    }

    private function localizedAutoApprovalReason(string $reason, IL10N $l): string
    {
        $reason = trim($reason);
        if ($reason === '' || $reason === 'Automatic approval' || $reason === 'Automatische Genehmigung') {
            return $l->t('Automatic approval');
        }

        if ($reason === 'configured_user' || $reason === 'Automatic approval: configured user' || $reason === 'Automatische Genehmigung: konfigurierter Benutzer') {
            return $l->t('Automatic approval: configured user');
        }

        foreach (['group:', 'Automatic approval: group ', 'Automatische Genehmigung: Gruppe '] as $prefix) {
            if (str_starts_with($reason, $prefix)) {
                return $l->t('Automatic approval: group %s', [substr($reason, strlen($prefix))]);
            }
        }

        return $reason;
    }

    private function autoApproveRequest(array $request, string $reason, int $now): void
    {
        $requestId = (int)$request['id'];
        $this->runInTransaction(function () use ($requestId, $reason, $now): void {
            $qb = $this->db->getQueryBuilder();
            $qb->update('vacation_requests')
                ->set('status', $qb->createNamedParameter(self::STATUS_APPROVED))
                ->set('approved_by', $qb->createNamedParameter(null))
                ->set('approved_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT))
                ->set('notified_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT))
                ->set('auto_approved', $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT))
                ->set('auto_approval_reason', $qb->createNamedParameter($reason))
                ->set('rejected_by', $qb->createNamedParameter(null))
                ->set('rejected_at', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT))
                ->set('rejection_reason', $qb->createNamedParameter(null))
                ->set('updated_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT))
                ->where($qb->expr()->eq('id', $qb->createNamedParameter($requestId, IQueryBuilder::PARAM_INT)));
            $qb->executeStatement();
            $this->revisionService->recordApproval($requestId, $now);
            $this->recordAudit($requestId, 'approved_automatically', null, $reason, $now);
        });

        $request['status'] = self::STATUS_APPROVED;
        $request['approved_at'] = $now;
        $request['auto_approved'] = 1;
        $request['auto_approval_reason'] = $reason;
        $this->notifyRequesterAutoApproved($request);
    }

    private function runInTransaction(callable $operation): mixed
    {
        $this->db->beginTransaction();
        try {
            $result = $operation();
            $this->db->commit();
            return $result;
        } catch (Throwable $exception) {
            $this->db->rollBack();
            throw $exception;
        }
    }

    private function dayValuesForRange(array $dayValues, string $start, string $end): array
    {
        return array_filter($dayValues, static fn ($value, string $day): bool => $day >= $start && $day <= $end, ARRAY_FILTER_USE_BOTH);
    }

    private function approvalMapKey(string $start, string $end, string $sourceKey): string
    {
        return $start . '|' . $end . '|' . $sourceKey;
    }

    private function fingerprint(string $userId, int $year, string $start, string $end, array $days, string $sourceKey): string
    {
        ksort($days);
        $hasPartialDay = count(array_filter($days, static fn ($value): bool => (float)$value !== 1.0)) > 0;
        if (!$hasPartialDay) {
            return hash('sha256', implode('|', [$userId, (string)$year, $start, $end, $sourceKey, implode(',', array_keys($days))]));
        }

        return hash('sha256', $userId . '|' . $year . '|' . $start . '|' . $end . '|' . $sourceKey . '|' . json_encode($days, JSON_THROW_ON_ERROR));
    }

    private function personalAppUrl(array $request = []): string
    {
        $parameters = isset($request['year']) ? ['year' => (int)$request['year']] : [];

        return $this->urlGenerator->getAbsoluteURL(
            $this->urlGenerator->linkToRoute(Application::APP_ID . '.page.index', $parameters)
        );
    }

    private function selectApprovalForRange(?array $exactApproval, ?array $legacyApproval, array $dayValues, array $range): ?array
    {
        if ($exactApproval !== null && !$this->approvalMatchesRangeDays($exactApproval, $dayValues, $range)) {
            $exactApproval = null;
        }
        if ($legacyApproval !== null && !$this->approvalMatchesRangeDays($legacyApproval, $dayValues, $range)) {
            $legacyApproval = null;
        }
        if ($exactApproval === null) {
            return $legacyApproval;
        }
        if ($legacyApproval === null) {
            return $exactApproval;
        }

        return $this->approvalDisplayPriority((string)$legacyApproval['status']) > $this->approvalDisplayPriority((string)$exactApproval['status'])
            ? $legacyApproval
            : $exactApproval;
    }

    private function approvalMatchesRangeDays(array $approval, array $dayValues, array $range): bool
    {
        $approvedDays = json_decode((string)($approval['day_list_json'] ?? ''), true);
        if (!is_array($approvedDays)) {
            return false;
        }

        $currentDays = array_filter($dayValues, static function ($value, string $day) use ($range): bool {
            return $day >= (string)$range['start'] && $day <= (string)$range['end'];
        }, ARRAY_FILTER_USE_BOTH);
        ksort($approvedDays);
        ksort($currentDays);

        return json_encode($approvedDays) === json_encode($currentDays);
    }

    private function approvalDisplayPriority(string $status): int
    {
        return match ($status) {
            self::STATUS_APPROVED,
            self::STATUS_REJECTED => 60,
            self::STATUS_PENDING_APPROVAL => 50,
            self::STATUS_CANCELLATION_PENDING,
            self::STATUS_APPROVED_MISSING => 45,
            self::STATUS_CHANGED_AFTER_APPROVAL => 40,
            self::STATUS_PENDING_DETECTION => 30,
            self::STATUS_CANCELLED => 10,
            default => 0,
        };
    }

    private function approvalsAppUrl(array $request = []): string
    {
        $parameters = [];
        if (isset($request['year'])) {
            $parameters['year'] = (int)$request['year'];
        }
        if (isset($request['user_id']) && (string)$request['user_id'] !== '') {
            $parameters['open_user_id'] = (string)$request['user_id'];
        }

        return $this->urlGenerator->getAbsoluteURL(
            $this->urlGenerator->linkToRoute(Application::APP_ID . '.page.approvals', $parameters)
        );
    }

    private function csvConfig(string $raw): array
    {
        $values = array_map('trim', explode(',', $raw));
        return array_values(array_filter(array_unique($values), static fn (string $value): bool => $value !== ''));
    }
}
