<?php declare(strict_types=1);
if(!$canFinance||!$selectedAccount||$selectedAccount['kind']!=='offline')return;
$scheduleMessages=isset($nativeAccess)?(new \Kulturhus\Native\Language\Catalog($db,$nativeAccess))->messages():[];
$scheduleText=static fn(string $source,array $values=[]):string=>\Kulturhus\Native\Language\BankDialogMessages::text($source,$scheduleMessages,$values);
$scheduleRows=[];$scheduleError='';$schedulePosted=input($_POST,'action')==='fints-schedule-save';$scheduleOriginal=\Kulturhus\Verein\Web\BankScheduleForm::original($_POST);$scheduleBlocked=$schedulePosted&&\Kulturhus\Verein\Web\BankScheduleForm::blocked($_POST,$selectedAccount['id']);
try{$scheduleService=new \Kulturhus\Verein\FintsScheduledSync($db,(string)$_SESSION['actor'],\Kulturhus\Verein\EnableBanking::root());$scheduleRows=array_values(array_filter($scheduleService->overview(),static fn(array $row):bool=>$row['account_id']===$selectedAccount['id']));}catch(DomainException $failure){$scheduleError=$failure->getMessage();}
if($schedulePosted&&(!is_string($_POST['mapping_id']??null)||!in_array($_POST['mapping_id'],array_column($scheduleRows,'id'),true)))$scheduleBlocked=true;
$runLabels=['complete'=>['Fertig','Die Umsätze wurden gelesen und abgeglichen.','active'],'needs_auth'=>['Freigabe nötig','Die Bank verlangt PIN, TAN oder eine Bestätigung in der Banking-App.','warning'],'needs_setup'=>['Einrichtung nötig','Ein gespeicherter FinTS-Zugang fehlt.','warning'],'failed'=>['Fehlgeschlagen','Der Abruf wurde abgebrochen. Bitte den manuellen Abruf öffnen.','error'],'skipped'=>['Nicht abgerufen','Der automatische Abruf ist für dieses Konto in den Kontoeinstellungen gesperrt.','warning']];
?>
<section class="card fints-schedule" id="fints-schedule" data-fints-schedule>
<h2><?= e($scheduleText('Automatischer Umsatzabruf')) ?></h2>
<p><?= e($scheduleText('Ein aktivierter Abrufplan liest neue Umsätze alle fünf Minuten, wenn der Abrufdienst des Vereins eingerichtet ist und läuft. Die Aktivierung allein bestätigt keine Bankverbindung. Es werden keine Zahlungen ausgelöst.')) ?></p>
<p><?= e($scheduleText('Beim ersten erfolgreichen Abruf wird der verfügbare Bankverlauf gelesen, danach werden die letzten 89 Tage überlappend abgeglichen. Eine erforderliche Bankfreigabe bleibt ein eigener Schritt.')) ?></p>
<?php if($schedulePosted): ?>
<?php if($bankWorkflowError!==''): ?><p class="error" role="alert" data-fints-schedule-error><?= e($scheduleText($bankWorkflowError)) ?></p><?php endif ?>
<details class="fints-schedule-original" <?= $scheduleBlocked?'open':'' ?>><summary><?= e($scheduleText('Abrufplan prüfen')) ?></summary><textarea readonly data-fints-schedule-original aria-label="<?= e($scheduleText('Abrufplan prüfen')) ?>"><?= e(json_encode($scheduleOriginal,JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE)) ?></textarea></details>
<p><a class="btn btn-outline theme-secondary" data-fints-schedule-reopen href="<?= e(url(['view'=>'finance','tab'=>'bank','account'=>$selectedAccount['id']])) ?>#fints-schedule"><?= e($scheduleText('Aktuellen Abrufplan neu öffnen')) ?></a></p>
<?php endif ?>
<?php if($scheduleError!==''): ?><p role="status"><?= e($scheduleText($scheduleError)) ?></p>
<?php elseif(!$scheduleRows): ?><p><?= e($scheduleText('Nach der ersten FinTS-Umsatzübernahme erscheint hier der Abrufplan dieses Bankkontos. Bis dahin den Bankzugang manuell öffnen.')) ?></p>
<?php else: ?>
<p><?= e($scheduleText('Ein Bankzugang wird nur mit deiner Zustimmung verschlüsselt gespeichert. TANs werden nicht gespeichert. Fordert die Bank erneut eine Freigabe an, öffne den Bankdialog dieses Kontos.')) ?></p>
<?php foreach($scheduleRows as$row):$last=(string)($row['last_state']??'');$status=$runLabels[$last]??['Noch kein Lauf','Es liegt noch kein Abrufnachweis vor.',''];$editing=$schedulePosted&&is_string($_POST['mapping_id']??null)&&$_POST['mapping_id']===$row['id'];$choice=$editing&&is_string($_POST['enabled']??null)?$_POST['enabled']:(string)$row['enabled'];$originalVersion=$editing&&is_string($_POST['schedule_version']??null)?$_POST['schedule_version']:(string)$row['version']; ?>
<section class="fints-schedule-row" data-fints-schedule-row="<?= e($row['id']) ?>">
<h3><?= e($row['label']) ?></h3>
<dl class="fints-schedule-current" data-fints-schedule-current><dt><?= e($scheduleText('Im Vereinsmanager gespeichert')) ?></dt><dd><?= e($scheduleText($row['enabled']?'Aktiviert':'Pausiert')) ?></dd><dt><?= e($scheduleText('Abrufintervall')) ?></dt><dd><?= e($scheduleText('Alle {minutes} Minuten',['minutes'=>$row['interval_minutes']])) ?></dd><dt><?= e($scheduleText('Letzter Abrufstatus')) ?></dt><dd><span class="status <?= e($status[2]) ?>"><?= e($scheduleText($status[0])) ?></span></dd></dl>
<p><?= e($scheduleText($status[1])) ?></p>
<?php if($last!==''): ?><?php if((string)($row['last_message']??'')!==''): ?><p data-fints-schedule-bank-message><?= e($row['last_message']) ?></p><?php endif ?><?php if((string)($row['last_started_at']??'')!==''): ?><p><?= e($scheduleText('Letzter Versuch')) ?>: <time datetime="<?= e($row['last_started_at']) ?>"><?= e((new DateTimeImmutable($row['last_started_at']))->setTimezone(new DateTimeZone('Europe/Berlin'))->format('d.m.Y H:i')) ?></time></p><?php endif ?><?php endif ?>
<?php if($finance->canWrite()): ?>
<form method="post" class="fints-schedule-form" data-fints-schedule-form><?= csrf().hidden('action','fints-schedule-save').hidden('mapping_id',$row['id']).hidden('account_id',$selectedAccount['id']).hidden('schedule_version',$originalVersion) ?>
<fieldset <?= $scheduleBlocked?'disabled':'' ?>><label><?= e($scheduleText('Automatisch abrufen')) ?><select class="form-control" name="enabled"><?php if(!in_array($choice,['0','1'],true)): ?><option value="<?= e($choice) ?>" selected><?= e($scheduleText('Ursprüngliche Auswahl nicht verfügbar: {value}',['value'=>$choice])) ?></option><?php endif ?><?php foreach(['0'=>'Pausiert','1'=>'Aktiviert']as$value=>$label): ?><option value="<?= e((string)$value) ?>" <?= $choice===(string)$value?'selected':'' ?>><?= e($scheduleText($label)) ?></option><?php endforeach ?></select></label><button class="btn btn-outline theme-secondary"><?= e($scheduleText('Einstellung speichern')) ?></button></fieldset>
</form>
<?php endif ?></section>
<?php endforeach ?><?php endif ?>
<?php if($finance->canWrite()): ?><a class="btn btn-outline theme-secondary fints-auth-link" data-fints-schedule-connect href="<?= e(url(['view'=>'finance','tab'=>'bank','account'=>$selectedAccount['id'],'fints'=>'1'])) ?>#fints-manual"><?= e($scheduleText('Bankdialog dieses Kontos öffnen')) ?></a><?php endif ?>
</section>
