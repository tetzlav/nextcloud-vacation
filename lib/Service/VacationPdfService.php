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

    public function render(array $row, int $year, string $timeZone): string
    {
        $periods = [];
        foreach ($row['dayRanges'] as $range) {
            $days = $this->rangeDays($row, $range);
            $periods[] = [
                'start' => (string)$range['start'],
                'end' => (string)$range['end'],
                'label' => $this->dateRange((string)$range['start'], (string)$range['end']),
                'days' => array_sum($days),
                'approvalLines' => $this->approvalLines($range['approval'] ?? null, $timeZone),
            ];
        }

        $baseEntitlement = (float)$row['baseEntitlement'];
        $carryover = (float)$row['carryover'];
        $expiredCarryover = (float)$row['expiredCarryover'];
        $data = [
            'year' => $year,
            'displayName' => (string)$row['displayName'],
            'logo' => $this->logoService->dataUri(),
            'baseEntitlement' => $baseEntitlement,
            'carryover' => $carryover,
            'expiredCarryover' => $expiredCarryover,
            'periods' => $periods,
            'totalCredits' => $baseEntitlement + $carryover,
            'totalDebits' => (float)$row['vacationDays'] + $expiredCarryover,
            'remaining' => (float)$row['remainingDays'],
            'generatedAt' => $this->l10n->l('date', time()),
        ];

        ob_start();
        $l = $this->l10n;
        $amount = fn (float $value): string => $this->amount($value);
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

        $values = $row['dayValues'] ?? $row['calendarDayValues'] ?? [];
        return array_map('floatval', array_filter(
            $values,
            static fn (mixed $value, string $day): bool => $day >= (string)$range['start'] && $day <= (string)$range['end'],
            ARRAY_FILTER_USE_BOTH
        ));
    }

    private function date(string $day): string
    {
        $locale = method_exists($this->l10n, 'getLocaleCode') ? strtolower((string)$this->l10n->getLocaleCode()) : '';
        $format = str_starts_with($locale, 'de') ? 'd.m.Y' : (str_starts_with($locale, 'en_us') ? 'm/d/Y' : 'd/m/Y');
        return date($format, strtotime($day));
    }

    private function dateRange(string $start, string $end): string
    {
        return $start === $end ? $this->date($start) : $this->date($start) . ' - ' . $this->date($end);
    }

    private function amount(float $value): string
    {
        $locale = method_exists($this->l10n, 'getLocaleCode') ? strtolower((string)$this->l10n->getLocaleCode()) : '';
        $decimal = str_starts_with($locale, 'de') ? ',' : '.';
        $formatted = number_format($value, 2, $decimal, '');
        return rtrim(rtrim($formatted, '0'), $decimal);
    }

    private function approvalLines(mixed $approval, string $timeZone): array
    {
        if (!is_array($approval)) {
            return [$this->l10n->t('Not synchronized')];
        }

        $status = (string)($approval['status'] ?? '');
        if ($status === ApprovalService::STATUS_APPROVED) {
            $approvedAt = (int)($approval['approved_at'] ?? 0);
            $requestId = (int)($approval['id'] ?? 0);
            $revision = (int)($approval['current_revision'] ?? 0);
            if ($approvedAt > 0) {
                $approvedDateTime = (new DateTimeImmutable('@' . $approvedAt))->setTimezone(new DateTimeZone($timeZone));
                $date = $this->date($approvedDateTime->format('Y-m-d'));
                $time = $approvedDateTime->format('H:i');
                $lines = [$requestId > 0 && $revision > 0
                    ? $this->l10n->t('on %1$s at %2$s · Approval #%3$s-R%4$s', [$date, $time, $requestId, $revision])
                    : $this->l10n->t('on %1$s at %2$s', [$date, $time])];
            } else {
                $lines = [$this->l10n->t('on %1$s at %2$s', ['-', '-'])];
            }
            if ((int)($approval['auto_approved'] ?? 0) === 1) {
                $lines[] = $this->l10n->t('Automatic');
            } else {
                $approvedBy = trim((string)($approval['approvedDisplayName'] ?? $approval['approved_by'] ?? ''));
                $lines[] = $this->l10n->t('by %s', [$approvedBy !== '' ? $approvedBy : '-']);
            }
            $revisionHash = $this->revisionService->revisionHash($requestId, $revision);
            if ($revisionHash !== null) {
                $lines[] = 'SHA-256 ' . $revisionHash;
            }
            return $lines;
        }

        return [match ($status) {
            ApprovalService::STATUS_PENDING_APPROVAL => $this->l10n->t('Waiting for approval'),
            ApprovalService::STATUS_PENDING_DETECTION => $this->l10n->t('Waiting for stabilization'),
            ApprovalService::STATUS_CHANGED_AFTER_APPROVAL => $this->l10n->t('Changed after approval'),
            ApprovalService::STATUS_REJECTED => $this->l10n->t('Rejected'),
            ApprovalService::STATUS_CANCELLATION_PENDING => $this->l10n->t('Cancellation pending'),
            ApprovalService::STATUS_APPROVED_MISSING => $this->l10n->t('Booking retained'),
            ApprovalService::STATUS_CANCELLED => $this->l10n->t('Cancelled'),
            default => $this->l10n->t('Not synchronized'),
        }];
    }
}
