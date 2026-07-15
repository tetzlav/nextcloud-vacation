<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/Service/ApprovalService.php';
require_once dirname(__DIR__) . '/lib/Service/VacationReportService.php';
require_once dirname(__DIR__) . '/lib/Service/SpecialLeaveService.php';

use OCA\NextcloudVacation\Service\ApprovalService;
use OCA\NextcloudVacation\Service\SpecialLeaveService;
use OCA\NextcloudVacation\Service\VacationReportService;

$check = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$check(ApprovalService::canRejectStatus(ApprovalService::STATUS_PENDING_APPROVAL), 'Pending requests must be rejectable.');
$check(ApprovalService::canRejectStatus(ApprovalService::STATUS_CHANGED_AFTER_APPROVAL), 'Changed approved requests must be rejectable.');
$check(!ApprovalService::canRejectStatus(ApprovalService::STATUS_APPROVED), 'Approved requests must not be directly rejectable.');
$check(!ApprovalService::canRejectStatus(ApprovalService::STATUS_CANCELLATION_PENDING), 'Cancellation requests must not be directly rejectable.');
$check(!ApprovalService::canRejectStatus(ApprovalService::STATUS_CANCELLED), 'Cancelled requests must not be rejectable.');

$check(VacationReportService::isValidCarryoverMonthDay('03-31'), 'A real month-day was rejected.');
$check(!VacationReportService::isValidCarryoverMonthDay('13-40'), 'An impossible month-day was accepted.');
$check(!VacationReportService::isValidCarryoverMonthDay('02-29'), 'A deadline that is not valid every year was accepted.');
$check(!VacationReportService::isValidCarryoverMonthDay('3-31'), 'A non-canonical month-day was accepted.');

$service = (new ReflectionClass(VacationReportService::class))->newInstanceWithoutConstructor();
$parseAmount = new ReflectionMethod(VacationReportService::class, 'parseDayAmount');
$check($parseAmount->invoke($service, '-2,5', -36600, 36600, 'carryover') === -250, 'A decimal carryover was parsed incorrectly.');
$check($parseAmount->invoke($service, '30', 0, 36600, 'entitlement') === 3000, 'An entitlement was parsed incorrectly.');

foreach ([['abc', -36600, 36600], ['1.234', -36600, 36600], ['367', 0, 36600], ['-1', 0, 36600]] as [$value, $minimum, $maximum]) {
    try {
        $parseAmount->invoke($service, $value, $minimum, $maximum, 'test');
        throw new RuntimeException('Invalid day amount was accepted: ' . $value);
    } catch (ReflectionException $exception) {
        throw $exception;
    } catch (InvalidArgumentException) {
    }
}

$constructor = (new ReflectionClass(SpecialLeaveService::class))->getConstructor();
$lockingParameter = $constructor?->getParameters()[2] ?? null;
$check(
    $lockingParameter !== null && (string)$lockingParameter->getType() === 'OCP\Lock\ILockingProvider',
    'Special-leave writes are missing the Nextcloud locking provider.'
);

$info = simplexml_load_file(dirname(__DIR__) . '/appinfo/info.xml');
$check($info !== false, 'Could not parse app metadata.');
$phpDependency = $info->dependencies->php ?? null;
$check($phpDependency !== null && (string)$phpDependency['min-version'] === '8.1', 'PHP 8.1 metadata requirement is missing.');

echo "State and input guard tests passed.\n";
