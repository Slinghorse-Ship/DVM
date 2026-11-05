<?php declare(strict_types=1);
$oiSourceContext=\Kulturhus\Verein\Web\OutgoingInvoiceSource::context($_GET);
$oiSourceList=['view'=>'finance','tab'=>'outgoing']+$oiSourceContext['fields'];
$oiSourceRetry=$outgoingInvoiceSource['raw']!==null?$oiSourceList+['invoice'=>$outgoingInvoiceSource['raw']]:null;
?>
<link rel="stylesheet" href="outgoing-invoice-native.css?v=2">
<div data-outgoing-invoice-source-page>
<div class="page-heading"><h1><?=e($oit('Rechnung prüfen'))?></h1></div>
<div class="content"><section class="card" data-outgoing-invoice-unavailable>
<p class="notice" role="alert"><?=e($oit($outgoingInvoiceSource['message']))?></p>
<p><?=e($oit('Es wurde keine andere Rechnung mit Beträgen geöffnet. Prüfe die ursprüngliche Kennung oder öffne die Rechnungsliste bewusst.'))?></p>
<?php if($outgoingInvoiceSource['raw']!==null): ?><dl class="data-list"><dt><?=e($oit('Ursprünglich angeforderte Rechnungskennung'))?></dt><dd data-outgoing-invoice-requested-id><?=e($outgoingInvoiceSource['raw'])?></dd></dl><?php endif ?>
<div class="actions">
<?php if($oiSourceRetry!==null): ?><a class="btn btn-outline theme-secondary" data-outgoing-invoice-source-retry href="<?=e(url($oiSourceRetry))?>"><?=e($oit($oiSourceContext['invalid']?'Rechnung ohne bisherige Filter erneut prüfen':'Genau diese Rechnung erneut prüfen'))?></a><?php endif ?>
<a class="btn btn-outline theme-secondary" data-outgoing-invoice-source-list href="<?=e(url($oiSourceList))?>"><?=e($oit($oiSourceContext['invalid']?'Rechnungsliste ohne bisherige Filter bewusst öffnen':'Rechnungsliste bewusst öffnen'))?></a>
</div>
<form method="get" class="filter-form" data-outgoing-invoice-source-correction>
<?php foreach($oiSourceList as$key=>$value)echo hidden($key,$value); ?>
<label><?=e($oit('Vollständige Rechnungskennung'))?><input class="form-control" name="invoice" value="<?=e($outgoingInvoiceSource['raw']??'')?>" required pattern="[a-f0-9]{32}" autocomplete="off" spellcheck="false"></label>
<button class="btn btn-outline theme-secondary"><?=e($oit($oiSourceContext['invalid']?'Korrigierte Rechnung ohne bisherige Filter öffnen':'Korrigierte Rechnung ausdrücklich öffnen'))?></button>
</form>
</section></div>
</div>
