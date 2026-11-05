<?php declare(strict_types=1);
use Kulturhus\Native\Language\ManagerLabels;
$excessText=static fn(string $key):string=>ManagerLabels::financeText('excess_'.$key,$managerLanguageMessages??[]);$excessEntryHistory=$finance->excessReturnsForEntry($r['id'],$_GET['excess_entry_page']??1);$excessEntrySources=[];
foreach($allocationRows as $link){if($link['voided_at']!==null||isset($excessEntrySources[$link['transaction_id']]))continue;$source=$finance->excessReturnSource($link['transaction_id']);if($source['eligible'])$excessEntrySources[$link['transaction_id']]=$source['transaction'];}
if(!$excessEntrySources&&!$excessEntryHistory['total'])return;
?>
<link rel="stylesheet" href="excess-returns.css?v=1">
<section class="card excess-returns" id="receipt-excess-returns"><h2><?= e($excessText('history')) ?></h2><p><?= e($excessText('history_hint')) ?></p>
<?php foreach($excessEntrySources as $source): ?><p><?= e($excessText('remaining')) ?>: <strong><?= e(euro($source['remaining_cents'])) ?></strong> · <a href="<?= e(url(bankTransactionContext($source['id'],$_GET)).'#excess-return-choice') ?>"><?= e($source['description']) ?></a></p><?php endforeach ?>
<?php foreach($excessEntryHistory['rows'] as $relation): ?><article class="excess-return-candidate"><h3><?= e(euro((int)$relation['cents'])) ?> · <?= e($relation['voided_at']===null?$excessText('active'):$excessText('released')) ?></h3><p class="excess-return-reason"><?= e($relation['reason']) ?></p><a href="<?= e(url(bankTransactionContext($relation['source_id'],$_GET)).'#excess-return-history') ?>"><?= e($excessText('source')) ?></a> · <a href="<?= e(url(bankTransactionContext($relation['returned_id'],$_GET)).'#excess-return-history') ?>"><?= e($excessText('return')) ?></a><?php if($relation['voided_at']!==null): ?><p class="excess-return-reason"><?= e($excessText('release_reason').': '.$relation['void_reason']) ?></p><?php endif ?></article><?php endforeach ?>
<?php $catalogue=$excessEntryHistory;$catalogueContext=receiptPaymentContext($r['id'],$_GET,false);$cataloguePageKey='excess_entry_page';$catalogueLabel=$excessText('history_pages');require __DIR__.'/../Banking/catalogue-pager.php'; ?>
</section>
