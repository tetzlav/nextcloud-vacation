<?php
$previousYear = (int)$_['year'] - 1;
$nextYear = (int)$_['year'] + 1;
$yearOptions = range($previousYear, $nextYear);
$approveUrlTemplate = $_['approveUrlTemplate'];
$approveOpenYearUrl = $_['approveOpenYearUrl'];
$rejectUrlTemplate = $_['rejectUrlTemplate'];
$confirmCancellationUrlTemplate = $_['confirmCancellationUrlTemplate'];
$keepBookingUrlTemplate = $_['keepBookingUrlTemplate'];
$carryoverSaveUrl = $_['carryoverSaveUrl'];
$specialLeaveUrl = $_['specialLeaveUrl'];
$requestToken = $_['requesttoken'] ?? '';
$activeActionId = max((int)$_['approvedId'], (int)$_['rejectedId'], (int)$_['cancellationId']);
$localeCode = method_exists($l, 'getLocaleCode') ? (string)$l->getLocaleCode() : 'en';
$formatDayAmount = static function (float $value) use ($localeCode): string {
    if (class_exists(\NumberFormatter::class)) {
        $formatter = new \NumberFormatter($localeCode, \NumberFormatter::DECIMAL);
        $formatter->setAttribute(\NumberFormatter::MIN_FRACTION_DIGITS, 0);
        $formatter->setAttribute(\NumberFormatter::MAX_FRACTION_DIGITS, 2);
        $formatted = $formatter->format($value);
        if ($formatted !== false) {
            return $formatted;
        }
    }

    $decimalSeparator = str_starts_with(strtolower($localeCode), 'de') ? ',' : '.';
    $formatted = number_format($value, 2, $decimalSeparator, '');
    return rtrim(rtrim($formatted, '0'), $decimalSeparator);
};
$dayUnit = static function (float $value) use ($l): string {
    return abs($value - 1.0) < 0.00001 ? $l->t('day') : $l->t('days');
};
$periodUnit = static function (int $count) use ($l): string {
    return $count === 1 ? $l->t('Period') : $l->t('Periods');
};
$instanceCount = static function (int $count) use ($l): string {
    return $count === 1 ? $l->t('%d instance', [$count]) : $l->t('%d instances', [$count]);
};
$formatDate = static function (string $day) use ($l): string {
    return $l->l('date', strtotime($day));
};
$displayTimeZone = new \DateTimeZone($_['timeZone'] ?? 'UTC');
$localDateTime = static function (int $timestamp) use ($displayTimeZone): \DateTimeImmutable {
    return (new \DateTimeImmutable('@' . $timestamp))->setTimezone($displayTimeZone);
};
$localizedDateFormatter = class_exists(\IntlDateFormatter::class)
    ? new \IntlDateFormatter($localeCode, \IntlDateFormatter::MEDIUM, \IntlDateFormatter::NONE, $displayTimeZone)
    : null;
$localizedTimeFormatter = class_exists(\IntlDateFormatter::class)
    ? new \IntlDateFormatter($localeCode, \IntlDateFormatter::NONE, \IntlDateFormatter::SHORT, $displayTimeZone)
    : null;
$formatTimestamp = static function (?int $timestamp) use ($l, $localDateTime, $localizedDateFormatter): string {
    if ($timestamp === null || $timestamp <= 0) {
        return '';
    }

    if ($localizedDateFormatter !== null) {
        $formatted = $localizedDateFormatter->format($localDateTime($timestamp));
        if ($formatted !== false) {
            return $formatted;
        }
    }

    return $l->l('date', $timestamp);
};
$formatTime = static function (?int $timestamp) use ($l, $localDateTime, $localizedTimeFormatter): string {
    if ($timestamp === null || $timestamp <= 0) {
        return '';
    }

    if ($localizedTimeFormatter !== null) {
        $formatted = $localizedTimeFormatter->format($localDateTime($timestamp));
        if ($formatted !== false) {
            return $formatted;
        }
    }

    return $l->l('time', $timestamp);
};
$formatDebugTimestamp = static function (?int $timestamp) use ($localDateTime): string {
    if ($timestamp === null || $timestamp <= 0) {
        return '-';
    }

    return $localDateTime($timestamp)->format('Y-m-d H:i:s T');
};
$formatDateRange = static function (array $range) use ($formatDate): string {
    if ($range['start'] === $range['end']) {
        return $formatDate($range['start']);
    }

    return $formatDate($range['start']) . ' - ' . $formatDate($range['end']);
};
$daysForRange = static function (array $days, array $range): array {
    return array_values(array_filter($days, static fn (string $day): bool => $day >= $range['start'] && $day <= $range['end']));
};
$debugEventGroups = static function (array $events, bool $deleted): array {
    $filtered = array_values(array_filter($events, static fn (array $event): bool => (bool)$event['isDeleted'] === $deleted && count($event['days']) > 0));
    usort($filtered, static function (array $a, array $b): int {
        return [$a['days'][0], $a['uid'], $a['start']] <=> [$b['days'][0], $b['uid'], $b['start']];
    });

    $groups = [];
    foreach ($filtered as $event) {
        $days = array_values(array_unique($event['days']));
        sort($days, SORT_STRING);
        $start = $days[0];
        $end = $days[count($days) - 1];
        $uid = (string)$event['uid'];
        $lastIndex = count($groups) - 1;
        $canAppend = false;

        if ($lastIndex >= 0 && $groups[$lastIndex]['uid'] === $uid) {
            $previousEnd = new DateTimeImmutable($groups[$lastIndex]['end']);
            $nextStart = new DateTimeImmutable($start);
            $canAppend = $nextStart <= $previousEnd->modify('+1 weekday');
        }

        if (!$canAppend) {
            $groups[] = [
                'uid' => $uid,
                'summary' => (string)$event['summary'],
                'start' => $start,
                'end' => $end,
                'days' => $days,
                'events' => [$event],
            ];
            continue;
        }

        $groups[$lastIndex]['end'] = max($groups[$lastIndex]['end'], $end);
        $groups[$lastIndex]['days'] = array_values(array_unique(array_merge($groups[$lastIndex]['days'], $days)));
        sort($groups[$lastIndex]['days'], SORT_STRING);
        $groups[$lastIndex]['events'][] = $event;
    }

    return $groups;
};
$autoApprovalReasonLabel = static function (string $reason) use ($l): string {
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
};
$approvalLabel = static function (?array $approval) use ($l, $formatTimestamp, $formatTime, $autoApprovalReasonLabel): string {
    if ($approval === null) {
        return $l->t('Not synced yet');
    }

    if ($approval['status'] === 'approved') {
        if ((int)($approval['auto_approved'] ?? 0) === 1) {
            return $l->t('Automatically approved on %s at %s: %s', [$formatTimestamp($approval['approved_at']), $formatTime($approval['approved_at']), $autoApprovalReasonLabel((string)($approval['auto_approval_reason'] ?? ''))]);
        }

        return $l->t('Approved by %s on %s at %s', [$approval['approvedDisplayName'], $formatTimestamp($approval['approved_at']), $formatTime($approval['approved_at'])]);
    }

    if ($approval['status'] === 'pending_approval') {
        return $l->t('Waiting for approval');
    }

    if ($approval['status'] === 'pending_detection') {
        return $l->t('Waiting for stabilization');
    }

    if ($approval['status'] === 'duplicate_conflict') {
        return $l->t('Duplicate calendar entry. Remove one of the overlapping entries before approval.');
    }

    if ($approval['status'] === 'changed_after_approval') {
        return $l->t('Changed after approval');
    }

    if ($approval['status'] === 'cancellation_pending') {
        return $l->t('Approved booking is missing from the calendar. Cancellation awaiting confirmation.');
    }

    if ($approval['status'] === 'approved_missing') {
        return $l->t('Booking retained although the calendar entry is missing.');
    }

    if ($approval['status'] === 'rejected') {
        return $l->t('Rejected by %s on %s at %s', [$approval['rejectedDisplayName'], $formatTimestamp($approval['rejected_at']), $formatTime($approval['rejected_at'])]);
    }

    if ($approval['status'] === 'cancelled') {
        return $l->t('Cancelled');
    }

    return $approval['status'];
};
$approvalClass = static function (?array $approval): string {
    if ($approval === null) {
        return 'approval-unknown';
    }

    return 'approval-' . str_replace('_', '-', (string)$approval['status']);
};
$approvalSymbol = static function (?array $approval): string {
    if ($approval === null) {
        return '?';
    }

    return match ($approval['status']) {
        'approved' => '&#10003;',
        'pending_approval' => '&#8594;',
        'pending_detection' => '&hellip;',
        'duplicate_conflict' => '!',
        'rejected' => '!',
        'changed_after_approval',
        'cancellation_pending',
        'approved_missing',
        'cancelled' => '!',
        default => '?',
    };
};
$approvalAction = static function (int $requestId) use ($approveUrlTemplate): string {
    return str_replace('__REQUEST_ID__', (string)$requestId, $approveUrlTemplate);
};
$rejectAction = static function (int $requestId) use ($rejectUrlTemplate): string {
    return str_replace('__REQUEST_ID__', (string)$requestId, $rejectUrlTemplate);
};
$confirmCancellationAction = static function (int $requestId) use ($confirmCancellationUrlTemplate): string {
    return str_replace('__REQUEST_ID__', (string)$requestId, $confirmCancellationUrlTemplate);
};
$keepBookingAction = static function (int $requestId) use ($keepBookingUrlTemplate): string {
    return str_replace('__REQUEST_ID__', (string)$requestId, $keepBookingUrlTemplate);
};
$pendingApprovalCount = static function (array $ranges): int {
    return count(array_filter($ranges, static function (array $range): bool {
        $approval = $range['approval'] ?? null;
        return is_array($approval) && in_array($approval['status'], ['duplicate_conflict', 'pending_approval', 'changed_after_approval', 'cancellation_pending'], true);
    }));
};
$summaryApprovalForRanges = static function (array $ranges): ?array {
    $priority = [
        'rejected' => 60,
        'duplicate_conflict' => 58,
        'cancellation_pending' => 55,
        'approved_missing' => 52,
        'changed_after_approval' => 50,
        'pending_approval' => 40,
        'pending_detection' => 30,
        'cancelled' => 20,
    ];
    $selected = null;
    $selectedPriority = 0;

    foreach ($ranges as $range) {
        $approval = $range['approval'] ?? null;
        if (!is_array($approval)) {
            if ($selectedPriority < 10) {
                $selected = null;
                $selectedPriority = 10;
            }
            continue;
        }

        $status = (string)($approval['status'] ?? '');
        $currentPriority = $priority[$status] ?? 0;
        if ($currentPriority > $selectedPriority) {
            $selected = $approval;
            $selectedPriority = $currentPriority;
        }
    }

    return $selectedPriority > 0 ? $selected : null;
};
?>

<div id="app-navigation">
    <ul class="vacation-navigation">
        <li class="vacation-view-entry <?php if (!$_['approvalOverview']) { print_unescaped('active'); } ?>">
            <a href="<?php p($_['personalUrl']); ?>" <?php if (!$_['approvalOverview']) { print_unescaped('aria-current="page"'); } ?>>
                <span><?php p($l->t('My vacation days')); ?></span>
            </a>
        </li>
        <?php if ($_['canManageApprovals']): ?>
            <li class="vacation-view-entry <?php if ($_['approvalOverview']) { print_unescaped('active'); } ?>">
                <a href="<?php p($_['approvalsUrl']); ?>" <?php if ($_['approvalOverview']) { print_unescaped('aria-current="page"'); } ?>>
                    <span><?php p($l->t('Vacation management')); ?></span>
                </a>
            </li>
        <?php endif; ?>
        <li class="vacation-period">
            <div class="vacation-period-content">
                <h3><?php p($l->t('Select calendar year')); ?></h3>
                <form method="get" action="<?php p($_['approvalOverview'] ? $_['approvalsUrl'] : $_['personalUrl']); ?>" class="vacation-year-form">
                    <select id="vacation-year" name="year" aria-label="<?php p($l->t('Year')); ?>">
                        <?php foreach ($yearOptions as $yearOption): ?>
                            <option value="<?php p($yearOption); ?>" <?php if ($yearOption === (int)$_['year']) { print_unescaped('selected'); } ?>><?php p($yearOption); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?php if ($_['debug']): ?>
                        <input type="hidden" name="debug" value="1">
                    <?php endif; ?>
                    <noscript><button type="submit"><?php p($l->t('Show')); ?></button></noscript>
                </form>
            </div>
        </li>
    </ul>
</div>

<div id="app-content">
    <div id="nextcloud-vacation" class="vacation-page">
        <header class="vacation-header">
            <div>
            <h2>
                <?php if ($_['approvalOverview']): ?>
                    <?php p($l->t('Vacation management %s', [$_['year']])); ?>
                <?php else: ?>
                    <?php p($l->t('Vacation days %s', [$_['year']])); ?>
                <?php endif; ?>
            </h2>
            <p>
                <?php if ($_['isAdmin']): ?>
                    <?php p($l->t('Overview for staff calendars')); ?>
                <?php else: ?>
                    <?php p($l->t('Your used vacation days')); ?>
                <?php endif; ?>
            </p>
            <?php if ($_['debug'] && $_['approveResult'] !== ''): ?>
                <p class="debug-action-result">approve_result: <?php p($_['approveResult']); ?> &middot; approved_id: <?php p((string)$_['approvedId']); ?></p>
            <?php endif; ?>
            </div>
        </header>

        <section class="vacation-meta">
            <span><?php p($l->t('Calendar URI: %s', [$_['calendarUri']])); ?></span>
            <span><?php p($l->t('Calendar name: %s', [$_['calendarDisplayname']])); ?></span>
            <span><?php p($l->t('Entitlement: %1$s %2$s', [$_['vacationEntitlement'], $dayUnit((float)$_['vacationEntitlement'])])); ?></span>
            <?php if ($_['isAdmin']): ?>
                <span><?php p($l->t('Admin group: %s', [$_['adminGroup']])); ?></span>
                <span><?php p($l->t('User group: %s', [$_['staffGroup']])); ?></span>
            <?php endif; ?>
        </section>

        <?php if ($_['isAdmin']): ?>
            <form method="post" action="<?php p($approveOpenYearUrl); ?>" class="vacation-bulk-actions">
                <?php if ($requestToken !== ''): ?><input type="hidden" name="requesttoken" value="<?php p($requestToken); ?>"><?php endif; ?>
                <input type="hidden" name="year" value="<?php p($_['year']); ?>">
                <input type="hidden" name="action" value="sync">
                <button type="submit"><?php p($l->t('Synchronize now')); ?></button>
            </form>
            <form method="post" action="<?php p($approveOpenYearUrl); ?>" class="vacation-bulk-actions">
                <?php if ($requestToken !== ''): ?>
                    <input type="hidden" name="requesttoken" value="<?php p($requestToken); ?>">
                <?php endif; ?>
                <input type="hidden" name="year" value="<?php p($_['year']); ?>">
                <input type="hidden" name="action" value="approve">
                <button type="submit"><?php p($l->t('Approve open periods for year')); ?></button>
            </form>
            <form method="post" action="<?php p($approveOpenYearUrl); ?>" class="vacation-bulk-actions">
                <?php if ($requestToken !== ''): ?><input type="hidden" name="requesttoken" value="<?php p($requestToken); ?>"><?php endif; ?>
                <input type="hidden" name="year" value="<?php p($_['year']); ?>">
                <input type="hidden" name="action" value="confirm_cancellations">
                <button type="submit" class="reject-button"><?php p($l->t('Confirm all cancellations for year')); ?></button>
            </form>
        <?php endif; ?>

        <div class="vacation-table-scroll" role="region" aria-label="<?php p($l->t('Vacation days')); ?>" tabindex="0">
        <table class="vacation-table">
            <colgroup>
                <col class="vacation-col-user">
                <col class="vacation-col-balance">
                <col class="vacation-col-periods">
            </colgroup>
            <thead>
                <tr>
                    <th><?php p($l->t('User')); ?></th>
                    <th title="<?php p($l->t('Taken / entitlement (remaining)')); ?>"><?php p($l->t('Vacation balance header')); ?></th>
                    <th><?php p($l->t('Periods')); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($_['report'] as $row): ?>
                    <?php
                    $rangeCount = count($row['dayRanges']);
                    $openCount = $pendingApprovalCount($row['dayRanges']);
                    $summaryApproval = $summaryApprovalForRanges($row['dayRanges']);
                    $rowHasActiveAction = $activeActionId > 0 && count(array_filter($row['dayRanges'], static function (array $range) use ($activeActionId): bool {
                        return isset($range['approval']['id']) && (int)$range['approval']['id'] === $activeActionId;
                    })) > 0;
                    $carryoverSourceLabel = '';
                    if ($row['carryoverSource'] === 'automatic') {
                        $carryoverSourceLabel = $l->t('Automatic carryover from previous year');
                    } elseif ($row['carryoverSource'] === 'manual') {
                        $carryoverSourceLabel = $l->t('Manual carryover');
                    }
                    $unusedCarryover = max(0.0, (float)$row['carryover'] - (float)$row['usedCarryover']);
                    $carryoverExpiryLabel = '';
                    if ($row['carryoverAvailable'] && $unusedCarryover > 0.0) {
                        $carryoverExpiryLabel = $l->t('%1$s carryover expires on %2$s', [$formatDayAmount($unusedCarryover), $formatDate($row['carryoverExpiresAt'])]);
                    } elseif (!$row['carryoverAvailable'] && (float)$row['expiredCarryover'] > 0.0) {
                        $carryoverExpiryLabel = $l->t('%1$s carryover used before expiry; %2$s expired on %3$s', [$formatDayAmount((float)$row['usedCarryover']), $formatDayAmount((float)$row['expiredCarryover']), $formatDate($row['carryoverExpiresAt'])]);
                    }
                    $carryoverTooltip = implode('. ', array_filter([$carryoverSourceLabel, $carryoverExpiryLabel]));
                    ?>
                    <tr class="<?php p($row['hasCalendar'] ? '' : 'missing-calendar'); ?>">
                        <td>
                            <strong><?php p($row['displayName']); ?></strong>
                            <?php if ($row['calendarSource'] !== ''): ?>
                                <span><?php p($l->t('Calendar: %s', [$row['calendarSource']])); ?></span>
                            <?php endif; ?>
                        </td>
                        <td class="vacation-balance <?php p($row['remainingDays'] < 0 ? 'negative-days' : ''); ?>">
                            <strong title="<?php p($l->t('Taken: %s, entitlement: %s, remaining: %s', [$formatDayAmount((float)$row['vacationDays']), $formatDayAmount((float)$row['entitlement']), $formatDayAmount((float)$row['remainingDays'])])); ?>"><?php p($formatDayAmount((float)$row['vacationDays'])); ?> / <?php p($formatDayAmount((float)$row['entitlement'])); ?> (<?php p($formatDayAmount((float)$row['remainingDays'])); ?>) <?php p($l->t('days')); ?></strong>
                            <?php if ($_['isAdmin']): ?>
                                <details class="entitlement-details" <?php if ((string)$_['openUserId'] === (string)$row['userId'] && $_['specialLeaveResult'] !== '') { print_unescaped('open'); } ?>>
                                    <summary>
                                        <?php p($formatDayAmount((float)$row['baseEntitlement'])); ?>
                                        + <?php p($formatDayAmount((float)$row['effectiveCarryover'])); ?> <?php p($l->t('Carryover')); ?>
                                        <?php p((float)$row['specialLeave'] < 0 ? '-' : '+'); ?> <?php p($formatDayAmount(abs((float)$row['specialLeave']))); ?> <?php p($l->t('Special leave')); ?>
                                    </summary>
                                    <form method="post" action="<?php p($carryoverSaveUrl); ?>" class="carryover-form">
                                        <?php if ($requestToken !== ''): ?>
                                            <input type="hidden" name="requesttoken" value="<?php p($requestToken); ?>">
                                        <?php endif; ?>
                                        <input type="hidden" name="year" value="<?php p($_['year']); ?>">
                                        <input type="hidden" name="user_id" value="<?php p($row['userId']); ?>">
                                        <label>
                                            <?php p($l->t('Employee entitlement')); ?>
                                            <input type="text" name="entitlement" value="<?php p($row['personalEntitlement'] === null ? '' : $formatDayAmount((float)$row['personalEntitlement'])); ?>" placeholder="<?php p($formatDayAmount((float)$row['globalEntitlement'])); ?>" inputmode="decimal">
                                        </label>
                                        <label>
                                            <?php p($l->t('Carryover')); ?>
                                            <input type="text" name="carryover" value="<?php p($formatDayAmount((float)$row['carryover'])); ?>" inputmode="decimal" title="<?php p($carryoverTooltip); ?>" aria-label="<?php p($l->t('Carryover')); ?>: <?php p($carryoverTooltip); ?>">
                                        </label>
                                        <button type="submit" class="carryover-button" title="<?php p($l->t('Save')); ?>" aria-label="<?php p($l->t('Save')); ?>">&#10003;</button>
                                    </form>
                                    <div class="special-leave-journal">
                                        <strong><?php p($l->t('Special leave')); ?></strong>
                                        <?php foreach ($row['specialLeaveEntries'] as $entry): ?>
                                            <div class="special-leave-entry" title="SHA-256 <?php p($entry['entry_hash']); ?>">
                                                <span><?php p($formatDayAmount((float)$entry['amount'])); ?> <?php p($dayUnit((float)$entry['amount'])); ?> &middot; <?php p($entry['reason']); ?></span>
                                                <small><?php p($l->t('Posted on %1$s by %2$s', [$formatTimestamp((int)$entry['granted_at']), $entry['grantedDisplayName']])); ?></small>
                                            </div>
                                        <?php endforeach; ?>
                                        <?php if (count($row['specialLeaveEntries']) === 0): ?>
                                            <small><?php p($l->t('No special leave entries')); ?></small>
                                        <?php endif; ?>
                                        <form method="post" action="<?php p($specialLeaveUrl); ?>" class="special-leave-form">
                                            <?php if ($requestToken !== ''): ?><input type="hidden" name="requesttoken" value="<?php p($requestToken); ?>"><?php endif; ?>
                                            <input type="hidden" name="year" value="<?php p($_['year']); ?>">
                                            <input type="hidden" name="user_id" value="<?php p($row['userId']); ?>">
                                            <label>
                                                <?php p($l->t('Amount in days')); ?>
                                                <input type="text" name="amount" inputmode="decimal" placeholder="1" required>
                                            </label>
                                            <label class="special-leave-reason">
                                                <?php p($l->t('Reason')); ?>
                                                <input type="text" name="reason" maxlength="255" required>
                                            </label>
                                            <button type="submit" class="carryover-button" title="<?php p($l->t('Post special leave')); ?>" aria-label="<?php p($l->t('Post special leave')); ?>">&#10003;</button>
                                        </form>
                                        <small><?php p($l->t('Use a negative amount to correct an earlier entry.')); ?></small>
                                        <?php if ((string)$_['openUserId'] === (string)$row['userId'] && $_['specialLeaveResult'] !== ''): ?>
                                            <span class="special-leave-result <?php p($_['specialLeaveResult'] === 'added' ? 'special-leave-success' : 'special-leave-error'); ?>">
                                                <?php p($_['specialLeaveResult'] === 'added' ? $l->t('Special leave posted') : $l->t('Special leave could not be posted')); ?>
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                </details>
                                <?php if ((float)$row['expiredCarryover'] > 0.0): ?>
                                    <span class="carryover-expiry carryover-expired">
                                        <?php p($l->t('%1$s expired on %2$s', [$formatDayAmount((float)$row['expiredCarryover']), $formatDate($row['carryoverExpiresAt'])])); ?>
                                    </span>
                                <?php endif; ?>
                            <?php else: ?>
                                <span class="entitlement-summary"><?php p($formatDayAmount((float)$row['baseEntitlement'])); ?> + <?php p($formatDayAmount((float)$row['effectiveCarryover'])); ?> <?php p($l->t('Carryover')); ?> <?php p((float)$row['specialLeave'] < 0 ? '-' : '+'); ?> <?php p($formatDayAmount(abs((float)$row['specialLeave']))); ?> <?php p($l->t('Special leave')); ?></span>
                                <?php if ($carryoverExpiryLabel !== ''): ?>
                                    <span class="carryover-expiry <?php p(!$row['carryoverAvailable'] ? 'carryover-expired' : ''); ?>">
                                        <?php p($carryoverExpiryLabel); ?>
                                    </span>
                                <?php endif; ?>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div class="period-overview">
                            <details class="user-periods" <?php if ($rowHasActiveAction || (string)$_['openUserId'] === (string)$row['userId']) { print_unescaped('open'); } ?>>
                                <summary>
                                    <?php p($rangeCount); ?> <?php p($periodUnit($rangeCount)); ?>
                                    <?php if ($summaryApproval !== null): ?>
                                        <span class="period-status-marker <?php p($approvalClass($summaryApproval)); ?>" title="<?php p($approvalLabel($summaryApproval)); ?>" role="img" aria-label="<?php p($approvalLabel($summaryApproval)); ?>"><?php print_unescaped($approvalSymbol($summaryApproval)); ?></span>
                                    <?php endif; ?>
                                    <?php if ($openCount > 0): ?>
                                        <span><?php p($l->t('%s open', [$openCount])); ?></span>
                                    <?php endif; ?>
                                </summary>
                                <div class="period-list">
                                    <?php foreach ($row['dayRanges'] as $range): ?>
                                        <?php
                                        $approval = $range['approval'] ?? null;
                                        $rangeHasActiveAction = $activeActionId > 0 && $approval !== null && (int)$approval['id'] === $activeActionId;
                                        $hasRangeDayValues = isset($range['dayValues']) && is_array($range['dayValues']);
                                        $displayDayValues = isset($range['bookedDayValues']) && is_array($range['bookedDayValues'])
                                            ? $range['bookedDayValues']
                                            : ($hasRangeDayValues ? $range['dayValues'] : ($row['calendarDayValues'] ?? $row['dayValues']));
                                        $rangeDays = isset($range['bookedDayValues']) || $hasRangeDayValues
                                            ? array_keys($displayDayValues)
                                            : $daysForRange($row['days'], $range);
                                        $rangeDayCount = array_sum(array_map(static fn (string $day): float => (float)($displayDayValues[$day] ?? 1), $rangeDays));
                                        $rangeDayLabels = array_map(static function (string $day) use ($formatDate, $formatDayAmount, $displayDayValues): string {
                                            $label = $formatDate($day);
                                            $value = (float)($displayDayValues[$day] ?? 1);
                                            return $value === 1.0 ? $label : $label . ' (' . $formatDayAmount($value) . ')';
                                        }, $rangeDays);
                                        ?>
                                        <div class="period-row <?php p($approvalClass($approval)); ?>">
                                            <details class="day-ranges" <?php if ($rangeHasActiveAction) { print_unescaped('open'); } ?>>
                                                <summary>
                                                    <?php p($formatDateRange($range)); ?> <span class="period-day-count">(<?php p($formatDayAmount($rangeDayCount)); ?> <?php p($dayUnit($rangeDayCount)); ?>)</span>
                                                    <span class="period-status-marker <?php p($approvalClass($approval)); ?>" title="<?php p($approvalLabel($approval)); ?>" role="img" aria-label="<?php p($approvalLabel($approval)); ?>"><?php print_unescaped($approvalSymbol($approval)); ?></span>
                                                </summary>
                                                <div><?php p(implode(', ', $rangeDayLabels)); ?></div>
                                                <div class="approval-state <?php p($approvalClass($approval)); ?>">
                                                    <span class="approval-label"><?php p($approvalLabel($approval)); ?></span>
                                                    <?php if (
                                                        $approval !== null
                                                        && (int)($approval['id'] ?? 0) > 0
                                                        && (int)($approval['current_revision'] ?? 0) > 0
                                                        && preg_match('/^[a-f0-9]{64}$/', (string)($approval['snapshot_hash'] ?? '')) === 1
                                                    ): ?>
                                                        <?php $approvalHash = (string)$approval['snapshot_hash']; ?>
                                                        <details class="approval-integrity">
                                                            <summary><?php p($l->t('Approval #%1$s-R%2$s', [(int)$approval['id'], (int)$approval['current_revision']])); ?></summary>
                                                            <div class="approval-integrity-content">
                                                                <code>SHA-256 <?php p($approvalHash); ?></code>
                                                                <button
                                                                    type="button"
                                                                    class="approval-hash-copy"
                                                                    data-copy-hash="<?php p($approvalHash); ?>"
                                                                    data-copy-label="<?php p($l->t('Copy hash')); ?>"
                                                                    data-copied-label="<?php p($l->t('Copied')); ?>"
                                                                    title="<?php p($l->t('Copy hash')); ?>"
                                                                    aria-label="<?php p($l->t('Copy hash')); ?>"
                                                                ><span class="copy-icon" aria-hidden="true"></span></button>
                                                            </div>
                                                        </details>
                                                    <?php endif; ?>
                                                    <?php if (($approval['status'] ?? '') === 'rejected' && trim((string)($approval['rejection_reason'] ?? '')) !== ''): ?>
                                                        <span class="rejection-reason"><?php p($approval['rejection_reason']); ?></span>
                                                    <?php endif; ?>
                                                    <?php if ($_['isAdmin'] && $approval !== null && in_array($approval['status'], ['pending_approval', 'changed_after_approval'], true)): ?>
                                                        <form method="post" action="<?php p($approvalAction((int)$approval['id'])); ?>">
                                                            <?php if ($requestToken !== ''): ?>
                                                                <input type="hidden" name="requesttoken" value="<?php p($requestToken); ?>">
                                                            <?php endif; ?>
                                                            <input type="hidden" name="year" value="<?php p($_['year']); ?>">
                                                            <input type="hidden" name="user_id" value="<?php p($row['userId']); ?>">
                                                            <?php if ($_['debug']): ?>
                                                                <input type="hidden" name="debug" value="1">
                                                            <?php endif; ?>
                                                            <button type="submit" class="approval-button"><?php p($l->t('Approve')); ?></button>
                                                        </form>
                                                        <form method="post" action="<?php p($rejectAction((int)$approval['id'])); ?>" class="rejection-form">
                                                            <?php if ($requestToken !== ''): ?>
                                                                <input type="hidden" name="requesttoken" value="<?php p($requestToken); ?>">
                                                            <?php endif; ?>
                                                            <input type="hidden" name="year" value="<?php p($_['year']); ?>">
                                                            <?php if ($_['debug']): ?>
                                                                <input type="hidden" name="debug" value="1">
                                                            <?php endif; ?>
                                                            <input type="text" name="reason" value="" placeholder="<?php p($l->t('Reason optional')); ?>">
                                                            <button type="submit" class="reject-button"><?php p($l->t('Reject')); ?></button>
                                                        </form>
                                                    <?php endif; ?>
                                                    <?php if ($_['isAdmin'] && $approval !== null && in_array($approval['status'], ['cancellation_pending', 'approved_missing'], true)): ?>
                                                        <form method="post" action="<?php p($confirmCancellationAction((int)$approval['id'])); ?>" class="rejection-form">
                                                            <?php if ($requestToken !== ''): ?><input type="hidden" name="requesttoken" value="<?php p($requestToken); ?>"><?php endif; ?>
                                                            <input type="hidden" name="year" value="<?php p($_['year']); ?>">
                                                            <input type="hidden" name="user_id" value="<?php p($row['userId']); ?>">
                                                            <?php if ($_['debug']): ?><input type="hidden" name="debug" value="1"><?php endif; ?>
                                                            <input type="text" name="reason" value="" placeholder="<?php p($l->t('Reason optional')); ?>">
                                                            <button type="submit" class="reject-button"><?php p($l->t('Confirm cancellation')); ?></button>
                                                        </form>
                                                        <?php if ($approval['status'] === 'cancellation_pending'): ?>
                                                            <form method="post" action="<?php p($keepBookingAction((int)$approval['id'])); ?>">
                                                                <?php if ($requestToken !== ''): ?><input type="hidden" name="requesttoken" value="<?php p($requestToken); ?>"><?php endif; ?>
                                                                <input type="hidden" name="year" value="<?php p($_['year']); ?>">
                                                                <input type="hidden" name="user_id" value="<?php p($row['userId']); ?>">
                                                                <?php if ($_['debug']): ?><input type="hidden" name="debug" value="1"><?php endif; ?>
                                                                <button type="submit" class="approval-button"><?php p($l->t('Keep booking')); ?></button>
                                                            </form>
                                                        <?php endif; ?>
                                                    <?php endif; ?>
                                                </div>
                                            </details>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </details>
                            <a class="button vacation-pdf-button" href="<?php p($_['pdfUrls'][$row['userId']] ?? $_['pdfUrl']); ?>"><?php p($l->t('Download vacation summary PDF')); ?></a>
                            </div>
                        </td>
                    </tr>
                    <?php if ($_['debug'] && (count($row['matchedEvents']) > 0 || count($row['dayRanges']) > 0 || count($row['apiDebugSamples'] ?? []) > 0)): ?>
                        <tr class="debug-row">
                            <td colspan="3">
                                <?php $activeDebugGroups = $debugEventGroups($row['matchedEvents'], false); ?>
                                <?php $deletedDebugGroups = $debugEventGroups($row['matchedEvents'], true); ?>
                                <?php if (count($activeDebugGroups) > 0): ?>
                                    <details class="debug-section" open>
                                        <summary><?php p($l->t('Active calendar instances (%d)', [count($activeDebugGroups)])); ?></summary>
                                        <div class="debug-section-content">
                                            <?php foreach ($activeDebugGroups as $group): ?>
                                                <details class="debug-event-group">
                                                    <summary><?php p($group['summary']); ?> &middot; <?php p($formatDateRange($group)); ?> &middot; <?php p($instanceCount(count($group['events']))); ?></summary>
                                                    <div><?php p($l->t('UID: %s', [$group['uid']])); ?></div>
                                                    <div><?php p(implode(', ', array_map($formatDate, $group['days']))); ?></div>
                                                    <details class="debug-raw-events">
                                                        <summary><?php p($l->t('Raw calendar instances')); ?></summary>
                                                        <?php foreach ($group['events'] as $event): ?>
                                                            <div><?php p($event['summary']); ?> &middot; <?php p($event['start']); ?> - <?php p($event['end']); ?> &middot; lastmodified: <?php p($formatDebugTimestamp($event['lastModified'] ?? null)); ?></div>
                                                        <?php endforeach; ?>
                                                    </details>
                                                </details>
                                            <?php endforeach; ?>
                                        </div>
                                    </details>
                                <?php endif; ?>
                                <?php if (count($deletedDebugGroups) > 0): ?>
                                    <details class="debug-section debug-event-deleted">
                                        <summary><?php p($l->t('Deleted calendar instances (%d)', [array_sum(array_map(static fn (array $group): int => count($group['events']), $deletedDebugGroups))])); ?></summary>
                                        <div class="debug-section-content">
                                            <?php foreach ($deletedDebugGroups as $group): ?>
                                                <details class="debug-event-group">
                                                    <summary><?php p($group['summary']); ?> &middot; <?php p($formatDateRange($group)); ?> &middot; <?php p($instanceCount(count($group['events']))); ?></summary>
                                                    <div><?php p($l->t('UID: %s', [$group['uid']])); ?></div>
                                                    <div><?php p(implode(', ', array_map($formatDate, $group['days']))); ?></div>
                                                    <details class="debug-raw-events">
                                                        <summary><?php p($l->t('Raw calendar instances')); ?></summary>
                                                        <?php foreach ($group['events'] as $event): ?>
                                                            <div><?php p($event['summary']); ?> &middot; <?php p($event['start']); ?> - <?php p($event['end']); ?> &middot; deleted_at: <?php p($formatDebugTimestamp($event['deletedAt'] ?? null)); ?> &middot; lastmodified: <?php p($formatDebugTimestamp($event['lastModified'] ?? null)); ?></div>
                                                        <?php endforeach; ?>
                                                    </details>
                                                </details>
                                            <?php endforeach; ?>
                                        </div>
                                    </details>
                                <?php endif; ?>
                                <?php if (count($row['dayRanges']) > 0): ?>
                                    <details class="debug-section" open>
                                        <summary><?php p($l->t('Approval debug')); ?></summary>
                                        <div class="debug-section-content">
                                            <?php foreach ($row['dayRanges'] as $range): ?>
                                                <?php if (($range['approval'] ?? null) !== null): ?>
                                                    <?php $approval = $range['approval']; ?>
                                                    <details class="debug-event-group">
                                                        <summary><?php p($formatDateRange($range)); ?></summary>
                                                        <div>id: <?php p($approval['id']); ?></div>
                                                        <div>revision: <?php p((int)($approval['current_revision'] ?? 0) > 0 ? 'R' . (int)$approval['current_revision'] : '-'); ?></div>
                                                        <div>snapshot_hash: <?php p((string)($approval['snapshot_hash'] ?? '')); ?></div>
                                                        <div>status: <?php p($approval['status']); ?></div>
                                                        <div>source_key: <?php p((string)($range['sourceKey'] ?? '')); ?></div>
                                                        <div>first_seen_at: <?php p($formatDebugTimestamp($approval['first_seen_at'] ?? null)); ?></div>
                                                        <div>last_seen_at: <?php p($formatDebugTimestamp($approval['last_seen_at'] ?? null)); ?></div>
                                                        <div>lastmodified_at: <?php p($formatDebugTimestamp($range['lastModified'] ?? null)); ?></div>
                                                        <div>notified_at: <?php p($formatDebugTimestamp($approval['notified_at'] ?? null)); ?></div>
                                                        <div>approved_at: <?php p($formatDebugTimestamp($approval['approved_at'] ?? null)); ?></div>
                                                        <div>auto_approved: <?php p((int)($approval['auto_approved'] ?? 0) === 1 ? 'yes' : 'no'); ?></div>
                                                        <div>auto_approval_reason: <?php p((string)($approval['auto_approval_reason'] ?? '')); ?></div>
                                                        <div>rejected_at: <?php p($formatDebugTimestamp($approval['rejected_at'] ?? null)); ?></div>
                                                        <div>rejection_reason: <?php p((string)($approval['rejection_reason'] ?? '')); ?></div>
                                                        <div>updated_at: <?php p($formatDebugTimestamp($approval['updated_at'] ?? null)); ?></div>
                                                    </details>
                                                <?php endif; ?>
                                            <?php endforeach; ?>
                                        </div>
                                    </details>
                                <?php endif; ?>
                                <?php if ($_['apiDebug'] && count($row['apiDebugSamples'] ?? []) > 0): ?>
                                    <details class="debug-section" open>
                                        <summary>Calendar API debug</summary>
                                        <div class="debug-section-content">
                                            <?php foreach ($row['apiDebugSamples'] as $sample): ?>
                                                <details class="debug-event-group">
                                                    <summary>
                                                        type: <?php p((string)($sample['type'] ?? '')); ?>
                                                        &middot; keys: <?php p(implode(', ', $sample['keys'] ?? [])); ?>
                                                        &middot; objects: <?php p((string)($sample['objectCount'] ?? 0)); ?>
                                                        &middot; calendardata: <?php p(($sample['hasCalendarData'] ?? false) ? 'yes' : 'no'); ?>
                                                    </summary>
                                                    <?php foreach (($sample['firstObject'] ?? []) as $key => $value): ?>
                                                        <div><?php p((string)$key); ?>: <?php p((string)$value); ?></div>
                                                    <?php endforeach; ?>
                                                </details>
                                            <?php endforeach; ?>
                                        </div>
                                    </details>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endif; ?>
                <?php endforeach; ?>
                <?php if (count($_['report']) === 0): ?>
                    <tr>
                        <td colspan="3"><?php p($l->t('No vacation data found.')); ?></td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
        </div>

        <?php if (!$_['isAdmin']): ?>
            <section class="vacation-help" aria-labelledby="vacation-help-title">
                <div class="vacation-help-heading">
                    <div>
                        <h3 id="vacation-help-title"><?php p($l->t('Request vacation')); ?></h3>
                        <p><?php p($l->t('Create an all-day event and select the %s calendar in the editor.', [$_['calendarDisplayname']])); ?></p>
                    </div>
                    <a class="button vacation-calendar-link" href="<?php p($_['calendarNewEventUrl']); ?>"><?php p($l->t('Open new calendar event')); ?></a>
                </div>

                <div class="vacation-help-grid">
                    <div>
                        <h4><?php p($l->t('Entry format')); ?></h4>
                        <p><?php p($l->t('Full day')); ?>: <code>&lt;<?php p($l->t('Initials')); ?>&gt;: Urlaub</code> <?php p($l->t('or')); ?> <code>&lt;<?php p($l->t('Initials')); ?>&gt;: Vacation</code></p>
                        <p><?php p($l->t('Half day')); ?>: <code>&lt;<?php p($l->t('Initials')); ?>&gt;: 0,5d Urlaub</code>, <code>&lt;<?php p($l->t('Initials')); ?>&gt;: 0.5d Vacation</code>, <code>&lt;<?php p($l->t('Initials')); ?>&gt;: 1/2 Urlaub</code> <?php p($l->t('or')); ?> <code>&lt;<?php p($l->t('Initials')); ?>&gt;: 1/2 Vacation</code> <span class="vacation-help-note"><?php p($l->t('(d is optional)')); ?></span></p>
                    </div>
                    <div>
                        <h4><?php p($l->t('Period')); ?></h4>
                        <p><?php p($l->t('For up to one work week, use the event start and end dates.')); ?></p>
                        <p><?php p($l->t('Across a weekend, open More details, repeat weekly on Mon, Tue, Wed, Thu and Fri, and end On date on the day after the last vacation day.')); ?></p>
                    </div>
                    <div>
                        <h4><?php p($l->t('Changes and cancellation')); ?></h4>
                        <p><?php p($l->t('Changed approved vacation must be approved again. To cancel, delete the event from the Status calendar. The days remain booked until a calendar manager confirms the cancellation.')); ?></p>
                    </div>
                </div>

                <p class="vacation-approval-note">
                    <strong><?php p($l->t('Approval')); ?>:</strong>
                    <?php if ($_['autoApprovalReason'] !== null): ?>
                        <?php p($l->t('No manual approval is required. After the configured wait time, the request is approved automatically (%s).', [$autoApprovalReasonLabel((string)$_['autoApprovalReason'])])); ?>
                        <?php if ($_['employeeNotificationsEnabled']): ?>
                            <?php p($l->t('You will receive an email after automatic approval.')); ?>
                        <?php endif; ?>
                    <?php else: ?>
                        <?php p($l->t('After the configured wait time, the request is submitted to the calendar managers in %s.', [$_['adminGroup']])); ?>
                        <?php if ($_['employeeNotificationsEnabled']): ?>
                            <?php p($l->t('You will receive an email when approval is pending and after the decision.')); ?>
                        <?php endif; ?>
                    <?php endif; ?>
                </p>
            </section>
        <?php endif; ?>
    </div>
</div>
