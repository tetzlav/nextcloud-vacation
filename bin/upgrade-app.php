<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script can only be run from the command line.\n");
    exit(1);
}

$nextcloudDir = realpath($argv[1] ?? '/var/www/nextcloud');
if ($nextcloudDir === false || !is_file($nextcloudDir . '/lib/base.php')) {
    fwrite(STDERR, "Nextcloud installation not found.\n");
    exit(1);
}

define('OC_CONSOLE', 1);
chdir($nextcloudDir);
require_once $nextcloudDir . '/lib/base.php';

$appId = 'nextcloud_vacation';
fwrite(STDOUT, sprintf("Updating <%s> ...\n", $appId));
\OC_App::updateApp($appId);
fwrite(STDOUT, sprintf("Updated <%s>.\n", $appId));
