<?php declare(strict_types=1);
$periodDisposalTextMessages=$managerLanguageMessages??[];
$periodDisposalText=static fn(string$text):string=>\Kulturhus\Native\Language\BalanceFilingMessages::text($text,$periodDisposalTextMessages);
// Only shown inside the write-authorised open-period source view.
$proof=$options['disposal']??null;$inherited=($preview['snapshot']['disposal']??null)!==null&&$proof===null;
$movement=$proof['kind']??'';
$field=static fn(string $key,string $default=''):string=>input($_GET,'disposal_preview')==='1'?input($_GET,$key,$default):$default;
$itemId=explode(':',$choice['id'],2)[0];
$saleChoices=(new \Kulturhus\Verein\BalancePeriodSale($db,(string)$_SESSION['actor']))->choices($r['period'],$preview['snapshot']['plan']??(new \Kulturhus\Verein\AssetBooks($db,(string)$_SESSION['actor']))->get($itemId)['data'],$itemId);
$documents=(new \Kulturhus\Verein\BalancePeriodDisposalEvidence($db,(string)$_SESSION['actor']))->choices($itemId,$preview['snapshot']['invoice_id']??'');
$documentOptions=static function(string $name,string $value)use($documents,$periodDisposalText):void { ?>
<select class="form-control" name="<?= e($name) ?>" data-select-search><option value=""><?= e($periodDisposalText('Nachweis auswählen')) ?></option><?php foreach($documents[$name] as $document): ?><option value="<?= e($document['id']) ?>" <?= $value===$document['id']?'selected':'' ?>><?= e($document['title']) ?></option><?php endforeach ?></select>
<?php if(!$documents[$name]): ?><small><?= e($periodDisposalText($name==='part_document'?'Den Nachweis zuerst in der Dokumentenablage mit diesem Gegenstand oder seiner Anschaffungsrechnung verknüpfen.':'Den Nachweis zuerst in der Dokumentenablage mit diesem Gegenstand verknüpfen.')) ?></small><?php endif ?>
<?php }; ?>
<?php if($inherited): ?><p><?= e($periodDisposalText('Der frühere Anlagenabgang ist bereits berücksichtigt.')) ?></p><?php else: ?>
<details <?= $proof!==null||input($_GET,'disposal_preview')==='1'?'open':'' ?>><summary><?= e($periodDisposalText('Gegenstand verkauft, ausgemustert oder Lizenz beendet?')) ?></summary>
<form method="get" data-period-disposal><?= hidden('view','finance').hidden('tab','tax').hidden('balance','1').hidden('periods','1').hidden('period_id',$periodId).hidden('period_source',$selected).($sourceCatalogueQueryHidden??'').hidden('disposal_preview','1') ?>
<label><?= e($periodDisposalText('Was ist passiert?')) ?><select class="form-control" name="disposal_kind"><?php foreach([''=>'Kein Abgang','sale'=>'Ganzer Gegenstand verkauft','scrap'=>'Ganzer Gegenstand ausgemustert – ohne Erlös','partial_scrap'=>'Ein Teil ausgemustert – ohne Erlös','software_end'=>'Software-Lizenz endgültig beendet'] as $key=>$label): ?><option value="<?= e($key) ?>" <?= $field('disposal_kind',$movement)===$key?'selected':'' ?>><?= e($periodDisposalText($label)) ?></option><?php endforeach ?></select></label>
<label><?= e($periodDisposalText('Datum')) ?><input class="form-control" type="date" name="disposal_date" min="<?= e($r['period']['start']) ?>" max="<?= e($r['period']['end']) ?>" value="<?= e($field('disposal_date',$proof['date']??'')) ?>"></label>
<label><?= e($periodDisposalText('Grund')) ?><textarea class="form-control" name="disposal_reason" maxlength="2000"><?= e($field('disposal_reason',$proof['reason']??'')) ?></textarea></label>
<label data-disposal-not="sale"><?= e($periodDisposalText('Nachweis zum Gegenstand')) ?><?php $documentOptions('disposal_document',$field('disposal_document',$proof['document_id']??'')); ?></label>
<div data-disposal-for="sale"><label><?= e($periodDisposalText('Ausgestellte Verkaufsrechnung')) ?><select class="form-control" name="sale_entry"><option value=""><?= e($periodDisposalText('Rechnung auswählen')) ?></option><?php foreach($saleChoices as $saleChoice): ?><option value="<?= e($saleChoice['entry_id']) ?>" <?= $field('sale_entry',$proof['sale_entry']??'')===$saleChoice['entry_id']?'selected':'' ?>><?= e($saleChoice['label'].' · '.$dateLabel($saleChoice['date'])) ?></option><?php endforeach ?></select></label>
<?php if(!$saleChoices): ?><p><?= e($periodDisposalText('Zuerst eine eigene Ausgangsrechnung mit einem Stück dieses Gegenstands ausstellen. Als Datum das Lieferdatum der Rechnung angeben.')) ?></p><?php endif ?>
<?php $saleVatKind=$field('sale_vat_kind',$proof['vat_kind']??'domestic');require __DIR__.'/../Assets/asset-sale-vat.php'; ?>
<label><input class="check" type="checkbox" name="sale_confirmed" value="1" <?= $field('sale_confirmed',$movement==='sale'?'1':'')==='1'?'checked':'' ?>><?= e($periodDisposalText('Der ganze Gegenstand wurde mit dieser Rechnung verkauft.')) ?></label></div>
<label data-disposal-for="software_end"><input class="check" type="checkbox" name="rights_ended" value="1" <?= $field('rights_ended',!empty($proof['rights_ended'])?'1':'')==='1'?'checked':'' ?>><?= e($periodDisposalText('Alle Nutzungsrechte sind endgültig beendet.')) ?></label>
<details data-disposal-for="partial_scrap" <?= $field('disposal_kind',$movement)==='partial_scrap'?'open':'' ?>><summary><?= e($periodDisposalText('Teilkosten und Nachweis')) ?></summary>
<p><?= e($periodDisposalText('Die Kosten des betroffenen Teils anhand eines Belegs angeben, nicht aus der Stückzahl schätzen.')) ?></p>
<label><?= e($periodDisposalText('Ursprüngliche Kosten dieses Teils (Euro)')) ?><input class="form-control" name="part_cost" inputmode="decimal" value="<?= e($field('part_cost',isset($proof['allocation'])?number_format($proof['allocation']['cost_cents']/100,2,',',''):'')) ?>"></label>
<label><?= e($periodDisposalText('Geplanter Restwert dieses Teils (Euro)')) ?><input class="form-control" name="part_residual" inputmode="decimal" value="<?= e($field('part_residual',isset($proof['allocation'])?number_format($proof['allocation']['residual_cents']/100,2,',',''):'0,00')) ?>"></label>
<label><?= e($periodDisposalText('Nachweis der Teilkosten')) ?><?php $documentOptions('part_document',$field('part_document',$proof['allocation']['document_id']??'')); ?></label>
<label><?= e($periodDisposalText('Wie wurden die Teilkosten ermittelt?')) ?><textarea class="form-control" name="part_reason" maxlength="2000"><?= e($field('part_reason',$proof['allocation']['reason']??'')) ?></textarea></label>
<label><input class="check" type="checkbox" name="same_use_and_life" value="1" <?= $field('same_use_and_life',!empty($proof['allocation']['same_use_and_life'])?'1':'')==='1'?'checked':'' ?>><?= e($periodDisposalText('Für diesen Teil gelten die bisherige Nutzung und Nutzungsdauer.')) ?></label>
<label><input class="check" type="checkbox" name="part_confirmed" value="1" <?= $field('part_confirmed',!empty($proof['allocation']['confirmed'])?'1':'')==='1'?'checked':'' ?>><?= e($periodDisposalText('Die Teilkosten sind anhand des Nachweises geprüft.')) ?></label>
</details><button class="btn btn-outline theme-secondary secondary"><?= e($periodDisposalText('Abgang prüfen')) ?></button></form></details>
<?php endif ?>
