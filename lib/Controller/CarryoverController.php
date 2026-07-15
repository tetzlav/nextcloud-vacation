<?php

declare(strict_types=1);

namespace OCA\NextcloudVacation\Controller;

use OCA\NextcloudVacation\AppInfo\Application;
use OCA\NextcloudVacation\Service\VacationReportService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\IRequest;
use OCP\IURLGenerator;

class CarryoverController extends Controller
{
    public function __construct(
        string $appName,
        IRequest $request,
        private VacationReportService $reportService,
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
        $userId = trim((string)$this->request->getParam('user_id', ''));
        $amount = (string)$this->request->getParam('carryover', '0');
        $entitlement = (string)$this->request->getParam('entitlement', '');

        if ($this->UserId !== null && $userId !== '' && $this->reportService->isCalendarAdmin($this->UserId)) {
            $this->reportService->savePersonalEntitlement($userId, $year, $entitlement, $this->UserId);
            $this->reportService->saveCarryover($userId, $year, $amount, $this->UserId);
        }

        return new RedirectResponse($this->urlGenerator->linkToRoute(Application::APP_ID . '.page.approvals', [
            'year' => $year,
            'open_balance_user_id' => $userId,
        ]));
    }
}
