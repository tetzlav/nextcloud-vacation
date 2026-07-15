<?php

declare(strict_types=1);

require dirname(__DIR__) . '/lib/Service/VacationReportService.php';

use OCA\NextcloudVacation\Service\VacationReportService;

$service = (new ReflectionClass(VacationReportService::class))->newInstanceWithoutConstructor();
$method = new ReflectionMethod(VacationReportService::class, 'dayRanges');
$check = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$ranges = $method->invoke(
    $service,
    [
        'source-a' => ['2026-07-13' => 1.0, '2026-07-14' => 1.0],
        'source-b' => ['2026-07-13' => 1.0, '2026-07-14' => 1.0],
    ],
    [
        'source-a' => ['2026-07-13' => 100, '2026-07-14' => 100],
        'source-b' => ['2026-07-13' => 200, '2026-07-14' => 200],
    ],
    [
        '2026-07-13' => ['source-a' => true, 'source-b' => true],
        '2026-07-14' => ['source-a' => true, 'source-b' => true],
    ]
);

$check(count($ranges) === 2, 'Overlapping sources must remain separate ranges.');
$check(array_column($ranges, 'sourceKey') === ['source-a', 'source-b'], 'Source identities changed.');
$check($ranges[0]['duplicateConflict'] === true, 'First overlapping source was not marked.');
$check($ranges[1]['duplicateConflict'] === true, 'Second overlapping source was not marked.');
$check($ranges[0]['duplicateDays'] === ['2026-07-13', '2026-07-14'], 'Duplicate days differ.');
$check($ranges[0]['dayValues'] === ['2026-07-13' => 1.0, '2026-07-14' => 1.0], 'Range values differ.');
$check($ranges[0]['lastModified'] === 100, 'First source timestamp differs.');
$check($ranges[1]['lastModified'] === 200, 'Second source timestamp differs.');
$expectedCompositeSource = hash('sha256', 'source-a,source-b');
$check($ranges[0]['legacyCompositeSourceKey'] === $expectedCompositeSource, 'Legacy composite source differs.');
$check($ranges[1]['legacyCompositeSourceKey'] === $expectedCompositeSource, 'Second legacy composite source differs.');

$separateRanges = $method->invoke(
    $service,
    [
        'source-a' => ['2026-07-13' => 1.0],
        'source-b' => ['2026-07-14' => 0.5],
    ],
    [
        'source-a' => ['2026-07-13' => 100],
        'source-b' => ['2026-07-14' => 200],
    ],
    [
        '2026-07-13' => ['source-a' => true],
        '2026-07-14' => ['source-b' => true],
    ]
);

$check(count($separateRanges) === 2, 'Separate sources must remain separate ranges.');
$check($separateRanges[0]['duplicateConflict'] === false, 'First separate source was marked.');
$check($separateRanges[1]['duplicateConflict'] === false, 'Second separate source was marked.');
$check($separateRanges[1]['dayValues'] === ['2026-07-14' => 0.5], 'Half-day value differs.');
$check($separateRanges[0]['legacyCompositeSourceKey'] === '', 'Separate source has a composite key.');

$partialRanges = $method->invoke(
    $service,
    [
        'source-a' => ['2026-07-13' => 1.0, '2026-07-14' => 1.0],
        'source-b' => ['2026-07-14' => 0.5, '2026-07-15' => 0.5],
    ],
    [
        'source-a' => ['2026-07-13' => 100, '2026-07-14' => 100],
        'source-b' => ['2026-07-14' => 200, '2026-07-15' => 200],
    ],
    [
        '2026-07-13' => ['source-a' => true],
        '2026-07-14' => ['source-a' => true, 'source-b' => true],
        '2026-07-15' => ['source-b' => true],
    ]
);

$check(count($partialRanges) === 2, 'Partially overlapping sources must remain separate ranges.');
$check($partialRanges[0]['duplicateDays'] === ['2026-07-14'], 'First partial overlap differs.');
$check($partialRanges[1]['duplicateDays'] === ['2026-07-14'], 'Second partial overlap differs.');
$check(array_sum($partialRanges[0]['dayValues']) === 2.0, 'Full-day source value differs.');
$check(array_sum($partialRanges[1]['dayValues']) === 1.0, 'Half-day source value differs.');
$check($partialRanges[0]['legacyCompositeSourceKey'] === '', 'Partial overlap has a composite key.');

echo "Duplicate source range tests passed.\n";
