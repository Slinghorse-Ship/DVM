<?php declare(strict_types=1);
use Kulturhus\Verein\BalanceClosingCorrections;
$originalRevision=input($_GET,'correction_original');$originalSha=input($_GET,'correction_sha256');
try{
    if(!preg_match('/\A[1-9][0-9]{0,3}\z/D',$originalRevision))throw new DomainException('Ursprünglichen Korrekturausgang vollständig auswählen.',422);
    $originalService=new BalanceClosingCorrections($db,(string)$_SESSION['actor']);
    $original=$originalService->original($year,(int)$originalRevision,$originalSha);$originalCurrent=$originalService->get($year);
}catch(DomainException $ex){if(!headers_sent())http_response_code(in_array($ex->getCode(),[403,404,422],true)?$ex->getCode():403);echo '<p role="alert">'.e($closingText($ex->getMessage())).'</p>';return;}
$originalState=match($original['state']){'draft'=>'Entwurf zur Prüfung vorbereitet','reviewed'=>'Korrekturabschluss bestätigt','discarded'=>'Entwurf verworfen','reaffirmed'=>'Unveränderten Korrekturabschluss erneut bestätigt'};
$originalExplanation=match($original['state']){
    'draft'=>'Dieser Entwurf wurde damals zur Prüfung gespeichert. Seine Freigabe erfolgt gesondert.',
    'reviewed'=>'Dieser Korrekturabschluss wurde damals gesondert geprüft und bestätigt.',
    'discarded'=>'Dieser Entwurf wurde damals mit der angegebenen Begründung verworfen.',
    'reaffirmed'=>'Dieser unveränderte Korrekturabschluss wurde damals erneut geprüft und bestätigt.',
};
$originalCurrentLabel=$originalCurrent['draft']!==null?'Korrektur wartet auf Prüfung':(!$originalCurrent['carry_current']?'Vorjahresabgleich erforderlich':($originalCurrent['current']?'Aktuell geprüft':'Erneute Prüfung erforderlich'));
$originalAt=(new DateTimeImmutable($original['at']))->setTimezone(new DateTimeZone('Europe/Berlin'))->format('d.m.Y H:i');
$originalMoney=static fn(int $cents):string=>number_format($cents/100,2,',','.').' €';
$originalCurrentUrl=url(['view'=>'finance','tab'=>'tax','balance'=>'1','balance_year'=>(string)$year,'corrections'=>'1']);
?>
<link rel="stylesheet" href="tax-guided.css?v=1"><div class="content" data-tax-guided>
<div class="page-heading"><h1><?= e($closingText('Ausgang der Abschlusskorrektur')) ?> · <?= $year ?></h1></div>
<section class="card" data-balance-correction-original="<?= e($original['sha256']) ?>" data-correction-revision="<?= (int)$original['revision'] ?>">
<p role="status"><strong><?= e($closingText($originalState)) ?></strong></p>
<p><?= e($closingText($originalExplanation)) ?></p>
<dl><dt><?= e($closingText('Damals angenommen am')) ?></dt><dd><?= e($originalAt) ?></dd><dt><?= e($closingText('Begründung dieses Auftrags')) ?></dt><dd><?= e($original['note']) ?></dd></dl>
<details><summary><?= e($closingText('Damals geprüfter Zahlenentwurf und Nachweise')) ?></summary>
<p><?= e($original['proof']['input']['reason']) ?></p><p><?= e($original['proof']['input']['legal_basis']) ?></p>
<p><?= e($closingText('Jahresergebnis dieses Zahlenstands')) ?>: <strong><?= $originalMoney($original['proof']['calculation']['annual_result_cents']) ?></strong></p>
<?php foreach($original['proof']['deltas']as$accountId=>$change):$originalAccount=$original['proof']['calculation']['accounts'][$accountId];$originalSign=in_array($originalAccount['group'],['equity','reserves','provisions','liabilities','member_payables','deferred','revenue'],true)?-1:1; ?>
<article><h2><?= e($originalAccount['name']) ?></h2><p><?= e($closingText('Jahresanfang:')) ?> <?= $originalMoney($originalSign*$change['opening_before_cents']) ?> → <?= $originalMoney($originalSign*$change['opening_after_cents']) ?></p><p><?= e($closingText('Jahresende:')) ?> <?= $originalMoney($originalSign*$change['closing_before_cents']) ?> → <?= $originalMoney($originalSign*$change['closing_after_cents']) ?></p></article>
<?php endforeach ?>
<?php foreach($original['proof']['input']['entries']as$entry): ?><article><h2><?= e($entry['date'].' · '.$entry['description']) ?></h2><p><?= e($entry['evidence']) ?></p>
<?php foreach($entry['lines']as$line):$originalAccount=$original['proof']['calculation']['accounts'][$line['account']]; ?><p><?= e($originalAccount['name']) ?> · <?= e($closingText('Soll')) ?> <?= $originalMoney($line['debit_cents']) ?> · <?= e($closingText('Haben')) ?> <?= $originalMoney($line['credit_cents']) ?><?= $line['sphere']!==''?' · '.e($closingText(\Kulturhus\Verein\TaxCatalog::SPHERES[$line['sphere']])):'' ?></p><?php endforeach ?>
</article><?php endforeach ?>
</details></section>
<section class="card"><h2><?= e($closingText('Aktueller Stand des Jahresabschlusses')) ?></h2><p><?= e($closingText($originalCurrentLabel)) ?></p><a class="btn btn-outline" href="<?= e($originalCurrentUrl) ?>"><?= e($closingText('Aktuellen Abschluss öffnen')) ?></a></section>
</div>
