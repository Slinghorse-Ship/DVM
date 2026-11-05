<?php declare(strict_types=1);
// Included in each original asset's section, with the same permission and CSRF boundaries.
$useOld=input($old,'area_item')===$itemId&&str_starts_with(input($old,'action'),'balance-period-area-asset-use-')?$old:[];
$useSphereProof=$item['sphere_change']??null;
$useSpheres=$useSphereProof?array_unique([$useSphereProof['book']['opening_sphere'],...array_column($useSphereProof['book']['movements'],'to_sphere')]):[$item['plan']['sphere']];
?>
<?php if(!empty($useSphereProof['book']['movements'])): ?><details><summary><?= e($areaText('Bestätigte Bereichswechsel')) ?></summary>
<?php foreach($useSphereProof['book']['movements'] as $useMove): ?><p><?= e($dateLabel($useMove['on'])) ?> · <?= e($areaText(['1'=>'Allgemeine Vereinsarbeit','2'=>'Vermögensverwaltung','3'=>'Zweckbetrieb','4'=>'Wirtschaftlicher Geschäftsbetrieb'][$useMove['to_sphere']])) ?> <?= e($areaText('· Buchwert')) ?> <?= $money($useMove['book_cents']) ?></p><?php endforeach ?>
<p><?= e($areaText('Der Buchwert wird intern übertragen. Im Gesamtverein entstehen dadurch keine neue Anschaffung und kein zusätzlicher Gewinn.')) ?></p></details><?php endif ?>
<?php if(!empty($share['changes'])): ?><h5><?= e($areaText('Geänderte Nutzung')) ?></h5>
<?php foreach($share['changes'] as $change): ?><p><strong><?= e($areaText('Ab')) ?> <?= e($dateLabel($change['on'])) ?></strong></p><ul>
<?php foreach($change['weights'] as $u=>$weight): $measureLabel=$weight===1?['hours'=>'Nutzungsstunde','days'=>'Nutzungstag','uses'=>'Nutzung','area'=>'Flächeneinheit'][$share['measure']]:\Kulturhus\Verein\BalanceAssetAllocation::MEASURES[$share['measure']]; ?><li><?= e($areaUnits[$u]['name']) ?>: <?= e((string)$weight) ?> <?= e($areaText($measureLabel)) ?></li><?php endforeach ?></ul><p><?= e($change['reason']) ?></p>
<?php if($areaCanEdit&&$areaMatches('asset-use-remove',$itemId,$change['on'])): ?><details><summary><?= e($areaText('Diese Änderung berichtigen')) ?></summary><form method="post" <?= $areaPost!==[]?'data-unsaved-changes':'' ?>><?= $areaForm('asset-use-remove').hidden('area_item',$itemId).hidden('use_on',$change['on']) ?><label><?= e($areaText('Grund')) ?><input class="form-control" name="evidence" maxlength="500" value="<?= e(input($useOld,'evidence')) ?>" required></label><button class="btn btn-outline theme-secondary secondary"><?= e($areaText('Nutzungswechsel aufheben')) ?></button><?= hidden('area_form_complete','1') ?></form></details><?php endif ?>
<?php endforeach ?><?php endif ?>
<?php if(($item['plan']['method']??null)==='cost'&&$share!==null): ?><p><?= e($areaText('Die belegten Nutzungsanteile werden unverändert vorgetragen.')) ?></p><?php endif ?>
<?php if($areaCanEdit&&$areaMatches('asset-use-change',$itemId)&&$share!==null&&($item['plan']['method']??null)!=='cost'): ?><details <?= $useOld?'open':'' ?>><summary><?= e($areaText('Nutzung ab einem Datum ändern')) ?></summary>
<form method="post" <?= $areaPost!==[]?'data-unsaved-changes':'' ?>><?= $areaForm('asset-use-change').hidden('area_item',$itemId) ?>
<p><?= e($areaText('Die bisherige Nutzung bleibt bis zum Vormonat bestehen. Trage die neuen Anteile im selben Maßstab ein.')) ?></p>
<?php if($useSphereProof): ?><p><?= e($areaText('Wähle die Betriebe im ab diesem Datum bestätigten Vereinsbereich. Die steuerliche Bewertung bleibt unverändert.')) ?></p><?php endif ?>
<label><?= e($areaText('Gültig ab (Monatsbeginn)')) ?><input class="form-control" type="date" name="use_on" min="<?= e($r['period']['start']) ?>" max="<?= e($r['period']['end']) ?>" value="<?= e(input($useOld,'use_on')) ?>" required></label>
<?php foreach($areaUnits as $u=>$unit):if(!in_array($unit['sphere'],$useSpheres,true))continue; ?><label><?= e($unit['name']) ?> · <?= e(\Kulturhus\Verein\BalanceAssetAllocation::MEASURES[$share['measure']]) ?><input class="form-control" name="weights[<?= e($u) ?>]" inputmode="numeric" pattern="[0-9]*" value="<?= e(is_array($useOld['weights']??null)?input($useOld['weights'],$u):'') ?>"></label><?php endforeach ?>
<label><?= e($areaText('Grund und Nachweis')) ?><input class="form-control" name="evidence" maxlength="500" value="<?= e(input($useOld,'evidence')) ?>" required></label>
<button class="btn btn-solid theme-primary"><?= e($areaText('Nutzungswechsel speichern')) ?></button><?= hidden('area_form_complete','1') ?></form></details><?php endif ?>
