<?php declare(strict_types=1);
$sourceText=static fn(string $text):string=>\Kulturhus\Native\Language\FinanceReceiptMessages::text($text,$financeFrameMessages??[]);
$sourceRequest=$financialOriginalSource;$sourceReceipt=$sourceRequest['kind']==='receipt';
$sourceTitle=$sourceRequest['kind']==='ambiguous'?'Finanzquelle prüfen':($sourceReceipt?'Beleg prüfen':'Kontoumsatz prüfen');
$sourceContextKeys=$sourceReceipt?['kind','status','from','to','q','account','direction','sort','order','per_page','page']:['account','cash_page','bank_page','bank_q','assignment','bank_from','bank_to','bank_direction','bank_amount','from','to','import_record','import_page','import_q','import_state','import_from','import_to','import_mapping','import_transaction'];$sourceContextInvalid=array_key_exists('invoice_return_invalid',$_GET);foreach(array_intersect_key($_GET,array_flip(array_merge($sourceContextKeys,['from_invoice','invoices_q','inv_status','inv_kind','invoices_page','invoice_return_invalid'])))as$value)if(!is_string($value))$sourceContextInvalid=true;
$sourceListBase=$sourceReceipt?['view'=>$view==='donations'?'donations':'finance','tab'=>$view==='donations'?'money':($tab==='contributions'?'contributions':'receipts')]:['view'=>'finance','tab'=>'bank'];
$sourceList=$sourceListBase+($sourceContextInvalid?[]:scalarFields($_GET,$sourceContextKeys));
$sourceRetry=$sourceRequest['raw']!==null?($sourceContextInvalid?$sourceListBase+[$sourceRequest['field']=>$sourceRequest['raw']]:($sourceReceipt?receiptPaymentContext($sourceRequest['raw'],$_GET,false):bankTransactionContext($sourceRequest['raw'],$_GET))):null;
?>
<link rel="stylesheet" href="finance-receipt.css?v=5">
<div data-financial-original-page><div class="page-heading financial-original-heading"><h1><?=e($sourceText($sourceTitle))?></h1></div>
<div class="content"><section class="card financial-original-unavailable" data-financial-original-unavailable="<?=e($sourceRequest['kind'])?>">
<p class="notice" role="alert"><?=e($sourceText($sourceRequest['message']))?></p>
<p><?=e($sourceText('Es wurde keine andere Quelle und keine Ersatzliste mit Beträgen geöffnet. Prüfe die ursprüngliche Kennung oder öffne die Liste bewusst.'))?></p>
<?php if($sourceRequest['raw']!==null): ?><dl class="data-list"><dt><?=e($sourceText('Ursprünglich angeforderte Kennung'))?></dt><dd data-financial-requested-id><?=e($sourceRequest['raw'])?></dd></dl><?php endif ?>
<div class="actions"><?php if($sourceRetry!==null): ?><a class="btn btn-outline theme-secondary" data-financial-source-retry href="<?=e(url($sourceRetry))?>"><?=e($sourceText($sourceContextInvalid?'Quelle ohne bisherige Filter erneut prüfen':'Genau diese Quelle erneut prüfen'))?></a><?php endif ?><a class="btn btn-outline theme-secondary" data-financial-source-list href="<?=e(url($sourceList))?>"><?=e($sourceText($sourceContextInvalid?'Liste ohne bisherige Filter ausdrücklich öffnen':($sourceReceipt?'Belegliste bewusst öffnen':'Kontoliste bewusst öffnen')))?></a><?php if($sourceRequest['kind']==='ambiguous'): ?><a class="btn btn-outline theme-secondary" data-financial-source-receipt-list href="<?=e(url(['view'=>'finance','tab'=>'receipts']))?>"><?=e($sourceText('Belegliste bewusst öffnen'))?></a><?php endif ?></div>
<?php if(in_array($sourceRequest['kind'],['receipt','bank'],true)): ?>
<form method="get" class="filter-form" data-financial-source-correction><?php foreach($sourceRetry??$sourceList as$key=>$value)if(!in_array($key,['entry','transaction'],true)&&is_string($value))echo hidden($key,$value); ?>
<label><?=e($sourceText($sourceReceipt?'Vollständige Belegkennung':'Vollständige Umsatzkennung'))?><input class="form-control" name="<?=e($sourceRequest['field'])?>" value="<?=e($sourceRequest['raw']??'')?>" required pattern="[a-f0-9]{32}" autocomplete="off" spellcheck="false"></label>
<button class="btn btn-outline theme-secondary"><?=e($sourceText($sourceContextInvalid?'Korrigierte Quelle ohne bisherige Filter öffnen':'Korrigierte Quelle ausdrücklich öffnen'))?></button></form>
<?php endif ?>
</section></div></div>
