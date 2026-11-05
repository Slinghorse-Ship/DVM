<?php declare(strict_types=1);
use Kulturhus\Verein\{AssetBooks,AssetSale,Documents};
$monthValues=[];
for($m=1;$m<=12;$m++){$s=AssetBooks::schedule($data,$year,$m);$monthValues[$m]=['depreciation'=>euro($s['depreciation_cents']),'remaining'=>euro($s['closing_cents'])];}
$disposalDraft=input($_POST,'action')==='asset-book-year'?$_POST:[];
$evidenceDocs=array_filter((new Documents($db,(string)$_SESSION['actor']))->listing(),static fn($d)=>in_array('finance',$d['scopes'],true));
$software=$data['account']==='01350';
$saleChoices=$software?[]:AssetSale::choices($db,(string)$_SESSION['actor'],$data,$year);
?>
<details data-asset-disposal data-month-values="<?= e(json_encode($monthValues,JSON_THROW_ON_ERROR)) ?>" <?= input($disposalDraft,'disposal_kind')!==''?'open':'' ?>><summary><?= $software?'Softwarelizenz beendet':'Gegenstand verkauft oder entsorgt' ?></summary>
<label><?= $software?'Bestehen die Rechte an der gekauften Software noch?':'Was ist mit dem Gegenstand geschehen?' ?><select class="form-control" name="disposal_kind">
<?php if($software): ?>
<option value="">Ja, die Lizenz besteht noch</option><option value="software_end" <?= input($disposalDraft,'disposal_kind')==='software_end'?'selected':'' ?>>Nein, vollständig beendet</option>
<?php else: ?>
<option value="">Noch vorhanden</option><option value="scrap" <?= input($disposalDraft,'disposal_kind')==='scrap'?'selected':'' ?>>Vollständig entsorgt oder verschrottet</option><option value="sale" <?= input($disposalDraft,'disposal_kind')==='sale'?'selected':'' ?>>Vollständig verkauft</option>
<?php endif ?></select></label>
<?php if($software): ?><p>Nur nicht mehr benutzt? Dann bleibt die Lizenz hier bestehen. Verkauf, Übertragung, Erstattung und laufende Software-Abos separat klären.</p><?php endif ?>
<div data-disposal-fields>
<?php if(!$software): ?>
<div data-disposal-sale>
<label>Verkaufsrechnung<select class="form-control" name="sale_entry" data-searchable-select><option value="">Rechnung auswählen …</option><?php foreach($saleChoices as $choice): ?><option value="<?= e($choice['entry_id']) ?>" data-sale="<?= e(json_encode($choice,JSON_THROW_ON_ERROR)) ?>" <?= input($disposalDraft,'sale_entry')===$choice['entry_id']?'selected':'' ?>><?= e($choice['label'].' · '.euro($choice['gross_cents'])) ?></option><?php endforeach ?></select></label>
<a href="<?= e(url(['view'=>'finance','tab'=>'outgoing'])) ?>">Verkaufsrechnung anlegen oder prüfen</a>
<?php $saleVatKind=input($disposalDraft,'sale_vat_kind')?:'domestic';require __DIR__.'/asset-sale-vat.php'; ?>
<p data-sale-amounts aria-live="polite"></p>
</div>
<?php endif ?>
<label><?= $software?'Datum des endgültigen Lizenzendes':'Datum der Übergabe oder Entsorgung' ?><input class="form-control" name="disposal_date" type="date" min="<?= e(max($data['acquired_on'],$year.'-01-01')) ?>" max="<?= $year ?>-12-31" value="<?= e(input($disposalDraft,'disposal_date')) ?>"></label>
<p data-disposal-amounts aria-live="polite">Datum wählen. Abschreibung und auszubuchender Restwert werden berechnet.</p>
<div data-disposal-scrap>
<label>Nachweis auswählen<select class="form-control" name="disposal_document" data-searchable-select><option value="">Nachweis aus den Dokumenten wählen …</option><?php foreach($evidenceDocs as $d): ?><option value="<?= e($d['id']) ?>" <?= input($disposalDraft,'disposal_document')===$d['id']?'selected':'' ?>><?= e($d['title']) ?></option><?php endforeach ?></select></label>
<?php if($software): ?>
<label class="check"><input class="check" type="checkbox" name="software_rights_ended" value="1" <?= input($disposalDraft,'software_rights_ended')==='1'?'checked':'' ?>>Alle Rechte dieser Lizenz sind endgültig beendet. Es bleiben keine Nutzungs- oder Übertragungsrechte.</label>
<label class="check"><input class="check" type="checkbox" name="disposal_confirmed" value="1" <?= input($disposalDraft,'disposal_confirmed')==='1'?'checked':'' ?>>Datum, Nachweis und Restwert geprüft. Kein Verkauf, keine Übertragung oder Schenkung, keine Erstattung oder Entschädigung.</label>
<?php else: ?>
<label class="check"><input class="check" type="checkbox" name="disposal_confirmed" value="1" <?= input($disposalDraft,'disposal_confirmed')==='1'?'checked':'' ?>>Datum, Nachweis und Restwert sind geprüft. Der gesamte Gegenstand wurde entsorgt oder verschrottet, ohne Verkaufserlös, Schenkung, Entnahme, Versicherungsersatz oder andere Entschädigung.</label>
<?php endif ?>
</div>
<label><?= $software?'Warum endet die Lizenz?':'Grund des Abgangs' ?><textarea class="form-control" name="disposal_reason" maxlength="2000"><?= e(input($disposalDraft,'disposal_reason')) ?></textarea></label>
<?php if(!$software): ?>
<div data-disposal-sale><label class="check"><input class="check" type="checkbox" name="sale_confirmed" value="1" <?= input($disposalDraft,'sale_confirmed')==='1'?'checked':'' ?>>Rechnung, Datum und Restwert geprüft. Lieferungsart und Steuerbefreiung sind anhand der Nachweise geprüft. Der gesamte Gegenstand wurde verkauft, nicht verschenkt oder nur teilweise abgegeben.</label></div>
<p>Der Abgang wird im Anlagenverzeichnis erfasst. Den physischen Inventarbestand separat berichtigen.</p>
<?php endif ?>
</div></details>
