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
use OCP\IUserManager;
use OCP\L10N\IFactory as IL10NFactory;
use Throwable;

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
        private IL10NFactory $l10nFactory,
        private IUserManager $userManager,
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

        $requestedUserId = trim((string)$this->request->getParam('user_id', ''));
        $targetUserId = $requestedUserId === '' ? $this->UserId : $requestedUserId;
        if (
            $targetUserId !== $this->UserId
            && (!$this->reportService->isCalendarAdmin($this->UserId) || !$this->reportService->isStaffUser($targetUserId))
        ) {
            return new DataResponse(['error' => 'Forbidden'], 403);
        }

        $report = $this->reportService->reportForUser($targetUserId, $year, false, false);
        $report = $this->approvalService->applyBookedDaysToReport($report, $year);
        $report = $this->approvalService->attachApprovalsToReport($report, $year);
        if (count($report) === 0) {
            return new DataResponse(['error' => 'No vacation data found'], 404);
        }

        $timeZone = $this->timeZoneForUser($targetUserId);
        $targetL10n = $this->l10nForUser($targetUserId);
        $pdf = $this->pdfService->render($report[0], $year, $timeZone, $targetL10n);
        $displayName = trim((string)($report[0]['displayName'] ?? $targetUserId));
        $generatedOn = (new DateTimeImmutable('now', new DateTimeZone($timeZone)))->format('Y-m-d');
        $filename = sprintf(
            '%s-%d_%s_%s.pdf',
            $this->filenamePart($targetL10n->t('Vacation'), 'Vacation'),
            $year,
            $this->filenamePart($displayName, $targetUserId),
            $generatedOn
        );

        return new DataDownloadResponse($pdf, $filename, 'application/pdf');
    }

    private function l10nForUser(string $userId): IL10N
    {
        $user = $this->userManager->get($userId);
        if ($user === null) {
            return $this->l10n;
        }

        try {
            return $this->l10nFactory->get(
                Application::APP_ID,
                $this->l10nFactory->getUserLanguage($user)
            );
        } catch (Throwable) {
            return $this->l10n;
        }
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
