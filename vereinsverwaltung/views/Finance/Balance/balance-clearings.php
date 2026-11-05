<?php declare(strict_types=1);
require_once __DIR__.'/../../../src/Finance/Balance/BalanceClearings.php';
use Kulturhus\Verein\{BalanceClearings,Permissions,BoardAccess};
$clearingMessages=$managerLanguageMessages??[];
$clearingText=static fn(string$source):string=>$clearingMessages!==[]?\Kulturhus\Native\Language\BalanceFilingMessages::text($source,$clearingMessages):$source;
$service=new BalanceClearings($db,(string)$_SESSION['actor']);
$clearingHome=['view'=>'finance','tab'=>'tax','balance'=>'1','balance_year'=>(string)$year,'clearings'=>'1'];
if(array_key_exists('clearing_original',$_GET)){require __DIR__.'/balance-clearing-original.php';return;}
$post=str_starts_with(input($_POST,'action'),'balance-clearing-')?$_POST:[];
$currentClearing=null;$currentClearingError='';try{$currentClearing=$service->get($year);}catch(DomainException|JsonException$ex){$currentClearingError=$ex->getMessage();}
try{
 if($post!==[]){if(input($post,'balance_year')!==(string)$year||!preg_match('/\A(?:0|[1-9][0-9]{0,2})\z/D',input($post,'version'))||!preg_match('/\A[1-9][0-9]{0,3}\z/D',input($post,'segment_version')))throw new DomainException('Ursprüngliche Angaben zum Verrechnungsnachweis vollständig prüfen.',422);$c=$service->forAction($year,(int)input($post,'version'),(int)input($post,'segment_version'));}
 else{$c=$currentClearing;if($c===null)throw new DomainException($currentClearingError);}
}catch(DomainException|JsonException$ex){echo '<p role="alert">'.e($clearingText($ex->getMessage())).'</p><a class="btn btn-outline" href="'.e(url($clearingHome)).'">'.e($clearingText('Aktuellen Verrechnungsnachweis öffnen')).'</a>';if($post!==[]){$originalInputPost=$post;$originalInputText=$clearingText;$originalInputOpen=true;require __DIR__.'/balance-original-inputs.php';}return;}
$units=$c['segments']['allocation']['units'];$entries=$c['segments']['journal']['entries'];$cases=$c['proof']['cases']??[];
$caseUnits=$c['proof']['units']??$units;
$writer=Permissions::actor($db,(string)$_SESSION['actor'])['finance_access']==='write';$board=$writer&&BoardAccess::allowed($db,(string)$_SESSION['actor']);
$clearingSource=end($c['segments']['history']);
$fields=static function(string$action)use($post,$year,$c,$clearingSource):string{$same=input($post,'action')===$action;return csrf().hidden('balance_year',$same?input($post,'balance_year'):(string)$year).hidden('version',$same?input($post,'version'):(string)$c['version']).hidden('segment_version',$same?input($post,'segment_version'):(string)$c['segments']['version']).hidden('clearing_segment_sha256',$same?input($post,'clearing_segment_sha256'):($clearingSource['sha256']??'')).hidden('action',$action);};
$clearingReviewed=static fn(string$action):bool=>input($post,'action')===$action&&input($post,'reviewed')==='1';
$money=static fn($v)=>number_format($v/100,2,',','.');
$rowData=['advances'=>[],'repayments'=>[]];
foreach($rowData as $type=>$_){
    if(input($post,'action')==='balance-clearing-save'&&is_array($post[$type]??null))$rowData[$type]=array_slice($post[$type],0,200,true);
    else foreach($c['proof']['instructions'][$type]??[] as $id=>$row){$row['id']=$id;$row['amount']=number_format($row['cents']/100,2,',','');unset($row['cents']);$rowData[$type][]=$row;}
    if(input($post,'action')!=='balance-clearing-save')$rowData[$type][]=['id'=>bin2hex(random_bytes(16))];
}
$expandRows=input($post,'action')==='balance-clearing-save';
$advanceChoices=[];foreach($cases as $key=>$case)$advanceChoices[$key]=$caseUnits[$case['from']]['name'].' → '.$caseUnits[$case['to']]['name'].' · '.number_format($case['original_cents']/100,2,',','.').' € · '.$case['origin_year'].' · '.substr($key,0,8);
foreach($rowData['advances'] as $row){
    if(!is_array($row)||!preg_match('/^[a-f0-9]{32}$/D',input($row,'id'))||input($row,'voucher')===''||!isset($units[input($row,'from')],$units[input($row,'to')]))continue;
    $advanceChoices[input($row,'id')]=$units[input($row,'from')]['name'].' → '.$units[input($row,'to')]['name'].' · '.input($row,'amount').' € · '.$year.' · '.substr(input($row,'id'),0,8);
}
$clearingIssue=static function(string$issue)use($units,$c,$clearingText):string{
    foreach($units as$unit){$prefix=$unit['name'].': ';if(!str_starts_with($issue,$prefix))continue;$rest=substr($issue,strlen($prefix));
        $plain='Buchungsaufteilung und belegte Vorschüsse stimmen noch nicht überein.';if($rest===$plain)return $prefix.$clearingText($plain);
        foreach($c['segments']['journal']['accounts']as$account){$suffix='auf der Gegenseite; getrennten Ausweis klären.';if($rest===$account['name'].' '.$suffix)return $prefix.$account['name'].' '.$clearingText($suffix);}
    }return $clearingText($issue);
};
$rowHtml=static function(string $type,string $index,array $row)use($entries,$units,$caseUnits,$advanceChoices,$expandRows,$clearingText):string{
    $advance=$type==='advances';$prefix=$type.'['.$index.']';$saved=input($row,'voucher')!=='';$id=input($row,'id');
    $select=static function(string $label,string $key,array $choices)use($prefix,$row,$clearingText):string{
        $s='<label>'.e($clearingText($label)).'<select class="form-control" name="'.e($prefix.'['.$key.']').'"><option value="">'.e($clearingText('Auswählen …')).'</option>';
        $old=input($row,$key);if($old!==''&&!isset($choices[$old]))$s.='<option selected value="'.e($old).'">'.e($clearingText('Bisherige Auswahl prüfen:')).' '.e($old).'</option>';
        foreach($choices as $value=>$text)$s.='<option value="'.e($value).'"'.(input($row,$key)===(string)$value?' selected':'').'>'.e($text).'</option>';
        return $s.'</select></label>';
    };
    $source=[];foreach($entries as $entry)$source[$entry['id']]=$entry['date'].' · '.$entry['description'];
    $caption=$clearingText($advance?'Vorschuss':'Rückzahlung').($saved?' · '.input($row,'amount').' €':' '.$clearingText('hinzufügen'));
    if($saved&&$advance)$caption.=' · '.($units[input($row,'from')]['name']??$caseUnits[input($row,'from')]['name']??input($row,'from')).' → '.($units[input($row,'to')]['name']??$caseUnits[input($row,'to')]['name']??input($row,'to'));
    $s='<details'.($saved&&$expandRows?' open':'').'><summary>'.e($caption).'</summary>'.hidden($prefix.'[id]',$id);
    $s.=$select('Zugehörige Buchung','voucher',$source);
    if($advance){$names=array_column($units,'name');$names=array_combine(array_keys($units),$names);$s.=$select('Welcher Bereich hat bezahlt?','from',$names).$select('Für welchen Bereich?','to',$names);}
    else{$s.=$select('Welcher Vorschuss wird zurückgezahlt?','advance',$advanceChoices);}
    $s.='<label>'.e($clearingText('Betrag in Euro')).'<input class="form-control" name="'.e($prefix.'[amount]').'" inputmode="decimal" value="'.e(input($row,'amount')).'" placeholder="12,50"></label>';
    if($advance)$s.='<label>'.e($clearingText('Rückzahlung vereinbart bis')).'<input class="form-control" type="date" name="'.e($prefix.'[repay_by]').'" value="'.e(input($row,'repay_by')).'"></label>';
    $s.='<label>'.e($clearingText('Grund / Beleg')).'<input class="form-control" name="'.e($prefix.'[reason]').'" maxlength="1000" value="'.e(input($row,'reason')).'"></label>';
    if($saved)$s.='<label class="check"><input class="check" type="checkbox" name="'.e($prefix.'[remove]').'" value="1"'.(input($row,'remove')==='1'?' checked':'').'>'.e($clearingText('Diese Zuordnung aufheben')).'</label>';
    return $s.'</details>';
};
?>
<?php if($post!==[]){$originalInputPost=$post;$originalInputText=$clearingText;$originalInputUnits=$units;$originalInputAccounts=$c['segments']['journal']['accounts'];$originalInputVouchers=array_column($entries,'description','id');$originalInputAdvances=$advanceChoices;$originalInputAssets=$c['segments']['journal']['asset_schedule']['items']??[];$originalInputOpen=!$writer;require __DIR__.'/balance-original-inputs.php';} ?>
<link rel="stylesheet" href="tax-guided.css?v=3"><div class="content" data-tax-guided data-balance-clearings>
<?php if($post!==[]): ?><section class="card" role="status"><h2><?= e($clearingText('Formularstand')) ?></h2><p><?= e($clearingText('Diese Eingaben gehören zum damals geöffneten Verrechnungsnachweis. Andere Schreibaufträge werden hier nicht angeboten. Aktuellen Stand getrennt prüfen.')) ?></p><a class="btn btn-outline" href="<?= e(url($clearingHome)) ?>"><?= e($clearingText('Aktuellen Verrechnungsnachweis öffnen')) ?></a></section><?php endif ?>
<section class="card"><h1><?= e($clearingText('Vorschüsse zwischen Vereinsbereichen ·')) ?> <?= $year ?></h1>
<p><a href="<?= e(url(['view'=>'finance','tab'=>'tax','balance'=>'1','balance_year'=>(string)$year,'segments'=>'1'])) ?>"><?= e($clearingText('Zurück zur Bereichsaufteilung')) ?></a></p>
<p><?= e($clearingText('Ein Bereich hat für einen anderen bezahlt und soll das Geld zurückbekommen.')) ?></p>
<p role="status"><strong><?= e($clearingText($post!==[]?'Ungespeicherte Eingaben – gespeicherten Nachweis getrennt prüfen':($c['current_approval']?'Verrechnungsnachweis geprüft':'Prüfung des Verrechnungsnachweises offen'))) ?></strong></p>
<?php if(!$c['source_current']): ?><p role="alert"><?= e($clearingText('Die Buchungsaufteilung wurde geändert. Angaben unten erneut mit den Belegen abgleichen und speichern.')) ?></p><?php endif ?>
<?php if(!$c['carry_current']): ?><p role="alert"><?= e($clearingText('Der Vorjahresnachweis wurde geändert. Der Vorstand muss den neuen Vortrag prüfen und übernehmen.')) ?></p><?php endif ?>
<?php if($c['statement']&&$c['source_current']): ?>
<?php if($c['statement']['complete']): ?><p><?= e($clearingText('Die Buchungsaufteilung stimmt mit den Vorschüssen und Rückzahlungen überein.')) ?></p>
<?php else: ?><div role="alert"><p><?= e($clearingText('Vor der Prüfung noch klären:')) ?></p><ul><?php foreach($c['statement']['issues'] as $issue): ?><li><?= e($clearingIssue($issue)) ?></li><?php endforeach ?></ul></div><?php endif ?>
<?php endif ?>
<p><?= e($clearingText('Hier werden keine Überweisungen ausgelöst. Die Prüfung betrifft nur den Nachweis, nicht die Freigabe einer Steuererklärung.')) ?></p></section>
<?php if($c['segments']['version']===0): ?><p role="alert"><?= e($clearingText('Zuerst in der Bereichsaufteilung die Buchungszeilen zuordnen.')) ?></p><?php endif ?>
<section class="card"><h2><?= e($clearingText('Noch zurückzuzahlen')) ?></h2>
<?php if($c['proof']): ?><p><strong><?= e($clearingText('Insgesamt:')) ?> <?= $money($c['proof']['totals']['closing_cents']) ?> €</strong></p><?php endif ?>
<?php if(!$cases): ?><p><?= e($clearingText('Noch keine Vorschüsse erfasst.')) ?></p><?php else: ?><div class="table-scroll" role="region" tabindex="0" aria-label="<?= e($clearingText('Vorschüsse und Rückzahlungen')) ?>"><table class="table"><thead><tr><th><?= e($clearingText('Von / für')) ?></th><th><?= e($clearingText('Jahr')) ?></th><th><?= e($clearingText('Vorgestreckt')) ?></th><th><?= e($clearingText('Im Jahr zurückgezahlt')) ?></th><th><?= e($clearingText('Noch offen')) ?></th><th><?= e($clearingText('Rückzahlung bis')) ?></th></tr></thead><tbody>
<?php foreach($cases as $case): ?><tr><td data-label="<?= e($clearingText('Von / für')) ?>"><?= e($caseUnits[$case['from']]['name'].' / '.$caseUnits[$case['to']]['name']) ?></td><td data-label="<?= e($clearingText('Jahr')) ?>"><?= $case['origin_year'] ?></td><td data-label="<?= e($clearingText('Vorgestreckt')) ?>"><?= $money($case['original_cents']) ?> €</td><td data-label="<?= e($clearingText('Zurückgezahlt')) ?>"><?= $money($case['repaid_cents']) ?> €</td><td data-label="<?= e($clearingText('Noch offen')) ?>"><strong><?= $money($case['closing_cents']) ?> €</strong><?php if($case['status']==='settled'): ?> <?= e($clearingText('· ausgeglichen')) ?><?php elseif($case['status']==='overdue'): ?> <?= e($clearingText('· Termin am Jahresende überschritten')) ?><?php endif ?></td><td data-label="<?= e($clearingText('Rückzahlung bis')) ?>"><?= e($case['repay_by']) ?></td></tr><?php endforeach ?></tbody></table></div><?php endif ?>
<p><?= e($clearingText('Die Angaben beziehen sich auf das Jahresende. Der Nachweis wird mit den Unterlagen zum Jahresabschluss exportiert.')) ?></p></section>
<?php if($c['statement']&&$c['source_current']): ?><section class="card"><details><summary><?= e($clearingText('Abgleich je Vereinsbereich')) ?></summary>
<div class="table-scroll" role="region" tabindex="0" aria-label="<?= e($clearingText('Bereichsbestände zum Jahresende')) ?>"><table class="table"><thead><tr><th><?= e($clearingText('Bereich')) ?></th><th><?= e($clearingText('Vermögen')) ?></th><th><?= e($clearingText('Intern vorgestreckt')) ?></th><th><?= e($clearingText('Kapital und Ergebnis')) ?></th><th><?= e($clearingText('Schulden gegenüber Dritten')) ?></th><th><?= e($clearingText('Intern zurückzuzahlen')) ?></th><th><?= e($clearingText('Differenz')) ?></th></tr></thead><tbody>
<?php foreach($c['statement']['units'] as $unit): $total=$unit['totals']; ?><tr><td data-label="<?= e($clearingText('Bereich')) ?>"><?= e($unit['name']) ?></td><td data-label="<?= e($clearingText('Vermögen')) ?>"><?= $money($total['asset_total_cents']) ?> €</td><td data-label="<?= e($clearingText('Intern vorgestreckt')) ?>"><?= $money($unit['internal']['closing_advance_cents']) ?> €</td><td data-label="<?= e($clearingText('Kapital und Ergebnis')) ?>"><?= $money($total['capital_total_cents']+$total['annual_result_cents']) ?> €</td><td data-label="<?= e($clearingText('Schulden gegenüber Dritten')) ?>"><?= $money($total['liability_total_cents']) ?> €</td><td data-label="<?= e($clearingText('Intern zurückzuzahlen')) ?>"><?= $money($unit['internal']['closing_owed_cents']) ?> €</td><td data-label="<?= e($clearingText('Differenz')) ?>"><?= $money($total['difference_cents']) ?> €</td></tr><?php endforeach ?>
</tbody></table></div><p><?= e($clearingText('Interne Vorschüsse heben sich im Gesamtverein auf. Die Originalbuchungen bleiben unverändert.')) ?></p></details></section><?php endif ?>
<?php if($writer&&$c['segments']['version']>0&&($c['segments']['carry_current']||$post!==[])&&($c['carry_current']||$board||$post!==[])&&($post===[]||input($post,'action')==='balance-clearing-save')): ?>
<section class="card"><h2><?= e($clearingText('Vorschüsse und Rückzahlungen erfassen')) ?></h2><form method="post" data-clearing-form data-clearing-complete-advance="<?= e($clearingText('Vorschuss oben vervollständigen')) ?>" <?= $post!==[]?'data-unsaved-changes':'' ?>><?= $fields('balance-clearing-save') ?>
<?php foreach($rowData as $type=>$rows): ?><fieldset><legend><?= e($clearingText($type==='advances'?'Vorgestreckte Beträge':'Tatsächliche Rückzahlungen')) ?></legend>
<div data-clearing-rows="<?= e($type) ?>" data-next="<?= max([-1,...array_filter(array_keys($rows),'is_int')])+1 ?>"><?php foreach($rows as $i=>$row)if(is_array($row))echo $rowHtml($type,(string)$i,$row); ?></div>
<template data-clearing-template="<?= e($type) ?>"><?= $rowHtml($type,'__row__',['id'=>'__id__']) ?></template>
<button type="button" class="btn btn-outline theme-secondary secondary" data-clearing-add="<?= e($type) ?>" hidden><?= e($clearingText($type==='advances'?'Weiteren Vorschuss hinzufügen':'Weitere Rückzahlung hinzufügen')) ?></button></fieldset><?php endforeach ?>
<label><?= e($clearingText('Prüfvermerk / Grund der Änderung')) ?><input class="form-control" name="evidence" maxlength="1000" value="<?= e(input($post,'action')==='balance-clearing-save'?input($post,'evidence'):'') ?>" required></label>
<label class="check"><input class="check" type="checkbox" name="reviewed" value="1" required <?= $clearingReviewed('balance-clearing-save')?'checked':'' ?>><?= e($clearingText('Angaben und Belege stimmen. Offene Beträge aus dem Vorjahr werden unverändert übernommen.')) ?></label>
<?= hidden('clearing_form_complete','1') ?><button class="btn btn-solid theme-primary"><?= e($clearingText('Nachweis speichern')) ?></button></form></section><?php endif ?>
<?php if($board&&$c['version']>0&&$c['state']==='draft'&&$c['source_current']&&$c['carry_current']&&($c['statement']['complete']??false)&&($post===[]||input($post,'action')==='balance-clearing-confirm')): ?>
<section class="card"><h2><?= e($clearingText('Nachweis prüfen')) ?></h2><form method="post" <?= $post!==[]?'data-unsaved-changes':'' ?>><?= $fields('balance-clearing-confirm') ?><label><?= e($clearingText('Prüfvermerk')) ?><input class="form-control" name="evidence" maxlength="1000" value="<?= e(input($post,'action')==='balance-clearing-confirm'?input($post,'evidence'):'') ?>" required></label>
<label class="check"><input class="check" type="checkbox" name="reviewed" value="1" required <?= $clearingReviewed('balance-clearing-confirm')?'checked':'' ?>><?= e($clearingText('Ich habe Vorschüsse, Rückzahlungen und offene Beträge anhand der Belege geprüft.')) ?></label><button class="btn btn-solid theme-primary"><?= e($clearingText('Nachweis als geprüft bestätigen')) ?></button></form></section><?php endif ?>
<?php if($c['current_approval']): ?><section class="card"><h2><?= e($clearingText('Steuerliches Ergebnis')) ?></h2><a class="btn btn-solid theme-primary button" href="<?= e(url(['view'=>'finance','tab'=>'tax','balance'=>'1','balance_year'=>(string)$year,'tax_reconciliation'=>'1'])) ?>"><?= e($clearingText('Steuerwerte prüfen')) ?></a></section><?php endif ?>
<?php require __DIR__.'/balance-clearing-history.php'; ?>
</div><script defer src="balance-clearings.js?v=1"></script>
