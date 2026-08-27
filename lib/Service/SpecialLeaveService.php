<?php

declare(strict_types=1);

namespace OCA\NextcloudVacation\Service;

use InvalidArgumentException;
use JsonException;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\IUserManager;
use OCP\Lock\ILockingProvider;
use Throwable;

class SpecialLeaveService
{
    public function __construct(
        private IDBConnection $db,
        private IUserManager $userManager,
        private ILockingProvider $lockingProvider
    ) {
    }

    public function grant(string $userId, int $year, string $amount, string $reason, string $grantedBy): array
    {
        $userId = trim($userId);
        $grantedBy = trim($grantedBy);
        $reason = trim($reason);
        $amountHundredths = $this->parseAmount($amount);
        if ($userId === '' || $grantedBy === '' || $year < 2000 || $year > 2100) {
            throw new InvalidArgumentException('Invalid special leave recipient or year.');
        }
        if ($this->userManager->get($userId) === null) {
            throw new InvalidArgumentException('Special leave recipient does not exist.');
        }
        if ($amountHundredths === 0) {
            throw new InvalidArgumentException('Special leave amount must not be zero.');
        }
        if ($reason === '') {
            throw new InvalidArgumentException('Special leave reason is required.');
        }
        if (mb_strlen($reason) > 255) {
            throw new InvalidArgumentException('Special leave reason is too long.');
        }

        $grantedAt = time();
        $lockKey = 'nextcloud_vacation/special_leave/' . hash('sha256', $userId . '|' . $year);
        $this->lockingProvider->acquireLock($lockKey, ILockingProvider::LOCK_EXCLUSIVE);
        try {
            $this->db->beginTransaction();
            try {
                $previousHash = $this->latestHash($userId, $year);
                $snapshot = [
                    'schema' => 1,
                    'user_id' => $userId,
                    'year' => $year,
                    'amount_hundredths' => $amountHundredths,
                    'reason' => $reason,
                    'granted_by' => $grantedBy,
                    'granted_at' => $grantedAt,
                    'previous_hash' => $previousHash,
                ];
                $entryHash = self::entryHash($snapshot);

                $qb = $this->db->getQueryBuilder();
                $qb->insert('vacation_special_leave')->values([
                    'user_id' => $qb->createNamedParameter($userId),
                    'year' => $qb->createNamedParameter($year, IQueryBuilder::PARAM_INT),
                    'amount_hundredths' => $qb->createNamedParameter($amountHundredths, IQueryBuilder::PARAM_INT),
                    'reason' => $qb->createNamedParameter($reason),
                    'granted_by' => $qb->createNamedParameter($grantedBy),
                    'granted_at' => $qb->createNamedParameter($grantedAt, IQueryBuilder::PARAM_INT),
                    'previous_hash' => $qb->createNamedParameter($previousHash),
                    'entry_hash' => $qb->createNamedParameter($entryHash),
                ]);
                $qb->executeStatement();
                $this->db->commit();
            } catch (Throwable $exception) {
                $this->db->rollBack();
                throw $exception;
            }
        } finally {
            $this->lockingProvider->releaseLock($lockKey, ILockingProvider::LOCK_EXCLUSIVE);
        }

        return $this->normalizeEntry($snapshot + ['entry_hash' => $entryHash]);
    }

    public function entriesByUserForYear(int $year): array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from('vacation_special_leave')
            ->where($qb->expr()->eq('year', $qb->createNamedParameter($year, IQueryBuilder::PARAM_INT)))
            ->orderBy('granted_at', 'ASC')
            ->addOrderBy('id', 'ASC');
        $result = $qb->executeQuery();
        try {
            $entries = [];
            $corrections = [];
            while (($row = $result->fetch()) !== false) {
                $entry = $this->normalizeEntry($row);
                if ((int)$entry['corrects_id'] > 0) {
                    $corrections[] = $entry;
                    continue;
                }
                $entries[(string)$entry['user_id']][(int)$entry['id']] = $entry;
            }

            foreach ($corrections as $correction) {
                $userId = (string)$correction['user_id'];
                $correctsId = (int)$correction['corrects_id'];
                if (!isset($entries[$userId][$correctsId])) {
                    continue;
                }
                $entries[$userId][$correctsId]['reason'] = (string)$correction['reason'];
                $entries[$userId][$correctsId]['corrected_by'] = (string)$correction['granted_by'];
                $entries[$userId][$correctsId]['correctedDisplayName'] = (string)$correction['grantedDisplayName'];
                $entries[$userId][$correctsId]['corrected_at'] = (int)$correction['granted_at'];
                $entries[$userId][$correctsId]['correction_id'] = (int)$correction['id'];
                $entries[$userId][$correctsId]['entry_hash'] = (string)$correction['entry_hash'];
                $entries[$userId][$correctsId]['previous_hash'] = (string)$correction['previous_hash'];
            }

            foreach ($entries as $userId => $userEntries) {
                $entries[$userId] = array_values($userEntries);
            }
            return $entries;
        } finally {
            $result->closeCursor();
        }
    }

    public static function entryHash(array $snapshot): string
    {
        $canonical = [
            'schema' => (int)($snapshot['schema'] ?? 1),
            'user_id' => (string)$snapshot['user_id'],
            'year' => (int)$snapshot['year'],
            'amount_hundredths' => (int)$snapshot['amount_hundredths'],
            'reason' => (string)$snapshot['reason'],
            'granted_by' => (string)$snapshot['granted_by'],
            'granted_at' => (int)$snapshot['granted_at'],
            'previous_hash' => (string)($snapshot['previous_hash'] ?? ''),
        ];
        if ($canonical['schema'] >= 2) {
            $canonical['corrects_id'] = (int)($snapshot['corrects_id'] ?? 0);
        }
        try {
            $json = json_encode($canonical, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } catch (JsonException $exception) {
            throw new \RuntimeException('Could not encode special leave entry.', 0, $exception);
        }
        return hash('sha256', $json);
    }

    private function latestHash(string $userId, int $year): string
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('entry_hash')
            ->from('vacation_special_leave')
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
            ->andWhere($qb->expr()->eq('year', $qb->createNamedParameter($year, IQueryBuilder::PARAM_INT)))
            ->orderBy('id', 'DESC')
            ->setMaxResults(1);
        $result = $qb->executeQuery();
        try {
            $hash = $result->fetchOne();
            return is_string($hash) && preg_match('/^[a-f0-9]{64}$/', $hash) === 1 ? $hash : '';
        } finally {
            $result->closeCursor();
        }
    }

    public function correctReason(
        int $entryId,
        string $expectedUserId,
        int $expectedYear,
        string $reason,
        string $correctedBy
    ): array
    {
        $expectedUserId = trim($expectedUserId);
        $reason = trim($reason);
        $correctedBy = trim($correctedBy);
        if (
            $entryId <= 0
            || $expectedUserId === ''
            || $expectedYear < 2000
            || $expectedYear > 2100
            || $correctedBy === ''
            || $reason === ''
            || mb_strlen($reason) > 255
        ) {
            throw new InvalidArgumentException('Invalid special leave reason correction.');
        }

        $original = $this->entryById($entryId);
        if (
            $original === null
            || (int)($original['corrects_id'] ?? 0) > 0
            || (string)$original['user_id'] !== $expectedUserId
            || (int)$original['year'] !== $expectedYear
        ) {
            throw new InvalidArgumentException('Special leave entry does not exist or is not correctable.');
        }

        $userId = (string)$original['user_id'];
        $year = (int)$original['year'];
        $lockKey = 'nextcloud_vacation/special_leave/' . hash('sha256', $userId . '|' . $year);
        $this->lockingProvider->acquireLock($lockKey, ILockingProvider::LOCK_EXCLUSIVE);
        try {
            $this->db->beginTransaction();
            try {
                $original = $this->entryById($entryId);
                if (
                    $original === null
                    || (int)($original['corrects_id'] ?? 0) > 0
                    || (string)$original['user_id'] !== $expectedUserId
                    || (int)$original['year'] !== $expectedYear
                ) {
                    throw new InvalidArgumentException('Special leave entry does not exist or is not correctable.');
                }

                $correctedAt = time();
                $snapshot = [
                    'schema' => 2,
                    'user_id' => $userId,
                    'year' => $year,
                    'amount_hundredths' => 0,
                    'reason' => $reason,
                    'granted_by' => $correctedBy,
                    'granted_at' => $correctedAt,
                    'previous_hash' => $this->latestHash($userId, $year),
                    'corrects_id' => $entryId,
                ];
                $entryHash = self::entryHash($snapshot);

                $qb = $this->db->getQueryBuilder();
                $qb->insert('vacation_special_leave')->values([
                    'user_id' => $qb->createNamedParameter($userId),
                    'year' => $qb->createNamedParameter($year, IQueryBuilder::PARAM_INT),
                    'amount_hundredths' => $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT),
                    'reason' => $qb->createNamedParameter($reason),
                    'granted_by' => $qb->createNamedParameter($correctedBy),
                    'granted_at' => $qb->createNamedParameter($correctedAt, IQueryBuilder::PARAM_INT),
                    'previous_hash' => $qb->createNamedParameter($snapshot['previous_hash']),
                    'entry_hash' => $qb->createNamedParameter($entryHash),
                    'corrects_id' => $qb->createNamedParameter($entryId, IQueryBuilder::PARAM_INT),
                ]);
                $qb->executeStatement();
                $this->db->commit();
            } catch (Throwable $exception) {
                $this->db->rollBack();
                throw $exception;
            }
        } finally {
            $this->lockingProvider->releaseLock($lockKey, ILockingProvider::LOCK_EXCLUSIVE);
        }

        $effective = $this->normalizeEntry($original);
        $effective['reason'] = $reason;
        $effective['corrected_by'] = $correctedBy;
        $effective['correctedDisplayName'] = $this->displayName($correctedBy);
        $effective['corrected_at'] = $correctedAt;
        $effective['entry_hash'] = $entryHash;
        $effective['previous_hash'] = (string)$snapshot['previous_hash'];
        return $effective;
    }

    private function entryById(int $entryId): ?array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from('vacation_special_leave')
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($entryId, IQueryBuilder::PARAM_INT)))
            ->setMaxResults(1);
        $result = $qb->executeQuery();
        try {
            $row = $result->fetch();
            return is_array($row) ? $row : null;
        } finally {
            $result->closeCursor();
        }
    }

    private function normalizeEntry(array $row): array
    {
        $grantedBy = (string)$row['granted_by'];
        $grantor = $this->userManager->get($grantedBy);
        return [
            'id' => (int)($row['id'] ?? 0),
            'user_id' => (string)$row['user_id'],
            'year' => (int)$row['year'],
            'amount_hundredths' => (int)$row['amount_hundredths'],
            'amount' => (int)$row['amount_hundredths'] / 100,
            'reason' => (string)$row['reason'],
            'granted_by' => $grantedBy,
            'grantedDisplayName' => $grantor === null ? $grantedBy : ($grantor->getDisplayName() ?: $grantedBy),
            'granted_at' => (int)$row['granted_at'],
            'previous_hash' => (string)($row['previous_hash'] ?? ''),
            'entry_hash' => (string)$row['entry_hash'],
            'corrects_id' => (int)($row['corrects_id'] ?? 0),
            'corrected_by' => '',
            'correctedDisplayName' => '',
            'corrected_at' => 0,
            'correction_id' => 0,
        ];
    }

    private function displayName(string $userId): string
    {
        $user = $this->userManager->get($userId);
        return $user === null ? $userId : ($user->getDisplayName() ?: $userId);
    }

    private function parseAmount(string $amount): int
    {
        $normalized = str_replace(',', '.', trim($amount));
        if ($normalized === '' || !is_numeric($normalized)) {
            throw new InvalidArgumentException('Invalid special leave amount.');
        }
        return (int)round((float)$normalized * 100);
    }
}
