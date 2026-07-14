<?php

declare(strict_types=1);

namespace OCA\NextcloudVacation\Settings;

use OCA\NextcloudVacation\AppInfo\Application;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\Settings\IIconSection;

class Section implements IIconSection
{
    public const ID = 'nextcloud-vacation';

    public function __construct(
        private IL10N $l,
        private IURLGenerator $urlGenerator
    ) {
    }

    public function getID(): string
    {
        return self::ID;
    }

    public function getName(): string
    {
        return $this->l->t('Vacation');
    }

    public function getPriority(): int
    {
        return 50;
    }

    public function getIcon(): string
    {
        return $this->urlGenerator->imagePath(Application::APP_ID, 'settings.svg');
    }
}