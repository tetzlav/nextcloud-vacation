<?php

declare(strict_types=1);

namespace OCA\NextcloudVacation\Cron;

use OCA\NextcloudVacation\AppInfo\Application;
use OCA\NextcloudVacation\Service\ApprovalService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\IConfig;

class SyncVacationRequestsJob extends TimedJob
{
    private const JOB_INTERVAL = 60;
    private const DEFAULT_SYNC_INTERVAL_MINUTES = 15;
    private const LAST_SYNC_CONFIG_KEY = 'last_calendar_sync_at';

    public function __construct(
        ITimeFactory $time,
        private ApprovalService $approvalService,
        private IConfig $config
    ) {
        parent::__construct($time);
        $this->setInterval(self::JOB_INTERVAL);
        $this->setAllowParallelRuns(false);
    }

    protected function run($arguments): void
    {
        // Flush mail queued by web actions without waiting for the calendar scan.
        $this->approvalService->sendQueuedMails();

        $now = time();
        $lastSyncAt = (int)$this->config->getAppValue(
            Application::APP_ID,
            self::LAST_SYNC_CONFIG_KEY,
            '0'
        );
        $syncIntervalMinutes = max(1, min(1440, (int)$this->config->getAppValue(
            Application::APP_ID,
            'sync_interval_minutes',
            (string)self::DEFAULT_SYNC_INTERVAL_MINUTES
        )));
        if ($lastSyncAt + ($syncIntervalMinutes * 60) > $now) {
            return;
        }

        $this->approvalService->syncOpenRequests();
        $this->config->setAppValue(Application::APP_ID, self::LAST_SYNC_CONFIG_KEY, (string)$now);

        // Also deliver notifications created by this calendar scan.
        $this->approvalService->sendQueuedMails();
    }
}
