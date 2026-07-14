<?php

declare(strict_types=1);

namespace OCA\NextcloudVacation\Controller;

use OCA\NextcloudVacation\AppInfo\Application;
use OCA\NextcloudVacation\Settings\Section;
use OCA\NextcloudVacation\Service\PdfLogoService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\IConfig;
use OCP\IRequest;
use OCP\IURLGenerator;

class AdminSettingsController extends Controller
{
    public function __construct(
        string $appName,
        IRequest $request,
        private IConfig $config,
        private IURLGenerator $urlGenerator,
        private PdfLogoService $pdfLogoService
    ) {
        parent::__construct($appName, $request);
    }

    /**
     * @AdminRequired
     */
    public function save(): RedirectResponse
    {
        $this->setCsvAppValue('admin_groups');
        $this->setCsvAppValue('approver_users');
        $this->setCsvAppValue('auto_approval_groups');
        $this->setCsvAppValue('auto_approval_users');
        $this->setCheckboxAppValue('employee_notifications_enabled');
        $this->setAppValue('staff_group');
        $this->setAppValue('calendar_uri');
        $this->setAppValue('calendar_displayname');
        $this->setAppValue('vacation_keywords');
        $this->setAppValue('vacation_entitlement');
        $this->setAppValue('carryover_expires');
        $this->setAppValue('approval_wait_minutes');
        $this->setAppValue('sync_interval_minutes');
        $this->setAppValue('display_timezone');

        if ($this->request->getParam('remove_pdf_logo', '0') === '1') {
            $this->pdfLogoService->remove();
        } else {
            $upload = $this->request->getUploadedFile('pdf_logo');
            if (is_array($upload)) {
                $this->pdfLogoService->saveUploadedFile($upload);
            }
        }

        return new RedirectResponse($this->urlGenerator->linkToRoute('settings.AdminSettings.index', [
            'section' => Section::ID,
        ]));
    }

    private function setAppValue(string $key): void
    {
        $value = trim((string)$this->request->getParam($key, ''));
        $this->config->setAppValue(Application::APP_ID, $key, $value);
    }

    private function setCsvAppValue(string $key): void
    {
        $values = $this->request->getParam($key, []);
        if (!is_array($values)) {
            $values = explode(',', (string)$values);
        }

        $values = array_values(array_filter(array_map(static function ($value): string {
            return trim((string)$value);
        }, $values)));

        $this->config->setAppValue(Application::APP_ID, $key, implode(',', $values));
    }

    private function setCheckboxAppValue(string $key): void
    {
        $this->config->setAppValue(Application::APP_ID, $key, $this->request->getParam($key, '0') === '1' ? '1' : '0');
    }
}
