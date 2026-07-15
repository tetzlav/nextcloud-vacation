<?php

declare(strict_types=1);

namespace OCA\NextcloudVacation\Service;

use JsonException;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

class VacationRevisionService
{
    public function __construct(
        private IDBConnection $db,
        private VacationReportService $reportService
    ) {
    }

    /**
     * Must be called inside the transaction that stores the approval decision.
     */
    public function recordApproval(int $requestId, int $createdAt): array
    {
        $request = $this->requestById($requestId);
        if ($request === null) {
            throw new \RuntimeException('Vacation request not found while creating approval revision.');
        }

        $revision = max(0, (int)($request['current_revision'] ?? 0)) + 1;
        $snapshot = self::snapshotFromRequest($request);
        $snapshotJson = self::encodeSnapshot($snapshot);
        $snapshotHash = hash('sha256', $snapshotJson);

        $qb = $this->db->getQueryBuilder();
        $qb->insert('vacation_request_revisions')->values([
            'request_id' => $qb->createNamedParameter($requestId, IQueryBuilder::PARAM_INT),
            'revision' => $qb->createNamedParameter($revision, IQueryBuilder::PARAM_INT),
            'snapshot_json' => $qb->createNamedParameter($snapshotJson),
            'snapshot_hash' => $qb->createNamedParameter($snapshotHash),
            'approved_by' => $qb->createNamedParameter($request['approved_by'] ?? null),
            'approved_at' => $qb->createNamedParameter((int)($request['approved_at'] ?? 0), IQueryBuilder::PARAM_INT),
            'created_at' => $qb->createNamedParameter($createdAt, IQueryBuilder::PARAM_INT),
        ]);
        $qb->executeStatement();

        $qb = $this->db->getQueryBuilder();
        $qb->update('vacation_requests')
            ->set('current_revision', $qb->createNamedParameter($revision, IQueryBuilder::PARAM_INT))
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($requestId, IQueryBuilder::PARAM_INT)));
        $qb->executeStatement();

        return [
            'request_id' => $requestId,
            'revision' => $revision,
            'snapshot' => $snapshot,
            'snapshot_hash' => $snapshotHash,
            'approved_by' => $request['approved_by'] ?? null,
            'approved_at' => (int)($request['approved_at'] ?? 0),
            'created_at' => $createdAt,
        ];
    }

    public function requestDetails(int $requestId, ?int $revision = null, bool $compareCurrent = false): ?array
    {
        $request = $this->requestById($requestId);
        if ($request === null) {
            return null;
        }

        $revisions = $this->revisionsForRequest($requestId);
        $selectedRevision = null;
        if ($revision !== null) {
            foreach ($revisions as $candidate) {
                if ((int)$candidate['revision'] === $revision) {
                    $selectedRevision = $candidate;
                    break;
                }
            }
        } elseif (count($revisions) > 0) {
            $selectedRevision = $revisions[array_key_last($revisions)];
        }

        return [
            'request' => $this->normalizeRequest($request),
            'revisions' => $revisions,
            'audit' => $this->auditForRequest($requestId),
            'selected_revision' => $selectedRevision,
            'comparison' => $compareCurrent && $selectedRevision !== null
                ? $this->compareWithCurrentCalendar($selectedRevision['snapshot'])
                : null,
        ];
    }

    public function revisionHash(int $requestId, int $revision): ?string
    {
        if ($requestId <= 0 || $revision <= 0) {
            return null;
        }

        $qb = $this->db->getQueryBuilder();
        $qb->select('snapshot_hash')
            ->from('vacation_request_revisions')
            ->where($qb->expr()->eq('request_id', $qb->createNamedParameter($requestId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('revision', $qb->createNamedParameter($revision, IQueryBuilder::PARAM_INT)))
            ->setMaxResults(1);
        $result = $qb->executeQuery();
        try {
            $hash = $result->fetchOne();
            return is_string($hash) && preg_match('/^[a-f0-9]{64}$/', $hash) === 1 ? $hash : null;
        } finally {
            $result->closeCursor();
        }
    }

    public static function snapshotFromRequest(array $request): array
    {
        $days = [];
        if (isset($request['day_list_json']) && is_string($request['day_list_json']) && $request['day_list_json'] !== '') {
            $decoded = json_decode($request['day_list_json'], true);
            if (is_array($decoded)) {
                $days = $decoded;
            }
        } elseif (isset($request['days']) && is_array($request['days'])) {
            $days = $request['days'];
        }
        ksort($days);

        return [
            'request_id' => (int)$request['id'],
            'user_id' => (string)$request['user_id'],
            'year' => (int)$request['year'],
            'summary' => (string)($request['summary'] ?? ''),
            'source_key' => (string)($request['source_key'] ?? ''),
            'fingerprint' => (string)($request['fingerprint'] ?? ''),
            'date_start' => (string)$request['date_start'],
            'date_end' => (string)$request['date_end'],
            'days_count_hundredths' => isset($request['days_count_hundredths'])
                ? (int)$request['days_count_hundredths']
                : ((int)($request['days_count'] ?? 0) * 100),
            'days' => $days,
            'status' => (string)($request['status'] ?? ''),
            'first_seen_at' => (int)($request['first_seen_at'] ?? 0),
            'last_seen_at' => (int)($request['last_seen_at'] ?? 0),
            'notified_at' => (int)($request['notified_at'] ?? 0),
            'approved_by' => $request['approved_by'] ?? null,
            'approved_at' => (int)($request['approved_at'] ?? 0),
            'auto_approved' => (int)($request['auto_approved'] ?? 0) === 1,
            'auto_approval_reason' => $request['auto_approval_reason'] ?? null,
        ];
    }

    public static function encodeSnapshot(array $snapshot): string
    {
        try {
            return json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } catch (JsonException $exception) {
            throw new \RuntimeException('Could not encode vacation approval snapshot.', 0, $exception);
        }
    }

    private function compareWithCurrentCalendar(array $snapshot): array
    {
        $report = $this->reportService->reportForUser(
            (string)$snapshot['user_id'],
            (int)$snapshot['year'],
            false,
            false,
            false
        );
        $current = null;
        foreach ($report as $row) {
            foreach ($row['dayRanges'] as $range) {
                $snapshotSourceKey = (string)$snapshot['source_key'];
                $rangeSourceKey = (string)($range['sourceKey'] ?? '');
                $matchesSource = $snapshotSourceKey !== '' && $rangeSourceKey === $snapshotSourceKey;
                $matchesLegacyRange = $snapshotSourceKey === ''
                    && (string)$range['start'] === (string)$snapshot['date_start']
                    && (string)$range['end'] === (string)$snapshot['date_end'];
                if (!$matchesSource && !$matchesLegacyRange) {
                    continue;
                }

                $days = isset($range['dayValues']) && is_array($range['dayValues'])
                    ? $range['dayValues']
                    : array_filter(
                        $row['dayValues'] ?? [],
                        static fn (mixed $value, string $day): bool => $day >= (string)$range['start'] && $day <= (string)$range['end'],
                        ARRAY_FILTER_USE_BOTH
                    );
                ksort($days);
                $current = [
                    'source_key' => (string)($range['sourceKey'] ?? ''),
                    'date_start' => (string)$range['start'],
                    'date_end' => (string)$range['end'],
                    'days_count_hundredths' => (int)round(array_sum(array_map('floatval', $days)) * 100),
                    'days' => $days,
                ];
                break 2;
            }
        }

        if ($current === null) {
            return [
                'matches' => false,
                'calendar_entry_found' => false,
                'differences' => ['calendar_entry' => ['snapshot' => 'present', 'current' => 'missing']],
                'current' => null,
            ];
        }

        $snapshotComparable = $snapshot;
        $currentComparable = $current;
        $snapshotComparable['days'] = $this->dayValuesInHundredths($snapshot['days'] ?? []);
        $currentComparable['days'] = $this->dayValuesInHundredths($current['days'] ?? []);
        $differences = [];
        foreach (['source_key', 'date_start', 'date_end', 'days_count_hundredths', 'days'] as $field) {
            if (($snapshotComparable[$field] ?? null) !== ($currentComparable[$field] ?? null)) {
                $differences[$field] = [
                    'snapshot' => $snapshotComparable[$field] ?? null,
                    'current' => $currentComparable[$field] ?? null,
                ];
            }
        }

        return [
            'matches' => count($differences) === 0,
            'calendar_entry_found' => true,
            'differences' => $differences,
            'current' => $current,
        ];
    }

    private function requestById(int $requestId): ?array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from('vacation_requests')
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($requestId, IQueryBuilder::PARAM_INT)))
            ->setMaxResults(1);
        $result = $qb->executeQuery();
        try {
            $row = $result->fetch();
            return $row === false ? null : $row;
        } finally {
            $result->closeCursor();
        }
    }

    private function revisionsForRequest(int $requestId): array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from('vacation_request_revisions')
            ->where($qb->expr()->eq('request_id', $qb->createNamedParameter($requestId, IQueryBuilder::PARAM_INT)))
            ->orderBy('revision', 'ASC');
        $result = $qb->executeQuery();
        try {
            $revisions = [];
            while (($row = $result->fetch()) !== false) {
                $snapshot = json_decode((string)$row['snapshot_json'], true);
                $revisions[] = [
                    'id' => (int)$row['id'],
                    'request_id' => (int)$row['request_id'],
                    'revision' => (int)$row['revision'],
                    'snapshot' => is_array($snapshot) ? $snapshot : [],
                    'snapshot_hash' => (string)$row['snapshot_hash'],
                    'hash_valid' => is_array($snapshot) && hash_equals(
                        (string)$row['snapshot_hash'],
                        hash('sha256', self::encodeSnapshot($snapshot))
                    ),
                    'approved_by' => $row['approved_by'],
                    'approved_at' => (int)$row['approved_at'],
                    'created_at' => (int)$row['created_at'],
                ];
            }
            return $revisions;
        } finally {
            $result->closeCursor();
        }
    }

    private function auditForRequest(int $requestId): array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from('vacation_request_audit')
            ->where($qb->expr()->eq('request_id', $qb->createNamedParameter($requestId, IQueryBuilder::PARAM_INT)))
            ->orderBy('created_at', 'ASC')
            ->addOrderBy('id', 'ASC');
        $result = $qb->executeQuery();
        try {
            $audit = [];
            while (($row = $result->fetch()) !== false) {
                $audit[] = [
                    'id' => (int)$row['id'],
                    'action' => (string)$row['action'],
                    'actor_id' => $row['actor_id'],
                    'reason' => $row['reason'],
                    'created_at' => (int)$row['created_at'],
                ];
            }
            return $audit;
        } finally {
            $result->closeCursor();
        }
    }

    private function normalizeRequest(array $request): array
    {
        foreach (['id', 'year', 'days_count', 'days_count_hundredths', 'approved_at', 'updated_at', 'current_revision'] as $key) {
            if (isset($request[$key])) {
                $request[$key] = (int)$request[$key];
            }
        }
        $request['days'] = [];
        if (isset($request['day_list_json']) && is_string($request['day_list_json'])) {
            $days = json_decode($request['day_list_json'], true);
            $request['days'] = is_array($days) ? $days : [];
        }
        return $request;
    }

    private function dayValuesInHundredths(mixed $days): array
    {
        if (!is_array($days)) {
            return [];
        }

        $normalized = [];
        foreach ($days as $day => $value) {
            $normalized[(string)$day] = (int)round((float)$value * 100);
        }
        ksort($normalized);
        return $normalized;
    }
}
