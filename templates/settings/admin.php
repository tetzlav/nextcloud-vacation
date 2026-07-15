<?php
$groups = $_['groups'];
$adminGroups = $_['adminGroups'];
$staffGroup = $_['staffGroup'];
$approverUsers = $_['approverUsers'];
$approverCandidates = $_['approverCandidates'];
$autoApprovalGroups = $_['autoApprovalGroups'];
$autoApprovalUsers = $_['autoApprovalUsers'];
$autoApprovalUserCandidates = $_['autoApprovalUserCandidates'];
$requestToken = $_['requesttoken'] ?? '';

foreach ($adminGroups as $configuredGroup) {
    if ($configuredGroup !== '' && !isset($groups[$configuredGroup])) {
        $groups[$configuredGroup] = $configuredGroup;
    }
}

foreach ($autoApprovalGroups as $configuredGroup) {
    if ($configuredGroup !== '' && !isset($groups[$configuredGroup])) {
        $groups[$configuredGroup] = $configuredGroup;
    }
}

if ($staffGroup !== '' && !isset($groups[$staffGroup])) {
    $groups[$staffGroup] = $staffGroup;
}
?>
<div class="section" id="nextcloud-vacation-admin-settings">
    <h2><?php p($l->t('Vacation')); ?></h2>
    <form method="post" enctype="multipart/form-data" action="<?php p($_['saveUrl']); ?>" class="vacation-settings-form">
        <?php if ($requestToken !== ''): ?>
            <input type="hidden" name="requesttoken" value="<?php p($requestToken); ?>">
        <?php endif; ?>
        <div class="vacation-settings-row">
            <label for="nextcloud-vacation-admin-groups"><?php p($l->t('Admin groups')); ?></label>
            <select id="nextcloud-vacation-admin-groups" name="admin_groups[]" multiple>
                <?php foreach ($groups as $groupId => $groupName): ?>
                    <option value="<?php p($groupId); ?>" <?php if (in_array($groupId, $adminGroups, true)) { print_unescaped('selected'); } ?>><?php p($groupName); ?></option>
                <?php endforeach; ?>
            </select>
            <div class="settings-hint"><?php p($l->t('Groups that may view the staff overview and approve vacation.')); ?></div>
        </div>

        <div class="vacation-settings-row">
            <label for="nextcloud-vacation-approver-users"><?php p($l->t('Approvers')); ?></label>
            <select id="nextcloud-vacation-approver-users" name="approver_users[]" multiple>
                <?php foreach ($approverCandidates as $userId => $displayName): ?>
                    <option value="<?php p($userId); ?>" <?php if (in_array($userId, $approverUsers, true)) { print_unescaped('selected'); } ?>><?php p($displayName); ?></option>
                <?php endforeach; ?>
            </select>
            <div class="settings-hint"><?php p($l->t('Users who receive approval emails. If none are selected, all admin group members are notified.')); ?></div>
        </div>

        <div class="vacation-settings-row">
            <label for="nextcloud-vacation-auto-approval-groups"><?php p($l->t('Auto-approval groups')); ?></label>
            <select id="nextcloud-vacation-auto-approval-groups" name="auto_approval_groups[]" multiple>
                <?php foreach ($groups as $groupId => $groupName): ?>
                    <option value="<?php p($groupId); ?>" <?php if (in_array($groupId, $autoApprovalGroups, true)) { print_unescaped('selected'); } ?>><?php p($groupName); ?></option>
                <?php endforeach; ?>
            </select>
            <div class="settings-hint"><?php p($l->t('Vacation periods from members of these groups are approved automatically.')); ?></div>
        </div>

        <div class="vacation-settings-row">
            <label for="nextcloud-vacation-auto-approval-users"><?php p($l->t('Auto-approval users')); ?></label>
            <select id="nextcloud-vacation-auto-approval-users" name="auto_approval_users[]" multiple>
                <?php foreach ($autoApprovalUserCandidates as $userId => $displayName): ?>
                    <option value="<?php p($userId); ?>" <?php if (in_array($userId, $autoApprovalUsers, true)) { print_unescaped('selected'); } ?>><?php p($displayName); ?></option>
                <?php endforeach; ?>
            </select>
            <div class="settings-hint"><?php p($l->t('Vacation periods from these users are approved automatically.')); ?></div>
        </div>

        <div class="vacation-settings-row">
            <label for="nextcloud-vacation-employee-notifications"><?php p($l->t('Employee emails')); ?></label>
            <input id="nextcloud-vacation-employee-notifications" type="checkbox" name="employee_notifications_enabled" value="1" <?php if ($_['employeeNotificationsEnabled']) { print_unescaped('checked'); } ?>>
            <div class="settings-hint"><?php p($l->t('Send approval status emails to employees. Disable this while testing to avoid noisy notifications.')); ?></div>
        </div>

        <div class="vacation-settings-row">
            <label for="nextcloud-vacation-staff-group"><?php p($l->t('Staff group')); ?></label>
            <select id="nextcloud-vacation-staff-group" name="staff_group">
                <?php foreach ($groups as $groupId => $groupName): ?>
                    <option value="<?php p($groupId); ?>" <?php if ($groupId === $staffGroup) { print_unescaped('selected'); } ?>><?php p($groupName); ?></option>
                <?php endforeach; ?>
            </select>
            <div class="settings-hint"><?php p($l->t('Users in this group are included in the staff overview.')); ?></div>
        </div>

        <div class="vacation-settings-row">
            <label for="nextcloud-vacation-calendar-uri"><?php p($l->t('Calendar URI')); ?></label>
            <input id="nextcloud-vacation-calendar-uri" type="text" name="calendar_uri" value="<?php p($_['calendarUri']); ?>">
            <div class="settings-hint"><?php p($l->t('Internal calendar URI, usually status.')); ?></div>
        </div>

        <div class="vacation-settings-row">
            <label for="nextcloud-vacation-calendar-displayname"><?php p($l->t('Calendar name')); ?></label>
            <input id="nextcloud-vacation-calendar-displayname" type="text" name="calendar_displayname" value="<?php p($_['calendarDisplayname']); ?>">
            <div class="settings-hint"><?php p($l->t('Display name shown when a calendar is found by name.')); ?></div>
        </div>

        <div class="vacation-settings-row">
            <label for="nextcloud-vacation-keywords"><?php p($l->t('Vacation keywords')); ?></label>
            <input id="nextcloud-vacation-keywords" type="text" name="vacation_keywords" value="<?php p($_['vacationKeywords']); ?>" placeholder="Urlaub, Vacation">
            <div class="settings-hint"><?php p($l->t('Comma-separated words that identify vacation entries, for example Urlaub, Vacation.')); ?></div>
        </div>

        <div class="vacation-settings-row">
            <label for="nextcloud-vacation-entitlement"><?php p($l->t('Vacation entitlement')); ?></label>
            <input id="nextcloud-vacation-entitlement" type="number" min="0" name="vacation_entitlement" value="<?php p($_['vacationEntitlement']); ?>">
            <div class="settings-hint"><?php p($l->t('Default annual vacation entitlement in days.')); ?></div>
        </div>

        <div class="vacation-settings-row">
            <label for="nextcloud-vacation-carryover-expires"><?php p($l->t('Carryover expires')); ?></label>
            <input id="nextcloud-vacation-carryover-expires" type="text" name="carryover_expires" value="<?php p($_['carryoverExpires']); ?>" placeholder="03-31" pattern="\d{2}-\d{2}" maxlength="5" required>
            <div class="settings-hint"><?php p($l->t('Month and day until carryover is available, formatted as MM-DD.')); ?></div>
        </div>

        <div class="vacation-settings-row">
            <label for="nextcloud-vacation-approval-wait"><?php p($l->t('Approval wait time')); ?></label>
            <input id="nextcloud-vacation-approval-wait" type="number" min="0" name="approval_wait_minutes" value="<?php p($_['approvalWaitMinutes']); ?>">
            <div class="settings-hint"><?php p($l->t('Minutes a vacation entry must stay unchanged before approvers are notified.')); ?></div>
        </div>

        <div class="vacation-settings-row">
            <label for="nextcloud-vacation-pdf-logo"><?php p($l->t('PDF logo')); ?></label>
            <div class="vacation-logo-control">
                <?php if ($_['pdfLogoConfigured'] && $_['pdfLogoDataUri'] !== null): ?>
                    <img class="vacation-logo-preview" src="<?php p($_['pdfLogoDataUri']); ?>" alt="<?php p($l->t('Current PDF logo')); ?>">
                    <label class="vacation-logo-remove">
                        <input type="checkbox" name="remove_pdf_logo" value="1">
                        <span><?php p($l->t('Remove logo')); ?></span>
                    </label>
                <?php endif; ?>
                <input id="nextcloud-vacation-pdf-logo" type="file" name="pdf_logo" accept="image/png,image/jpeg">
            </div>
            <div class="settings-hint"><?php p($l->t('Logo for vacation summary PDFs. PNG or RGB JPEG, maximum 2 MB. It is stored in private Nextcloud app data.')); ?></div>
        </div>

        <div class="vacation-settings-row">
            <label for="nextcloud-vacation-sync-interval"><?php p($l->t('Calendar scan interval')); ?></label>
            <input id="nextcloud-vacation-sync-interval" type="number" min="1" max="1440" name="sync_interval_minutes" value="<?php p($_['syncIntervalMinutes']); ?>">
            <div class="settings-hint"><?php p($l->t('Minutes between calendar scans. Mail delivery is checked on every regular Nextcloud cron run.')); ?></div>
        </div>

        <div class="vacation-settings-row">
            <label for="nextcloud-vacation-display-timezone"><?php p($l->t('Display timezone')); ?></label>
            <select id="nextcloud-vacation-display-timezone" name="display_timezone">
                <option value="" <?php if ($_['displayTimezone'] === '') { print_unescaped('selected'); } ?>><?php p($l->t('Automatic')); ?></option>
                <?php foreach ($_['timezones'] as $timezone): ?>
                    <option value="<?php p($timezone); ?>" <?php if ($timezone === $_['displayTimezone']) { print_unescaped('selected'); } ?>><?php p($timezone); ?></option>
                <?php endforeach; ?>
            </select>
            <div class="settings-hint"><?php p($l->t('Optional fallback timezone. Automatic uses the user timezone, then the system timezone, then UTC.')); ?></div>
        </div>

        <div class="vacation-settings-actions">
            <button type="submit" class="primary"><?php p($l->t('Save')); ?></button>
        </div>
    </form>
</div>
