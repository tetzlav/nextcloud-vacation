<?php

declare(strict_types=1);

namespace OCA\NextcloudVacation\Controller;

use OCA\NextcloudVacation\AppInfo\Application;
use OCA\NextcloudVacation\Service\ApprovalService;
use OCA\NextcloudVacation\Service\VacationReportService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\IRequest;
use OCP\IURLGenerator;

class ApprovalController extends Controller
{
    public function __construct(
        string $appName,
        IRequest $request,
        private ApprovalService $approvalService,
        private VacationReportService $reportService,
        private IURLGenerator $urlGenerator,
        private ?string $UserId
    ) {
        parent::__construct($appName, $request);
    }

    /**
     * @NoAdminRequired
     */
    public function approve(int $id): RedirectResponse
    {
        $result = 'not_logged_in';
        if ($this->UserId !== null && $this->reportService->isCalendarAdmin($this->UserId)) {
            $result = $this->approvalService->approve($id, $this->UserId);
        } elseif ($this->UserId !== null) {
            $result = 'not_admin';
        }

        $year = (int)$this->request->getParam('year', date('Y'));
        $params = [
            'year' => $year,
            'approve_result' => $result,
            'approved_id' => $id,
        ];
        if ($this->request->getParam('debug', '0') === '1') {
            $params['debug'] = '1';
        }

        return new RedirectResponse($this->urlGenerator->linkToRoute(Application::APP_ID . '.page.approvals', $params));
    }

    /**
     * @NoAdminRequired
     */
    public function approveOpenYear(): RedirectResponse
    {
        $year = (int)$this->request->getParam('year', date('Y'));

        if ($this->UserId !== null && $this->reportService->isCalendarAdmin($this->UserId)) {
            $action = $this->request->getParam('action', '');

            if ($action === 'sync') {
                $this->approvalService->syncOpenRequests([$year]);
            } elseif ($action === 'confirm_cancellations') {
                $this->approvalService->confirmCancellationsForYear($year, $this->UserId);
            } elseif ($action === 'approve') {
                $this->approvalService->approveOpenRequestsForYear($year, $this->UserId);
            }
        }

        return new RedirectResponse($this->urlGenerator->linkToRoute(Application::APP_ID . '.page.approvals', [
            'year' => $year,
        ]));
    }

    /**
     * @NoAdminRequired
     */
    public function reject(int $id): RedirectResponse
    {
        if ($this->UserId !== null && $this->reportService->isCalendarAdmin($this->UserId)) {
            $reason = trim((string)$this->request->getParam('reason', ''));
            $this->approvalService->reject($id, $this->UserId, $reason);
        }

        $year = (int)$this->request->getParam('year', date('Y'));
        $params = [
            'year' => $year,
            'rejected_id' => $id,
        ];
        if ($this->request->getParam('debug', '0') === '1') {
            $params['debug'] = '1';
        }

        return new RedirectResponse($this->urlGenerator->linkToRoute(Application::APP_ID . '.page.approvals', $params));
    }

    /**
     * @NoAdminRequired
     */
    public function confirmCancellation(int $id): RedirectResponse
    {
        return $this->cancellationDecision($id, true);
    }

    /**
     * @NoAdminRequired
     */
    public function keepBooking(int $id): RedirectResponse
    {
        return $this->cancellationDecision($id, false);
    }

    private function cancellationDecision(int $id, bool $cancel): RedirectResponse
    {
        if ($this->UserId !== null && $this->reportService->isCalendarAdmin($this->UserId)) {
            $reason = trim((string)$this->request->getParam('reason', ''));
            if ($cancel) {
                $this->approvalService->confirmCancellation($id, $this->UserId, $reason);
            } else {
                $this->approvalService->keepBooking($id, $this->UserId, $reason);
            }
        }

        $params = [
            'year' => (int)$this->request->getParam('year', date('Y')),
            'open_user_id' => (string)$this->request->getParam('user_id', ''),
            'cancellation_id' => $id,
        ];
        if ($this->request->getParam('debug', '0') === '1') {
            $params['debug'] = '1';
        }

        return new RedirectResponse($this->urlGenerator->linkToRoute(Application::APP_ID . '.page.approvals', $params));
    }
}
