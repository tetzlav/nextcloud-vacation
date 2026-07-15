<?php

declare(strict_types=1);

namespace OCA\NextcloudVacation\Controller;

use InvalidArgumentException;
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
        $result = 'forbidden';

        if ($this->UserId !== null && $userId !== '' && $this->reportService->isCalendarAdmin($this->UserId)) {
            try {
                $this->reportService->saveVacationBalanceSettings(
                    $userId,
                    $year,
                    $entitlement,
                    $amount,
                    $this->UserId
                );
                $result = 'saved';
            } catch (InvalidArgumentException) {
                $result = 'invalid';
            }
        }

        return new RedirectResponse($this->urlGenerator->linkToRoute(Application::APP_ID . '.page.approvals', [
            'year' => $year,
            'open_balance_user_id' => $userId,
            'balance_result' => $result,
        ]));
    }
}
