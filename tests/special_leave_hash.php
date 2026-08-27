<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/Service/SpecialLeaveService.php';

use OCA\NextcloudVacation\Service\SpecialLeaveService;

$entry = [
    'schema' => 1,
    'user_id' => 'test.user',
    'year' => 2026,
    'amount_hundredths' => 200,
    'reason' => 'Moving house',
    'granted_by' => 'calendar.manager',
    'granted_at' => 1784102400,
    'previous_hash' => '',
];

$hash = SpecialLeaveService::entryHash($entry);
if (strlen($hash) !== 64 || $hash !== SpecialLeaveService::entryHash($entry)) {
    throw new RuntimeException('Special-leave hash is not deterministic SHA-256.');
}

$changed = $entry;
$changed['amount_hundredths'] = 100;
if ($hash === SpecialLeaveService::entryHash($changed)) {
    throw new RuntimeException('Changed special-leave content retained its hash.');
}

$next = $entry;
$next['previous_hash'] = $hash;
if ($hash === SpecialLeaveService::entryHash($next)) {
    throw new RuntimeException('Previous hash is not part of the special-leave hash.');
}

$correction = [
    'schema' => 2,
    'user_id' => 'test.user',
    'year' => 2026,
    'amount_hundredths' => 0,
    'reason' => 'Birth of a child',
    'granted_by' => 'calendar.manager',
    'granted_at' => 1784102500,
    'previous_hash' => $hash,
    'corrects_id' => 42,
];
$correctionHash = SpecialLeaveService::entryHash($correction);
$otherTarget = $correction;
$otherTarget['corrects_id'] = 43;
if ($correctionHash === SpecialLeaveService::entryHash($otherTarget)) {
    throw new RuntimeException('Corrected entry ID is not part of the special-leave hash.');
}

echo "Special-leave hash tests passed.\n";
