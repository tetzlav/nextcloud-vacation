<?php

declare(strict_types=1);

namespace OCA\NextcloudVacation\Service;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use OCP\Calendar\IManager as ICalendarManager;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\IUserManager;
use Sabre\VObject\Component\VCalendar;
use Sabre\VObject\Reader;
use Throwable;

class VacationReportService
{
    public const ADMIN_GROUP = 'calendar-managers';
    public const STAFF_GROUP = 'staff';
    public const CALENDAR_URI = 'status';
    public const CALENDAR_DISPLAYNAME = 'Status';
    private const APP_ID = 'nextcloud_vacation';
    private const DEFAULT_ADMIN_GROUPS = 'calendar-managers';
    private const DEFAULT_VACATION_ENTITLEMENT = 30;
    private const DEFAULT_CARRYOVER_EXPIRES = '03-31';
    private const DEFAULT_VACATION_KEYWORDS = 'Urlaub, Vacation';
    private const MAX_BALANCE_DAYS_HUNDREDTHS = 36600;
    private ?array $vacationKeywordCache = null;

    public function __construct(
        private IDBConnection $db,
        private ICalendarManager $calendarManager,
        private IConfig $config,
        private IGroupManager $groupManager,
        private IUserManager $userManager,
        private SpecialLeaveService $specialLeaveService
    ) {
    }

    public function isCalendarAdmin(string $userId): bool
    {
        if ($userId === 'admin') {
            return true;
        }

        foreach ($this->adminGroups() as $groupId) {
            if ($this->groupManager->isInGroup($userId, $groupId)) {
                return true;
            }
        }

        return false;
    }

    public function isStaffUser(string $userId): bool
    {
        return $this->groupManager->isInGroup($userId, $this->staffGroup());
    }

    public function vacationKeywords(): string
    {
        return implode(', ', $this->vacationKeywordList());
    }

    public function reportForUser(string $userId, int $year, bool $debug = false, bool $apiDebug = false, bool $includeBookedBalance = true): array
    {
        return $this->reportForUsers([$userId], $year, $debug, true, $apiDebug, $includeBookedBalance);
    }

    public function reportForStaff(int $year, bool $debug = false, bool $apiDebug = false, bool $includeBookedBalance = true): array
    {
        $group = $this->groupManager->get($this->staffGroup());
        if ($group === null) {
            return [];
        }

        $userIds = [];
        foreach ($group->getUsers() as $user) {
            $userIds[] = $user->getUID();
        }

        sort($userIds, SORT_NATURAL | SORT_FLAG_CASE);

        return $this->reportForUsers($userIds, $year, $debug, true, $apiDebug, $includeBookedBalance);
    }

    private function reportForUsers(
        array $userIds,
        int $year,
        bool $debug,
        bool $includeAutomaticCarryover = true,
        bool $apiDebug = false,
        bool $includeBookedBalance = true
    ): array
    {
        $timezone = new DateTimeZone(date_default_timezone_get());
        $from = new DateTimeImmutable($year . '-01-01', $timezone);
        $to = new DateTimeImmutable(($year + 1) . '-01-01', $timezone);
        $globalEntitlement = $this->vacationEntitlement();
        $personalEntitlements = $this->personalEntitlementsForYear($year);
        $specialLeavesByUser = $this->specialLeaveService->entriesByUserForYear($year);
        $carryovers = $this->carryoversForYear($year);
        $automaticCarryovers = $includeAutomaticCarryover
            ? $this->automaticCarryoversForYear($userIds, $year, $carryovers)
            : [];
        $carryoverAvailable = $this->carryoverAvailable($year, $timezone);
        $report = [];

        foreach ($userIds as $userId) {
            $calendar = $this->findReportCalendar($userId);
            $dayValues = [];
            $events = 0;
            $matchedEvents = [];
            $apiDebugSamples = [];
            $daySources = [];
            $sourceDayValues = [];
            $sourceDayLastModified = [];
            $displayName = $this->displayName($userId);

            if ($calendar !== null) {
                foreach ($this->calendarObjectData($userId, $calendar, $from, $to, $apiDebug) as $calendarObject) {
                    if ($apiDebug && isset($calendarObject['apiDebug']) && count($apiDebugSamples) < 5) {
                        $apiDebugSamples[] = $calendarObject['apiDebug'];
                    }

                    $calendarData = $calendarObject['calendardata'];
                    $isDeleted = $calendarObject['isDeleted'];
                    $lastModified = (int)$calendarObject['lastModified'];
                    if ($calendarData === '') {
                        foreach ($calendarObject['events'] as $event) {
                            if (!$this->apiEventMatches($event)) {
                                continue;
                            }

                            $eventDays = $this->apiEventDays($event, $from, $to, $timezone);
                            $eventValue = $this->apiEventVacationValue($event);
                            $events++;
                            $sourceKey = $this->apiEventSourceKey($event);
                            foreach ($eventDays as $day) {
                                $currentValue = $dayValues[$day] ?? null;
                                if ($currentValue === null || $eventValue > $currentValue) {
                                    $dayValues[$day] = $eventValue;
                                }
                                $daySources[$day][$sourceKey] = true;
                                $sourceDayValues[$sourceKey][$day] = max(
                                    (float)($sourceDayValues[$sourceKey][$day] ?? 0.0),
                                    $eventValue
                                );
                                $sourceDayLastModified[$sourceKey][$day] = max(
                                    (int)($sourceDayLastModified[$sourceKey][$day] ?? 0),
                                    $lastModified
                                );
                            }
                            if ($debug) {
                                $matchedEvents[] = $this->debugApiEvent($event, $eventDays, $timezone, $lastModified);
                            }
                        }

                        continue;
                    }

                    try {
                        $vcalendar = Reader::read($calendarData);
                    } catch (Throwable) {
                        continue;
                    }

                    if (!$vcalendar instanceof VCalendar) {
                        continue;
                    }

                    $expanded = $vcalendar->expand($from, $to);
                    foreach ($expanded->select('VEVENT') as $event) {
                        if (!$this->eventMatches($event)) {
                            continue;
                        }

                        $eventDays = $this->eventDays($event, $from, $to, $timezone);
                        $eventValue = $this->eventVacationValue($event);
                        if ($isDeleted) {
                            if ($debug) {
                                $matchedEvents[] = $this->debugEvent($event, $eventDays, $timezone, true, (int)$calendarObject['deletedAt'], $lastModified);
                            }
                            continue;
                        }

                        $events++;
                        $sourceKey = $this->eventSourceKey($event);
                        foreach ($eventDays as $day) {
                            $currentValue = $dayValues[$day] ?? null;
                            if ($currentValue === null || $eventValue > $currentValue) {
                                $dayValues[$day] = $eventValue;
                            }
                            $daySources[$day][$sourceKey] = true;
                            $sourceDayValues[$sourceKey][$day] = max(
                                (float)($sourceDayValues[$sourceKey][$day] ?? 0.0),
                                $eventValue
                            );
                            $sourceDayLastModified[$sourceKey][$day] = max(
                                (int)($sourceDayLastModified[$sourceKey][$day] ?? 0),
                                $lastModified
                            );
                        }
                        if ($debug) {
                            $matchedEvents[] = $this->debugEvent($event, $eventDays, $timezone, false, 0, $lastModified);
                        }
                    }
                }
            }

            ksort($dayValues);
            $calendarDayValues = $dayValues;
            $dayRanges = $this->dayRanges($sourceDayValues, $sourceDayLastModified, $daySources);
            if ($includeBookedBalance) {
                foreach ($this->bookedDayValues($userId, $year) as $day => $value) {
                    $dayValues[$day] = max((float)($dayValues[$day] ?? 0.0), (float)$value);
                }
                ksort($dayValues);
            }
            $dayList = array_keys($dayValues);
            $personalEntitlement = isset($personalEntitlements[$userId])
                ? $this->hundredthsToFloat($personalEntitlements[$userId])
                : null;
            $baseEntitlement = $personalEntitlement ?? $globalEntitlement;
            $specialLeaveEntries = $specialLeavesByUser[$userId] ?? [];
            $specialLeave = array_sum(array_map(
                static fn (array $entry): float => (float)$entry['amount'],
                $specialLeaveEntries
            ));
            $carryoverSource = 'none';
            $carryoverHundredths = 0;
            if (isset($carryovers[$userId])) {
                $carryoverHundredths = $carryovers[$userId];
                $carryoverSource = 'manual';
            } elseif (isset($automaticCarryovers[$userId])) {
                $carryoverHundredths = $automaticCarryovers[$userId];
                $carryoverSource = 'automatic';
            }

            $carryover = $this->hundredthsToFloat($carryoverHundredths);
            $carryoverExpiresAt = $this->carryoverExpiresAt($year, $timezone);
            $carryoverCutoff = $carryoverExpiresAt->format('Y-m-d');
            $vacationThroughCarryoverExpiry = array_sum(array_filter(
                $dayValues,
                static fn (mixed $value, string $day): bool => $day <= $carryoverCutoff,
                ARRAY_FILTER_USE_BOTH
            ));
            $usedCarryover = min($carryover, (float)$vacationThroughCarryoverExpiry);
            $effectiveCarryover = $carryoverAvailable ? $carryover : $usedCarryover;
            $expiredCarryover = $carryoverAvailable ? 0.0 : max(0.0, $carryover - $usedCarryover);
            $entitlement = $baseEntitlement + $effectiveCarryover + $specialLeave;
            $vacationDays = array_sum($dayValues);

            $report[] = [
                'userId' => $userId,
                'displayName' => $displayName,
                'calendarName' => $calendar['displayname'] ?? '',
                'events' => $events,
                'entitlement' => $entitlement,
                'globalEntitlement' => $globalEntitlement,
                'baseEntitlement' => $baseEntitlement,
                'personalEntitlement' => $personalEntitlement,
                'specialLeave' => $specialLeave,
                'specialLeaveEntries' => $specialLeaveEntries,
                'carryover' => $carryover,
                'effectiveCarryover' => $effectiveCarryover,
                'usedCarryover' => $usedCarryover,
                'expiredCarryover' => $expiredCarryover,
                'carryoverSource' => $carryoverSource,
                'carryoverExpiresAt' => $carryoverCutoff,
                'carryoverAvailable' => $carryoverAvailable,
                'vacationDays' => $vacationDays,
                'remainingDays' => $entitlement - $vacationDays,
                'days' => $dayList,
                'dayValues' => $dayValues,
                'calendarDayValues' => $calendarDayValues,
                'dayRanges' => $dayRanges,
                'hasCalendar' => $calendar !== null,
                'calendarSource' => $calendar['source'] ?? '',
                'matchedEvents' => $matchedEvents,
                'apiDebugSamples' => $apiDebugSamples,
            ];
        }

        return $report;
    }

    private function displayName(string $userId): string
    {
        $user = $this->userManager->get($userId);
        if ($user === null) {
            return $userId;
        }

        return $user->getDisplayName() ?: $userId;
    }

    private function findReportCalendar(string $userId): ?array
    {
        $calendarUri = mb_strtolower($this->calendarUri());
        $calendarDisplayName = mb_strtolower($this->calendarDisplayName());

        foreach ($this->calendarsForUser($userId) as $calendar) {
            if (
                mb_strtolower((string)$calendar['uri']) === $calendarUri
                || mb_strtolower((string)$calendar['displayname']) === $calendarDisplayName
            ) {
                $calendar['source'] = $userId . '/' . $calendar['uri'];
                return $calendar;
            }
        }

        return null;
    }


    public function saveVacationBalanceSettings(
        string $userId,
        int $year,
        string $entitlement,
        string $carryover,
        string $updatedBy
    ): void
    {
        $userId = trim($userId);
        $updatedBy = trim($updatedBy);
        if (
            $year < 2000
            || $year > 2100
            || $userId === ''
            || $this->userManager->get($userId) === null
            || !$this->isStaffUser($userId)
            || !$this->isCalendarAdmin($updatedBy)
        ) {
            throw new InvalidArgumentException('Invalid employee, year or calendar administrator.');
        }

        $entitlement = trim($entitlement);
        $entitlementHundredths = $entitlement === ''
            ? null
            : $this->parseDayAmount($entitlement, 0, self::MAX_BALANCE_DAYS_HUNDREDTHS, 'entitlement');
        $carryoverHundredths = $this->parseDayAmount(
            $carryover,
            -self::MAX_BALANCE_DAYS_HUNDREDTHS,
            self::MAX_BALANCE_DAYS_HUNDREDTHS,
            'carryover'
        );
        $now = time();

        $this->db->beginTransaction();
        try {
            $this->persistPersonalEntitlement($userId, $year, $entitlementHundredths, $updatedBy, $now);
            $this->persistCarryover($userId, $year, $carryoverHundredths, $updatedBy, $now);
            $this->db->commit();
        } catch (Throwable $exception) {
            $this->db->rollBack();
            throw $exception;
        }
    }

    private function persistCarryover(
        string $userId,
        int $year,
        int $amountHundredths,
        string $updatedBy,
        int $now
    ): void
    {
        $existing = $this->carryoverForUserYear($userId, $year);
        $qb = $this->db->getQueryBuilder();

        if ($existing === null) {
            $qb->insert('vacation_carryovers')
                ->values([
                    'user_id' => $qb->createNamedParameter($userId),
                    'year' => $qb->createNamedParameter($year, IQueryBuilder::PARAM_INT),
                    'amount_hundredths' => $qb->createNamedParameter($amountHundredths, IQueryBuilder::PARAM_INT),
                    'updated_by' => $qb->createNamedParameter($updatedBy),
                    'updated_at' => $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT),
                ]);
        } else {
            $qb->update('vacation_carryovers')
                ->set('amount_hundredths', $qb->createNamedParameter($amountHundredths, IQueryBuilder::PARAM_INT))
                ->set('updated_by', $qb->createNamedParameter($updatedBy))
                ->set('updated_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT))
                ->where($qb->expr()->eq('id', $qb->createNamedParameter((int)$existing['id'], IQueryBuilder::PARAM_INT)));
        }

        $qb->executeStatement();
    }

    private function persistPersonalEntitlement(
        string $userId,
        int $year,
        ?int $amountHundredths,
        string $updatedBy,
        int $now
    ): void
    {
        $existing = $this->personalEntitlementForUserYear($userId, $year);
        $qb = $this->db->getQueryBuilder();

        if ($amountHundredths === null) {
            if ($existing !== null) {
                $qb->delete('vacation_entitlements')
                    ->where($qb->expr()->eq('id', $qb->createNamedParameter((int)$existing['id'], IQueryBuilder::PARAM_INT)));
                $qb->executeStatement();
            }

            return;
        }

        if ($existing === null) {
            $qb->insert('vacation_entitlements')
                ->values([
                    'user_id' => $qb->createNamedParameter($userId),
                    'year' => $qb->createNamedParameter($year, IQueryBuilder::PARAM_INT),
                    'amount_hundredths' => $qb->createNamedParameter($amountHundredths, IQueryBuilder::PARAM_INT),
                    'updated_by' => $qb->createNamedParameter($updatedBy),
                    'updated_at' => $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT),
                ]);
        } else {
            $qb->update('vacation_entitlements')
                ->set('amount_hundredths', $qb->createNamedParameter($amountHundredths, IQueryBuilder::PARAM_INT))
                ->set('updated_by', $qb->createNamedParameter($updatedBy))
                ->set('updated_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT))
                ->where($qb->expr()->eq('id', $qb->createNamedParameter((int)$existing['id'], IQueryBuilder::PARAM_INT)));
        }

        $qb->executeStatement();
    }

    private function carryoversForYear(int $year): array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('user_id', 'amount_hundredths')
            ->from('vacation_carryovers')
            ->where($qb->expr()->eq('year', $qb->createNamedParameter($year, IQueryBuilder::PARAM_INT)));

        $result = $qb->executeQuery();
        try {
            $carryovers = [];
            while (($row = $result->fetch()) !== false) {
                $carryovers[(string)$row['user_id']] = (int)$row['amount_hundredths'];
            }

            return $carryovers;
        } finally {
            $result->closeCursor();
        }
    }

    private function carryoverForUserYear(string $userId, int $year): ?array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from('vacation_carryovers')
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
            ->andWhere($qb->expr()->eq('year', $qb->createNamedParameter($year, IQueryBuilder::PARAM_INT)))
            ->setMaxResults(1);

        $result = $qb->executeQuery();
        try {
            $row = $result->fetch();
            return $row === false ? null : $row;
        } finally {
            $result->closeCursor();
        }
    }

    private function personalEntitlementsForYear(int $year): array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('user_id', 'amount_hundredths')
            ->from('vacation_entitlements')
            ->where($qb->expr()->eq('year', $qb->createNamedParameter($year, IQueryBuilder::PARAM_INT)));

        $result = $qb->executeQuery();
        try {
            $entitlements = [];
            while (($row = $result->fetch()) !== false) {
                $entitlements[(string)$row['user_id']] = (int)$row['amount_hundredths'];
            }

            return $entitlements;
        } finally {
            $result->closeCursor();
        }
    }

    private function personalEntitlementForUserYear(string $userId, int $year): ?array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from('vacation_entitlements')
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
            ->andWhere($qb->expr()->eq('year', $qb->createNamedParameter($year, IQueryBuilder::PARAM_INT)))
            ->setMaxResults(1);

        $result = $qb->executeQuery();
        try {
            $row = $result->fetch();
            return $row === false ? null : $row;
        } finally {
            $result->closeCursor();
        }
    }

    private function automaticCarryoversForYear(array $userIds, int $year, array $manualCarryovers): array
    {
        $missingUserIds = array_values(array_filter($userIds, static fn (string $userId): bool => !isset($manualCarryovers[$userId])));
        if (count($missingUserIds) === 0) {
            return [];
        }

        $previousReport = $this->reportForUsers($missingUserIds, $year - 1, false, false, false, true);
        $carryovers = [];
        foreach ($previousReport as $row) {
            if ((float)$row['vacationDays'] <= 0.0) {
                continue;
            }

            // Consume special leave before annual entitlement so it never becomes carryover.
            $vacationChargedToBase = max(
                0.0,
                (float)$row['vacationDays']
                    - (float)$row['effectiveCarryover']
                    - max(0.0, (float)($row['specialLeave'] ?? 0.0))
            );
            $baseAvailableForCarryover = max(
                0.0,
                (float)$row['baseEntitlement'] + min(0.0, (float)($row['specialLeave'] ?? 0.0))
            );
            $remainingHundredths = (int)round(max(0.0, $baseAvailableForCarryover - $vacationChargedToBase) * 100);
            if ($remainingHundredths > 0) {
                $carryovers[(string)$row['userId']] = $remainingHundredths;
            }
        }

        return $carryovers;
    }

    private function carryoverAvailable(int $year, DateTimeZone $timezone): bool
    {
        $today = new DateTimeImmutable('today', $timezone);
        return $today <= $this->carryoverExpiresAt($year, $timezone);
    }

    private function bookedDayValues(string $userId, int $year): array
    {
        $qb = $this->db->getQueryBuilder();
        $bookedStatus = $qb->expr()->orX(
            $qb->expr()->eq('status', $qb->createNamedParameter('approved')),
            $qb->expr()->eq('status', $qb->createNamedParameter('changed_after_approval')),
            $qb->expr()->eq('status', $qb->createNamedParameter('cancellation_pending')),
            $qb->expr()->eq('status', $qb->createNamedParameter('approved_missing'))
        );
        $qb->select('day_list_json')
            ->from('vacation_requests')
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
            ->andWhere($qb->expr()->eq('year', $qb->createNamedParameter($year, IQueryBuilder::PARAM_INT)))
            ->andWhere($bookedStatus);

        $result = $qb->executeQuery();
        try {
            $days = [];
            while (($json = $result->fetchOne()) !== false) {
                $values = json_decode((string)$json, true);
                if (!is_array($values)) {
                    continue;
                }
                foreach ($values as $day => $value) {
                    if (!is_string($day) || !is_numeric($value)) {
                        continue;
                    }
                    $days[$day] = max((float)($days[$day] ?? 0.0), (float)$value);
                }
            }
            return $days;
        } finally {
            $result->closeCursor();
        }
    }

    private function carryoverExpiresAt(int $year, DateTimeZone $timezone): DateTimeImmutable
    {
        $monthDay = $this->carryoverExpiresMonthDay();
        return new DateTimeImmutable($year . '-' . $monthDay . ' 23:59:59', $timezone);
    }

    private function parseDayAmount(string $amount, int $minimum, int $maximum, string $field): int
    {
        $normalized = str_replace(',', '.', trim($amount));
        if (preg_match('/^-?\d+(?:\.\d{1,2})?$/', $normalized) !== 1) {
            throw new InvalidArgumentException('Invalid ' . $field . ' amount.');
        }

        $hundredths = (int)round(((float)$normalized) * 100);
        if ($hundredths < $minimum || $hundredths > $maximum) {
            throw new InvalidArgumentException(ucfirst($field) . ' amount is outside the supported range.');
        }

        return $hundredths;
    }

    private function hundredthsToFloat(int $value): float
    {
        return $value / 100;
    }
    public function adminGroups(): array
    {
        $raw = $this->config->getAppValue(self::APP_ID, 'admin_groups', self::DEFAULT_ADMIN_GROUPS);
        return $this->csvConfig($raw);
    }

    public function staffGroup(): string
    {
        return $this->config->getAppValue(self::APP_ID, 'staff_group', self::STAFF_GROUP);
    }

    public function calendarUri(): string
    {
        return $this->config->getAppValue(self::APP_ID, 'calendar_uri', self::CALENDAR_URI);
    }

    public function calendarDisplayName(): string
    {
        return $this->config->getAppValue(self::APP_ID, 'calendar_displayname', self::CALENDAR_DISPLAYNAME);
    }

    public function vacationEntitlement(): int
    {
        return max(
            0,
            (int)$this->config->getAppValue(
                self::APP_ID,
                'vacation_entitlement',
                (string)self::DEFAULT_VACATION_ENTITLEMENT
            )
        );
    }

    public function carryoverExpiresMonthDay(): string
    {
        $raw = trim($this->config->getAppValue(self::APP_ID, 'carryover_expires', self::DEFAULT_CARRYOVER_EXPIRES));
        return self::isValidCarryoverMonthDay($raw) ? $raw : self::DEFAULT_CARRYOVER_EXPIRES;
    }

    public static function isValidCarryoverMonthDay(string $value): bool
    {
        if (preg_match('/^(\d{2})-(\d{2})$/', trim($value), $matches) !== 1) {
            return false;
        }

        return checkdate((int)$matches[1], (int)$matches[2], 2001);
    }

    private function csvConfig(string $raw): array
    {
        $values = array_map('trim', explode(',', $raw));
        return array_values(array_filter(array_unique($values), static fn (string $value): bool => $value !== ''));
    }

    private function vacationKeywordList(): array
    {
        if ($this->vacationKeywordCache !== null) {
            return $this->vacationKeywordCache;
        }

        $raw = $this->config->getAppValue(self::APP_ID, 'vacation_keywords', self::DEFAULT_VACATION_KEYWORDS);
        $keywords = [];
        $seen = [];

        foreach (array_slice(explode(',', $raw), 0, 20) as $keyword) {
            $keyword = trim($keyword);
            if ($keyword === '' || mb_strlen($keyword) > 80 || preg_match('/[\x00-\x1F\x7F]/u', $keyword) === 1) {
                continue;
            }

            $normalized = mb_strtolower($keyword);
            if (isset($seen[$normalized])) {
                continue;
            }

            $seen[$normalized] = true;
            $keywords[] = $keyword;
        }

        $this->vacationKeywordCache = count($keywords) > 0
            ? $keywords
            : array_map('trim', explode(',', self::DEFAULT_VACATION_KEYWORDS));

        return $this->vacationKeywordCache;
    }

    private function vacationPattern(): string
    {
        return '/(?:' . $this->vacationKeywordAlternation() . ')/iu';
    }

    private function halfDayPattern(): string
    {
        return '/(?:^|[^0-9])(?:0[,.]5|1\s*\/\s*2)\s*d?\s*(?:' . $this->vacationKeywordAlternation() . ')/iu';
    }

    private function vacationKeywordAlternation(): string
    {
        return implode('|', array_map(
            static fn (string $keyword): string => preg_quote($keyword, '/'),
            $this->vacationKeywordList()
        ));
    }

    private function calendarsForUser(string $userId): array
    {
        $calendars = [];
        foreach ($this->calendarManager->getCalendarsForPrincipal($this->principalForUser($userId)) as $calendar) {
            $uri = $this->calendarUriFromApiResult($calendar);
            if ($uri === '') {
                continue;
            }

            $calendars[] = [
                'displayname' => $this->calendarDisplayNameFromApiResult($calendar),
                'uri' => $uri,
            ];
        }

        return $calendars;
    }

    private function calendarObjectData(
        string $userId,
        array $calendar,
        DateTimeImmutable $from,
        DateTimeImmutable $to,
        bool $apiDebug
    ): array
    {
        $query = $this->calendarManager->newQuery($this->principalForUser($userId));
        $query->addSearchCalendar((string)$calendar['uri']);
        $query->setTimerangeStart($from);
        $query->setTimerangeEnd($to);

        $objects = [];
        foreach ($this->calendarManager->searchForPrincipal($query) as $object) {
            $calendarData = $this->calendarDataFromApiResult($object);
            $apiEvents = $calendarData === '' ? $this->apiEventsFromApiResult($object) : [];
            if ($calendarData === '' && count($apiEvents) === 0 && !$apiDebug) {
                continue;
            }

            $objects[] = [
                'calendardata' => $calendarData,
                'events' => $apiEvents,
                'apiDebug' => $apiDebug ? $this->apiDebugSampleFromApiResult($object) : [],
                'isDeleted' => false,
                'deletedAt' => 0,
                'lastModified' => $this->lastModifiedFromApiResult($object),
            ];
        }

        return $objects;
    }

    private function principalForUser(string $userId): string
    {
        return 'principals/users/' . $userId;
    }

    private function calendarUriFromApiResult(mixed $calendar): string
    {
        if (is_array($calendar)) {
            return (string)($calendar['uri'] ?? $calendar['key'] ?? '');
        }

        if (is_object($calendar)) {
            if (method_exists($calendar, 'getUri')) {
                return (string)$calendar->getUri();
            }

            if (method_exists($calendar, 'getKey')) {
                return (string)$calendar->getKey();
            }
        }

        return '';
    }

    private function apiDebugSampleFromApiResult(mixed $object): array
    {
        if (!is_array($object)) {
            return [
                'type' => get_debug_type($object),
                'keys' => [],
                'hasCalendarData' => false,
                'objectCount' => 0,
                'firstObject' => [],
            ];
        }

        $firstObject = [];
        if (isset($object['objects']) && is_array($object['objects']) && isset($object['objects'][0]) && is_array($object['objects'][0])) {
            foreach ($object['objects'][0] as $key => $value) {
                $firstObject[(string)$key] = $this->summarizeApiDebugValue($value);
            }
        }

        return [
            'type' => 'array',
            'keys' => array_keys($object),
            'hasCalendarData' => isset($object['calendardata']) || isset($object['calendarData']),
            'objectCount' => isset($object['objects']) && is_array($object['objects']) ? count($object['objects']) : 0,
            'firstObject' => $firstObject,
        ];
    }

    private function summarizeApiDebugValue(mixed $value): string
    {
        if (is_array($value)) {
            $first = $value[0] ?? null;
            $parameters = $value[1] ?? [];
            if (is_array($first)) {
                return 'list[' . count($value) . ']';
            }

            $suffix = is_array($parameters) && count($parameters) > 0
                ? ' ' . json_encode($parameters, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
                : '';

            return $this->apiScalarText($first) . $suffix;
        }

        return $this->apiScalarText($value);
    }

    private function apiEventsFromApiResult(mixed $object): array
    {
        if (!is_array($object)) {
            return [];
        }

        $events = [];
        if (isset($object['objects']) && is_array($object['objects'])) {
            foreach ($object['objects'] as $event) {
                if (is_array($event)) {
                    $events[] = $event + [
                        '_calendar_result_id' => $object['id'] ?? '',
                        '_calendar_result_type' => $object['type'] ?? '',
                    ];
                }
            }

            return $events;
        }

        return isset($object['DTSTART']) ? [$object] : [];
    }

    private function calendarDisplayNameFromApiResult(mixed $calendar): string
    {
        if (is_array($calendar)) {
            return (string)($calendar['displayname'] ?? $calendar['displayName'] ?? $calendar['name'] ?? '');
        }

        if (is_object($calendar) && method_exists($calendar, 'getDisplayName')) {
            return (string)($calendar->getDisplayName() ?? '');
        }

        return '';
    }

    private function calendarDataFromApiResult(mixed $object): string
    {
        if (is_array($object)) {
            $calendarData = $object['calendardata'] ?? $object['calendarData'] ?? '';
            if (is_resource($calendarData)) {
                return (string)stream_get_contents($calendarData);
            }

            return (string)$calendarData;
        }

        if (is_object($object)) {
            foreach (['getCalendarData', 'getData', 'getIcs'] as $method) {
                if (method_exists($object, $method)) {
                    return (string)$object->$method();
                }
            }
        }

        return '';
    }

    private function lastModifiedFromApiResult(mixed $object): int
    {
        if (is_array($object)) {
            $lastModified = isset($object['lastmodified'])
                ? (int)$object['lastmodified']
                : (int)($object['lastModified'] ?? 0);

            foreach ($this->apiEventsFromApiResult($object) as $event) {
                $eventLastModified = $this->apiEventDateTime($event, 'LAST-MODIFIED', new DateTimeZone('UTC'))
                    ?? $this->apiEventDateTime($event, 'DTSTAMP', new DateTimeZone('UTC'));
                if ($eventLastModified !== null) {
                    $lastModified = max($lastModified, $eventLastModified->getTimestamp());
                }
            }

            return $lastModified;
        }

        if (is_object($object) && method_exists($object, 'getLastModified')) {
            return (int)$object->getLastModified();
        }

        return 0;
    }

    private function eventMatches(object $event): bool
    {
        $fields = [
            (string)($event->SUMMARY ?? ''),
            (string)($event->DESCRIPTION ?? ''),
            (string)($event->LOCATION ?? ''),
            (string)($event->CATEGORIES ?? ''),
        ];

        return preg_match($this->vacationPattern(), implode("\n", $fields)) === 1;
    }

    private function apiEventMatches(array $event): bool
    {
        $fields = [
            $this->apiEventText($event, 'SUMMARY'),
            $this->apiEventText($event, 'DESCRIPTION'),
            $this->apiEventText($event, 'LOCATION'),
            $this->apiEventText($event, 'CATEGORIES'),
        ];

        return preg_match($this->vacationPattern(), implode("\n", $fields)) === 1;
    }


    private function eventVacationValue(object $event): float
    {
        $summary = (string)($event->SUMMARY ?? '');
        if (preg_match($this->halfDayPattern(), $summary) === 1) {
            return 0.5;
        }

        return 1.0;
    }

    private function apiEventVacationValue(array $event): float
    {
        if (preg_match($this->halfDayPattern(), $this->apiEventText($event, 'SUMMARY')) === 1) {
            return 0.5;
        }

        return 1.0;
    }

    private function eventSourceKey(object $event): string
    {
        $uid = trim((string)($event->UID ?? ''));
        $start = isset($event->DTSTART) ? $event->DTSTART->getDateTime()->format('Ymd\THis') : '';

        return hash('sha256', $uid !== '' ? $uid : $start);
    }

    private function apiEventSourceKey(array $event): string
    {
        $uid = trim($this->apiEventText($event, 'UID'));
        $start = $this->apiEventRawValue($event, 'DTSTART');

        return hash('sha256', $uid !== '' ? $uid : $start);
    }

    private function eventDays(
        object $event,
        DateTimeImmutable $from,
        DateTimeImmutable $to,
        DateTimeZone $timezone
    ): array {
        $start = DateTimeImmutable::createFromInterface($event->DTSTART->getDateTime())->setTimezone($timezone);
        $end = isset($event->DTEND)
            ? DateTimeImmutable::createFromInterface($event->DTEND->getDateTime())->setTimezone($timezone)
            : $start->modify('+1 day');

        if ($end <= $start) {
            $end = $start->modify('+1 day');
        }

        $rangeStart = $start > $from ? $start : $from;
        $rangeEnd = $end < $to ? $end : $to;
        $cursor = $rangeStart->setTime(0, 0);
        $lastDay = $rangeEnd->modify('-1 second')->setTime(0, 0);
        $days = [];

        while ($cursor <= $lastDay) {
            if ((int)$cursor->format('N') <= 5) {
                $days[] = $cursor->format('Y-m-d');
            }
            $cursor = $cursor->modify('+1 day');
        }

        return $days;
    }

    private function apiEventDays(
        array $event,
        DateTimeImmutable $from,
        DateTimeImmutable $to,
        DateTimeZone $timezone
    ): array {
        $start = $this->apiEventDateTime($event, 'DTSTART', $timezone);
        if ($start === null) {
            return [];
        }

        $end = $this->apiEventDateTime($event, 'DTEND', $timezone);
        if ($end === null && $this->apiEventRawValue($event, 'DURATION') !== '') {
            try {
                $end = $start->add(new \DateInterval($this->apiEventRawValue($event, 'DURATION')));
            } catch (Throwable) {
                $end = null;
            }
        }
        $end ??= $start->modify('+1 day');

        if ($end <= $start) {
            $end = $start->modify('+1 day');
        }

        $rangeStart = $start > $from ? $start : $from;
        $rangeEnd = $end < $to ? $end : $to;
        $cursor = $rangeStart->setTime(0, 0);
        $lastDay = $rangeEnd->modify('-1 second')->setTime(0, 0);
        $days = [];

        while ($cursor <= $lastDay) {
            if ((int)$cursor->format('N') <= 5) {
                $days[] = $cursor->format('Y-m-d');
            }
            $cursor = $cursor->modify('+1 day');
        }

        return $days;
    }

    private function apiEventDateTime(array $event, string $field, DateTimeZone $fallbackTimezone): ?DateTimeImmutable
    {
        $value = $this->apiEventValue($event, $field);
        if ($value instanceof \DateTimeInterface) {
            return DateTimeImmutable::createFromInterface($value)->setTimezone($fallbackTimezone);
        }

        $raw = trim($this->apiScalarText($value));
        if ($raw === '') {
            return null;
        }

        $parameters = $this->apiEventParameters($event, $field);
        $timezone = $fallbackTimezone;
        if (isset($parameters['TZID']) && (string)$parameters['TZID'] !== '') {
            try {
                $timezone = new DateTimeZone((string)$parameters['TZID']);
            } catch (Throwable) {
                $timezone = $fallbackTimezone;
            }
        } elseif (str_ends_with($raw, 'Z')) {
            $timezone = new DateTimeZone('UTC');
        }

        $normalized = rtrim($raw, 'Z');
        $format = preg_match('/^\d{8}$/', $normalized) === 1 ? '!Ymd' : '!Ymd\THis';
        $date = DateTimeImmutable::createFromFormat($format, $normalized, $timezone);
        if ($date === false) {
            try {
                $date = new DateTimeImmutable($raw, $timezone);
            } catch (Throwable) {
                return null;
            }
        }

        return $date->setTimezone($fallbackTimezone);
    }

    private function apiEventText(array $event, string $field): string
    {
        $value = $event[$field] ?? '';
        if (is_array($value)) {
            $first = $value[0] ?? '';
            if (is_array($first)) {
                $texts = [];
                foreach ($value as $entry) {
                    if (is_array($entry)) {
                        $texts[] = $this->apiScalarText($entry[0] ?? '');
                    }
                }

                return implode("\n", $texts);
            }

            return $this->apiScalarText($first);
        }

        return $this->apiScalarText($value);
    }

    private function apiEventValue(array $event, string $field): mixed
    {
        $value = $event[$field] ?? null;

        return is_array($value) ? ($value[0] ?? null) : $value;
    }

    private function apiScalarText(mixed $value): string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Ymd\THisP');
        }

        if ($value instanceof \DateInterval) {
            return $value->format('P%yY%mM%dDT%hH%iM%sS');
        }

        if (is_scalar($value) || $value === null) {
            return (string)$value;
        }

        if ($value instanceof \Stringable) {
            return (string)$value;
        }

        return get_debug_type($value);
    }

    private function apiEventRawValue(array $event, string $field): string
    {
        return trim($this->apiEventText($event, $field));
    }

    private function apiEventParameters(array $event, string $field): array
    {
        $value = $event[$field] ?? null;
        if (is_array($value) && isset($value[1]) && is_array($value[1])) {
            return $value[1];
        }

        return [];
    }

    private function debugEvent(object $event, array $days, DateTimeZone $timezone, bool $isDeleted, int $deletedAt, int $lastModified): array
    {
        $start = DateTimeImmutable::createFromInterface($event->DTSTART->getDateTime())->setTimezone($timezone);
        $end = isset($event->DTEND)
            ? DateTimeImmutable::createFromInterface($event->DTEND->getDateTime())->setTimezone($timezone)
            : $start->modify('+1 day');

        return [
            'summary' => (string)($event->SUMMARY ?? ''),
            'uid' => (string)($event->UID ?? ''),
            'start' => $start->format('Y-m-d H:i'),
            'end' => $end->format('Y-m-d H:i'),
            'days' => $days,
            'isDeleted' => $isDeleted,
            'deletedAt' => $deletedAt,
            'lastModified' => $lastModified,
        ];
    }

    private function debugApiEvent(array $event, array $days, DateTimeZone $timezone, int $lastModified): array
    {
        $start = $this->apiEventDateTime($event, 'DTSTART', $timezone);
        $end = $this->apiEventDateTime($event, 'DTEND', $timezone);
        if ($start !== null && $end === null) {
            $end = $start->modify('+1 day');
        }

        return [
            'summary' => $this->apiEventText($event, 'SUMMARY'),
            'uid' => $this->apiEventText($event, 'UID'),
            'start' => $start?->format('Y-m-d H:i') ?? '',
            'end' => $end?->format('Y-m-d H:i') ?? '',
            'days' => $days,
            'isDeleted' => false,
            'deletedAt' => 0,
            'lastModified' => $lastModified,
        ];
    }

    private function rangeLastModified(array $dayLastModified, string $start, string $end): int
    {
        $timestamps = [];
        foreach ($dayLastModified as $day => $lastModified) {
            if ($day >= $start && $day <= $end && (int)$lastModified > 0) {
                $timestamps[] = (int)$lastModified;
            }
        }

        return count($timestamps) === 0 ? 0 : max($timestamps);
    }

    private function dayRanges(array $sourceDayValues, array $sourceDayLastModified, array $daySources): array
    {
        $ranges = [];
        foreach ($sourceDayValues as $sourceKey => $values) {
            if (!is_array($values) || count($values) === 0) {
                continue;
            }

            ksort($values);
            $days = array_keys($values);
            $rangeStart = $days[0];
            $previous = new DateTimeImmutable($days[0]);

            foreach (array_slice($days, 1) as $day) {
                $current = new DateTimeImmutable($day);
                $gap = (int)$previous->diff($current)->format('%a');

                if ($gap > 1 && !($previous->format('N') === '5' && $gap === 3)) {
                    $ranges[] = $this->sourceRange(
                        (string)$sourceKey,
                        $rangeStart,
                        $previous->format('Y-m-d'),
                        $values,
                        $sourceDayLastModified[(string)$sourceKey] ?? [],
                        $daySources
                    );
                    $rangeStart = $day;
                }

                $previous = $current;
            }

            $ranges[] = $this->sourceRange(
                (string)$sourceKey,
                $rangeStart,
                $previous->format('Y-m-d'),
                $values,
                $sourceDayLastModified[(string)$sourceKey] ?? [],
                $daySources
            );
        }

        usort($ranges, static fn (array $left, array $right): int => [
            $left['start'],
            $left['end'],
            $left['sourceKey'],
        ] <=> [
            $right['start'],
            $right['end'],
            $right['sourceKey'],
        ]);

        return $ranges;
    }

    private function sourceRange(
        string $sourceKey,
        string $start,
        string $end,
        array $sourceDayValues,
        array $sourceLastModified,
        array $daySources
    ): array {
        $values = array_filter(
            $sourceDayValues,
            static fn (mixed $value, string $day): bool => $day >= $start && $day <= $end,
            ARRAY_FILTER_USE_BOTH
        );
        ksort($values);

        $duplicateDays = [];
        $duplicateSources = [];
        $sourceSets = [];
        foreach (array_keys($values) as $day) {
            $sources = array_keys($daySources[$day] ?? []);
            sort($sources, SORT_STRING);
            $sourceSets[] = implode(',', $sources);
            if (count($sources) <= 1) {
                continue;
            }

            $duplicateDays[] = $day;
            foreach ($sources as $duplicateSource) {
                if ($duplicateSource !== $sourceKey) {
                    $duplicateSources[$duplicateSource] = true;
                }
            }
        }

        $sourceSets = array_values(array_unique($sourceSets));
        $legacyCompositeSourceKey = count($duplicateDays) === count($values)
            && count($sourceSets) === 1
            && str_contains($sourceSets[0], ',')
                ? hash('sha256', $sourceSets[0])
                : '';

        return [
            'start' => $start,
            'end' => $end,
            'sourceKey' => $sourceKey,
            'lastModified' => $this->rangeLastModified($sourceLastModified, $start, $end),
            'dayValues' => array_map('floatval', $values),
            'duplicateConflict' => count($duplicateDays) > 0,
            'duplicateDays' => $duplicateDays,
            'duplicateSourceKeys' => array_keys($duplicateSources),
            'legacyCompositeSourceKey' => $legacyCompositeSourceKey,
        ];
    }
}
