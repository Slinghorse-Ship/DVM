<?php declare(strict_types=1);
$gcHistory=$finance->goodsCorrectionHistory($r['id']);$gcFirst=input($_GET,'correction_first');
if(!$gcHistory)return;
?>
<section class="goods-correction-history" data-goods-correction-history><h3><?= $goodsE('Begründete Korrekturen') ?></h3>
<?php foreach($gcHistory as$gcEvent):$gcBefore=json_decode($gcEvent['previous_source'],true,512,JSON_THROW_ON_ERROR);$gcAfter=json_decode($gcEvent['next_source'],true,512,JSON_THROW_ON_ERROR); ?>
<details data-goods-correction-event="<?= e($gcEvent['id']) ?>" <?= $gcFirst===$gcEvent['id']?'open':'' ?>><summary><?= e(euro((int)$gcBefore['value_cents'])) ?> → <?= e(euro((int)$gcAfter['value_cents'])) ?> · <?= $goodsE('Fassung') ?> <?= e((string)$gcEvent['result_version']) ?></summary>
<p><?= $goodsE('Änderungsgrund') ?>: <strong><?= e($gcEvent['reason']) ?></strong></p><p><?= $goodsE('Gespeichert am') ?> <?= e($gcEvent['happened_at']) ?> · <?= $goodsE('Durch') ?> <?= e($gcEvent['actor_id']) ?></p>
<div class="goods-correction-pair"><?php foreach(['previous'=>'Vorheriger Stand','next'=>'Gespeicherte Korrektur']as$gcSide=>$gcHeading):$gcSnapshot=$gcSide==='previous'?$gcBefore:$gcAfter;$gcPositions=json_decode($gcEvent[$gcSide.'_positions'],true,512,JSON_THROW_ON_ERROR); ?>
<section><h4><?= $goodsE($gcHeading) ?></h4><dl><?php foreach(['donor'=>'Zuwender','designation'=>'Bezeichnung','description'=>'Beschreibung','received_on'=>'Spendendatum','value_cents'=>'Wert','origin'=>'Ursprung','evidence_present'=>'Unterlagen','valuation'=>'Wertermittlung']as$gcField=>$gcLabel):$gcValue=$gcSnapshot[$gcField];if($gcField==='value_cents')$gcValue=euro((int)$gcValue);elseif($gcField==='origin')$gcValue=$goodsT($goodsOrigins[$gcValue]??$gcValue);elseif($gcField==='evidence_present')$gcValue=$goodsT($gcValue?'Als vorhanden vermerkt':'Nicht bestätigt'); ?>
<dt><?= $goodsE($gcLabel) ?></dt><dd><?= nl2br(e((string)$gcValue)) ?></dd><?php endforeach ?></dl>
<h5><?= $goodsE('Positionen') ?></h5><ul><?php foreach($gcPositions as$gcPosition): ?><li><?= e($gcPosition['description']) ?> · <?= e(euro((int)$gcPosition['gross_cents'])) ?><?php if($gcPosition['account_code']!==''): ?> · <?= e($gcPosition['account_code'].' '.$gcPosition['account_name']) ?><?php endif ?></li><?php endforeach ?></ul></section><?php endforeach ?></div></details>
<?php endforeach ?></section>
