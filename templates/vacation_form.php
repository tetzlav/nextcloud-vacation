<?php
$e = static fn (mixed $value): string => htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="<?php echo $e(method_exists($l, 'getLanguageCode') ? $l->getLanguageCode() : 'en'); ?>">
<head>
    <meta charset="utf-8">
    <title><?php echo $e($l->t('Vacation %1$s - %2$s', [$data['year'], $data['displayName']])); ?></title>
    <style>
        @page { margin: 12mm 13mm 13mm; }
        * { box-sizing: border-box; }
        body { margin: 0; color: #111; font-family: "DejaVu Sans", sans-serif; font-size: 8.2pt; line-height: 1.15; }
        .header { width: 100%; margin-bottom: 4mm; border-collapse: collapse; }
        .header td { border: 0; padding: 0; vertical-align: middle; }
        .title { font-size: 13pt; font-weight: bold; }
        .logo-cell { width: 35%; height: 15mm; text-align: right; }
        .logo { max-width: 55mm; max-height: 14mm; }
        .brand-fallback { color: #555; font-size: 11pt; font-weight: bold; }
        table.ledger { width: 100%; border-collapse: collapse; table-layout: fixed; }
        .ledger th, .ledger td { border: 0.3mm solid #333; padding: 0.75mm 1.25mm; vertical-align: middle; }
        .ledger th { font-size: 8pt; line-height: 1.05; }
        .ledger .description { width: 26%; font-weight: bold; }
        .ledger .number { width: 12%; text-align: right; }
        .ledger .debit { background: #e8e8e8; }
        .ledger .approval { width: 38%; }
        .ledger .balance { width: 12%; }
        .ledger .period td { font-weight: normal; }
        .ledger tr { page-break-inside: avoid; }
        .ledger .period .description { padding-left: 2mm; white-space: nowrap; }
        .ledger .special-leave-row .description span { display: block; }
        .ledger .special-leave-row .special-leave-reason { font-weight: normal; }
        .approval-line { display: block; font-size: 7pt; line-height: 1.15; }
        .approval-hash { font-family: "DejaVu Sans Mono", monospace; font-size: 4.2pt; line-height: 1.1; white-space: nowrap; }
        .ledger .totals td { border-top: 0.65mm solid #111; font-weight: bold; }
        .footer { position: fixed; bottom: -6mm; left: 0; right: 0; color: #666; font-size: 7pt; text-align: right; }
    </style>
</head>
<body>
    <table class="header">
        <tr>
            <td class="title"><?php echo $e($l->t('Vacation %1$s - %2$s', [$data['year'], $data['displayName']])); ?></td>
            <td class="logo-cell">
                <?php if ($data['logo'] !== null): ?>
                    <img class="logo" src="<?php echo $e($data['logo']); ?>" alt="">
                <?php else: ?>
                    <span class="brand-fallback"><?php echo $e($l->t('Vacation')); ?></span>
                <?php endif; ?>
            </td>
        </tr>
    </table>

    <table class="ledger">
        <thead>
            <tr>
                <th class="description"></th>
                <th class="number"><?php echo $e($l->t('Credit')); ?><br>+</th>
                <th class="number debit"><?php echo $e($l->t('Debit')); ?><br>-</th>
                <th class="approval"><?php echo $e($l->t('Approved')); ?><br>&nbsp;</th>
                <th class="number balance"><?php echo $e($l->t('Entitlement %s', [$data['year']])); ?></th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td class="description"><?php echo $e($l->t('Carryover from %s', [$data['year'] - 1])); ?></td>
                <td class="number"><?php echo $e($amount($data['carryover'])); ?></td>
                <td class="number debit"></td>
                <td class="approval"></td>
                <td class="number balance"></td>
            </tr>
            <tr>
                <td class="description"><?php echo $e($l->t('Vacation entitlement %s', [$data['year']])); ?></td>
                <td class="number"><?php echo $e($amount($data['baseEntitlement'])); ?></td>
                <td class="number debit"></td>
                <td class="approval"></td>
                <td class="number balance"></td>
            </tr>
            <?php foreach ($data['specialLeaveEntries'] as $entry): ?>
                <tr class="special-leave-row">
                    <td class="description">
                        <span><?php echo $e($l->t('Special leave')); ?></span>
                        <span class="special-leave-reason"><?php echo $e($entry['reason']); ?></span>
                    </td>
                    <td class="number"><?php echo $e($amount((float)$entry['amount'])); ?></td>
                    <td class="number debit"></td>
                    <td class="approval">
                        <?php foreach ($entry['postingLines'] as $line): ?>
                            <span class="approval-line<?php echo str_starts_with($line, 'SHA-256 ') ? ' approval-hash' : ''; ?>"><?php echo $e($line); ?></span>
                        <?php endforeach; ?>
                    </td>
                    <td class="number balance"></td>
                </tr>
            <?php endforeach; ?>
            <?php foreach ($data['periods'] as $period): ?>
                <tr class="period">
                    <td class="description"><?php echo $e($period['label']); ?></td>
                    <td class="number"></td>
                    <td class="number debit"><?php echo $e($amount((float)$period['days'])); ?></td>
                    <td class="approval">
                        <?php foreach ($period['approvalLines'] as $line): ?>
                            <span class="approval-line<?php echo str_starts_with($line, 'SHA-256 ') ? ' approval-hash' : ''; ?>"><?php echo $e($line); ?></span>
                        <?php endforeach; ?>
                    </td>
                    <td class="number balance"></td>
                </tr>
            <?php endforeach; ?>
            <?php if ($data['expiredCarryover'] > 0): ?>
                <tr class="period">
                    <td class="description"><?php echo $e($l->t('Expired carryover')); ?></td>
                    <td class="number"></td>
                    <td class="number debit"><?php echo $e($amount($data['expiredCarryover'])); ?></td>
                    <td class="approval"></td>
                    <td class="number balance"></td>
                </tr>
            <?php endif; ?>
            <tr class="totals">
                <td class="description"></td>
                <td class="number"><?php echo $e($amount($data['totalCredits'])); ?></td>
                <td class="number debit"><?php echo $e($amount($data['totalDebits'])); ?></td>
                <td class="approval"></td>
                <td class="number balance"><?php echo $e($amount($data['remaining'])); ?></td>
            </tr>
        </tbody>
    </table>

    <div class="footer"><?php echo $e($l->t('Printed on %s', [$data['generatedAt']])); ?></div>
</body>
</html>
