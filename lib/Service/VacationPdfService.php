<?php

declare(strict_types=1);

namespace OCA\NextcloudVacation\Service;

use DateTimeImmutable;
use DateTimeZone;
use Dompdf\Dompdf;
use Dompdf\Options;
use OCP\IL10N;

class VacationPdfService
{
    public function __construct(
        private PdfLogoService $logoService,
        private IL10N $l10n,
        private VacationRevisionService $revisionService
    ) {
    }

    public function render(array $row, int $year, string $timeZone, ?IL10N $l10n = null): string
    {
        $l10n ??= $this->l10n;
        $periodsByDays = [];
        foreach ($row['dayRanges'] as $range) {
            $days = $this->rangeDays($row, $range);
            ksort($days);
            $period = [
                'start' => (string)$range['start'],
                'end' => (string)$range['end'],
                'label' => $this->dateRange((string)$range['start'], (string)$range['end'], $l10n),
                'days' => array_sum($days),
                'approvalLines' => $this->approvalLines($range['approval'] ?? null, $timeZone, $l10n),
                'approvalStatus' => (string)($range['approval']['status'] ?? ''),
            ];
            $key = json_encode($days, JSON_THROW_ON_ERROR);
            if (
                !isset($periodsByDays[$key])
                || $this->approvalPriority($period['approvalStatus']) > $this->approvalPriority($periodsByDays[$key]['approvalStatus'])
            ) {
                $periodsByDays[$key] = $period;
            }
        }
        $periods = array_values($periodsByDays);

        $baseEntitlement = (float)$row['baseEntitlement'];
        $carryover = (float)$row['carryover'];
        $specialLeave = (float)($row['specialLeave'] ?? 0.0);
        $expiredCarryover = (float)$row['expiredCarryover'];
        $specialLeaveEntries = array_map(
            fn (array $entry): array => $entry + [
                'postingLines' => $this->specialLeavePostingLines($entry, $timeZone, $l10n),
            ],
            $row['specialLeaveEntries'] ?? []
        );
        $data = [
            'year' => $year,
            'displayName' => (string)$row['displayName'],
            'logo' => $this->logoService->dataUri(),
            'baseEntitlement' => $baseEntitlement,
            'carryover' => $carryover,
            'specialLeave' => $specialLeave,
            'specialLeaveEntries' => $specialLeaveEntries,
            'expiredCarryover' => $expiredCarryover,
            'periods' => $periods,
            'totalCredits' => $baseEntitlement + $carryover + $specialLeave,
            'totalDebits' => (float)$row['vacationDays'] + $expiredCarryover,
            'remaining' => (float)$row['remainingDays'],
            'generatedAt' => $l10n->l('date', time()),
        ];

        ob_start();
        $l = $l10n;
        $amount = fn (float $value): string => $this->amount($value, $l10n);
        include dirname(__DIR__, 2) . '/templates/vacation_form.php';
        $html = (string)ob_get_clean();

        $options = new Options();
        $options->set('isRemoteEnabled', false);
        $options->set('isHtml5ParserEnabled', true);
        $options->set('defaultFont', 'DejaVu Sans');
        $options->set('chroot', dirname(__DIR__, 2));

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        return $dompdf->output();
    }

    private function rangeDays(array $row, array $range): array
    {
        if (isset($range['bookedDayValues']) && is_array($range['bookedDayValues'])) {
            return array_map('floatval', $range['bookedDayValues']);
        }

        if (isset($range['dayValues']) && is_array($range['dayValues'])) {
            return array_map('floatval', $range['dayValues']);
        }

        $values = $row['dayValues'] ?? $row['calendarDayValues'] ?? [];
        return array_map('floatval', array_filter(
            $values,
            static fn (mixed $value, string $day): bool => $day >= (string)$range['start'] && $day <= (string)$range['end'],
            ARRAY_FILTER_USE_BOTH
        ));
    }

    private function approvalPriority(string $status): int
    {
        return match ($status) {
            'approved' => 50,
            'rejected' => 40,
            'pending_approval', 'changed_after_approval' => 30,
            'pending_detection' => 20,
            'duplicate_conflict' => 10,
            default => 0,
        };
    }

    private function date(string $day, IL10N $l10n): string
    {
        $locale = method_exists($l10n, 'getLocaleCode') ? strtolower((string)$l10n->getLocaleCode()) : '';
        $format = str_starts_with($locale, 'de') ? 'd.m.Y' : (str_starts_with($locale, 'en_us') ? 'm/d/Y' : 'd/m/Y');
        return date($format, strtotime($day));
    }

    private function dateRange(string $start, string $end, IL10N $l10n): string
    {
        return $start === $end ? $this->date($start, $l10n) : $this->date($start, $l10n) . ' - ' . $this->date($end, $l10n);
    }

    private function amount(float $value, IL10N $l10n): string
    {
        $locale = method_exists($l10n, 'getLocaleCode') ? strtolower((string)$l10n->getLocaleCode()) : '';
        $decimal = str_starts_with($locale, 'de') ? ',' : '.';
        $formatted = number_format($value, 2, $decimal, '');
        return rtrim(rtrim($formatted, '0'), $decimal);
    }

    private function specialLeavePostingLines(array $entry, string $timeZone, IL10N $l10n): array
    {
        $postedAt = (new DateTimeImmutable('@' . (int)$entry['granted_at']))
            ->setTimezone(new DateTimeZone($timeZone));
        return [
            $l10n->t('Credited on %s', [$this->date($postedAt->format('Y-m-d'), $l10n)]),
            $l10n->t('by %s', [(string)$entry['grantedDisplayName']]),
            'SHA-256 ' . (string)$entry['entry_hash'],
        ];
    }

    private function approvalLines(mixed $approval, string $timeZone, IL10N $l10n): array
    {
        if (!is_array($approval)) {
            return [$l10n->t('Not synchronized')];
        }

        $status = (string)($approval['status'] ?? '');
        if ($status === ApprovalService::STATUS_APPROVED) {
            $approvedAt = (int)($approval['approved_at'] ?? 0);
            $requestId = (int)($approval['id'] ?? 0);
            $revision = (int)($approval['current_revision'] ?? 0);
            if ($approvedAt > 0) {
                $approvedDateTime = (new DateTimeImmutable('@' . $approvedAt))->setTimezone(new DateTimeZone($timeZone));
                $date = $this->date($approvedDateTime->format('Y-m-d'), $l10n);
                $time = $approvedDateTime->format('H:i');
                $lines = [$requestId > 0 && $revision > 0
                    ? $l10n->t('on %1$s at %2$s · Approval #%3$s-R%4$s', [$date, $time, $requestId, $revision])
                    : $l10n->t('on %1$s at %2$s', [$date, $time])];
            } else {
                $lines = [$l10n->t('on %1$s at %2$s', ['-', '-'])];
            }
            if ((int)($approval['auto_approved'] ?? 0) === 1) {
                $lines[] = $l10n->t('Automatic');
            } else {
                $approvedBy = trim((string)($approval['approvedDisplayName'] ?? $approval['approved_by'] ?? ''));
                $lines[] = $l10n->t('by %s', [$approvedBy !== '' ? $approvedBy : '-']);
            }
            $revisionHash = $this->revisionService->revisionHash($requestId, $revision);
            if ($revisionHash !== null) {
                $lines[] = 'SHA-256 ' . $revisionHash;
            }
            return $lines;
        }

        return [match ($status) {
            ApprovalService::STATUS_PENDING_APPROVAL => $l10n->t('Waiting for approval'),
            ApprovalService::STATUS_PENDING_DETECTION => $l10n->t('Waiting for stabilization'),
            ApprovalService::STATUS_DUPLICATE_CONFLICT => $l10n->t('Duplicate calendar entry. Remove one of the overlapping entries before approval.'),
            ApprovalService::STATUS_CHANGED_AFTER_APPROVAL => $l10n->t('Changed after approval'),
            ApprovalService::STATUS_REJECTED => $l10n->t('Rejected'),
            ApprovalService::STATUS_CANCELLATION_PENDING => $l10n->t('Cancellation pending'),
            ApprovalService::STATUS_APPROVED_MISSING => $l10n->t('Booking retained'),
            ApprovalService::STATUS_CANCELLED => $l10n->t('Cancelled'),
            default => $l10n->t('Not synchronized'),
        }];
    }
}
