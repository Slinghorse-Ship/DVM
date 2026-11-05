<?php declare(strict_types=1);

$balanceFilingMessages=$annualTaxMessages??[];
$bt=static fn(string $source):string=>\Kulturhus\Native\Language\BalanceFilingMessages::text($source,$balanceFilingMessages);
use Kulturhus\Verein\{BalanceTaxFiling,BalanceTaxMapping};
$profile=$b['profile'];$mapping=$b['mapping'];$valuesReviewed=$b['review']['complete'];$approved=false;
$closingLabel=isset($b['period'])?implode('.',array_reverse(explode('-',$b['period']['start']))).' – '.implode('.',array_reverse(explode('-',$b['period']['end']))):(string)$year;
$taxReviewRoute=isset($b['period_id'])?['view'=>'finance','tab'=>'tax','balance'=>'1','periods'=>'1','period_id'=>$b['period_id'],'period_tax'=>'1']:['view'=>'finance','tab'=>'tax','balance'=>'1','balance_year'=>(string)$year,'tax_reconciliation'=>'1'];
try{$report=$service->sourceReport($filing,true);$allValues=$service->previewValues($filing);$values=$allValues['whole'];$subsidiary=$allValues['subsidiary'];$automatic=BalanceTaxMapping::preview($year,$values['book']['accounts'],$values['asset_schedule']);}
catch(DomainException $ex){$issue=$ex->getMessage();$editable=false;}
if($filing['state']==='validated'&&$canFile)try{$service->assertApproved($filing);$approved=true;}catch(DomainException){}
if(($balanceLocalHandled??false)&&input($_POST,'filing')===$fid&&isset($_POST['profile'])){
    $posted=is_array($_POST['profile']??null)?$_POST['profile']:[];
    foreach(['supplier','whole_business_name','whole_book_standard'] as $key)$profile[$key]=input($posted,$key);
    $company=is_array($posted['company']??null)?$posted['company']:[];$profile['company']+=['legal_status'=>''];foreach($profile['company'] as $key=>$_)$profile['company'][$key]=input($company,$key);
    if(is_array($_POST['mapping']??null))$mapping=array_filter($_POST['mapping'],static fn($v)=>is_string($v));
    foreach(['scope_confirmed','asset_values_unchanged','asset_tax_values_reviewed'] as $key)$profile[$key]=input($posted,$key)==='1';
    $valuesReviewed=input($_POST,'values_reviewed')==='1';
    // Preserve stale form versions on errors; never silently upgrade an old edit.
    $hidden=csrf().hidden('filing',$fid).hidden('version',input($_POST,'version')).hidden('tax_year',(string)$year);
}
?>
<p><a href="<?= e(url($base)) ?>"><?=e($bt('← Alle Erklärungen'))?></a></p>
<section class="card"><h2><?=e($bt('E-Bilanz'))?> <?= e($closingLabel) ?></h2><p class="tax-environment <?= e($filing['environment']) ?>"><?= e($filing['environment']==='production'?$bt('PRODUKTION · echte Vereinsdaten'):$bt('TEST · ausschließlich erfundene Daten')) ?></p><p role="status"><strong><?= e($states[$filing['state']]) ?></strong></p>
<?php if($issue!==''): ?><p role="alert"><?= e($issue) ?></p><?php endif ?>
<?php if(in_array($filing['state'],['sending','unknown'],true)): ?><p role="alert"><?=e($bt('Übermittlung nicht erneut starten. Zuerst den Eingang anhand des Protokolls klären lassen.'))?></p><?php endif ?>
<?php foreach($filings->attempts($fid) as $attempt): ?><p><?= e($states[$attempt['state']].' · '.$attempt['started_at']) ?><?php if($attempt['transfer_ticket']): ?><br><?=e($bt('Transferticket:'))?> <code><?= e($attempt['transfer_ticket']) ?></code><?php endif ?></p><?php endforeach ?>
<?php if(\Kulturhus\Verein\BalanceFilingEnvironment::submitted($filing)): ?><a class="btn btn-outline theme-secondary button secondary" href="<?= e(url($base+['filing'=>$fid,'tax_filing_download'=>'receipt'])) ?>"><?=e($bt('Übermittlungsprotokoll öffnen'))?></a><p><?= e($feedback['message']) ?></p><?php endif ?></section>
<?php if(!$values)return; ?>
<?php if(input($_GET,'balance_local_details')==='1'){require __DIR__.'/balance-tax-revision.php';?><p><a class="btn btn-outline" href="<?= e(url($base+['filing'=>$fid])) ?>"><?=e($bt('Zurück zu Angaben und XML-Vorbereitung'))?></a></p><?php return;} ?>
<section class="card"><h2><?=e($bt('Gesamtverein und steuerliches Ergebnis'))?></h2>
<p><?=e($bt('Gesamter Verein · Jahresergebnis:'))?> <strong><?= $money($values['book']['annual_result_cents']) ?></strong><br><?=e($bt('Steuerpflichtige Betriebe · Ergebnis nach Korrekturen:'))?> <strong><?= $money($subsidiary['result']['taxable_income_cents']) ?></strong></p>
<details><summary><?=e($bt('Berechnung ansehen'))?></summary><dl><?php foreach(['book_cents'=>$bt('Buchführungsergebnis der steuerpflichtigen Betriebe'),'valuation_difference_cents'=>$bt('Steuerliche Wertänderungen'),'tax_book_cents'=>$bt('Ergebnis zu Steuerbuchwerten'),'additions_cents'=>$bt('Nicht abziehbare Ausgaben hinzugerechnet'),'deductions_cents'=>$bt('Steuerfreie Einnahmen abgezogen'),'taxable_income_cents'=>$bt('Ergebnis vor Verlustabzug und Freibeträgen')] as $key=>$label): ?><dt><?= e($label) ?></dt><dd><?= $money($subsidiary['result'][$key]) ?></dd><?php endforeach ?></dl>
<?php foreach($subsidiary['off_balance']['entries'] as $entry): ?><p><?= e($entry['reason']) ?> · <?= $money($entry['cents']) ?><br><?= e($entry['legal_basis']) ?></p><?php endforeach ?>
<?php foreach(['opening_adjustments'=>$bt('Steuerliche Anfangsunterschiede'),'current_adjustments'=>$bt('Steuerliche Wertänderungen im Zeitraum')] as $period=>$label): ?><?php foreach($subsidiary['valuation'][$period] as $entry): ?><h3><?= e($label) ?></h3><p><?= e($entry['reason']) ?></p><?php foreach($entry['lines'] as $line): ?><p><?= e($subsidiary['valuation']['accounts'][$line['account']]['name']) ?> <?=e($bt('· Soll'))?> <?= $money($line['debit_cents']) ?> <?=e($bt('· Haben'))?> <?= $money($line['credit_cents']) ?></p><?php endforeach;endforeach;endforeach ?>
<?php foreach($subsidiary['review']['asset_tax_plans'] as $item=>$plan): ?><p><?= e($values['asset_schedule']['items'][$item]['name']??$values['asset_schedule']['items'][$item]['receipt_number']??$item) ?> · Steuerliche Nutzungsdauer <?= (int)$plan['months'] ?> Monate<br><?= e($plan['reason']) ?></p><?php endforeach ?></details>
<details><summary><?=e($bt('Gesamtbilanz, GuV und Anlagen ansehen'))?></summary><?php $balanceReport=$report;require __DIR__.'/balance-report.php'; ?></details>
<?php $sphereTaxValues=$subsidiary;require __DIR__.'/balance-tax-sphere-values.php'; ?>
<a href="<?= e(url($taxReviewRoute)) ?>"><?=e($bt('Steuerliches Ergebnis prüfen oder ändern'))?></a>
<?php if($subsidiary['previous']!==null): ?><p><?=e($bt('Steuerliche Anfangswerte sind aus der geprüften Nebenrechnung des Vorzeitraums übernommen.'))?></p><?php endif ?></section>
<details class="card" <?= !$filing['xml_data']?'open':'' ?>><summary><?=e($bt('1. Angaben prüfen'))?></summary><form method="post" id="balance-local-main"><?= $hidden.hidden('profile[tax_privileged]','yes') ?><fieldset <?= !$editable?'disabled':'' ?>>
<label><?=e($bt('Wofür ist der Verein tätig?'))?><input class="form-control" name="profile[whole_business_name]" value="<?= e($profile['whole_business_name']) ?>" maxlength="200" required></label>
<label><?=e($bt('Grundlage der Gesamtbilanz'))?><select class="form-control" name="profile[whole_book_standard]" required><option value=""><?=e($bt('Mit der Buchhaltung klären'))?></option><option value="HGBM" <?= $profile['whole_book_standard']==='HGBM'?'selected':'' ?>><?=e($bt('Deutsches Handelsrecht (HGB)'))?></option></select></label>
<details <?= $profile['company']['name']===''?'open':'' ?>><summary><?=e($bt('Verein und Absender'))?></summary>
<?php foreach(['name'=>$filing['environment']==='production'?$bt('Name des Vereins'):$bt('Name des Testvereins'),'tax_number'=>$filing['environment']==='production'?$bt('Steuernummer · 13 Stellen'):$bt('Test-Steuernummer · 13 Stellen'),'street'=>$bt('Straße'),'house_number'=>$bt('Hausnummer'),'postal_code'=>$bt('Postleitzahl'),'city'=>$bt('Ort'),'country'=>$bt('Land')] as $key=>$label): ?><label><?= e($label) ?><input class="form-control" name="profile[company][<?= e($key) ?>]" value="<?= e($profile['company'][$key]) ?>" maxlength="200" required></label><?php endforeach ?>
<fieldset class="balance-local-options"><legend><?=e($bt('Rechtsform laut Gründungs- oder Registerunterlagen'))?></legend>
<?php foreach([''=>'Noch zu klären','EV'=>'Eingetragener Verein','ST'=>'Stiftung','GMBH'=>'GmbH','gGMBH'=>'Gemeinnützige GmbH','AG'=>'AG','UG'=>'Unternehmergesellschaft','EG'=>'Genossenschaft','Other.KOER'=>'Sonstige Körperschaft']as $legalValue=>$legalLabel):?>
<label class="check"><input class="check" type="radio" name="profile[company][legal_status]" value="<?=e($legalValue)?>" <?=($profile['company']['legal_status']??'')===$legalValue?'checked':''?>><span><?=e($bt($legalLabel))?></span></label>
<?php endforeach?></fieldset>
<label><?=e($bt('Datenlieferant · Name und Anschrift'))?><input class="form-control" name="profile[supplier]" value="<?= e($profile['supplier']) ?>" maxlength="200" required></label></details>
<details><summary><?=e($bt('Kontenzuordnung der Gesamtbilanz'))?></summary>
<?php $targets=BalanceTaxFiling::targets();foreach($values['book']['accounts'] as $key=>$account): if(!$account['opening_cents']&&!$account['debit_cents']&&!$account['credit_cents']&&!isset($mapping[$key]))continue;$default=$automatic['rows'][$key];$choices=BalanceTaxMapping::choices($year,$account,$targets);$selected=$mapping[$key]??'';$fieldName='mapping['.$key.']';require __DIR__.'/balance-tax-account.php';endforeach ?></details>
<label class="check"><input class="check" type="checkbox" name="profile[scope_confirmed]" value="1" <?= $profile['scope_confirmed']?'checked':'' ?> required><?=e($bt('Gesamtbilanz und Nebenrechnung enthalten alle Vereinsbereiche und steuerpflichtigen Betriebe dieses Zeitraums.'))?></label>
<?php if(isset($subsidiary['sphere_reconciliation'])||(isset($allValues['period'])?!empty($subsidiary['review']['asset_tax_plans']):isset($subsidiary['valuation']['asset_tax']))): ?><label class="check"><input class="check" type="checkbox" name="profile[asset_tax_values_reviewed]" value="1" <?= $profile['asset_tax_values_reviewed']?'checked':'' ?> required><?=e($bt('Anlagenverzeichnis und getrennte Buch- und Steuerwerte geprüft.'))?></label>
<?php else: ?><label class="check"><input class="check" type="checkbox" name="profile[asset_values_unchanged]" value="1" <?= $profile['asset_values_unchanged']?'checked':'' ?> required><?=e($bt('Anlagenverzeichnis vollständig; Anlagenwerte gelten unverändert auch steuerlich.'))?></label><?php endif ?>
<label class="check"><input class="check" type="checkbox" name="values_reviewed" value="1" <?= $valuesReviewed?'checked':'' ?> required><?=e($bt('Die geprüfte steuerliche Nebenrechnung gehört unverändert zu diesem Abschluss.'))?></label>
<?php if($editable): ?><button class="btn btn-solid theme-primary" name="action" value="tax-filing-balance-save"><?=e($bt('Alle Angaben speichern'))?></button><?php endif ?></fieldset></form></details>
<?php if($editable): ?><button class="btn btn-outline" form="balance-local-main" name="action" value="balance-local-details" formnovalidate><?=e($bt('Weitere Steuerwerte und Abgabeart bearbeiten'))?></button><?php else: ?><a class="btn btn-outline" href="<?= e(url($base+['filing'=>$fid,'balance_local_details'=>'1'])) ?>"><?=e($bt('Weitere Steuerwerte und Abgabeart ansehen'))?></a><?php endif ?>
<?php require __DIR__.'/balance-tax-filing-approval.php'; ?>
