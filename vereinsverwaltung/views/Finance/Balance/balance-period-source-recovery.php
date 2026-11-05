<?php declare(strict_types=1);
$sourceOld=$_POST;$sourceAction=input($sourceOld,'action');$sourcePeriodId=input($sourceOld,'period_id');$sourceCurrent=null;$sourceBefore=null;$sourceSubmitted=null;$sourceRetry=false;$sourceRecoveryMessage=null;
try{
    $sourceSubmitted=\Kulturhus\Verein\BalancePeriodSourceForm::read($sourceOld);
    \Kulturhus\Verein\BalancePeriodSourceQuery::read($sourceOld,$sourceSubmitted['source_type']);
    $sourceBefore=$service->journalForAction($sourcePeriodId,$sourceSubmitted['version'],$sourceSubmitted['journal_hash']);
    $sourceCurrent=$service->report($sourcePeriodId);
    $sourceRetry=$sourceBefore['state']==='open'&&\Kulturhus\Verein\BalancePeriodLedger::enabled()&&\Kulturhus\Verein\Permissions::actor($db,(string)$_SESSION['actor'])['finance_access']==='write'&&((new \Kulturhus\Verein\Settings($db,(string)$_SESSION['actor']))->section('tax')['data']['accounting_basis']??'euer')==='balance';
}catch(DomainException $ex){$sourceRecoveryMessage=$ex->getMessage();try{if($sourcePeriodId!=='')$sourceCurrent=$service->report($sourcePeriodId);}catch(DomainException){/* Keep the exact submitted input independently of current rights. */}}
$sourceHidden=static function(string $name,mixed $value)use(&$sourceHidden):string{if(is_string($value))return hidden($name,$value);$out='';if(is_array($value))foreach($value as$key=>$child)$out.=$sourceHidden($name.'['.$key.']',$child);return$out;};
?>
<link rel="stylesheet" href="tax-guided.css?v=3"><div class="content" data-tax-guided data-period-source-recovery>
<div class="page-heading"><h1><?= e($periodText($sourceAction==='balance-period-source-correct'?'Belegübernahme berichtigen':'Belegübernahme prüfen')) ?></h1></div>
<p role="status"><?= e($periodText('Diese Eingaben sind noch nicht als neuer Buchungsstand gespeichert. Der ursprüngliche Bezug bleibt erhalten.')) ?></p>
<?php if($sourceRecoveryMessage!==null): ?><p role="alert"><?= e($periodText($sourceRecoveryMessage)) ?></p><?php endif ?>
<?php if($sourceCurrent!==null): ?><section class="card" data-period-source-current><h2><?= e($periodText('Aktueller Stand dieses Abschlusszeitraums')) ?></h2><p><?= e(implode('.',array_reverse(explode('-',$sourceCurrent['period']['start']))).' – '.implode('.',array_reverse(explode('-',$sourceCurrent['period']['end'])))) ?></p><p><?= e($periodText($sourceCurrent['state']==='closed'?'Buchungsstand festgeschrieben':($sourceCurrent['state']==='setup'?'Anfangsbestände prüfen':'Buchungsstand offen'))) ?></p><a class="btn btn-outline" href="<?= e(url(['view'=>'finance','tab'=>'tax','balance'=>'1','periods'=>'1','period_id'=>$sourcePeriodId])) ?>"><?= e($periodText('Aktuellen Abschlusszeitraum öffnen')) ?></a></section><?php endif ?>
<?php if($sourceRetry): ?><p><?= e($periodText('Nur dieser ursprüngliche Vorgang kann hier erneut geprüft werden. Ein neuer Buchungsstand wird nicht automatisch eingesetzt.')) ?></p>
<form method="post" class="card" data-period-source-operation="<?= e($sourceAction) ?>" data-unsaved-changes>
<?= csrf() ?>
<?php foreach(['action','period_id','version','journal_hash','source_type','source_id','source_hash','recognised_on','private_paid_on','donation_use','asset_options','purchase_form','classify_purchases','purchase_uses','previous_source_hash',...\Kulturhus\Verein\BalancePeriodSourceQuery::FIELDS]as$key)if(array_key_exists($key,$sourceOld))echo $sourceHidden($key,$sourceOld[$key]); ?>
<?php if($sourceAction==='balance-period-source-correct'): ?><label><?= e($periodText('Grund der Berichtigung')) ?><textarea class="form-control" name="reason" maxlength="500" rows="3" required><?= e(input($sourceOld,'reason')) ?></textarea></label><?php endif ?>
<label><input class="check" type="checkbox" name="reviewed" value="1" <?= input($sourceOld,'reviewed')==='1'?'checked':'' ?> required><?= e($periodText('Beleg, Datum und Zuordnung sind geprüft.')) ?></label>
<?= hidden('period_source_form_complete',input($sourceOld,'period_source_form_complete')) ?>
<button class="btn btn-solid theme-primary"><?= e($periodText($sourceAction==='balance-period-source-correct'?'Belegübernahme berichtigen':'In diesen Zeitraum übernehmen')) ?></button>
</form><?php else: ?><p><?= e($periodText('Die ursprünglichen Angaben bleiben lesbar. Für neue Buchungen den aktuellen Stand und die aktuellen Rechte prüfen.')) ?></p><?php endif ?>
<section class="card" data-period-source-submitted><h2><?= e($periodText('Geprüfte Quellenangaben')) ?></h2><dl>
<?php foreach(['source_type'=>'Belegart','recognised_on'=>'Leistungsdatum','private_paid_on'=>'Privat bezahlt am','donation_use'=>'Verwendung der Geldspende']as$key=>$label)if(is_string($sourceOld[$key]??null)): ?><dt><?= e($periodText($label)) ?></dt><dd data-period-source-input="<?= e($key) ?>"><?= e($key==='source_type'?$periodText(['invoice'=>'Rechnungen und Beiträge','transaction'=>'Bankumsätze','depreciation'=>'Anlagenwerte'][$sourceOld[$key]]??$sourceOld[$key]):$sourceOld[$key]) ?></dd><?php endif ?></dl></section>
<?php $originalInputPost=$sourceOld;$originalInputText=$periodText;$originalInputOpen=!$sourceRetry;$originalInputConfirmations=['reviewed'=>'Beleg, Datum und Zuordnung sind geprüft.'];require __DIR__.'/balance-original-inputs.php'; ?>
</div>
<?php unset($sourceHidden); ?>
