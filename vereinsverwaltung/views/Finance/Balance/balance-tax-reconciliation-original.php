<?php declare(strict_types=1);
$revision=input($_GET,'tax_original');$sha=input($_GET,'tax_original_sha256');
try{if(!preg_match('/\A[1-9][0-9]{0,3}\z/D',$revision))throw new DomainException('Ursprünglichen steuerlichen Nachweis vollständig auswählen.',422);$original=$service->original($year,(int)$revision,$sha);$values=$original['values'];}
catch(DomainException|JsonException$ex){if(!headers_sent())http_response_code(in_array($ex->getCode(),[403,404,422],true)?$ex->getCode():403);echo '<p role="alert">'.e($taxText($ex->getMessage())).'</p>';return;}
$money=static fn(int$n):string=>number_format($n/100,2,',','.').' €';$taxOriginalMode=true;
$current=null;$currentError='';try{$current=$service->get($year);}catch(DomainException|JsonException$ex){$currentError=$ex->getMessage();}
$currentLabel=$current===null?'Aktuelle Steuerwerte getrennt prüfen':(!$current['source_current']?'Geänderten Bereichsabgleich erneut prüfen':(!$current['carry_current']?'Steuerliche Vorjahreswerte erneut abstimmen':($current['current_approval']?'Steuerwerte aktuell geprüft':'Aktuelle Prüfung der Steuerwerte noch offen')));
$review=$values['income']['scope']['review'];
?>
<link rel="stylesheet" href="tax-guided.css?v=3"><div class="content" data-tax-guided>
<div class="page-heading"><h1><?= e($taxText('Steuerwerte-Abgleich')) ?> · <?= $year ?></h1></div>
<section class="card" data-tax-reconciliation-original="<?= e($original['sha256']) ?>" data-tax-revision="<?= (int)$original['revision'] ?>">
<p role="status"><strong><?= e($taxText($original['state']==='reviewed'?'Diese Steuerwerte wurden damals vom Vorstand bestätigt.':'Diese Steuerwerte wurden damals zur gesonderten Prüfung vorbereitet.')) ?></strong></p>
<dl><dt><?= e($taxText('Damals angenommen am')) ?></dt><dd><?= e((new DateTimeImmutable($original['at']))->setTimezone(new DateTimeZone('Europe/Berlin'))->format('d.m.Y H:i')) ?></dd><dt><?= e($taxText('Ursprünglicher Vermerk')) ?></dt><dd><?= e($original['note']) ?></dd></dl>
<details><summary><?= e($taxText('Damals geprüfte Korrekturen und Quellen')) ?></summary>
<?php foreach(['opening_adjustments'=>'Unterschiede am Jahresanfang','current_adjustments'=>'Bewertungsänderungen im Jahr','off_balance_adjustments'=>'Korrekturen außerhalb der Bilanz','asset_tax_plans'=>'Abweichende Nutzungsdauer']as$period=>$label): ?><h2><?= e($taxText($label)) ?></h2><?php if(!$values['review'][$period]): ?><p><?= e($taxText('Keine.')) ?></p><?php else:foreach($values['review'][$period]as$key=>$row):$id=$period==='asset_tax_plans'?$key:$row['id'];require __DIR__.'/balance-tax-reconciliation-adjustment.php';endforeach;endif;endforeach ?>
<p><a class="btn btn-outline" data-tax-original-clearing href="<?= e(url(['view'=>'finance','tab'=>'tax','balance'=>'1','balance_year'=>(string)$year,'clearings'=>'1','clearing_original'=>(string)$review['clearing']['revision'],'clearing_original_sha256'=>$review['clearing']['sha256']])) ?>"><?= e($taxText('Ursprünglichen Verrechnungsnachweis öffnen')) ?></a></p>
<p><a class="btn btn-outline" data-tax-original-allocation href="<?= e(url(['view'=>'finance','tab'=>'tax','balance'=>'1','balance_year'=>(string)$year,'segments'=>'1','segment_original'=>(string)$review['allocation']['revision'],'segment_original_sha256'=>$review['allocation']['sha256']])) ?>"><?= e($taxText('Ursprüngliche Buchungsaufteilung öffnen')) ?></a></p>
<?php if($original['prior_source']!==null):$prior=$original['prior_source']; ?><p><a class="btn btn-outline" data-tax-original-prior href="<?= e(url(['view'=>'finance','tab'=>'tax','balance'=>'1','balance_year'=>(string)$prior['year'],'tax_reconciliation'=>'1','tax_original'=>(string)$prior['revision'],'tax_original_sha256'=>$prior['sha256']])) ?>"><?= e($taxText('Damals übernommene steuerliche Vorjahreswerte öffnen')) ?></a></p><?php endif ?>
</details></section>
<?php require __DIR__.'/balance-tax-reconciliation-values.php'; ?>
<section class="card"><h2><?= e($taxText('Aktueller Stand der Steuerwerte')) ?></h2><p><?= e($taxText($currentLabel)) ?></p><?php if($currentError!==''): ?><p role="alert"><?= e($taxText($currentError)) ?></p><?php endif ?><a class="btn btn-outline" href="<?= e(url($taxHome)) ?>"><?= e($taxText('Aktuelle Steuerwerte öffnen')) ?></a></section>
</div>
