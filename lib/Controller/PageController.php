<?php

declare(strict_types=1);

namespace OCA\NextcloudVacation\Controller;

use DateTimeZone;
use Exception;
use OCA\NextcloudVacation\AppInfo\Application;
use OCA\NextcloudVacation\Service\ApprovalService;
use OCA\NextcloudVacation\Service\EmployeeApproverService;
use OCA\NextcloudVacation\Service\VacationReportService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IConfig;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\Util;

class PageController extends Controller
{
    public function __construct(
        string $appName,
        IRequest $request,
        private VacationReportService $reportService,
        private ApprovalService $approvalService,
        private EmployeeApproverService $employeeApproverService,
        private IURLGenerator $urlGenerator,
        private IConfig $config,
        private ?string $UserId
    ) {
        parent::__construct($appName, $request);
    }

    /**
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function index(): TemplateResponse
    {
        return $this->renderPage(false);
    }

    /**
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function approvals(): Response
    {
        if ($this->UserId === null || !$this->reportService->isCalendarAdmin($this->UserId)) {
            return new RedirectResponse($this->urlGenerator->linkToRoute(Application::APP_ID . '.page.index'));
        }

        return $this->renderPage(true);
    }

    private function renderPage(bool $approvalOverview): TemplateResponse
    {
        Util::addStyle(Application::APP_ID, 'style');
        Util::addScript(Application::APP_ID, 'main');

        $year = (int)$this->request->getParam('year', date('Y'));
        if ($year < 2000 || $year > 2100) {
            $year = (int)date('Y');
        }
        $debug = $this->request->getParam('debug', '0') === '1';
        $apiDebug = $debug && $this->request->getParam('api_debug', '0') === '1';

        $canManageApprovals = $this->UserId !== null && $this->reportService->isCalendarAdmin($this->UserId);
        $autoApprovalReason = $this->UserId === null || $approvalOverview
            ? null
            : $this->approvalService->autoApprovalReasonForUser($this->UserId);

        $report = $this->UserId === null
            ? []
            : ($approvalOverview
                ? $this->reportService->reportForStaff($year, $debug, $apiDebug)
                : $this->reportService->reportForUser($this->UserId, $year, $debug, $apiDebug));

        $report = $this->approvalService->applyBookedDaysToReport($report, $year);
        $report = $this->approvalService->attachApprovalsToReport($report, $year);
        $pdfUrls = [];
        foreach ($report as $row) {
            $reportUserId = (string)($row['userId'] ?? '');
            if ($reportUserId !== '') {
                $pdfUrls[$reportUserId] = $this->urlGenerator->linkToRoute(Application::APP_ID . '.pdf.download', [
                    'year' => $year,
                    'user_id' => $reportUserId,
                ]);
            }
        }

        return new TemplateResponse(Application::APP_ID, 'main', [
            'year' => $year,
            'debug' => $debug,
            'apiDebug' => $apiDebug,
            'approveResult' => (string)$this->request->getParam('approve_result', ''),
            'approvedId' => (int)$this->request->getParam('approved_id', 0),
            'rejectedId' => (int)$this->request->getParam('rejected_id', 0),
            'cancellationId' => (int)$this->request->getParam('cancellation_id', 0),
            'specialLeaveResult' => (string)$this->request->getParam('special_leave_result', ''),
            'approverResult' => (string)$this->request->getParam('approver_result', ''),
            'openUserId' => (string)$this->request->getParam('open_user_id', ''),
            'isAdmin' => $approvalOverview,
            'approvalOverview' => $approvalOverview,
            'canManageApprovals' => $canManageApprovals,
            'personalUrl' => $this->urlGenerator->linkToRoute(Application::APP_ID . '.page.index', ['year' => $year]),
            'approvalsUrl' => $this->urlGenerator->linkToRoute(Application::APP_ID . '.page.approvals', ['year' => $year]),
            'autoApprovalReason' => $autoApprovalReason,
            'employeeNotificationsEnabled' => $this->approvalService->employeeNotificationsEnabled(),
            'report' => $report,
            'adminGroup' => implode(', ', $this->reportService->adminGroups()),
            'staffGroup' => $this->reportService->staffGroup(),
            'calendarUri' => $this->reportService->calendarUri(),
            'calendarDisplayname' => $this->reportService->calendarDisplayName(),
            'vacationEntitlement' => $this->reportService->vacationEntitlement(),
            'timeZone' => $this->timeZoneForUser($this->UserId),
            'approveUrlTemplate' => $this->urlGenerator->linkToRoute(Application::APP_ID . '.approval.approve', ['id' => '__REQUEST_ID__']),
            'approveOpenYearUrl' => $this->urlGenerator->linkToRoute(Application::APP_ID . '.approval.approve_open_year'),
            'rejectUrlTemplate' => $this->urlGenerator->linkToRoute(Application::APP_ID . '.approval.reject', ['id' => '__REQUEST_ID__']),
            'confirmCancellationUrlTemplate' => $this->urlGenerator->linkToRoute(Application::APP_ID . '.approval.confirm_cancellation', ['id' => '__REQUEST_ID__']),
            'keepBookingUrlTemplate' => $this->urlGenerator->linkToRoute(Application::APP_ID . '.approval.keep_booking', ['id' => '__REQUEST_ID__']),
            'carryoverSaveUrl' => $this->urlGenerator->linkToRoute(Application::APP_ID . '.carryover.save'),
            'specialLeaveUrl' => $this->urlGenerator->linkToRoute(Application::APP_ID . '.special_leave.grant'),
            'approverAssignmentUrl' => $this->urlGenerator->linkToRoute(Application::APP_ID . '.approver_assignment.save'),
            'approverAssignments' => $approvalOverview ? $this->employeeApproverService->assignments() : [],
            'approverCandidates' => $approvalOverview ? $this->employeeApproverService->candidates() : [],
            'defaultApproverUsers' => $approvalOverview ? $this->approvalService->defaultApproverUsers() : [],
            'calendarNewEventUrl' => $this->urlGenerator->linkToRoute('calendar.view.indexdirect.new'),
            'pdfUrl' => $this->urlGenerator->linkToRoute(Application::APP_ID . '.pdf.download', ['year' => $year]),
            'pdfUrls' => $pdfUrls,
        ]);
    }

    private function timeZoneForUser(?string $userId): string
    {
        $candidates = [];
        if ($userId !== null) {
            $candidates[] = $this->config->getUserValue($userId, 'core', 'timezone', '');
        }
        $candidates[] = $this->config->getAppValue(Application::APP_ID, 'display_timezone', '');
        $candidates[] = (string)$this->config->getSystemValue('logtimezone', '');
        $candidates[] = date_default_timezone_get();
        $candidates[] = 'UTC';

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
