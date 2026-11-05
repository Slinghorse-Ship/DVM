<?php declare(strict_types=1);
// Selected immutable review row; all labels resolve from the same original values.
$taxRowChart=$values['valuation']['accounts'];$taxRowSource=$values['income']['scope']['source'];
$taxRowAccount=static function(string$id)use($taxRowChart,$taxRowSource):string{$account=$taxRowChart[$id]??$taxRowSource['accounts'][$id];return trim(($account['skr_code']??'').' '.$account['name']);};
?>
<article data-tax-correction="<?= e($period.':'.$id) ?>"><p><strong><?= e($row['reason']) ?></strong><?php if(isset($row['legal_basis'])): ?><br><?= e($row['legal_basis']) ?><?php endif ?></p>
<?php if($period==='asset_tax_plans'):$taxRowAsset=$taxRowSource['asset_schedule']['items'][$id]; ?>
<p><?= e($taxRowAsset['name']??$taxRowAsset['receipt_number']??$taxText('Gegenstand')) ?></p><dl><dt><?= e($taxText('Nutzungsdauer in Monaten')) ?></dt><dd><?= (int)$row['months'] ?></dd><dt><?= e($taxText('Abschreibungskonto')) ?></dt><dd><?= e($taxRowAccount($row['depreciation_account'])) ?></dd></dl>
<?php elseif(isset($row['lines'])): ?><ul><?php foreach($row['lines']as$line): ?><li><?= e($taxText($line['debit_cents']!==0?'Sollkonto':'Habenkonto')) ?> · <?= e($taxRowAccount($line['account'])) ?> · <?= $money($line['debit_cents']!==0?$line['debit_cents']:$line['credit_cents']) ?></li><?php endforeach ?></ul>
<?php elseif(isset($row['sources'])):$taxRowSources=\Kulturhus\Verein\BalanceClubTaxReconciliation::correctionSources($values);$taxRowVouchers=array_column($taxRowSource['entries'],null,'id');$taxRowAdjustments=array_column($values['valuation']['current_adjustments'],null,'id'); ?>
<p><?= e($taxText($row['kind']==='non_deductible_expense'?'Nicht abziehbare Ausgabe hinzurechnen':'Steuerfreie Einnahme abziehen')) ?></p><ul>
<?php foreach($row['sources']as$key=>$amount):$taxRowParts=explode(':',$key);$taxRowOrigin=$taxRowSources[$key];$taxRowCaption='';
 if(isset($taxRowOrigin['asset_id']))$taxRowCaption=$taxRowOrigin['name'].' · '.$taxText('Abschreibung (steuerlich)');
 elseif($taxRowParts[0]==='journal'){$taxRowVoucher=$taxRowVouchers[$taxRowParts[1]];$taxRowCaption=implode('.',array_reverse(explode('-',$taxRowVoucher['date']))).' · '.$taxRowVoucher['description'].' · '.$taxRowAccount($taxRowOrigin['account']);}
 elseif($taxRowParts[0]==='tax')$taxRowCaption=$taxText('Steuerkorrektur').' · '.$taxRowAdjustments[$taxRowParts[1]]['reason'].' · '.$taxRowAccount($taxRowOrigin['account']);
 ?><li><?= e($taxRowCaption) ?> · <?= $money($amount) ?></li><?php endforeach ?></ul>
<?php endif ?></article>
