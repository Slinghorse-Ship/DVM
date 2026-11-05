<?php declare(strict_types=1);
use Kulturhus\Verein\Finance\Invoices\OutgoingInvoiceRequests;
use Kulturhus\Verein\{OutgoingInvoices,Finance,TaxCatalog};
$oit=static fn(string $text):string=>\Kulturhus\Native\Language\OutgoingInvoiceMessages::text($text,$outgoinginvoiceMessages);
$oiUrl=static fn(array $query=[]):string=>url($outgoinginvoiceUrl($outgoinginvoiceId,$query));
$oiWhen=static fn(string $time):string=>(new DateTimeImmutable($time,new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('Europe/Berlin'))->format('d.m.Y H:i:s');
?>
<link rel="stylesheet" href="general-settings-native.css?v=3"><link rel="stylesheet" href="outgoing-invoice-native.css?v=1">
<div class="general-settings-native" data-outgoinginvoice-native>
<?php if(!$outgoinginvoiceState): ?><p><?=e($oit('Rechnungsbereich ist für diesen Zugriff nicht verfügbar.'))?></p></div><?php return;endif ?>
<?php foreach($outgoinginvoiceState['errors']as$piece=>$message): ?><p role="alert" data-outgoinginvoice-error="<?=e($piece)?>"><?=e($oit($message))?></p><?php endforeach ?>
<?php if($first=$outgoinginvoiceState['receipt']): ?>
<section class="card" data-outgoinginvoice-receipt="<?=e($first['key'])?>"><h3><?=e($oit('Rechnungsbestätigung'))?></h3>
<p><?=e($oiWhen($first['created_at']))?> · <?=e($oit(['save'=>'Entwurf gespeichert','review'=>'Gespeicherten Stand geprüft','issue'=>'Rechnung ausgegeben'][$first['input']['operation']]))?></p>
<p><?=e($oit('Diese erste Bestätigung erhält die ganzen damaligen Angaben und das tatsächliche Ergebnis. Spätere Fassungen und Quellen ersetzen sie nicht.'))?></p>
<a class="btn btn-outline theme-secondary" data-navigation-native download data-outgoinginvoice-download href="<?=e($oiUrl(['outgoinginvoice_original'=>$first['key']]))?>"><?=e($oit('Genau diese ursprüngliche Bestätigung herunterladen'))?></a>
<dl class="general-settings-description"><dt><?=e($oit('Damals gespeicherter Empfänger'))?></dt><dd><?=e($first['result']['data']['buyer_name'])?></dd><dt><?=e($oit('Tatsächlicher Rechnungsbetrag brutto'))?></dt><dd><?=e(euro((int)$first['result']['data']['total_cents']))?></dd>
<?php if($first['result']['number']!==''): ?><dt><?=e($oit('Ursprüngliche Rechnungsnummer'))?></dt><dd><?=e($first['result']['number'])?></dd><?php endif ?></dl>
<?php if($first['file']): ?><a class="btn btn-outline theme-secondary" data-navigation-native download href="<?=e($oiUrl(['outgoinginvoice_pdf'=>'first','outgoinginvoice_receipt'=>$first['key'],'outgoinginvoice_download'=>'1']))?>"><?=e($oit('Zugehöriges unverändertes Original-PDF herunterladen'))?></a><?php endif ?>
<?php if(in_array($first['input']['operation'],['save','review'],true)): ?><details data-outgoinginvoice-original-values><summary><?=e($oit('Ganze ursprünglich übermittelte Angaben lesen'))?></summary>
<dl class="general-settings-description outgoinginvoice-values">
<?php $oiOriginalLabels=['buyer_name'=>'Name / Firma','buyer_street'=>'Straße und Hausnummer','buyer_postcode'=>'Postleitzahl','buyer_city'=>'Ort','buyer_email'=>'E-Mail des Empfängers (optional)','buyer_reference'=>'Empfängerreferenz / Leitweg-ID bei E-Rechnung','seller_contact'=>'Ansprechperson / Rechnungsstelle bei E-Rechnung','description'=>'Wofür ist diese Rechnung?','note'=>'Zusatztext für diese Rechnung','vat_reason'=>'Bei 0 %: tatsächlicher Grund der Steuerbefreiung','document_date'=>'Rechnungsdatum','delivery_date'=>'Leistungsdatum','due_date'=>'Zahlbar bis','invoice_format'=>'Gewünschtes Rechnungsformat','category'=>'Rechnungszweck','payment_account'=>'Zahlungskonto','contact_id'=>'Kontaktverknüpfung','domestic_confirmed'=>'Inländische Rechnungsparteien bestätigt'];foreach($oiOriginalLabels as$key=>$label):
 $oiOriginalValue=$first['input']['data'][$key];
 if($key==='invoice_format'&&in_array($oiOriginalValue,['pdf','hybrid-xrechnung'],true))$oiOriginalValue=$oit($oiOriginalValue==='pdf'?'Gewöhnliches PDF für Post oder E-Mail':'PDF mit eingebetteter geprüfter XRechnung');
 elseif($key==='category'&&isset(Finance::CATEGORIES[$oiOriginalValue]))$oiOriginalValue=$oit(Finance::CATEGORIES[$oiOriginalValue]);
 elseif($key==='payment_account')$oiOriginalValue=$oiOriginalValue===''?$oit('Vorgabe der Rechnungsbank verwenden'):(string)($first['source']['payment_account']['name']??$oit('Gesondertes Zahlungskonto gewählt'));
 elseif($key==='contact_id')$oiOriginalValue=$oit($oiOriginalValue===''?'Keine Kontaktverknüpfung':'Kontaktverknüpfung vorhanden');
 elseif($key==='domestic_confirmed'&&in_array($oiOriginalValue,['0','1'],true))$oiOriginalValue=$oit($oiOriginalValue==='1'?'Bewusst bestätigt':'Noch nicht bestätigt');
 ?>
<dt><?=e($oit($label))?></dt><dd><pre><?= "\n".e($oiOriginalValue) ?></pre></dd>
<?php endforeach ?></dl>
<?php foreach($first['input']['data']['positions']as$i=>$position): ?><section><h4><?=e($oit('Position').' '.($i+1))?></h4><dl class="general-settings-description outgoinginvoice-values">
<?php foreach(['description'=>'Beschreibung','quantity'=>'Menge','unit'=>'Einheit','unit_gross'=>'Preis je Einheit brutto (EUR)','tax_rate'=>'Umsatzsteuer','sphere'=>'Vereinsbereich','account'=>'Sachkonto (optional)']as$key=>$label):$oiOriginalValue=$position[$key];if($key==='unit'&&isset(OutgoingInvoices::UNITS[$oiOriginalValue]))$oiOriginalValue=$oit(OutgoingInvoices::UNITS[$oiOriginalValue]);elseif($key==='sphere')$oiOriginalValue=$oiOriginalValue===''?$oit('Später kontieren'):$oit(TaxCatalog::costCenters()[$oiOriginalValue]??$oiOriginalValue); ?><dt><?=e($oit($label))?></dt><dd><pre><?= "\n".e($oiOriginalValue) ?></pre></dd><?php endforeach ?></dl></section><?php endforeach ?>
</details><?php endif ?></section>
<?php endif ?>
<?php $oiCurrent=$outgoinginvoiceState['current'];$oiCanWrite=$outgoinginvoiceState['can_write']; ?>
<?php if($oiCurrent&&$oiCurrent['document_id']===null): ?>
<section class="card" data-outgoinginvoice-saved><h3><?=e($oit('Aktueller gespeicherter Rechnungsstand'))?></h3>
<p><?=e($oiCurrent['data']['buyer_name'])?> · <?=e(euro((int)$oiCurrent['data']['total_cents']))?></p><p><?=e($oit('Dieses PDF zeigt den gespeicherten Entwurf ohne endgültige Rechnungsnummer. Nicht gespeicherte Formulareingaben gehören nicht zu diesem PDF.'))?></p>
<?php $oiPdfUrl=$oiUrl(['outgoinginvoice_pdf'=>'current','outgoinginvoice_version'=>(string)$oiCurrent['version'],'outgoinginvoice_source'=>hash('sha256',OutgoingInvoiceRequests::json($outgoinginvoiceState['source']))]); ?>
<?php $previewUrl=$oiPdfUrl;\Kulturhus\Verein\Documents\InterfaceText::configure($outgoinginvoiceMessages);require __DIR__.'/../../Documents/pdf-viewer.php'; ?>
<?php if($oiCanWrite&&!$outgoinginvoiceDraft): ?><div class="general-settings-actions"><a class="btn btn-outline theme-secondary" href="<?=e($oiUrl(['outgoinginvoice_edit'=>'1']))?>"><?=e($oit('Gespeicherten Entwurf bewusst weiter bearbeiten'))?></a>
<?php if($oiCurrent['state']==='reviewed'): ?><a class="btn btn-solid theme-primary" href="<?=e($oiUrl(['outgoinginvoice_issue'=>'1']))?>"><?=e($oit('Geprüften Stand zur Ausgabe öffnen'))?></a><?php endif ?></div><?php endif ?>
</section><?php endif ?>
<?php if($outgoinginvoiceDraft&&$outgoinginvoiceDraft['accepted']===null): ?>
<?php if($outgoinginvoiceDraft['input']['operation']==='issue'): ?>
<section class="card" data-outgoinginvoice-issue="<?=e($outgoinginvoiceDraft['id'])?>"><h3><?=e($oit('Geprüfte Rechnung gesondert ausgeben'))?></h3>
<p><?=e($oit('Diese Aktion vergibt die endgültige Nummer und speichert Buchung und Original-PDF gemeinsam. Sie versendet keine E-Mail und erzeugt keine Zahlung.'))?></p>
<a class="btn btn-outline theme-secondary" data-outgoinginvoice-outcome-link href="<?=e($oiUrl(['outgoinginvoice_receipt'=>$outgoinginvoiceDraft['key']]))?>"><?=e($oit('Ergebnis dieses Entwurfs lesend prüfen'))?></a>
<?php if($outgoinginvoiceDraft['pending']): ?><p role="status"><?=e($oit('Rechnungsausgang nicht bestätigt. Die ursprüngliche persönliche Bestätigung zuerst lesend prüfen.'))?></p>
<a class="btn btn-outline theme-secondary" href="<?=e($oiUrl(['outgoinginvoice_draft'=>$outgoinginvoiceDraft['id'],'outgoinginvoice_receipt'=>$outgoinginvoiceDraft['key']]))?>"><?=e($oit('Ursprünglichen Rechnungsausgang lesen'))?></a>
<form method="post" action="<?=e($oiUrl())?>" data-outgoinginvoice-recover><?=csrf().hidden('action','outgoing-invoice-native').hidden('invoice',$outgoinginvoiceId).hidden('outgoinginvoice_draft',$outgoinginvoiceDraft['id'])?><button class="btn btn-outline theme-secondary" name="outgoinginvoice_intent" value="current"><?=e($oit('Ganze Angaben mit aktuellem Stand erneut öffnen'))?></button></form>
<?php else: ?><a class="btn btn-outline theme-secondary" href="<?=e($oiUrl(['outgoinginvoice_edit'=>'1']))?>"><?=e($oit('Gespeicherten Entwurf bewusst weiter bearbeiten'))?></a><form method="post" action="<?=e($oiUrl())?>" data-outgoinginvoice-issue-form><?=csrf().hidden('action','outgoing-invoice-native').hidden('invoice',$outgoinginvoiceId).hidden('outgoinginvoice_draft',$outgoinginvoiceDraft['id']).hidden('outgoinginvoice_key',$outgoinginvoiceDraft['key'])?>
<label class="general-settings-check"><input class="check" type="checkbox" name="outgoinginvoice_approved" value="1"><?=e($oit('Genau diesen gespeicherten und geprüften Stand mit endgültiger Nummer ausgeben.'))?></label><div class="general-settings-actions"><button class="btn btn-solid theme-primary" name="outgoinginvoice_intent" value="issue"><?=e($oit('Rechnung verbindlich ausgeben'))?></button><button class="btn btn-outline theme-secondary" name="outgoinginvoice_intent" value="discard"><?=e($oit('Ausgabevorbereitung verwerfen'))?></button></div></form>
<?php endif ?></section>
<?php else:require __DIR__.'/outgoing-invoice-form-native.php';endif ?>
<?php endif ?>
<?php if($outgoinginvoiceId!==''):$oiHistory=$outgoinginvoiceState['history'];$oiHistoryQuery=$oiHistory['query']??(is_string($_GET['outgoinginvoice_history_q']??null)?$_GET['outgoinginvoice_history_q']:'');$oiHistoryPage=$oiHistory['page_input']??(is_string($_GET['outgoinginvoice_history_page']??null)?$_GET['outgoinginvoice_history_page']:'1'); ?>
<section class="card" data-outgoinginvoice-history><h3><?=e($oit('Eigene frühere Rechnungsbestätigungen'))?></h3>
<form method="get" class="general-settings-page-form"><?=hidden('view','finance').hidden('tab','outgoing').hidden('invoice',$outgoinginvoiceId)?><label><?=e($oit('Ganze Bestätigungssuche'))?><?php if(strpbrk($oiHistoryQuery,"\r\n")!==false): ?><textarea class="form-control" name="outgoinginvoice_history_q"><?= "\n".e($oiHistoryQuery) ?></textarea><?php else: ?><input class="form-control" name="outgoinginvoice_history_q" value="<?=e($oiHistoryQuery)?>"><?php endif ?></label><label><?=e($oit('Bestätigungsseite'))?><input class="form-control" inputmode="numeric" name="outgoinginvoice_history_page" value="<?=e($oiHistoryPage)?>"></label><button class="btn btn-outline theme-secondary"><?=e($oit('Eigene Bestätigungen suchen'))?></button></form>
<?php if($oiHistory): ?><p><?=e((string)$oiHistory['total'])?> · <?=e($oit('Bestätigungen in Seiten zu 25'))?></p><ul><?php foreach($oiHistory['rows']as$row): ?><li><a href="<?=e($oiUrl(['outgoinginvoice_receipt'=>$row['request_key']]))?>"><?=e($oiWhen($row['created_at']))?> · <?=e($oit('Ursprüngliche Bestätigung öffnen'))?></a></li><?php endforeach ?></ul>
<?php if($oiHistory['page']!==null): ?><nav class="general-settings-actions" aria-label="<?=e($oit('Bestätigungsseiten'))?>"><?php if($oiHistory['page']>1): ?><a class="btn btn-outline theme-secondary" href="<?=e($oiUrl(['outgoinginvoice_history_q'=>$oiHistoryQuery,'outgoinginvoice_history_page'=>(string)($oiHistory['page']-1)]))?>"><?=e($oit('Vorherige Bestätigungsseite'))?></a><?php endif ?><?php if($oiHistory['page']<$oiHistory['pages']): ?><a class="btn btn-outline theme-secondary" href="<?=e($oiUrl(['outgoinginvoice_history_q'=>$oiHistoryQuery,'outgoinginvoice_history_page'=>(string)($oiHistory['page']+1)]))?>"><?=e($oit('Nächste Bestätigungsseite'))?></a><?php endif ?></nav><?php endif;endif ?>
</section><?php endif ?></div>
