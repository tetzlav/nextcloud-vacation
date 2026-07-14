<?php

declare(strict_types=1);

namespace OCA\NextcloudVacation\Controller;

use DateTimeImmutable;
use DateTimeZone;
use Exception;
use OCA\NextcloudVacation\AppInfo\Application;
use OCA\NextcloudVacation\Service\ApprovalService;
use OCA\NextcloudVacation\Service\VacationPdfService;
use OCA\NextcloudVacation\Service\VacationReportService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\Response;
use OCP\IConfig;
use OCP\IL10N;
use OCP\IRequest;

class PdfController extends Controller
{
    public function __construct(
        string $appName,
        IRequest $request,
        private VacationReportService $reportService,
        private ApprovalService $approvalService,
        private VacationPdfService $pdfService,
        private IConfig $config,
        private IL10N $l10n,
        private ?string $UserId
    ) {
        parent::__construct($appName, $request);
    }

    /**
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function download(): Response
    {
        if ($this->UserId === null) {
            return new DataResponse(['error' => 'Not authenticated'], 401);
        }

        $year = (int)$this->request->getParam('year', date('Y'));
        if ($year < 2000 || $year > 2100) {
            return new DataResponse(['error' => 'Invalid year'], 400);
        }

        $report = $this->reportService->reportForUser($this->UserId, $year, false, false);
        $report = $this->approvalService->applyBookedDaysToReport($report, $year);
        $report = $this->approvalService->attachApprovalsToReport($report, $year);
        if (count($report) === 0) {
            return new DataResponse(['error' => 'No vacation data found'], 404);
        }

        $timeZone = $this->timeZoneForUser($this->UserId);
        $pdf = $this->pdfService->render($report[0], $year, $timeZone);
        $displayName = trim((string)($report[0]['displayName'] ?? $this->UserId));
        $generatedOn = (new DateTimeImmutable('now', new DateTimeZone($timeZone)))->format('Y-m-d');
        $filename = sprintf(
            '%s-%d_%s_%s.pdf',
            $this->filenamePart($this->l10n->t('Vacation'), 'Vacation'),
            $year,
            $this->filenamePart($displayName, $this->UserId),
            $generatedOn
        );

        return new DataDownloadResponse($pdf, $filename, 'application/pdf');
    }

    private function filenamePart(string $value, string $fallback): string
    {
        $value = strtr($value, [
            'Ä' => 'Ae',
            'Ö' => 'Oe',
            'Ü' => 'Ue',
            'ä' => 'ae',
            'ö' => 'oe',
            'ü' => 'ue',
            'ß' => 'ss',
        ]);
        $ascii = function_exists('iconv') ? iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value) : false;
        $slug = trim((string)preg_replace('/[^a-zA-Z0-9]+/', '-', $ascii === false ? $value : $ascii), '-');
        if ($slug !== '') {
            return $slug;
        }

        $fallbackSlug = trim((string)preg_replace('/[^a-zA-Z0-9]+/', '-', $fallback), '-');
        return $fallbackSlug !== '' ? $fallbackSlug : 'Benutzer';
    }

    private function timeZoneForUser(string $userId): string
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
                return (new DateTimeZone($candidate))->getName();
            } catch (Exception) {
                continue;
            }
        }

        return 'UTC';
    }
}
