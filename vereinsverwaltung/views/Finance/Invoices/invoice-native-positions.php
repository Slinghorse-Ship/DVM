<?php declare(strict_types=1);
foreach($positionDraft as$rawPosition){
 if(!is_array($rawPosition)||array_filter($rawPosition,static fn($value,$name)=>!in_array($name,['new_item','items'],true)&&!is_string($value),ARRAY_FILTER_USE_BOTH)!==[]||(isset($rawPosition['new_item'])&&!is_array($rawPosition['new_item']))){
  echo '<p class="invoice-draft-position-values">'.e(json_encode($positionDraft,JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE|JSON_THROW_ON_ERROR)).'</p>';return;
 }
}
$nativePositionFields=['description','amount','tax_rate','quantity','unit','unit_price','price_basis','calculation_note','source_hint','sphere','account','booking_key','input_tax'];
$nativeItemFields=['name','home_location','location_version','unit','category','content_quantity','content_unit'];
$nativeOption=static function(string$value,string$label,string$selected):void{echo '<option value="'.e($value).'"'.($value===$selected?' selected':'').'>'.e($label).'</option>';};
$nativeSelect=static function(string$name,string$selected,array$options,string$empty='Noch offen')use($nativeOption,$invoiceEntryText):void{
 echo '<select class="form-control" name="'.e($name).'">';$nativeOption('',$invoiceEntryText($empty),$selected);
 if($selected!==''&&!array_key_exists($selected,$options))$nativeOption($selected,$selected.' · '.$invoiceEntryText('Bitte prüfen'),$selected);
 foreach($options as$value=>$label)$nativeOption((string)$value,(string)$label,$selected);echo '</select>';
};
?>
<section class="invoice-native-positions" data-invoice-native-positions>
<p><?=e($invoiceEntryText('Der Gesamtbetrag und alle Positionen bleiben im selben Vorgang. Erst Rechnung speichern legt die Ausgabe an.'))?></p>
<p><?=e($invoiceEntryText('Ohne JavaScript Preise und Positionsbeträge direkt vom Original übernehmen. Es wird nichts automatisch umgerechnet.'))?></p>
<?php foreach($positionDraft as$index=>$row):$rowName='invoice_positions['.$index.']';$whole=array_diff($nativePositionFields,array_keys($row))===[]&&(!isset($row['new_item'])||array_diff($nativeItemFields,array_keys($row['new_item']))===[]);?>
<details class="invoice-native-line" <?=count($positionDraft)<=6?'open':''?>><summary><?=e($invoiceEntryText('Position {number}',['number'=>$index+1]))?><?php if(($row['description']??'')!==''):?> · <?=e($row['description'])?><?php endif?><?php if(($row['amount']??'')!==''):?> · <?=e($row['amount'])?> €<?php endif?></summary>
<fieldset data-native-position="<?=e((string)$index)?>"><legend><?=e($invoiceEntryText('Position {number}',['number'=>$index+1]))?></legend>
<?php if(!$whole):?>
<p><?=e($invoiceEntryText('Positionen aus diesem Entwurf'))?></p>
<?php foreach(\Kulturhus\Verein\Web\InvoiceDraftRead::controls(['invoice_positions'=>[$index=>$row]])as[$name,$value])echo hidden($name,$value);?>
<?php foreach($row as$name=>$value):?><p class="invoice-draft-position-values"><strong><?=e($invoiceEntryText(match($name){'description'=>'Beschreibung','amount'=>'Betrag','source_hint'=>'Ausgelesene Originalzeile',default=>'Weitere ursprüngliche Angabe'}))?></strong>: <?=e(is_string($value)?$value:json_encode($value,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR))?></p><?php endforeach?>
<button name="invoice_read" value="position_edit:<?=e((string)$index)?>" formnovalidate class="btn btn-outline theme-secondary"><?=e($invoiceEntryText('Positionsangaben anzeigen – Entwurf behalten'))?></button>
<?php else:?>
<div class="invoice-native-position-fields">
<label><?=e($invoiceEntryText('Beschreibung / Position laut Rechnung'))?><input class="form-control" name="<?=e($rowName)?>[description]" maxlength="300" value="<?=e($row['description'])?>"></label>
<label><?=e($invoiceEntryText('Positionsbetrag einschließlich Steuern (€)'))?><input class="form-control" name="<?=e($rowName)?>[amount]" inputmode="decimal" value="<?=e($row['amount'])?>"></label>
<label><?=e($invoiceEntryText('Steuersatz laut Rechnung'))?><?php $nativeSelect($rowName.'[tax_rate]',$row['tax_rate'],['0'=>'0 %','7'=>'7 %','19'=>'19 %'],'Bitte prüfen');?></label>
<label><?=e($invoiceEntryText('Menge'))?><input class="form-control" name="<?=e($rowName)?>[quantity]" inputmode="decimal" value="<?=e($row['quantity'])?>"></label>
<label><?=e($invoiceEntryText('Einheit (z. B. Stück oder Satz)'))?><input class="form-control" name="<?=e($rowName)?>[unit]" maxlength="30" value="<?=e($row['unit'])?>"></label>
<label><?=e($invoiceEntryText('Einzelpreis (€)'))?><input class="form-control" name="<?=e($rowName)?>[unit_price]" inputmode="decimal" value="<?=e($row['unit_price'])?>"></label>
<label><?=e($invoiceEntryText('Der Einzelpreis ist'))?><?php $nativeSelect($rowName.'[price_basis]',$row['price_basis'],['gross'=>$invoiceEntryText('Brutto (einschließlich Steuern)'),'net'=>$invoiceEntryText('Netto (zuzüglich Steuern)')],'Bitte auswählen: Netto oder Brutto?');?></label>
<label><?=e($invoiceEntryText('Rundung / abweichender Positionsbetrag laut Original (falls nötig)'))?><input class="form-control" name="<?=e($rowName)?>[calculation_note]" maxlength="300" value="<?=e($row['calculation_note'])?>"></label>
<label><?=e($invoiceEntryText('Ausgelesene Originalzeile'))?><textarea class="form-control" name="<?=e($rowName)?>[source_hint]" maxlength="1000"><?=e($row['source_hint'])?></textarea></label>
</div>
<details class="invoice-native-accounting"><summary><?=e($invoiceEntryText('Steuerliche Zuordnung dieser Position'))?></summary>
<p><?=e($invoiceEntryText('Eine bekannte Sphäre kann bereits gewählt werden. Ein noch unbekanntes Steuerkonto darf offen bleiben.'))?></p><div class="invoice-native-position-fields">
<?php foreach(['sphere'=>'Sphäre: Zu welchem Vereinsbereich gehört die Ausgabe?','account'=>'Steuerkonto (SKR42): Was wurde bezahlt?','booking_key'=>'BU-Schlüssel (SKR42): Steuerbehandlung (falls erforderlich)']as$field=>$label):?>
<label><?=e($invoiceEntryText($label))?><?php $nativeSelect($rowName.'['.$field.']',$row[$field],$invoiceAccountingOptions[$field],$field==='booking_key'?'Ohne gesonderten Schlüssel':'Noch offen');?></label>
<?php endforeach?>
<label><?=e($invoiceEntryText('Vorsteuerabzug: Darf der Verein diese Umsatzsteuer abziehen?'))?><?php $nativeSelect($rowName.'[input_tax]',$row['input_tax'],['unknown'=>$invoiceEntryText('Noch klären'),'none'=>$invoiceEntryText('Kein Vorsteuerabzug – Bruttobetrag bleibt Kosten/Wert'),'deductible'=>$invoiceEntryText('Vorsteuerabzug für diese Ausgabe geklärt')]);?></label>
</div><p><?=e($invoiceEntryText('Umsatzsteuer laut Original nicht auf 0 % ändern. Bei keinem Vorsteuerabzug bleibt der BU-Schlüssel leer. Teilweise abziehbare Vorsteuer erfordert eine geprüfte Aufteilung.'))?></p></details>
<?php if($itemChoices||!empty($row['items'])):?>
<details><summary><?=e($invoiceEntryText('Vorhandene Inventarzuordnungen'))?></summary>
<?php $retainedItemIds=[];foreach($row['items']??[]as$reference)$retainedItemIds[explode('|',$reference,2)[0]]=true;$seenItemIds=[];?>
<?php foreach($row['items']??[]as$reference):$originalItemId=explode('|',$reference,2)[0];$number=($seenItemIds[$originalItemId]??0)+1;$seenItemIds[$originalItemId]=$number;$label=$invoiceItemOptions[$reference]['label']??$invoiceEntryText('Bisherige Zuordnung {reference} – bitte prüfen',['reference'=>$reference]);if($number>1)$label.=$invoiceEntryText(' · ursprüngliche Mehrfachzuordnung {number}',['number'=>$number]);?>
<label class="invoice-choice-card"><input class="check" type="checkbox" name="<?=e($rowName)?>[items][]" value="<?=e($reference)?>" checked><?=e($label)?></label>
<?php endforeach?>
<?php foreach($itemChoices as$item):if(isset($retainedItemIds[$item['id']]))continue;$reference=$item['id'].'|'.$item['version'];?>
<label class="invoice-choice-card"><input class="check" type="checkbox" name="<?=e($rowName)?>[items][]" value="<?=e($reference)?>"><?=e($item['code'].' · '.$item['name'])?></label>
<?php endforeach?>
</details><?php endif?>
<?php if(isset($row['new_item'])):$draft=$row['new_item'];?>
<section class="invoice-native-inventory"><h4><?=e($invoiceEntryText('Neuer Inventargegenstand mit dieser Position'))?></h4><div class="invoice-native-position-fields">
<label><?=e($invoiceEntryText('Name im Inventar'))?><input class="form-control" name="<?=e($rowName)?>[new_item][name]" maxlength="160" value="<?=e($draft['name'])?>"></label>
<label><?=e($invoiceEntryText('Wo wird der Gegenstand aufbewahrt?'))?><?php $places=array_column($invoiceInventoryOptions['locations'],'name','id');$nativeSelect($rowName.'[new_item][home_location]',$draft['home_location'],$places,'Noch keinen Lagerort gewählt');?></label>
<?=hidden($rowName.'[new_item][location_version]',$draft['location_version'])?>
<label><?=e($invoiceEntryText('Einheit im Inventar'))?><?php $nativeSelect($rowName.'[new_item][unit]',$draft['unit'],$invoiceInventoryOptions['units']);?></label>
<label><?=e($invoiceEntryText('Inventarkategorie (optional)'))?><input class="form-control" name="<?=e($rowName)?>[new_item][category]" maxlength="100" value="<?=e($draft['category'])?>"></label>
<label><?=e($invoiceEntryText('Wie viele Teile enthält eine Einheit?'))?><input class="form-control" name="<?=e($rowName)?>[new_item][content_quantity]" type="number" min="1" max="1000000" step="1" value="<?=e($draft['content_quantity'])?>"></label>
<label><?=e($invoiceEntryText('Einheit der enthaltenen Teile'))?><?php $nativeSelect($rowName.'[new_item][content_unit]',$draft['content_unit'],$invoiceInventoryOptions['units']);?></label>
</div><p><?=e($invoiceEntryText('Ursprüngliche Lagerortversion'))?>: <?=e($draft['location_version'])?></p><p><?=e($invoiceEntryText('Lagerortversion erst nach ausdrücklicher Prüfung übernehmen.'))?></p>
<div class="invoice-draft-controls"><button name="invoice_read" value="position_location:<?=e((string)$index)?>" formnovalidate class="btn btn-outline theme-secondary"><?=e($invoiceEntryText('Gewählten Lagerort im aktuellen Stand lesen'))?></button><button name="invoice_read" value="position_inventory_remove:<?=e((string)$index)?>" formnovalidate class="btn btn-outline theme-secondary"><?=e($invoiceEntryText('Neuanlage zurücknehmen'))?></button></div>
</section>
<?php elseif($invoiceInventoryOptions['can_create']):?><button name="invoice_read" value="position_inventory_add:<?=e((string)$index)?>" formnovalidate class="btn btn-outline theme-secondary"><?=e($invoiceEntryText('Neuen Gegenstand vormerken'))?></button><?php endif?>
<?php endif?>
<p><button name="invoice_read" value="position_remove:<?=e((string)$index)?>" formnovalidate class="btn btn-outline theme-secondary"><?=e($invoiceEntryText('Position entfernen'))?></button></p>
</fieldset></details>
<?php endforeach?>
<p><button name="invoice_read" value="position_add" formnovalidate class="btn btn-outline theme-secondary" <?=count($positionDraft)>=50?'disabled':''?>><?=e($invoiceEntryText('Position hinzufügen'))?></button></p>
<p><?=e($invoiceEntryText('Werte laut Original prüfen. Die Positionssumme muss genau dem Gesamtbetrag entsprechen.'))?></p>
<label class="invoice-choice-card"><input class="check" type="checkbox" name="positions_rounding_reviewed" value="1" <?=input($_POST,'positions_rounding_reviewed')==='1'?'checked':''?>><?=e($invoiceEntryText('Eine ausgewiesene Rundungsabweichung ausdrücklich bestätigen'))?></label>
</section>
