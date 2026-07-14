<?php

declare(strict_types=1);

namespace OCA\NextcloudVacation\Settings;

use OCA\NextcloudVacation\AppInfo\Application;
use OCA\NextcloudVacation\Service\ApprovalService;
use OCA\NextcloudVacation\Service\PdfLogoService;
use OCA\NextcloudVacation\Service\VacationReportService;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IConfig;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IURLGenerator;
use OCP\Settings\ISettings;
use OCP\Util;

class Admin implements ISettings
{
    public function __construct(
        private VacationReportService $reportService,
        private ApprovalService $approvalService,
        private IGroupManager $groupManager,
        private IURLGenerator $urlGenerator,
        private IConfig $config,
        private PdfLogoService $pdfLogoService
    ) {
    }

    public function getForm(): TemplateResponse
    {
        Util::addStyle(Application::APP_ID, 'admin');

        return new TemplateResponse(Application::APP_ID, 'settings/admin', [
            'saveUrl' => $this->urlGenerator->linkToRoute(Application::APP_ID . '.admin_settings.save'),
            'adminGroups' => $this->reportService->adminGroups(),
            'staffGroup' => $this->reportService->staffGroup(),
            'calendarUri' => $this->reportService->calendarUri(),
            'calendarDisplayname' => $this->reportService->calendarDisplayName(),
            'vacationKeywords' => $this->reportService->vacationKeywords(),
            'vacationEntitlement' => $this->reportService->vacationEntitlement(),
            'approvalWaitMinutes' => $this->approvalService->approvalWaitMinutes(),
            'syncIntervalMinutes' => max(1, min(1440, (int)$this->config->getAppValue(Application::APP_ID, 'sync_interval_minutes', '15'))),
            'autoApprovalGroups' => $this->approvalService->autoApprovalGroups(),
            'autoApprovalUsers' => $this->approvalService->autoApprovalUsers(),
            'autoApprovalUserCandidates' => $this->approvalService->autoApprovalUserCandidates(),
            'employeeNotificationsEnabled' => $this->approvalService->employeeNotificationsEnabled(),
            'carryoverExpires' => $this->reportService->carryoverExpiresMonthDay(),
            'displayTimezone' => $this->config->getAppValue(Application::APP_ID, 'display_timezone', ''),
            'timezones' => \DateTimeZone::listIdentifiers(),
            'approverUsers' => $this->approvalService->approverUsers(),
            'approverCandidates' => $this->approvalService->approverCandidates(),
            'groups' => $this->groups(),
            'pdfLogoConfigured' => $this->pdfLogoService->isConfigured(),
            'pdfLogoDataUri' => $this->pdfLogoService->dataUri(),
        ], '');
    }

    public function getSection(): string
    {
        return Section::ID;
    }

    public function getPriority(): int
    {
        return 50;
    }

    /**
     * @return array<string, string>
     */
    private function groups(): array
    {
        $groups = [];

        foreach ($this->groupManager->search('', null, null) as $group) {
            if (!$group instanceof IGroup) {
                continue;
            }

            $groups[$group->getGID()] = $group->getDisplayName();
        }

        asort($groups, SORT_NATURAL | SORT_FLAG_CASE);

        return $groups;
    }
}
