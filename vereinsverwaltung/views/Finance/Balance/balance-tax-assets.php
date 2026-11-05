<?php declare(strict_types=1);
use Kulturhus\Verein\BalanceTaxAssetValues;
$assetText=$bt??static fn(string $source):string=>$source;
$taxItems=$values['asset_schedule']['items']??[];
if(isset($values['period']))foreach($taxItems as $itemId=>&$item)$item=$item['schedule']+$item['plan']+['skr_code'=>$item['plan']['account'],'item_id'=>$itemId]+$item;unset($item);
$taxItems=array_filter($taxItems,static fn($i)=>BalanceTaxAssetValues::depreciationCode($i['skr_code'])&&($i['method']??'linear_monthly')==='linear_monthly'
    &&(!isset($i['disposal'])||in_array($i['disposal']['kind']??null,['scrap','sale','partial_scrap','software_end'],true))&&!isset($i['gwg'])&&!isset($i['degressive'])&&($i['sphere']==='4'||isset($values['whole_club_scope'])&&!isset($values['period_section'])));
if(!$taxItems)return;
?>
<details class="card" <?= input($_POST,'action')==='tax-filing-balance-asset'?'open':'' ?>><summary><?= e($assetText('Für die Buchhaltung: abweichende Abschreibung')) ?></summary>
<p><?= e($assetText('Nur ausfüllen, wenn die steuerliche Nutzungsdauer fachlich geklärt ist und vom Buch-Anlagenplan abweicht. Der Buch-Anlagenplan bleibt erhalten.')) ?></p>
<?php foreach($taxItems as $itemId=>$item):
    $depCode=BalanceTaxAssetValues::depreciationCode($item['skr_code']);
    $savedPlan=$b['review']['asset_tax_plans'][$itemId]??null;$inherited=isset($b['carry_forward']['asset_tax_plans'][$itemId]);
    $taxItem=$values['asset_tax']['tax_schedule']['items'][$itemId]??$item;
    if(isset($taxItem['schedule']))$taxItem=$taxItem['schedule']+$taxItem;
    $posted=input($_POST,'action')==='tax-filing-balance-asset'&&input($_POST,'filing')===$fid&&input($_POST,'asset_item')===$itemId;
    $years=$posted?input($_POST,'tax_years'):($savedPlan?(string)($savedPlan['months']/12):'');
    $reason=$posted?input($_POST,'tax_plan_reason'):($savedPlan['reason']??'');
    $depAccounts=array_filter($report['accounts'],static fn($a)=>$a['group']==='expense'&&$a['skr_code']===$depCode);
    $selected=$posted?input($_POST,'tax_depreciation_account'):($savedPlan['depreciation_account']??(count($depAccounts)===1?array_key_first($depAccounts):''));
?>
<details <?= $savedPlan||$posted?'open':'' ?>><summary><?= e($item['name']??$item['receipt_number']??$itemId) ?></summary>
<p><?= e($assetText('Buch-Anlagenplan:')) ?> <?= (int)($item['months']/12) ?> <?= e($assetText($item['months']===12?'Jahr':'Jahre')) ?> · <?= e($assetText('Abschreibung')) ?> <?= $money($item['depreciation_cents']) ?> · <?= e($assetText('Restbuchwert')) ?> <?= $money($item['book_closing_cents']) ?></p>
<?php if(isset($item['disposal'])): ?><p><?= e($assetText(match($item['disposal']['kind']){'sale'=>'Verkauft','partial_scrap'=>'Teilweise ausgemustert','software_end'=>'Lizenz beendet',default=>'Ausgemustert'})) ?> <?= e($assetText('am')) ?> <?= e(date('d.m.Y',strtotime($item['disposal']['date']))) ?> · <?= e($assetText('Ausgebuchter Restwert im Jahr')) ?> <?= (int)$values['year'] ?>: <?= $money($item['book_disposals_cents']) ?></p><?php endif ?>
<?php if($savedPlan): ?><p><strong><?= e($assetText('Steuerlich:')) ?> <?= (int)($savedPlan['months']/12) ?> <?= e($assetText($savedPlan['months']===12?'Jahr':'Jahre')) ?> · <?= e($assetText('Abschreibung')) ?> <?= $money($taxItem['depreciation_cents']) ?> · <?= e($assetText('Restwert')) ?> <?= $money($taxItem['book_closing_cents']) ?></strong></p><p><?= e($savedPlan['reason']) ?></p><?php endif ?>
<?php if($savedPlan&&isset($item['disposal'])): ?><p><?= e($assetText('Steuerlich ausgebuchter Restwert im Jahr')) ?> <?= (int)$values['year'] ?>: <?= $money($taxItem['book_disposals_cents']) ?></p><?php endif ?>
<?php $saleValues=$values['asset_tax']['items'][$itemId]['sale']??null;if($saleValues): ?><p><?= e($assetText('Verkaufsergebnis: Buch')) ?> <?= $money($saleValues['book']['result_cents']) ?> · <?= e($assetText('Steuer')) ?> <?= $money($saleValues['tax']['result_cents']) ?></p><?php endif ?>
<?php if($inherited): ?><p><?= e($assetText('Aus dem bestätigten Vorjahr übernommen.')) ?></p>
<?php elseif($editable): ?>
<form method="post"><?= $hidden.hidden('action','tax-filing-balance-asset').hidden('asset_item',$itemId) ?>
<label><?= e($assetText('Steuerliche Nutzungsdauer · Jahre')) ?><input class="form-control" name="tax_years" type="number" min="1" max="100" step="1" value="<?= e($years) ?>" required></label>
<?php if(count($depAccounts)===1): ?><?= hidden('tax_depreciation_account',array_key_first($depAccounts)) ?>
<?php else: ?><label><?= e($assetText('Abschreibungskonto')) ?><select class="form-control" name="tax_depreciation_account" required><option value=""><?= e($assetText('Bitte auswählen')) ?></option><?php foreach($depAccounts as $key=>$account): ?><option value="<?= e($key) ?>" <?= $selected===$key?'selected':'' ?>><?= e($account['name'].' · '.$depCode) ?></option><?php endforeach ?></select></label><?php endif ?>
<label><?= e($assetText('Grund und Nachweis')) ?><textarea class="form-control" name="tax_plan_reason" maxlength="1400" required><?= e($reason) ?></textarea></label>
<label class="check"><input class="check" type="checkbox" name="tax_plan_confirmed" value="1" required <?= $posted&&input($_POST,'tax_plan_confirmed')==='1'?'checked':'' ?>><?= e($assetText('Die abweichende steuerliche Nutzungsdauer ist fachlich bestätigt.')) ?></label>
<button class="btn btn-solid theme-primary"><?= e($assetText('Steuerwerte berechnen')) ?></button></form>
<?php if($savedPlan): ?><details><summary><?= e($assetText('Abweichung entfernen')) ?></summary><form method="post"><?= $hidden.hidden('action','tax-filing-balance-asset').hidden('asset_item',$itemId).hidden('remove_plan','1') ?><label class="check"><input class="check" type="checkbox" name="remove_confirmed" value="1" required><?= e($assetText('Für diesen Gegenstand wieder den Buch-Anlagenplan zugrunde legen.')) ?></label><button class="btn btn-outline theme-secondary secondary"><?= e($assetText('Abweichung entfernen')) ?></button></form></details><?php endif ?>
<?php endif ?></details>
<?php endforeach ?></details>
