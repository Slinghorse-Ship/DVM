<?php declare(strict_types=1);
$segmentRevision=input($_GET,'segment_original');$segmentSha=input($_GET,'segment_original_sha256');
try{
    if(!preg_match('/\A[1-9][0-9]{0,3}\z/D',$segmentRevision))throw new DomainException('Ursprünglichen Ausgang der Bereichsaufteilung vollständig auswählen.',422);
    $segmentOriginal=$segments->originalDetails($year,(int)$segmentRevision,$segmentSha);$original=$segmentOriginal['original'];$originalAllocation=$original['allocation'];
}catch(DomainException|JsonException$ex){if(!headers_sent())http_response_code(in_array($ex->getCode(),[403,404,422],true)?$ex->getCode():403);echo '<p role="alert">'.e($segmentText($ex->getMessage())).'</p>';return;}
$originalAt=(new DateTimeImmutable($original['at']))->setTimezone(new DateTimeZone('Europe/Berlin'))->format('d.m.Y H:i');
$originalMoney=static fn(int$n):string=>number_format($n/100,2,',','.').' €';
$current=null;$currentError='';try{$current=$segments->get($year);}catch(DomainException|JsonException$ex){$currentError=$ex->getMessage();}
$currentLabel=$current===null?'Aktuellen Zahlenstand getrennt prüfen':(!$current['carry_current']?'Vorjahresaufteilung erneut abstimmen':(!$current['source_current']?'Geänderten Vereinsabschluss erneut zuordnen':($current['current_approval']?'Aktuell geprüft':'Aktuelle Aufteilung noch nicht freigegeben')));
$currentUrl=url(['view'=>'finance','tab'=>'tax','balance'=>'1','balance_year'=>(string)$year,'segments'=>'1','segment_voucher'=>input($_GET,'segment_voucher'),'segment_line'=>input($_GET,'segment_line'),'asset_item'=>input($_GET,'asset_item')]);
?>
<link rel="stylesheet" href="tax-guided.css?v=2"><div class="content" data-tax-guided>
<div class="page-heading"><h1><?= e($segmentText('Ausgang der Bereichsaufteilung')) ?> · <?= $year ?></h1></div>
<section class="card" data-balance-segment-original="<?= e($original['sha256']) ?>" data-segment-revision="<?= (int)$original['revision'] ?>">
<p role="status"><strong><?= e($segmentText($original['state']==='reviewed'?'Diese Aufteilung wurde damals vom Vorstand freigegeben.':'Diese Aufteilung wurde damals zur gesonderten Prüfung vorbereitet.')) ?></strong></p>
<dl><dt><?= e($segmentText('Damals angenommen am')) ?></dt><dd><?= e($originalAt) ?></dd><dt><?= e($segmentText('Ursprünglicher Vermerk')) ?></dt><dd><?= e($original['note']) ?></dd></dl>
<details><summary><?= e($segmentText('Damals geprüfte Bereiche, Beträge und Nachweise')) ?></summary>
<p><?= e($segmentText($originalAllocation['complete']?'Dieser Zahlenentwurf war vollständig zugeordnet.':'Dieser Zahlenentwurf enthielt noch offene Zuordnungen oder Differenzen.')) ?></p>
<?php foreach($originalAllocation['segments']as$unit): ?><article><h2><?= e($unit['name']) ?></h2>
<?php if($unit['calculation']===null): ?><p><?= e($segmentText('Diese Teilbilanz war noch nicht vollständig ausgeglichen.')) ?></p><?php else:$calculation=$unit['calculation']; ?><dl><dt><?= e($segmentText('Vermögen')) ?></dt><dd><?= $originalMoney($calculation['asset_total_cents']) ?></dd><dt><?= e($segmentText('Kapital und Schulden einschließlich Ergebnis')) ?></dt><dd><?= $originalMoney($calculation['passive_total_cents']) ?></dd><dt><?= e($segmentText('Ergebnis')) ?></dt><dd><?= $originalMoney($calculation['annual_result_cents']) ?></dd></dl><?php endif ?></article><?php endforeach ?>
<?php if(!empty($originalAllocation['capital_carry'])): ?><article><h2><?= e($segmentText('Übernommenes Bereichskapital')) ?></h2>
<?php foreach($originalAllocation['capital_carry']as$account=>$parts):foreach($parts as$unitId=>$net): ?><p><?= e($segmentOriginal['journal']['accounts'][$account]['name']).' · '.e($originalAllocation['units'][$unitId]['name']) ?>: <?= $originalMoney(-$net) ?></p><?php endforeach;endforeach ?></article><?php endif ?>
<?php foreach($originalAllocation['allocations']as$key=>$allocationRow):$source=$segmentOriginal['sources'][$key]; ?><article><h3><?= e(implode('.',array_reverse(explode('-',$source['date']))).' · '.$source['description']) ?></h3><p><?= e($segmentOriginal['journal']['accounts'][$source['line']['account']]['name']) ?> · <?= e($segmentText('Ursprünglicher Betrag')) ?> <?= $originalMoney($source['cents']) ?></p><p><?= e($segmentText('Soll')) ?> <?= $originalMoney($source['line']['debit_cents']) ?> · <?= e($segmentText('Haben')) ?> <?= $originalMoney($source['line']['credit_cents']) ?></p>
<?php foreach($allocationRow['parts']as$unitId=>$amount): ?><p><?= e($originalAllocation['units'][$unitId]['name']) ?>: <?= $originalMoney($amount) ?></p><?php endforeach ?><p><?= e($allocationRow['reason']) ?></p></article><?php endforeach ?>
<?php foreach($originalAllocation['transfers']??[]as$transfer): ?><article><h3><?= e($segmentText('Begründete interne Mittelübertragung')) ?></h3><p><?= e($transfer['reason']) ?></p><?php foreach($transfer['parts']as$unitId=>$net): ?><p><?= e($originalAllocation['units'][$unitId]['name']) ?> · <?= e($segmentText($net>0?'Gibt ab:':'Erhält:')) ?> <?= $originalMoney(abs($net)) ?></p><?php endforeach ?></article><?php endforeach ?>
<?php foreach($originalAllocation['asset_shares']??[]as$itemId=>$share): ?><article><h3><?= e($segmentOriginal['journal']['asset_schedule']['items'][$itemId]['name']??$itemId) ?></h3><p><?= e($share['reason']) ?></p><p><?= e($segmentText(\Kulturhus\Verein\BalanceAssetAllocation::MEASURES[$share['measure']])) ?></p><?php foreach($share['weights']as$unitId=>$weight): ?><p><?= e($originalAllocation['units'][$unitId]['name']).': '.(int)$weight ?></p><?php endforeach ?></article><?php endforeach ?>
</details></section>
<section class="card"><h2><?= e($segmentText('Aktueller Stand der Bereichsaufteilung')) ?></h2><p><?= e($segmentText($currentLabel)) ?></p><?php if($currentError!==''): ?><p role="alert"><?= e($segmentText($currentError)) ?></p><?php endif ?><a class="btn btn-outline" href="<?= e($currentUrl) ?>"><?= e($segmentText('Aktuelle Bereichsaufteilung öffnen')) ?></a></section>
</div>
