<?php declare(strict_types=1);
$periodOriginalRevision=input($_GET,'period_original');$periodOriginalSha=input($_GET,'period_original_sha256');
try{
    if($r===null||!preg_match('/\A[1-9][0-9]{0,4}\z/D',$periodOriginalRevision))throw new DomainException('Ursprünglichen Vorgang des Abschlusszeitraums vollständig auswählen.',422);
    $periodOriginal=$service->originalCorrectionDetails($periodId,(int)$periodOriginalRevision,$periodOriginalSha);$periodEvent=$periodOriginal['event'];$periodProof=$periodOriginal['proof'];
}catch(DomainException$ex){if(!headers_sent())http_response_code(in_array($ex->getCode(),[403,404,422],true)?$ex->getCode():403);echo '<p role="alert">'.e($periodText($ex->getMessage())).'</p>';return;}
$periodKind=$periodEvent['payload']['kind'];
$periodState=match($periodKind){'correction_prepared'=>'Entwurf für diesen Zeitraum vorbereitet','correction_confirmed'=>'Korrektur dieses Abschlusszeitraums bestätigt','correction_discarded'=>'Entwurf für diesen Zeitraum verworfen','carry_refreshed'=>'Anfangsbestände dieses Zeitraums übernommen','reaffirmed'=>'Unveränderten Abschlusszeitraum erneut bestätigt'};
$periodExplanation=match($periodKind){'correction_prepared'=>'Dieser Zahlenentwurf wurde damals zur gesonderten Prüfung gespeichert.','correction_confirmed'=>'Diese Abschlusskorrektur wurde damals gesondert geprüft und bestätigt.','correction_discarded'=>'Dieser Zahlenentwurf wurde damals verworfen. Der zuvor bestätigte Abschluss blieb erhalten.','carry_refreshed'=>'Die geprüften Anfangsbestände wurden damals aus dem berichtigten Vorzeitraum übernommen.','reaffirmed'=>'Der unveränderte Abschluss wurde damals vom aktuellen Vorstand erneut geprüft.'};
$periodNote=in_array($periodKind,['correction_prepared','carry_refreshed'],true)?$periodProof['input']['reason']:$periodEvent['payload']['data']['evidence'];
$periodAt=(new DateTimeImmutable($periodEvent['payload']['at']))->setTimezone(new DateTimeZone('Europe/Berlin'))->format('d.m.Y H:i');
$periodMoney=static fn(int$n):string=>number_format($n/100,2,',','.').' €';
$periodDate=static fn(string$d):string=>implode('.',array_reverse(explode('-',$d)));
$periodCurrentLabel=$r['correction_draft']!==null?'Korrektur wartet auf Prüfung':($r['predecessor_issues']?'Vorzeitraum erneut abstimmen':($r['state']==='closed'?($r['issues']?'Abschluss erneut prüfen':'Aktuell geprüft'):'Buchungsstand offen'));
$periodCurrentUrl=url(['view'=>'finance','tab'=>'tax','balance'=>'1','periods'=>'1','period_id'=>$periodId]);
?>
<link rel="stylesheet" href="tax-guided.css?v=3"><div class="content" data-tax-guided>
<div class="page-heading"><h1><?= e($periodText('Ausgang dieses Abschlusszeitraums')) ?></h1></div>
<p><?= e($periodDate($r['period']['start']).' – '.$periodDate($r['period']['end'])) ?></p>
<section class="card" data-balance-period-original="<?= e($periodEvent['sha256']) ?>" data-period-revision="<?= (int)$periodEvent['revision'] ?>">
<p role="status"><strong><?= e($periodText($periodState)) ?></strong></p><p><?= e($periodText($periodExplanation)) ?></p>
<dl><dt><?= e($periodText('Damals angenommen am')) ?></dt><dd><?= e($periodAt) ?></dd><dt><?= e($periodText('Begründung dieses Auftrags')) ?></dt><dd><?= e($periodNote) ?></dd></dl>
<details><summary><?= e($periodText('Damals geprüfte Zeitraumwerte und Nachweise')) ?></summary>
<?php if($periodProof!==null): ?><p><?= e($periodProof['input']['reason']) ?></p><p><?= e($periodProof['input']['legal_basis']) ?></p><?php endif ?>
<p><?= e($periodText('Ergebnis des damaligen Zahlenentwurfs')) ?>: <strong><?= $periodMoney(($periodProof['calculation']??$periodOriginal['calculation'])['annual_result_cents']) ?></strong></p>
<?php foreach($periodProof['deltas']??[]as$accountId=>$change):$account=$periodProof['calculation']['accounts'][$accountId];$sign=in_array($account['group'],['equity','reserves','provisions','liabilities','member_payables','deferred','revenue'],true)?-1:1; ?><article><h2><?= e($account['name']) ?></h2><p><?= e($periodText('Anfang:')) ?> <?= $periodMoney($sign*$change['opening_before_cents']) ?> → <?= $periodMoney($sign*$change['opening_after_cents']) ?></p><p><?= e($periodText('Ende:')) ?> <?= $periodMoney($sign*$change['closing_before_cents']) ?> → <?= $periodMoney($sign*$change['closing_after_cents']) ?></p></article><?php endforeach ?>
<?php foreach($periodProof['input']['entries']??[]as$entry): ?><article><h2><?= e($periodDate($entry['date']).' · '.$entry['description']) ?></h2><p><?= e($entry['evidence']) ?></p>
<?php foreach($entry['lines']as$line): ?><p><?= e($periodProof['calculation']['accounts'][$line['account']]['name']) ?> · <?= e($periodText('Soll')) ?> <?= $periodMoney($line['debit_cents']) ?> · <?= e($periodText('Haben')) ?> <?= $periodMoney($line['credit_cents']) ?><?= $line['sphere']!==''?' · '.e($periodText(\Kulturhus\Verein\TaxCatalog::SPHERES[$line['sphere']])):'' ?></p><?php endforeach ?></article><?php endforeach ?>
</details></section>
<section class="card"><h2><?= e($periodText('Aktueller Stand dieses Abschlusszeitraums')) ?></h2><p><?= e($periodText($periodCurrentLabel)) ?></p><a class="btn btn-outline" href="<?= e($periodCurrentUrl) ?>"><?= e($periodText('Aktuellen Abschlusszeitraum öffnen')) ?></a></section>
</div>
