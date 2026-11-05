<?php declare(strict_types=1);
require __DIR__.'/../../../src/Web/invoice-overview-labels.php';
$invoiceArchiveForm=\Kulturhus\Verein\Web\InvoiceArchiveForm::prepare($_GET,$_POST,$inboxSelected,$finance->canWrite()&&$documents->canEdit($inboxId));
if($invoiceArchiveForm['state']==='hidden')return;
$invoiceArchiveLabels=['doc'=>'Rechnung','document_version'=>'Ursprüngliche Dateiversion','archive'=>'Ursprüngliche Absicht','reason'=>'Übermittelter Grund'];
$invoiceArchiveRaw=static fn(mixed$v):string=>is_string($v)?$v:(string)json_encode($v,JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE|JSON_PARTIAL_OUTPUT_ON_ERROR);
?>
<details class="card invoice-archive" id="rechnung-entfernen" data-invoice-archive="<?=e($invoiceArchiveForm['state'])?>" <?=$invoiceArchiveForm['submitted']||isset($_GET['invoice_archive_read'])?'open':''?>>
<summary><?=e($invoiceOverviewText($invoiceArchiveForm['state']==='form'?($invoiceArchiveForm['archive']==='0'?'Rechnung wiederherstellen':'Rechnung archivieren'):'Archiventscheidung prüfen'))?></summary>
<?php if($invoiceArchiveForm['state']!=='form'):?>
<p role="alert"><?=e($invoiceOverviewText($invoiceArchiveForm['message']))?></p><p><?=e($invoiceOverviewText('Es wurde keine andere Archivabsicht und keine neue Dateiversion zur Bestätigung eingesetzt.'))?></p>
<?php if(in_array($invoiceArchiveForm['archive'],['0','1'],true)):?><p data-invoice-archive-intention><?=e($invoiceOverviewText($invoiceArchiveForm['archive']==='0'?'Rechnung wiederherstellen':'Rechnung archivieren'))?></p><?php endif;?>
<dl><?php foreach($invoiceArchiveForm['raw']as$field=>$value):?><dt><?=e($invoiceOverviewText($invoiceArchiveLabels[$field]))?></dt><dd data-invoice-archive-raw="<?=e($field)?>"><?php if(!is_string($value)):?><span><?=e($invoiceOverviewText('Mehrteilige Angabe'))?></span><?php endif;?><pre><?=e($invoiceArchiveRaw($value))?></pre></dd><?php endforeach;?></dl>
<p><?=e($invoiceOverviewText('Aktueller Originalstand'))?>: <?=e($invoiceOverviewText($inboxSelected['state']==='archived'?'Archiviert':'Noch zu prüfen'))?> · <?=e($inboxSelected['version'])?></p>
<a class="btn btn-outline theme-secondary secondary" data-invoice-archive-read href="<?=e(url($invoiceArchiveForm['read_route']+['invoice_archive_read'=>bin2hex(random_bytes(8))]).'#rechnung-entfernen')?>"><?=e($invoiceOverviewText('Aktuellen Stand derselben Rechnung lesen'))?></a>
<?php else:?>
<form method="post" data-invoice-archive-form><?=csrf().hidden('action','invoice-inbox-archive').hidden('doc',$invoiceArchiveForm['id']).hidden('document_version',$invoiceArchiveForm['version']).hidden('archive',$invoiceArchiveForm['archive'])?>
<p><?=e($invoiceOverviewText('Die Datei bleibt im Archiv erhalten. Die E-Mail im Postfach bleibt unverändert.'))?></p>
<?php if($invoiceArchiveForm['reason_error']):?><p role="alert"><?=e($invoiceOverviewText('Der Grund ist länger als 1000 Zeichen. Bitte vollständig prüfen und kürzen.'))?></p><?php endif;?>
<label><?=e($invoiceOverviewText('Grund'))?><textarea class="form-control" name="reason" required maxlength="2000" rows="3"><?=e($invoiceArchiveForm['reason'])?></textarea><small><?=e($invoiceOverviewText('Bis zu 1000 Zeichen. Der Grund wird zur Originaldatei protokolliert.'))?></small></label>
<button class="btn btn-solid theme-primary"><?=e($invoiceOverviewText($invoiceArchiveForm['archive']==='0'?'Rechnung wiederherstellen':'Archivieren'))?></button>
</form><?php endif;?></details><script defer src="invoice-inbox-detail.js?v=1"></script>
