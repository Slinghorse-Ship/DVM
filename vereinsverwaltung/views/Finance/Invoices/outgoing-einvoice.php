<?php declare(strict_types=1);
use Kulturhus\Verein\Web\OutgoingXInvoiceForm;
$xinvoiceMessages=isset($nativeAccess)?(new \Kulturhus\Native\Language\Catalog($db,$nativeAccess))->messages():[];
$xinvoiceText=static fn(string$s,array$v=[]):string=>\Kulturhus\Native\Language\XInvoiceMessages::text($s,$xinvoiceMessages,$v);
$outgoingId=input($_GET,'entry',input($_POST,'entry_id'));$xinvoiceAccepted=null;$xinvoiceCurrent=null;$xinvoiceSourceError='';
try{
 if(($_SERVER['REQUEST_METHOD']??'GET')==='GET'&&input($_GET,'xinvoice_receipt')!=='')$xinvoiceAccepted=OutgoingXInvoiceForm::receiptAt($db,(string)$_SESSION['actor'],$outgoingId,input($_GET,'xinvoice_receipt'));
 elseif(!OutgoingXInvoiceForm::requested($_POST))$xinvoiceAccepted=OutgoingXInvoiceForm::receipt($db,(string)$_SESSION['actor'],$outgoingId,$_POST);
}catch(DomainException$failure){$xinvoiceSourceError=$failure->getMessage();}
try{$xinvoiceCurrent=OutgoingXInvoiceForm::current($db,(string)$_SESSION['actor'],$outgoingId);}catch(DomainException$failure){$xinvoiceSourceError=$xinvoiceSourceError?:$failure->getMessage();}
$xinvoiceState=OutgoingXInvoiceForm::state($outgoingId,$xinvoiceCurrent,$_POST);$outgoingValues=$xinvoiceState['values'];
$xinvoiceBack=['view'=>'finance','tab'=>'receipts','entry'=>$outgoingId];foreach(['q','page','status']as$contextKey)if(is_string($_GET[$contextKey]??null))$xinvoiceBack[$contextKey]=$_GET[$contextKey];
?>
<section class="card" data-outgoing-electronic-status="xinvoice"><link rel="stylesheet" href="outgoing-electronic.css?v=2">
<?php if($xinvoiceAccepted):$xinvoiceOriginal=$xinvoiceAccepted['snapshot']['source']['entry'];?>
<h2><?=e($xinvoiceText('Gespeichertes XRechnungs-Ergebnis'))?></h2>
<p><?=e($xinvoiceText('Diese XML-Datei wurde bereits vollständig geprüft und gespeichert. Lesen und Herunterladen erstellen keine weitere Datei und erfassen keine Zahlung.'))?></p>
<p><strong><?=e($xinvoiceAccepted['document']['title'])?></strong></p>
<dl class="data-list"><dt><?=e($xinvoiceText('Damals geprüfte Belegversion'))?></dt><dd data-xinvoice-accepted-version><?=e($xinvoiceAccepted['source_version'])?></dd><dt><?=e($xinvoiceText('Aktuelle Belegversion'))?></dt><dd><?=e($xinvoiceAccepted['current_version'])?></dd><dt><?=e($xinvoiceText('Damals ausgewiesener Zahlbetrag'))?></dt><dd><?=e(euro((int)$xinvoiceOriginal['amount_cents']-(int)$xinvoiceOriginal['allocated_cents']))?></dd></dl>
<?php if($xinvoiceAccepted['source_version']!==$xinvoiceAccepted['current_version']):?><p><?=e($xinvoiceText('Der Beleg hat inzwischen einen neueren Stand. Das hier angezeigte gespeicherte Ergebnis bleibt die ursprüngliche Bestätigung und wird nicht als neue Erstellung ausgegeben.'))?></p><?php endif?>
<?php if($xinvoiceAccepted['document']['state']!=='active'||(int)$xinvoiceAccepted['document']['revision']!==1):?><p><?=e($xinvoiceText('Das Dokument ist inzwischen archiviert oder hat eine neuere Dateiversion. Dieser Nachweis betrifft ausdrücklich die damals gespeicherte Originalversion.'))?></p><?php endif?>
<p><a class="btn btn-outline theme-secondary" href="<?=e(url(['document_file'=>$xinvoiceAccepted['document']['id'],'revision'=>1]))?>" data-xinvoice-original-download><?=e($xinvoiceText('Gespeichertes XML-Original herunterladen'))?></a> <a href="<?=e(url(['view'=>'documents','doc'=>$xinvoiceAccepted['document']['id']]))?>"><?=e($xinvoiceText('Datei und Prüfbericht ansehen'))?></a></p>
<?php if($xinvoiceCurrent):?><form method="post" data-xinvoice-next-form>
<?=csrf().hidden('action','outgoing-einvoice-save').hidden('entry_id',$outgoingId).hidden('entry_version',$xinvoiceAccepted['source_version']).hidden('xinvoice_receipt',$xinvoiceAccepted['document']['id'])?>
<?php foreach($xinvoiceAccepted['fields']as$fieldKey=>$fieldValue):?><?=hidden('xinvoice['.$fieldKey.']',$fieldValue)?><?php endforeach?><?=hidden('xinvoice[confirmed]','1')?>
<button class="btn btn-outline theme-secondary" name="xinvoice_next" value="1" type="submit"><?=e($xinvoiceText('Neue XML-Erfassung bewusst vorbereiten'))?></button>
<p><?=e($xinvoiceText('Diese Vorbereitung erstellt keine Datei. Die Angaben und den aktuellen Zahlbetrag danach prüfen und gesondert erneut bestätigen.'))?></p></form><?php endif?>
<p><a href="<?=e(url($xinvoiceBack))?>"><?=e($xinvoiceText('Zum Beleg zurück'))?></a></p>
<?php elseif(!$xinvoiceCurrent):?>
<h2><?=e($xinvoiceText('XRechnung erstellen'))?></h2><p><?=e($xinvoiceText($xinvoiceSourceError?:'Diese XML-Erfassung ist aktuell nicht verfügbar.'))?></p>
<?php if($xinvoiceState['own']):?><details><summary><?=e($xinvoiceText('Deine bisherigen XML-Angaben'))?></summary><dl class="data-list"><?php foreach($outgoingValues as$fieldKey=>$fieldValue):?><dt><?=e($fieldKey)?></dt><dd><?=e($fieldValue)?></dd><?php endforeach?></dl></details><?php endif?>
<a href="<?=e(url($xinvoiceBack))?>"><?=e($xinvoiceText('Zum Beleg zurück'))?></a>
<?php else:$outgoingSource=$xinvoiceCurrent['source'];
$groups=['Verein / Rechnungssteller'=>['seller_name'=>'Name laut Rechnung','seller_street'=>'Straße und Hausnummer','seller_postcode'=>'Postleitzahl','seller_city'=>'Ort','seller_register'=>'Vereinsregisternummer / Registergericht','seller_tax_number'=>'Steuernummer des Rechnungsstellers','seller_contact'=>'Ansprechperson / Rechnungsstelle','seller_phone'=>'Telefon','seller_email'=>'E-Mail für Rechnungskontakt und elektronische Adresse','iban'=>'Vereinskonto für Überweisung (IBAN)'],'Empfänger der Rechnung'=>['buyer_name'=>'Name / Firma laut Rechnung','buyer_street'=>'Straße und Hausnummer','buyer_postcode'=>'Postleitzahl','buyer_city'=>'Ort','buyer_email'=>'Elektronische Empfängeradresse (E-Mail)'],'Angaben zur vorhandenen Rechnung'=>['invoice_number'=>'Original-Rechnungsnummer (nicht automatisch unsere Belegnummer)','buyer_reference'=>'Käuferreferenz / Leitweg-ID laut Empfänger','delivery_date'=>'Datum der Leistung / Lieferung']];?>
<h2><?=e($xinvoiceText('XRechnung zur vorhandenen Ausgangsrechnung'))?></h2>
<p><?=e($xinvoiceText('Diese XML-Datei stellt eine bereits vereinbarte Rechnung dar. Sie erzeugt keine neue Forderung und versendet keine Nachricht. Vorhandene Originaldateien bleiben unverändert. Unterstützt sind inländische EUR-Rechnungen mit 0 %, 7 % oder 19 %. Bei 0 % muss eine ausdrückliche Steuerbefreiung vorliegen. Bereits zugeordnete Zahlungen werden im verbleibenden Zahlbetrag berücksichtigt.'))?></p>
<p><strong><?=e($outgoingSource['entry']['description'])?> · <?=e(euro($outgoingSource['entry']['amount_cents']))?></strong> · <?=e($xinvoiceText('Rechnungsdatum'))?> <?=e(dateDe($outgoingSource['entry']['document_date']))?></p>
<p><?=e($xinvoiceText('Bei einer hier ausgegebenen Rechnung bleiben die tatsächlichen Mengen, Einheiten und bekannten Originalangaben erhalten. Bei älteren Einnahmebelegen ohne Mengenangabe wird eine Position als vollständiges Leistungspaket mit Menge 1 ausgegeben. Keine Beiträge oder Spenden hier umdeuten.'))?></p>
<p><strong><?=e($xinvoiceText('Verwendungszweck für die Zahlung:'))?></strong> <?=e($outgoingSource['entry']['display_number'])?>. <?=e($xinvoiceText('Diese interne Belegnummer wird als Zahlungsreferenz in die XML-Datei übernommen. Die Original-Rechnungsnummer im folgenden Feld bleibt davon getrennt.'))?></p>
<form method="post" data-outgoing-xinvoice-form>
<?=csrf().hidden('action','outgoing-einvoice-save').hidden('entry_id',$outgoingId).hidden('entry_version',$xinvoiceState['version']).hidden('xinvoice_source_hash',$xinvoiceState['hash'])?>
<?php if($xinvoiceState['changed']):?><p role="status"><?=e($xinvoiceText('Der gespeicherte Rechnungsstand hat sich geändert. Deine vollständigen Angaben und die ursprünglich geprüfte Version bleiben erhalten. Zuerst den aktuellen Stand vergleichen und bewusst übernehmen.'))?></p><?php endif?>
<p><?=e($xinvoiceText('Ursprünglich geprüfte Belegversion:'))?> <span data-xinvoice-original-version><?=e($xinvoiceState['version'])?></span></p>
<?php foreach($groups as$heading=>$fields):?><fieldset><legend><?=e($xinvoiceText($heading))?></legend>
<p><?=e($xinvoiceText('Vorhandene Angaben sind vorausgefüllt. Fehlende Angaben bitte dem Original oder den ausdrücklich bestätigten Empfängerdaten entnehmen. Bekannte Originalangaben bleiben gebunden. Verkäufer und Käufer müssen in Deutschland ansässig sein.'))?></p>
<div class="form-grid"><?php foreach($fields as$key=>$label):$outgoingValue=$outgoingValues[$key];$invalid=in_array($key,$xinvoiceState['invalid'],true)||($xinvoiceState['own']&&(trim($outgoingValue)===''||mb_strlen($outgoingValue)>\Kulturhus\Verein\OutgoingEInvoice::inputLimit($key)||preg_match('/[\x00-\x1f]/',$outgoingValue)||(str_ends_with($key,'email')&&!filter_var($outgoingValue,FILTER_VALIDATE_EMAIL))));?>
<label><?=e($xinvoiceText($label))?><input class="form-control" name="xinvoice[<?=e($key)?>]" required maxlength="<?=\Kulturhus\Verein\OutgoingEInvoice::inputLimit($key)?>" type="<?=$key==='delivery_date'?'date':(str_ends_with($key,'email')?'email':'text')?>" value="<?=e($outgoingValue)?>" <?=$invalid?'aria-invalid="true"':''?> <?=$key==='iban'?'autocomplete="off"':''?>>
<?php if($invalid):?><span class="field-validation-error"><?=e($xinvoiceText('Bitte {field} prüfen und vollständig eingeben.',['field'=>$xinvoiceText($label)]))?></span><?php endif?></label>
<?php endforeach?></div></fieldset><?php endforeach?>
<?php $xinvoiceReasonInvalid=in_array('vat_reason',$xinvoiceState['invalid'],true)||($xinvoiceState['own']&&(mb_strlen($outgoingValues['vat_reason'])>1000||preg_match('/[\x00-\x1f]/',$outgoingValues['vat_reason'])));?>
<label><?=e($xinvoiceText('Grund der Steuerbefreiung bei 0 %'))?><input class="form-control" name="xinvoice[vat_reason]" maxlength="1000" value="<?=e($outgoingValues['vat_reason'])?>" <?=$xinvoiceReasonInvalid?'aria-invalid="true"':''?>><?php if($xinvoiceReasonInvalid):?><span class="field-validation-error"><?=e($xinvoiceText('Bitte {field} prüfen und vollständig eingeben.',['field'=>$xinvoiceText('Grund der Steuerbefreiung bei 0 %')]))?></span><?php endif?></label>
<label class="xinvoice-confirmation"><input class="check" type="checkbox" name="xinvoice[confirmed]" value="1" required <?=$outgoingValues['confirmed']==='1'?'checked':''?>> <span><?=e($xinvoiceText('Ich bestätige, dass dies eine bestehende deutsche Ausgangsrechnung ist, die Rechnungsangaben vollständig stimmen und die gespeicherten Umsatzsteuerbeträge tatsächlich ausgewiesen werden dürfen. Es handelt sich weder um einen echten Mitgliedsbeitrag noch um eine Spende.'))?></span></label>
<div class="outgoing-electronic-actions"><button class="btn btn-outline theme-secondary" type="submit" name="xinvoice_current" value="1" formnovalidate><?=e($xinvoiceText('Aktuelle XML-Grundlage vergleichen'))?></button></div>
<?php if(isset($xinvoiceFormResult['comparison'])):$xinvoiceComparison=$xinvoiceFormResult['comparison'];?>
<section class="card" data-xinvoice-comparison><h3><?=e($xinvoiceText('Aktuell verglichene XML-Grundlage'))?></h3>
<p><?=e($xinvoiceText('Aktuelle Belegversion:'))?> <?=e((string)$xinvoiceComparison['source']['entry']['version'])?></p>
<p><?=e($xinvoiceText('Aktueller verbleibender Zahlbetrag:'))?> <?=e(euro((int)$xinvoiceComparison['source']['entry']['amount_cents']-(int)$xinvoiceComparison['source']['entry']['allocated_cents']))?></p>
<?=hidden('xinvoice_compare_hash',$xinvoiceComparison['hash'])?>
<button class="btn btn-outline theme-secondary" type="submit" name="xinvoice_adopt" value="1" formnovalidate><?=e($xinvoiceText('Verglichene XML-Grundlage übernehmen'))?></button>
<p><?=e($xinvoiceText('Vergleichen und Übernehmen speichern keine Datei. Danach Angaben und Zahlbetrag prüfen, bewusst erneut bestätigen und gesondert speichern.'))?></p></section>
<?php endif?>
<p><?=e($xinvoiceText('Die vollständige KoSIT-Prüfung kann bis zu 90 Sekunden dauern. Nur bei bestandener Prüfung werden XML und Prüfbericht zum vorhandenen Beleg abgelegt. Eine bestandene Formatprüfung ersetzt keine steuerliche Prüfung.'))?></p>
<button class="btn btn-solid theme-primary" data-xinvoice-save <?=$xinvoiceState['changed']?'disabled':''?>><?=e($xinvoiceText('Prüfen und XML zum Beleg speichern'))?></button> <a href="<?=e(url($xinvoiceBack))?>"><?=e($xinvoiceText('Zum Beleg zurück'))?></a>
</form>
<?php endif?>
</section>
