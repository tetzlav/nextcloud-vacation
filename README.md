# nextcloud-vacation

Nextcloud app for tracking and approving vacation periods from users' `Status` calendars.

The calendar is the source for new vacation requests: users enter vacation in their own
Status calendar, for example `XY: Urlaub` or `XY: Vacation`. The app counts matching vacation days,
shows remaining entitlement, supports half-day vacation entries, and stores approved periods as binding bookings separately in its own table.

## Compatibility

- Nextcloud 28 through 35, including Nextcloud 34
- PHP 8.1 or newer; the PHP version must also be supported by the installed Nextcloud release
- Active calendar data is read through Nextcloud's public `OCP\Calendar\IManager` API

The Nextcloud 34 compatibility review found no dependency on the removed jQuery, jQuery UI,
Backbone or legacy `OC.*` menu APIs. Database access uses the public query builder methods
`executeQuery()` and `executeStatement()` that remain supported in Nextcloud 34.

## Features

- Reads users' own Status calendars through Nextcloud's public calendar API.
- Counts weekdays whose summary, description, location or category contains `Urlaub` or `Vacation`. Half days are detected from the same `0,5`, `0.5`, optional `d`, and `1/2` forms before either word, for example `XY: 0.5d Vacation` or `XY: 1/2 Vacation`.
- Shows vacation entitlement, carryover from the previous year, taken days and remaining days.
- Lets calendar admins edit yearly carryover and optional per-user yearly entitlement inline; regular users see the entitlement composition without edit controls.
- Can calculate automatic carryover from the previous year when the employee had vacation activity and no manual carryover was configured.
- Lets configured admins view all users from the configured staff group.
- Tracks approval and rejection state per vacation period.
- Can automatically approve vacation for configured groups or users and shows the reason and timestamp in the overview.
- Queues pending-approval, automatic-approval, approval and rejection emails so web requests do not wait for SMTP delivery.
- Employee status emails can be disabled temporarily while testing.
- Keeps approval periods separated by calendar entry UID, so adjacent but separate vacation entries are not merged into one approval.
- Keeps approved days reserved if their calendar entry is later changed or removed. Only a calendar manager can confirm a cancellation and release those days.
- Stores manager decisions in an append-only audit table and every approved version as an immutable, hashed revision snapshot.
- The calendar API returns active objects only. Previously approved entries remain booked when they disappear and require an explicit cancellation decision.
- Debug mode shows matched events, approval timestamps and available `lastmodified` values.
- The personal overview includes concise localized instructions and a link to the Calendar event editor.
- Every user, including calendar managers, gets the same personal overview at `/apps/nextcloud_vacation/`.
- Calendar managers get a separate protected approval overview at `/apps/nextcloud_vacation/approvals` and a second entry in the app navigation.
- The year selector reloads the active personal or approval view immediately when its value changes.
- Balance values include their day unit, and carryover expiry is shown only for the amount that has not already been consumed before the cutoff.
- Users can download a compact yearly vacation summary PDF for their records. It contains entitlement, carryover, booked periods, approval date, local approval time, approver and the compact approval reference `Approval #<request>-R<revision>`.
- Administrators can upload a PNG or JPEG logo for the PDF in the Vacation settings. The logo is stored in private Nextcloud app data and therefore survives Git deployments and app updates.

## Defaults

- admin access: user `admin`, or members of the configured admin groups. Default group: `calendar-managers`
- staff group: `staff`
- calendar URI: `status`
- calendar display name fallback: `Status`
- vacation keywords: `Urlaub, Vacation`
- vacation entitlement: `30`
- carryover expiry: `03-31`
- counted days: Monday-Friday
- approval wait time: `60` minutes
- calendar scan interval: `15` minutes

## Installation

Keep the Git repository outside the Nextcloud installation and deploy a copy without
`.git` into the configured writable custom app directory:

The deployment host needs Composer. The deployment script installs the exact locked
production dependencies, including the PDF renderer, before synchronizing the app.
The app intentionally does not vendor Sabre/VObject or HTTP client libraries: calendar
access uses Nextcloud's public calendar API and the Sabre classes bundled with Nextcloud.
Shipping another Sabre version inside the app can break Nextcloud's PHP process.

```bash
sudo install -d -o nextcloud -g nextcloud /home/nextcloud/src
sudo -u nextcloud git clone \
  https://github.com/tetzlav/nextcloud-vacation.git \
  /home/nextcloud/src/nextcloud_vacation

sudo -u nextcloud \
  /home/nextcloud/src/nextcloud_vacation/bin/deploy-nextcloud

cd /var/www/nextcloud
sudo -u nextcloud php occ app:enable nextcloud_vacation
```

Use the web app at `/apps/nextcloud_vacation/`.

## Updating

For ordinary code, template, CSS or translation changes, run the deployment script.
It performs `git pull --ff-only` and synchronizes the app with `rsync --delete`, excluding
the Git metadata:

```bash
sudo -u nextcloud \
  /home/nextcloud/src/nextcloud_vacation/bin/deploy-nextcloud
```

After adding the script for the first time, pull once before invoking it:

```bash
sudo -u nextcloud git -C /home/nextcloud/src/nextcloud_vacation pull --ff-only
sudo -u nextcloud \
  /home/nextcloud/src/nextcloud_vacation/bin/deploy-nextcloud
```

Only when a release contains a new app migration, request `occ upgrade` explicitly:

```bash
sudo -u nextcloud \
  /home/nextcloud/src/nextcloud_vacation/bin/deploy-nextcloud --upgrade
```

Version `0.1.32` adds immutable approval revisions, snapshot hashes and the `vacation:request` inspection command. Its migration creates the revision table and records existing currently approved bookings as revision `R1`. Version `0.1.31` removes the obsolete CLI prototype and its private Sabre/Guzzle stack to avoid conflicts with Nextcloud's bundled libraries; it does not add a database migration. Version `0.1.30` adds the PDF export, configurable PDF logo, PDF route and locked Composer dependencies; it does not add a database migration. Version `0.1.29` separates the personal overview from the protected approval overview; it does not add a migration. Version `0.1.24` adds an audit table and binding approved bookings. Version `0.1.23` adds mail queue categories so employee notifications can be skipped while approver notifications still work. Version `0.1.22` adds a database migration for the async mail queue. Version `0.1.21` adds a database migration for calendar-entry source keys on vacation requests. Version `0.1.20` adds a database migration for per-user entitlements and auto-approval metadata. Version `0.1.19` added a database migration for rejection metadata. Version `0.1.18` added a database migration for carryovers and half-day approval counts. After pulling a version with migrations, run `occ upgrade` once. For later ordinary code, template, CSS or translation fixes, no migration command is needed. Only run `occ upgrade`
deliberately when an update actually contains a new app migration. In the tested
Nextcloud setup there is no per-app migration command, and `occ upgrade` can also
update unrelated apps.

## Configuration

The settings are available in the Nextcloud administration settings under the
dedicated section "Vacation" / "Urlaub". Group and approver settings use dropdowns
populated from existing Nextcloud users and groups.

The optional PDF logo is configured in the same section. PNG and RGB JPEG files up to
2 MB are accepted. Without a logo, the generated form uses a neutral text heading.
The logo is stored through Nextcloud's private AppData API rather than in
`custom_apps/nextcloud_vacation`, so `rsync --delete` does not remove it.

On the personal overview, **Download vacation summary PDF** creates the yearly archive
copy. The PDF endpoint always uses the signed-in user's own report; another employee
cannot be selected through URL parameters. Approved rows include a compact reference
such as `Approval #44-R2`, which identifies request `44` and its second approved revision.
The complete SHA-256 snapshot hash is printed as a small third line so an archived
paper or PDF copy provides an integrity reference outside the database.
Downloads use a sortable name based on year, display name and the local generation date,
for example `Urlaub-2026_Test-User_2026-07-14.pdf` or, with English user
settings, `Vacation-2026_Test-User_2026-07-14.pdf`.

The bundled Dompdf renderer creates a regular PDF 1.7 document. PDF/A is not emitted
directly; archival conformance would require server-side post-processing with an ICC
profile and validation by a PDF/A-aware tool.

The same settings can be changed with `occ`:

```bash
cd /var/www/nextcloud
sudo -u nextcloud php occ config:app:set nextcloud_vacation admin_groups --value="calendar-managers"
sudo -u nextcloud php occ config:app:set nextcloud_vacation staff_group --value="staff"
sudo -u nextcloud php occ config:app:set nextcloud_vacation calendar_uri --value="status"
sudo -u nextcloud php occ config:app:set nextcloud_vacation calendar_displayname --value="Status"
sudo -u nextcloud php occ config:app:set nextcloud_vacation vacation_keywords --value="Urlaub, Vacation"
sudo -u nextcloud php occ config:app:set nextcloud_vacation vacation_entitlement --value="30"
sudo -u nextcloud php occ config:app:set nextcloud_vacation carryover_expires --value="03-31"
sudo -u nextcloud php occ config:app:set nextcloud_vacation auto_approval_groups --value=""
sudo -u nextcloud php occ config:app:set nextcloud_vacation auto_approval_users --value=""
sudo -u nextcloud php occ config:app:set nextcloud_vacation employee_notifications_enabled --value="1"
sudo -u nextcloud php occ config:app:set nextcloud_vacation approval_wait_minutes --value="60"
sudo -u nextcloud php occ config:app:set nextcloud_vacation sync_interval_minutes --value="15"
sudo -u nextcloud php occ config:app:set nextcloud_vacation display_timezone --value="Europe/Berlin"
```

Timestamp display uses the requesting user timezone first, then the optional `display_timezone` app setting, the system/PHP timezone, and finally UTC as fallback. In the admin settings this can be left on "Automatic" unless an explicit fallback timezone is desired.
Vacation keywords are configured as a comma-separated list. They are escaped before
building the matching expression, so administrators enter plain words or phrases rather
than regular expressions. The default is `Urlaub, Vacation`.

Calendar admins can override the global yearly entitlement per employee and year in
the overview. Leave the employee entitlement field empty to use the global default.
Carryover can be entered manually per employee and year. If no manual carryover is
configured, the app calculates carryover from the previous year only when the employee
had vacation activity in that previous year. The carryover amount is the unused part
of the previous year's base entitlement, not the full entitlement. Carryover is counted
only until the configured `carryover_expires` date in the target year.
Vacation taken on or before that date consumes carryover first. After the cutoff,
the consumed part remains in the effective entitlement while only unused carryover
expires. The same allocation is used when calculating automatic carryover into the
following year.

## Approval Workflow

A background job scans the configured staff calendars every 15 minutes. Opening the
overview is read-only. Calendar managers can use **Synchronize now** to scan the
currently displayed year immediately through a CSRF-protected POST request instead
of waiting for the timed job.

New or changed vacation periods are stored as `pending_detection`. The stabilization
timestamp starts when the app first observes the current vacation period fingerprint.
Calendar object `lastmodified` values from the public calendar API are shown in
debug output as diagnostic values when available.
Once a period has stayed unchanged for the
configured wait time, it becomes `pending_approval` and configured approvers receive
an email with a link to the app. The requesting user also receives an email that the
request is now waiting for approval.

If the requesting user is in one of the configured `auto_approval_groups` or is listed
in `auto_approval_users`, the period is approved automatically after the normal
stabilization wait time. The overview shows that it was automatically approved,
including the reason and timestamp, and the requesting user receives an
automatic-approval email. Approvers are not notified for these requests.

Approval requests are keyed by the calendar entry UID in addition to their date range.
This keeps adjacent but separate entries, for example an already approved half day and
a newly entered multi-day vacation directly before it, as separate approval periods.

Mail notifications are written to `oc_vacation_mail_queue` first. The app background
job sends queued mail in small batches on every regular Nextcloud cron run, while
calendar scans use the configurable `sync_interval_minutes` interval (default: 15 minutes). Approving many periods therefore
does not block the browser request. Disable `employee_notifications_enabled` in the admin settings while
testing if employees should not receive status emails. Employee notification queue
entries are skipped while this setting is disabled; approver notifications are still
queued and sent. Subjects, message bodies, dates and decimal values are localized for
each recipient using their Nextcloud language and locale settings. Auto-approval reasons
are stored as language-neutral identifiers and translated when displayed or mailed.

Inspect queue entries with:

```bash
sudo -u nextcloud php occ vacation:mail-queue
sudo -u nextcloud php occ vacation:mail-queue --status=failed
sudo -u nextcloud php occ vacation:mail-queue --status=all --limit=100
```

The app also provides synchronization and read-only inspection commands:

```bash
sudo -u nextcloud php occ vacation:sync 2026
sudo -u nextcloud php occ vacation:requests 2026
sudo -u nextcloud php occ vacation:requests 2026 --type=cancellations
sudo -u nextcloud php occ vacation:requests 2026 --type=all
sudo -u nextcloud php occ vacation:request 44
sudo -u nextcloud php occ vacation:request 44 --revision=2 --compare-current
sudo -u nextcloud php occ vacation:request 44 --revision=2 --json
```

`vacation:request` shows the current request, every immutable approval revision, its
SHA-256 integrity status and the append-only audit history. `--compare-current` compares
the selected revision (the latest by default) with the current Status calendar entry.
Approved periods also expose their compact approval reference and full snapshot hash in
an optional disclosure in the web overview.

Bulk approval commands require the user ID of a configured calendar manager and ask
for confirmation. Use `--yes` only for deliberate non-interactive execution:

```bash
sudo -u nextcloud php occ vacation:approve-year 2026 --actor=test.user
sudo -u nextcloud php occ vacation:confirm-cancellations 2026 --actor=test.user
```

Bulk approval never bypasses the stabilization wait. Requests in `pending_detection`
remain untouched, and stabilized requests covered by an auto-approval user or group are
approved automatically before the remaining manual requests are processed.

If an older bulk operation incorrectly stored approvals for an auto-approval user as
manual, they can be reclassified without changing calendar events. The command keeps
the original approval time, records a new immutable revision and audit entry, and does
not queue email:

```bash
sudo -u nextcloud php occ vacation:reclassify-auto-approvals 2026 \
  --user=employee.user \
  --actor=test.user
```

Year-wide approvals queue employee notifications by default, and year-wide cancellation
confirmation queues employee and approver notifications. Use `--no-notify` for a single
silent bulk operation; the global `employee_notifications_enabled` setting remains the
central switch for all employee notification emails.

Calendar admins can approve pending periods directly in the separate **Approvals** view.
Approver emails link to that view and open the affected employee in the relevant year;
employee emails continue to link to the personal overview. Approval stores
`approved_by` and `approved_at` in `oc_vacation_requests` using the configured table
prefix, increments `current_revision`, stores the complete approved state in
`oc_vacation_request_revisions`, and sends an email to the requesting user. Revisions
are created only by a manual, bulk or automatic approval. Synchronization, cancellation
and page reloads do not increment the revision. Calendar admins can also approve
all open periods for the displayed year with the maintenance button above the table;
that bulk action queues individual notification emails unless employee notifications are
globally disabled. Calendar admins can also reject
pending periods with an optional reason. Rejection stores `rejected_by`, `rejected_at`
and `rejection_reason`, sends an email to the requesting user, and does not change
the calendar entry or vacation day calculation. If an approved period later disappears
or changes in the calendar, its approved snapshot remains part of the vacation balance
and is marked as `cancellation_pending`. A calendar manager can confirm the cancellation,
which releases the booked days, or explicitly retain the booking. Until that decision,
an edited entry may reserve both the original approved booking and the new calendar request.
This conservative behavior prevents deleting past approved vacation from silently restoring
entitlement. Cancellation and retain decisions are stored in `oc_vacation_request_audit`.
Approval status changes and their audit records are committed in one database transaction.
Calendar managers can also confirm all `cancellation_pending` bookings for the displayed
year with one bulk action. Bookings explicitly retained as `approved_missing` are not
affected by this action.
Confirmed cancellations queue localized completion emails for the employee and the
configured approvers, including period, day count, actor, timestamp and an optional
reason. Employee delivery follows the `employee_notifications_enabled` setting.

## Debugging

Open the app with `?debug=1` to show matched calendar events and approval internals.
`last_seen_at` is refreshed whenever an open pending request is observed again; `updated_at` changes only when the stored approval request itself changes.
During the calendar API migration, `?debug=1&api_debug=1` additionally shows a
small sample of the raw public calendar API result shape for troubleshooting.

Debug output includes:

- event UID, dates and matched days
- calendar object `lastmodified` and range `lastmodified_at`
- approval `first_seen_at`, `last_seen_at`, `notified_at`, `approved_at` and `updated_at`
- approval request ID and current revision
- auto-approval flag and reason
- calendar entry `source_key`
- rejection `rejected_at` and `rejection_reason`

The public Nextcloud calendar API returns active calendar objects. Deleted raw
calendar rows are therefore no longer shown in debug mode; removed or changed
vacation periods are still detected through the app's stored approval fingerprints.

## Translations

Nextcloud loads app translations from the `l10n/` directory. This app ships a German
translation in `l10n/de.js` and `l10n/de.json`. New UI strings should be wrapped with
`$l->t('...')` in templates and then added to both translation files. Vacation periods
are rendered through Nextcloud localization so dates follow the user locale.
