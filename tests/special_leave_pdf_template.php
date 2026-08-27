<?php

declare(strict_types=1);

$l = new class {
    public function t(string $text, array $parameters = []): string
    {
        return $parameters === [] ? $text : vsprintf($text, $parameters);
    }

    public function getLanguageCode(): string
    {
        return 'en';
    }
};

$amount = static fn (float $value): string => rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
$hash = str_repeat('a', 64);
$data = [
    'year' => 2026,
    'displayName' => 'Test User',
    'logo' => null,
    'carryover' => 0.0,
    'baseEntitlement' => 30.0,
    'specialLeaveEntries' => [[
        'amount' => 2.0,
        'reason' => 'Moving house',
        'postingLines' => ['Credited on Jul 15, 2026', 'by Calendar Manager', 'SHA-256 ' . $hash],
    ]],
    'periods' => [],
    'cancelledPeriods' => [[
        'label' => 'Aug 14, 2026',
        'journalLines' => ['Confirmed on Jul 15, 2026 at 10:30', 'by Calendar Manager', 'Cancellation audit #17'],
    ]],
    'expiredCarryover' => 0.0,
    'totalCredits' => 32.0,
    'totalDebits' => 0.0,
    'remaining' => 32.0,
    'generatedAt' => 'Jul 15, 2026',
];

ob_start();
require dirname(__DIR__) . '/templates/vacation_form.php';
$html = (string)ob_get_clean();

foreach (['Special leave', 'Moving house', 'Credited on Jul 15, 2026', 'by Calendar Manager', $hash, 'Cancelled', 'Aug 14, 2026', 'Cancellation audit #17'] as $expected) {
    if (!str_contains($html, $expected)) {
        throw new RuntimeException('PDF template omitted special-leave value: ' . $expected);
    }
}

if (!str_contains($html, 'class="cancellation-period"')) {
    throw new RuntimeException('PDF template does not separate the cancellation date from its label.');
}

echo "Special-leave PDF template tests passed.\n";
