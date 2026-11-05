<?php declare(strict_types=1);
// The selected genuine dated report is already permission checked by the period ledger.
$balanceReport=$r;$balanceReportScreen=true;
?>
<div data-balance-period-report data-period-id="<?= e($r['id']) ?>">
<?php if($r['state']==='open'&&$canWrite): ?><details class="card"><summary><?= e($periodText('Bilanz, GuV und Kontennachweis ansehen')) ?></summary><?php require __DIR__.'/balance-report.php'; ?></details><?php else:require __DIR__.'/balance-report.php';endif ?>
</div>
<?php unset($balanceReportScreen,$balanceReport); ?>
