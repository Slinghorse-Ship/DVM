<?php declare(strict_types=1);
$sourceCanWrite=$canWrite&&$balanceReport['state']==='open';$sourceService=new \Kulturhus\Verein\BalanceSources($db,(string)$_SESSION['actor']);$boundEntrySources=[];
foreach($sourceService->yearBindings($year)as$binding){$key=$binding['source_type'].':'.$binding['source_id'];foreach($binding['entry_ids']as$entryId)$boundEntrySources[$entryId]=$key;}
ob_start();require __DIR__.'/balance-annual-source-catalogue.php';$annualSourceCatalogueHtml=ob_get_clean();$selection=$selected;
?>
<section class="card" id="balance-sources"><h2><?= e($annualSourceText('Belege übernehmen oder korrigieren')) ?></h2>
<?= $annualSourceCatalogueHtml ?>
<?php if($selection!==''):
    [$sourceType,$sourceId]=array_pad(explode(':',$selection,2),2,'');
    $selected=$choice;
    if($selected):
        $sourceCanWrite=$sourceCanWrite&&$selected['owner_period']===null&&($selected['owner_year']===null||(int)$selected['owner_year']===$year);
        $retained=input($old,'source_type')===$sourceType&&input($old,'source_id')===$sourceId?$old:[];
        $existing=$sourceService->binding($sourceType,$sourceId);
        $recognised=input($retained,'recognised_on',input($_GET,'recognised_on',$existing['options']['recognised_on']??$selected['date']));$privateDate=input($retained,'private_paid_on',input($_GET,'private_paid_on',$existing['options']['private_paid_on']??''));
        $donationUse=input($retained,'donation_use',input($_GET,'donation_use',$existing['options']['donation_use']??''));
        $options=\Kulturhus\Verein\BalanceSources::formOptions($sourceType,['recognised_on'=>$recognised,'private_paid_on'=>$privateDate,'donation_use'=>$donationUse]);
        $preview=$sourceService->preview($year,$sourceType,$sourceId,$options);
        $purchaseView=['questions'=>[],'request'=>null,'error'=>''];$purchaseBound=$existing!==null;
        if($sourceType==='invoice'){
            $purchaseView=\Kulturhus\Verein\BalanceInvoiceClassification::view($year,$preview['snapshot'],$retained?:$_GET,$existing['options']['invoice_classification']??null,$purchaseBound);
            if($purchaseView['request']!==null){$options['invoice_classification']=$purchaseView['request'];$preview=$sourceService->preview($year,$sourceType,$sourceId,$options);}
            if($purchaseView['error']!=='')$preview['issues'][]=$purchaseView['error'];
        }
        $correction=$existing?$sourceService->correctionPreview($year,$sourceType,$sourceId,$options):null;
        $isContribution=($preview['snapshot']['entry']['kind']??'')==='contribution';
?>
<h3><?= e($selected['name']) ?></h3>
<?php if($selected['owner_period']!==null): ?><p><a class="btn btn-outline" data-annual-source-other-owner href="<?= e(url(['view'=>'finance','tab'=>'tax','balance'=>'1','periods'=>'1','period_id'=>$selected['owner_period'],'period_source'=>$sourceType.':'.$sourceId])) ?>"><?= e($annualSourceText('Ursprünglichen Abschluss öffnen')) ?></a></p><?php elseif($selected['owner_year']!==null&&(int)$selected['owner_year']!==$year): ?><p><a class="btn btn-outline" data-annual-source-other-owner href="<?= e(url(['view'=>'finance','tab'=>'tax','balance'=>'1','balance_year'=>(string)$selected['owner_year'],'balance_source'=>$sourceType.':'.$sourceId])) ?>"><?= e($annualSourceText('Ursprünglichen Abschluss öffnen')) ?></a></p><?php endif ?>
<?php
$annualSelectedHistory=[];if($existing){$annualSelectedHistory=(new \Kulturhus\Verein\BalanceSourceHistory($db,(string)$_SESSION['actor']))->checked($existing);$annualSelectedHistory=array_values(array_filter($annualSelectedHistory,static fn(array $row):bool=>isset($row['payload']['decision'])));}
foreach(['first'=>reset($annualSelectedHistory),'latest'=>end($annualSelectedHistory)]as$kind=>$original)if($original&&($kind==='first'||$original['sha256']!==$annualSelectedHistory[0]['sha256'])): ?>
<p><a class="btn btn-outline text-wrap" data-annual-source-<?= $kind ?>-outcome href="<?= e(url(['view'=>'finance','tab'=>'tax','balance'=>'1','balance_year'=>(string)$year,'balance_source'=>$sourceType.':'.$sourceId,'source_original'=>(string)$original['revision'],'source_original_sha256'=>$original['sha256']])) ?>"><?= e($annualSourceText($kind==='first'?'Ursprüngliche Belegübernahme öffnen':'Letzte ursprüngliche Berichtigung öffnen')) ?></a></p><?php endif ?>
<?php if($sourceCanWrite){$annualSourceStand=$ledger->sourceDecisionStand($year);if($annualSourceStand['version']!==$balanceReport['version'])$sourceCanWrite=false;}
if(!$sourceCanWrite): ?>
<p><?= e($annualSourceText('Dieser Beleg bleibt lesbar. Neue Übernahmen benötigen einen offenen Jahresstand und aktuelle Finanzrechte.')) ?></p>
<?php foreach($preview['issues']as$issue): ?><p role="alert"><?= e($annualSourceText($issue)) ?></p><?php endforeach ?>
<?php if(!empty($preview['snapshot']['internal_movements']))require __DIR__.'/balance-own-movements.php'; ?>
<?php if(isset($preview['snapshot']['entry']['amount_cents'])): ?><p><?= e($balanceMoney((int)$preview['snapshot']['entry']['amount_cents'])) ?></p><?php endif ?>
</section><?php return;endif ?>

<?php if(isset($preview['snapshot']['entry']['amount_cents'])): ?><p><strong><?= $balanceMoney((int)$preview['snapshot']['entry']['amount_cents']) ?></strong></p><?php endif ?>
<?php if(!empty($preview['snapshot']['claim'])): ?><p><?= e($annualSourceText('Private Vorleistung:')) ?> <strong><?= e($preview['snapshot']['claim']['contact_name']) ?></strong></p><?php endif ?>
<?php if(!empty($preview['snapshot']['internal_movements']))require __DIR__.'/balance-own-movements.php'; ?>
<form method="post"><?= $hidden.hidden('source_type',$sourceType).hidden('source_id',$sourceId).hidden('source_hash',$preview['hash']) ?>
<?= hidden('source_request_key',input($retained,'source_request_key',bin2hex(random_bytes(16)))).hidden('source_before_sha256',input($retained,'source_before_sha256',$annualSourceStand['sha256'])) ?>
<?php if($sourceType==='invoice'&&empty($preview['snapshot']['claim']))echo hidden('private_paid_on',''); ?>
<?php if($sourceType==='transaction'&&empty($preview['snapshot']['donations'])&&$donationUse==='')echo hidden('donation_use',''); ?>
<?php if($sourceType==='invoice'): ?>
<label><?= e($annualSourceText($isContribution?'Beitragsforderung entstanden am':'Leistung oder Lieferung am')) ?><input class="form-control" type="date" name="recognised_on" value="<?= e($recognised) ?>" required></label>
<?php if(!empty($preview['snapshot']['claim'])): ?><label><?= e($annualSourceText('Vom Mitglied bezahlt am')) ?><input class="form-control" type="date" name="private_paid_on" value="<?= e($privateDate) ?>" required></label><?php endif ?>
<?php if(!$purchaseView['questions']&&$purchaseView['request']===null)echo hidden('purchase_form','1').hidden('classify_purchases','');elseif($purchaseBound&&$purchaseView['questions'])echo hidden('classify_purchases','');require __DIR__.'/balance-invoice-classification.php'; ?>
<div class="actions"><button class="btn btn-outline theme-secondary secondary" name="action" value="balance-source-preview" formnovalidate><?= e($annualSourceText('Vorschau aktualisieren')) ?></button><a class="btn btn-outline theme-secondary button secondary" href="<?= e(url(['view'=>'finance','tab'=>'receipts','entry'=>$sourceId])) ?>"><?= e($annualSourceText($isContribution?'Beitragsforderung öffnen':'Originalrechnung öffnen')) ?></a></div>
<?php endif ?>
<?php if($sourceType==='transaction'&&(!empty($preview['snapshot']['donations'])||$donationUse!=='')): ?>
<fieldset><legend><?= e($annualSourceText('Geldspenden in dieser Zahlung')) ?></legend>
<label><?= e($annualSourceText('Darf der Verein alle zugeordneten Geldspenden frei für seine Satzungszwecke verwenden?')) ?>
<select class="form-control" name="donation_use" required><option value=""><?= e($annualSourceText('Bitte wählen')) ?></option>
<option value="unrestricted" <?= $donationUse==='unrestricted'?'selected':'' ?>><?= e($annualSourceText('Ja, ohne besondere Zweckbindung und ohne Gegenleistung')) ?></option>
<option value="needs_review" <?= $donationUse==='needs_review'?'selected':'' ?>><?= e($annualSourceText('Nein oder noch unklar')) ?></option></select></label>
<button class="btn btn-outline theme-secondary secondary" name="action" value="balance-source-preview" formnovalidate><?= e($annualSourceText('Vorschau aktualisieren')) ?></button></fieldset>
<?php endif ?>
<?php foreach(array_unique(array_merge($preview['issues'],$correction['issues']??[])) as $issue): ?><p role="alert"><?= e($annualSourceText($issue)) ?></p><?php endforeach ?>
<p data-source-stale role="status" hidden><?= e($annualSourceText('Angaben geändert. Bitte die Vorschau aktualisieren.')) ?></p>
<?php if(!$preview['issues']&&(!$correction||!$correction['issues'])): ?>
<?php if($correction): ?>
<?= hidden('correction_hash',$correction['hash']) ?>
<h4><?= e($annualSourceText('Änderung prüfen')) ?></h4>
<?php if($sourceType==='invoice'):$before=$correction['items'][0]['before']['payload']['proposal']; ?>
<div class="form-grid"><div><strong><?= e($annualSourceText('Bisher')) ?></strong><p><?= $balanceMoney((int)$before['snapshot']['entry']['amount_cents']) ?> · <?= e($before['options']['recognised_on']) ?></p></div><div><strong><?= e($annualSourceText('Neu')) ?></strong><p><?= $balanceMoney((int)$preview['snapshot']['entry']['amount_cents']) ?> · <?= e($recognised) ?></p></div></div>
<?php endif ?>
<p><?= e($annualSourceText('Betroffene Belege:')) ?> <?= count($correction['items']) ?>. <?= e($annualSourceText('Bisherige Buchungen bleiben mit ihrer Gegenbuchung erhalten.')) ?></p>
<details><summary><?= e($annualSourceText('Zuordnung vorher und nachher')) ?></summary><?php foreach($correction['items'] as $changed):$p=$changed['proposal']; ?><h4><?= e($p['vouchers'][0]['description']??'Ausmusterung ohne Restwert') ?></h4>
<div class="form-grid"><?php foreach(['Bisher'=>$changed['before']['payload']['proposal'],'Neu'=>$p] as $label=>$shown): ?><div><strong><?= e($annualSourceText($label)) ?></strong>
<?php foreach($shown['vouchers'] as $voucher): ?><p><?= e($voucher['date'].' · '.$voucher['description']) ?></p><?php foreach($voucher['lines'] as $line): ?><p><?= e($shown['accounts'][$line['account']]['name']) ?> · <?= $balanceMoney($line['debit_cents']?:$line['credit_cents']) ?> <?= $annualSourceText($line['debit_cents']?'Soll':'Haben') ?><?= $line['sphere']!==''?' · '.e(\Kulturhus\Verein\TaxCatalog::SPHERES[$line['sphere']]):'' ?></p><?php endforeach;endforeach ?></div><?php endforeach ?></div><?php endforeach ?></details>
<label><?= e($annualSourceText('Grund der Korrektur')) ?><input class="form-control" name="correction_reason" value="<?= e(input($retained,'correction_reason')) ?>" maxlength="500" required></label>
<?php endif ?>
<?php if(!$correction): ?><?php if(!$preview['vouchers']): ?><p><?= e($annualSourceText('Ausmusterung ohne Restwert. Nur das Anlagenverzeichnis wird berichtigt; es entsteht keine Geldbuchung.')) ?></p><?php else: ?><details><summary><?= e($annualSourceText('Buchungen ansehen')) ?></summary><?php foreach($preview['vouchers'] as $voucher): ?><h4><?= e($voucher['date'].' · '.(!empty($preview['snapshot']['internal_movements'])?$ownMovementText($voucher['description']):$voucher['description'])) ?></h4><?php foreach($voucher['lines'] as $line): ?><p><?= e(!empty($preview['snapshot']['internal_movements'])&&$line['account']===\Kulturhus\Verein\BalanceInternalMovementPostings::ACCOUNT?$ownMovementText($preview['accounts'][$line['account']]['name']):$preview['accounts'][$line['account']]['name']) ?> · <?= $balanceMoney($line['debit_cents']?:$line['credit_cents']) ?> <?= $annualSourceText($line['debit_cents']?'Soll':'Haben') ?></p><?php endforeach;endforeach ?></details><?php endif;endif ?>
<label class="check"><input class="check" type="checkbox" name="reviewed" value="1" required><?= e($annualSourceText($correction?'Änderung und betroffene Buchungen geprüft.':'Beleg, Datum und Zuordnung geprüft.')) ?></label><?= hidden('annual_source_form_complete','1') ?><button class="btn btn-solid theme-primary" name="action" value="<?= $correction?'balance-source-correct':'balance-source-import' ?>"><?= e($annualSourceText($correction?'Korrektur übernehmen':'In Bilanz übernehmen')) ?></button>
<?php endif ?></form>
<?php if($existing):try{$sourceHistory=(new \Kulturhus\Verein\BalanceSourceHistory($db,(string)$_SESSION['actor']))->checked($existing);}catch(DomainException|\JsonException){$sourceHistory=[];} ?>
<?php if($sourceHistory): ?><details><summary><?= e($annualSourceText('Bisherige Fassungen')) ?> (<?= count($sourceHistory) ?>)</summary><?php foreach($sourceHistory as $revision): ?><p><?= (int)$revision['revision'] ?> · <?= e(substr($revision['payload']['at'],0,10).' · '.$revision['payload']['reason']) ?></p><?php endforeach ?></details><?php endif;endif ?>
<?php endif;endif ?>
</section>
<script defer src="balance-source-preview.js?v=1"></script>
