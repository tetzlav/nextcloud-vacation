<?php

declare(strict_types=1);

namespace OCA\NextcloudVacation\Controller;

use InvalidArgumentException;
use OCA\NextcloudVacation\AppInfo\Application;
use OCA\NextcloudVacation\Service\EmployeeApproverService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\IRequest;
use OCP\IURLGenerator;

class ApproverAssignmentController extends Controller
{
    public function __construct(
        string $appName,
        IRequest $request,
        private EmployeeApproverService $approverService,
        private IURLGenerator $urlGenerator,
        private ?string $UserId
    ) {
        parent::__construct($appName, $request);
    }

    /**
     * @NoAdminRequired
     */
    public function save(): RedirectResponse
    {
        $year = (int)$this->request->getParam('year', date('Y'));
        $employeeId = trim((string)$this->request->getParam('employee_id', ''));
        $approverId = trim((string)$this->request->getParam('approver_id', ''));
        $result = 'forbidden';

        if ($this->UserId !== null) {
            try {
                $this->approverService->save($employeeId, $approverId, $this->UserId);
                $result = 'saved';
            } catch (InvalidArgumentException) {
                $result = 'invalid';
            }
        }

        return new RedirectResponse($this->urlGenerator->linkToRoute(Application::APP_ID . '.page.approvals', [
            'year' => $year,
            'open_user_id' => $employeeId,
            'approver_result' => $result,
        ]));
    }
}
