<?php declare(strict_types=1);
use Kulturhus\Verein\SettingsSchema;
// One form owns all sections. Collapsing never disables or omits a saved field.
$settingFields=SettingsSchema::fields($section);
$settingGroups=match($section){
 'club'=>[
  ['name','Name & Eintragung','Vereinsname und Vereinsregister',['name','registered','register']],
  ['address','Anschrift & Kontakt','Diese Angaben werden für Briefe und Formulare verwendet.',['street','address2','postcode','city','phone','email','website']],
  ['options','Weitere Vereinsangaben','Bezeichnung und Vereinsart',['alternate','type','category']],
 ],
 'bank'=>[
  ['bank','Bankverbindung für Briefe & Rechnungen','Vereinskonto auswählen und die Bankdaten hinterlegen.',['account_id','holder','iban','bic']],
  ['options','Weitere Zahlungsangaben','Gläubiger-ID für Lastschrift und optionaler PayPal-Link',['creditor','paypal']],
 ],
 'tax'=>[
  ['notice','Finanzamt & Gemeinnützigkeit','Steuernummer, Förderungszweck und der hinterlegte Bescheid',['office','number','assessment','purpose','notice_type','exemption_date','assessment_date','assessment_no_prior_notice','notice_document_id']],
  ['closing','Jahresabschluss','Welche Abschlussart für diesen Verein vorbereitet wird',['accounting_basis']],
  ['receipts','Zuwendungsbescheinigungen','Zeichnungsberechtigung und Angaben aus dem Steuerbescheid',['receipt_signatory','receipt_authority_reviewed','membership_deductible','electronic_receipts','receipt_declaration']],
  ['vat','Umsatzsteuer & Vorsteuer','Zuerst die Vereinsberechtigung festlegen; danach werden neue Ausgaben passend vorausgefüllt.',['vat_id','small_business','input_tax_eligibility','input_tax_default']],
  ['depreciation','Anschaffungen & Abschreibungen (AfA)','Bei Bilanzierung sichtbar; bei EÜR zusätzlich einschaltbar.',['depreciation_enabled']],
  ['datev','DATEV & Wirtschaftsjahr','Angaben für den Export an die Buchhaltung',['advisor','client','fiscal_start']],
 ],
 default=>[['all','Angaben','',array_keys($settingFields)]],
};
$settingsFailed=input($_POST,'action')==='settings-save'&&input($_POST,'section')===$section;
foreach($settingGroups as $index=>[$groupId,$groupTitle,$groupHelp,$groupKeys]): ?>
<details class="settings-panel" id="settings-<?= e($section.'-'.$groupId) ?>" <?= $index===0||$settingsFailed?'open':'' ?>>
 <summary><span><strong><?= e($groupTitle) ?></strong><small><?= e($groupHelp) ?></small></span></summary>
 <div class="settings-panel-body">
 <?php if($section==='tax'&&$groupId==='vat'): ?><p>Diese Vereinsangabe steuert die Vorauswahl neuer Ausgaben. Bei „Nein“ bleibt die Umsatzsteuer aus dem Original sichtbar, wird aber vollständig als Bruttokosten gebucht und ein BU-Schlüssel wird nicht zugelassen. Gemeinnützigkeit allein entscheidet nicht über den Vorsteuerabzug.</p><?php endif ?>
 <?php if($section==='tax'&&$groupId==='closing'): ?><p>Abschlussart aus dem bisherigen Jahresabschluss übernehmen. Unklar? Vor einer Änderung mit der Buchhaltung oder Steuerberatung klären.</p><details><summary>EÜR oder Bilanz – was ist der Unterschied?</summary><p>EÜR stellt Einnahmen und Ausgaben gegenüber. Eine Bilanz erfasst zusätzlich Vermögen und Schulden; dazu gehört eine Gewinn- und Verlustrechnung. Die erforderliche Abschlussart lässt sich nicht allein aus der Gemeinnützigkeit ableiten.</p></details><p>Bereits gespeicherte Jahresangaben werden hierdurch nicht geändert.</p><?php endif ?>
 <?php if($section==='tax'&&$groupId==='depreciation'): ?><p>Bei Bilanzierung erscheint „Anschaffungen &amp; Abschreibungen (AfA)“ automatisch im Finanzmenü. Bei EÜR kann die Funktion ebenfalls benötigt werden. Die Auswahl betrifft die Anzeige im Manager, nicht die steuerliche Pflicht.</p><?php endif ?>
 <div class="form-grid"><?php foreach($groupKeys as $key){
  $field=$settingFields[$key];
  if($section==='bank'&&$key==='account_id'){$field[1]='select';$field[2]=[''=>'Noch nicht zugeordnet'];foreach($finance->accounts() as $bankAccount)if($bankAccount['kind']==='offline')$field[2][$bankAccount['id']]=$bankAccount['name'];}
  if($section==='tax'&&$key==='notice_document_id'){$field[1]='select';$field[2]=[''=>'Steuerbescheid als Finanzdokument auswählen'];foreach((new \Kulturhus\Verein\Documents($db,(string)$_SESSION['actor']))->listing() as $taxDoc){$taxText=mb_strtolower(($taxDoc['title']??'').' '.($taxDoc['notes']??''),'UTF-8');$isNotice=in_array($taxDoc['kind']??'', ['other','record'],true)&&preg_match('/steuer|freistell|bescheid|finanzamt/u',$taxText);if($taxDoc['origin']==='upload'&&$taxDoc['mime']==='application/pdf'&&in_array('finance',$taxDoc['scopes'],true)&&$isNotice)$field[2][$taxDoc['id']]=$taxDoc['title'];}$storedNotice=$sd[$key]??'';if($storedNotice!==''&&!isset($field[2][$storedNotice]))$field[2][$storedNotice]='Bisherige Zuordnung nicht verfügbar · bitte prüfen';}
  if($section==='tax'&&$groupId==='vat'&&$key==='input_tax_default'&&($sd['input_tax_eligibility']??'')==='no'){
   echo '<p class="muted">Vorsteuerabzug für neue Ausgaben: <strong>Nein</strong> – durch die Vereinseinstellung festgelegt.</p>'.hidden('data[input_tax_default]','none');
   continue;
  }
  settingsField($key,$field,$sd);
 } ?></div>
 </div>
</details>
<?php endforeach;
if($section==='club')foreach(['show_phone','show_email'] as $key)echo hidden('data['.$key.']',!empty($sd[$key])?'1':'0');
