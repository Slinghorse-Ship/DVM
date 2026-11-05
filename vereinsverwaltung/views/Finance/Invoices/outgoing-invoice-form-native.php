<?php declare(strict_types=1);
use Kulturhus\Verein\{OutgoingInvoices,Finance,TaxCatalog};
$oiDraft=$outgoinginvoiceDraft;$oiInput=$oiDraft['input'];$oiData=$oiInput['data'];$oiEditable=!$oiDraft['pending']&&$outgoinginvoiceState['can_write']&&(!$outgoinginvoiceState['current']||in_array($outgoinginvoiceState['current']['state'],['draft','reviewed'],true));
$oiTextField=static function(string $name,string $value,string $mode='',bool $date=false)use($oiEditable):void {
    $attributes=' class="form-control" name="'.e($name).'"'.($oiEditable?'':' readonly');
    if (strpbrk($value,"\r\n")!==false) { ?><textarea<?=$attributes?>><?= "\n".e($value) ?></textarea><?php return; }
    $type=$date&&TaxCatalog::documentYear($value)?'date':'text';
    ?><input<?=$attributes?> type="<?=$type?>"<?= $mode!==''?' inputmode="'.e($mode).'"':'' ?> value="<?=e($value)?>"><?php
};
$oiSelect=static function(string $name,string $value,array $options)use($oiEditable,$oit):void {
    ?><select class="form-control" name="<?=e($name)?>"<?=$oiEditable?'':' disabled'?>><?php
    if (!array_key_exists($value,$options)) { ?><option selected value="<?=e($value)?>"><?=e($oit('Ursprüngliche unbekannte Auswahl').': '.$value)?></option><?php }
    foreach ($options as $key=>$label) { ?><option value="<?=e((string)$key)?>"<?=$value===(string)$key?' selected':''?>><?=e($label)?></option><?php }
    ?></select><?php
};
?>
<link rel="stylesheet" href="general-settings-native.css?v=3">
<link rel="stylesheet" href="outgoing-invoice-native.css?v=1">
<section class="card general-settings-native" data-outgoinginvoice-editor="<?=e($oiDraft['id'])?>">
<h3><?=e($oit('Eigener ganzer Rechnungsentwurf'))?></h3>
<p><?=e($oit('Positionen hinzufügen, entfernen und verschieben erhält im selben Schritt alle anderen Angaben. Erst Entwurf speichern übernimmt sie in die Rechnung.'))?></p>
<?php if(isset($oiDraft['legacy_creation'])): ?><p role="status" data-outgoinginvoice-legacy><?=e($oit('Das frühere Anlageformular gehört zu dieser bereits gespeicherten Rechnung. Die übermittelten Angaben bleiben vollständig zur Bearbeitung erhalten. Erst bewusstes Speichern übernimmt sie in diesen Entwurf. Die persönliche Bestätigung dokumentiert diese neue Bearbeitung.'))?></p><?php endif ?>
<?php if(!$outgoinginvoiceState['can_write']): ?><p role="status"><?=e($oit('Der aktuelle Zugriff erlaubt Finanzlesen. Für Änderungen ist Finanzschreiben erforderlich.'))?></p><?php endif ?>
<?php if($outgoinginvoiceState['current']&&!in_array($outgoinginvoiceState['current']['state'],['draft','reviewed'],true)): ?><p role="status"><?=e($oit('Diese Rechnung ist bereits ausgegeben. Die ursprünglichen Angaben bleiben lesbar; Änderungen erfolgen über das Korrekturdokument.'))?></p><?php endif ?>
<a class="btn btn-outline theme-secondary" data-outgoinginvoice-outcome-link href="<?=e($oiUrl(['outgoinginvoice_receipt'=>$oiDraft['key']]))?>"><?=e($oit('Ergebnis dieses Entwurfs lesend prüfen'))?></a>
<?php if($oiDraft['pending']||$oiDraft['conflicted']): ?>
<?php if($oiDraft['pending']): ?><p role="status"><?=e($oit('Rechnungsausgang nicht bestätigt. Die ursprüngliche persönliche Bestätigung zuerst lesend prüfen.'))?></p><?php endif ?>
<a class="btn btn-outline theme-secondary" href="<?=e($oiUrl(['outgoinginvoice_draft'=>$oiDraft['id'],'outgoinginvoice_receipt'=>$oiDraft['key']]))?>"><?=e($oit('Ursprünglichen Rechnungsausgang lesen'))?></a>
<form method="post" action="<?=e($oiUrl())?>" data-outgoinginvoice-recover><?=csrf().hidden('action','outgoing-invoice-native').hidden('invoice',$oiInput['invoice']).hidden('outgoinginvoice_draft',$oiDraft['id'])?>
<button class="btn btn-outline theme-secondary" name="outgoinginvoice_intent" value="current"><?=e($oit('Ganze Angaben mit aktuellem Stand erneut öffnen'))?></button></form>
<?php endif ?>
<form method="post" action="<?=e($oiUrl())?>" novalidate data-outgoinginvoice-form>
<?=csrf().hidden('action','outgoing-invoice-native').hidden('invoice',$oiInput['invoice']).hidden('outgoinginvoice_draft',$oiDraft['id']).hidden('outgoinginvoice_version',$oiInput['version']).hidden('outgoinginvoice_key',$oiDraft['key'])?>
<section><h4><?=e($oit('1. Empfänger und Leistung'))?></h4><div class="general-settings-grid">
<?php foreach(['buyer_name'=>'Name / Firma','buyer_street'=>'Straße und Hausnummer','buyer_postcode'=>'Postleitzahl','buyer_city'=>'Ort','buyer_email'=>'E-Mail des Empfängers (optional)','description'=>'Wofür ist diese Rechnung?']as$key=>$label): ?>
<label class="general-settings-field"><?=e($oit($label))?><?php $oiTextField('data['.$key.']',$oiData[$key],$key==='buyer_email'?'email':''); ?></label>
<?php endforeach ?>
<label class="general-settings-field"><?=e($oit('Rechnungszweck'))?><?php $oiCategories=[];foreach(Finance::CATEGORIES as$key=>$label)$oiCategories[$key]=$oit($label);$oiSelect('data[category]',$oiData['category'],$oiCategories); ?></label>
<label class="general-settings-field"><?=e($oit('Zahlungskonto'))?><?php $oiAccounts=[''=>$oit('Vorgabe der Rechnungsbank verwenden')];foreach($finance->paymentAccounts()as$account)$oiAccounts[$account['id']]=$account['name'];$oiSelect('data[payment_account]',$oiData['payment_account'],$oiAccounts); ?></label>
</div><?=hidden('data[contact_id]',$oiData['contact_id'])?>
<?php if($oiData['contact_id']!==''): ?><button class="btn btn-outline theme-secondary" name="outgoinginvoice_intent" value="clear-contact"<?=$oiEditable?'':' disabled'?>><?=e($oit('Kontaktverknüpfung entfernen; Anschrift beibehalten'))?></button><?php endif ?></section>
<section><h4><?=e($oit('2. Ganze Rechnungspositionen'))?></h4><p><?=e($oit('Menge mit bis zu drei Nachkommastellen und Bruttopreis je Einheit eingeben. Die tatsächliche Rechnung berechnet Beträge und Umsatzsteuer.'))?></p>
<div data-outgoinginvoice-lines>
<?php foreach($oiData['positions']as$i=>$position):$prefix='data[positions]['.$i.']'; ?>
<fieldset data-outgoinginvoice-line><legend><?=e($oit('Position').' '.($i+1))?></legend>
<label class="general-settings-field"><?=e($oit('Beschreibung'))?><?php $oiTextField($prefix.'[description]',$position['description']); ?></label>
<div class="general-settings-grid">
<label class="general-settings-field"><?=e($oit('Menge'))?><?php $oiTextField($prefix.'[quantity]',$position['quantity'],'decimal'); ?></label>
<label class="general-settings-field"><?=e($oit('Einheit'))?><?php $oiUnits=[];foreach(OutgoingInvoices::UNITS as$key=>$label)$oiUnits[$key]=$oit($label);$oiSelect($prefix.'[unit]',$position['unit'],$oiUnits); ?></label>
<label class="general-settings-field"><?=e($oit('Preis je Einheit brutto (EUR)'))?><?php $oiTextField($prefix.'[unit_gross]',$position['unit_gross'],'decimal'); ?></label>
<label class="general-settings-field"><?=e($oit('Umsatzsteuer'))?><?php $oiSelect($prefix.'[tax_rate]',$position['tax_rate'],['0'=>'0 %','7'=>'7 %','19'=>'19 %']); ?></label>
<label class="general-settings-field"><?=e($oit('Vereinsbereich'))?><?php $oiSpheres=[''=>$oit('Später kontieren')];foreach(TaxCatalog::costCenters()as$key=>$label)$oiSpheres[(string)$key]=$oit($label);$oiSelect($prefix.'[sphere]',$position['sphere'],$oiSpheres); ?></label>
<label class="general-settings-field"><?=e($oit('Sachkonto (optional)'))?><?php $oiTextField($prefix.'[account]',$position['account'],'numeric'); ?></label>
</div>
<div class="general-settings-actions" data-outgoinginvoice-row-actions>
<?php foreach(['up'=>'Nach oben','down'=>'Nach unten','remove'=>'Position entfernen']as$operation=>$label):$disabled=!$oiEditable||($operation==='up'&&$i===0)||($operation==='down'&&$i===count($oiData['positions'])-1)||($operation==='remove'&&count($oiData['positions'])<=1); ?>
<button class="btn btn-outline theme-secondary" name="outgoinginvoice_row" value="<?=e($operation.':'.$i)?>"<?=$disabled?' disabled':''?>><?=e($oit($label))?></button>
<?php endforeach ?></div></fieldset>
<?php endforeach ?></div>
<button class="btn btn-outline theme-secondary" name="outgoinginvoice_row" value="add"<?=!$oiEditable||count($oiData['positions'])>=50?' disabled':''?>><?=e($oit('Weitere Position hinzufügen'))?></button>
<label class="general-settings-field"><?=e($oit('Bei 0 %: tatsächlicher Grund der Steuerbefreiung'))?><textarea class="form-control" name="data[vat_reason]"<?=$oiEditable?'':' readonly'?>><?= "\n".e($oiData['vat_reason']) ?></textarea></label>
</section>
<section><h4><?=e($oit('3. Rechnungsformat und Termine'))?></h4>
<label class="general-settings-field"><?=e($oit('Gewünschtes Rechnungsformat'))?><?php $oiSelect('data[invoice_format]',$oiData['invoice_format'],['pdf'=>$oit('Gewöhnliches PDF für Post oder E-Mail'),'hybrid-xrechnung'=>$oit('PDF mit eingebetteter geprüfter XRechnung')]); ?></label>
<p><?=e($oit('Gewöhnliches PDF benötigt keine E-Mail-Adresse oder Leitweg-ID. Falls der Empfänger eine elektronische Rechnung verlangt, das elektronische Format bewusst wählen und seine erforderlichen Angaben vervollständigen.'))?></p>
<div class="general-settings-grid">
<?php foreach(['document_date'=>'Rechnungsdatum','delivery_date'=>'Leistungsdatum','due_date'=>'Zahlbar bis']as$key=>$label): ?>
<label class="general-settings-field"><?=e($oit($label))?><?php $oiTextField('data['.$key.']',$oiData[$key],'',true); ?></label>
<?php endforeach ?>
<label class="general-settings-field"><?=e($oit('Empfängerreferenz / Leitweg-ID bei E-Rechnung'))?><?php $oiTextField('data[buyer_reference]',$oiData['buyer_reference']); ?></label>
<label class="general-settings-field"><?=e($oit('Ansprechperson / Rechnungsstelle bei E-Rechnung'))?><?php $oiTextField('data[seller_contact]',$oiData['seller_contact']); ?></label>
</div>
<?php if(in_array($oiData['domestic_confirmed'],['0','1'],true)): ?>
<?=hidden('data[domestic_confirmed]','0')?><label class="general-settings-check"><input class="check" type="checkbox" name="data[domestic_confirmed]" value="1"<?=$oiData['domestic_confirmed']==='1'?' checked':''?><?=$oiEditable?'':' disabled'?>><?=e($oit('Für E-Rechnung bestätigt: Verein und Empfänger sind in Deutschland ansässig.'))?></label>
<?php else: ?><label class="general-settings-field"><?=e($oit('Für E-Rechnung bestätigt: Verein und Empfänger sind in Deutschland ansässig.'))?><?php $oiSelect('data[domestic_confirmed]',$oiData['domestic_confirmed'],['0'=>$oit('Noch nicht bestätigt'),'1'=>$oit('Bewusst bestätigt')]); ?></label><?php endif ?>
<label class="general-settings-field"><?=e($oit('Zusatztext für diese Rechnung'))?><textarea class="form-control" name="data[note]"<?=$oiEditable?'':' readonly'?>><?= "\n".e($oiData['note']) ?></textarea></label>
<div class="general-settings-actions">
<button class="btn btn-solid theme-primary" name="outgoinginvoice_intent" value="save"<?=$oiEditable?'':' disabled'?>><?=e($oit('Ganzen Entwurf speichern'))?></button>
<?php if($oiInput['invoice']!==''): ?><button class="btn btn-outline theme-secondary" name="outgoinginvoice_intent" value="review"<?=$oiEditable?'':' disabled'?>><?=e($oit('Genau den gespeicherten Stand prüfen'))?></button><?php endif ?>
<button class="btn btn-outline theme-secondary" name="outgoinginvoice_intent" value="discard"<?=$oiDraft['pending']?' disabled':''?>><?=e($oit('Eigenen ungespeicherten Entwurf verwerfen'))?></button>
</div><p><?=e($oit('Entwurf speichern vergibt keine Rechnungsnummer und erzeugt keine Buchung. Geänderte Angaben zuerst speichern, danach den gespeicherten Stand prüfen und gesondert ausgeben.'))?></p>
</section></form></section>
