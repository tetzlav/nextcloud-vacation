<?php

declare(strict_types=1);

namespace OCA\NextcloudVacation\AppInfo;

use OCP\AppFramework\App;

class Application extends App
{
    public const APP_ID = 'nextcloud_vacation';

    public function __construct(array $urlParams = [])
    {
        $vendorAutoload = dirname(__DIR__, 2) . '/vendor/autoload.php';
        if (is_file($vendorAutoload)) {
            require_once $vendorAutoload;
        }

        parent::__construct(self::APP_ID, $urlParams);
    }
}
