<?php

declare(strict_types=1);

namespace OCA\NextcloudVacation\Controller;

use InvalidArgumentException;
use OCA\NextcloudVacation\AppInfo\Application;
use OCA\NextcloudVacation\Service\ApprovalService;
use OCA\NextcloudVacation\Service\SpecialLeaveService;
use OCA\NextcloudVacation\Service\VacationReportService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\IRequest;
use OCP\IURLGenerator;

class SpecialLeaveController extends Controller
{
    public function __construct(
        string $appName,
        IRequest $request,
        private SpecialLeaveService $specialLeaveService,
        private VacationReportService $reportService,
        private ApprovalService $approvalService,
        private IURLGenerator $urlGenerator,
        private ?string $UserId
    ) {
        parent::__construct($appName, $request);
    }

    /**
     * @NoAdminRequired
     */
    public function grant(): RedirectResponse
    {
        $year = (int)$this->request->getParam('year', date('Y'));
        $userId = trim((string)$this->request->getParam('user_id', ''));
        $result = 'forbidden';

        if ($this->UserId !== null && $userId !== '' && $this->reportService->isCalendarAdmin($this->UserId)) {
            try {
                $entry = $this->specialLeaveService->grant(
                    $userId,
                    $year,
                    (string)$this->request->getParam('amount', ''),
                    (string)$this->request->getParam('reason', ''),
                    $this->UserId
                );
                $this->approvalService->notifySpecialLeavePosted($entry);
                $result = 'added';
            } catch (InvalidArgumentException) {
                $result = 'invalid';
            }
        }

        return new RedirectResponse($this->urlGenerator->linkToRoute(Application::APP_ID . '.page.approvals', [
            'year' => $year,
            'open_balance_user_id' => $userId,
            'special_leave_result' => $result,
        ]));
    }
}
