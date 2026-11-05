<?php declare(strict_types=1);
// Included in the authenticated period view; all mutations repeat access/source checks.
$sourceService=new \Kulturhus\Verein\BalancePeriodSources($db,(string)$_SESSION['actor']);
$sourceMessages=$managerLanguageMessages??[];
$sourceText=static fn(string$text):string=>\Kulturhus\Native\Language\BalanceFilingMessages::text($text,$sourceMessages);
$sourceCanWrite=$canWrite&&$r['state']==='open'&&!$r['predecessor_issues'];
ob_start();require __DIR__.'/balance-period-source-catalogue.php';$sourceCatalogueHtml=ob_get_clean();
$binding=$choice?($r['sources'][$selected]??null):null;
$sourceCatalogueQueryHidden='';foreach($sourceCataloguePage['query']??[]as$key=>$value)$sourceCatalogueQueryHidden.=hidden($key,$value);
$recognised=input($old,'recognised_on',input($_GET,'recognised_on',$binding['proposal']['options']['recognised_on']??$choice['date']??''));
$privatePaid=input($old,'private_paid_on',input($_GET,'private_paid_on',$binding['proposal']['options']['private_paid_on']??''));
$donationUse=input($old,'donation_use',input($_GET,'donation_use',$binding['proposal']['options']['donation_use']??''));
$assetFormError='';$options=[];$costPlan=null;$purchaseView=['questions'=>[],'request'=>null,'error'=>''];$purchaseBound=$binding!==null;
if($choice){
    $options=$choice['type']==='invoice'?['recognised_on'=>$recognised,'private_paid_on'=>$privatePaid]:($binding['proposal']['options']??[]);
    try{
        if($choice['type']==='transaction')$options=\Kulturhus\Verein\BalancePeriodSources::formOptions('transaction',['donation_use'=>$donationUse]);
        if($choice['type']==='depreciation'){
            $selectedAsset=(new \Kulturhus\Verein\AssetBooks($db,(string)$_SESSION['actor']))->get(explode(':',$choice['id'],2)[0]);
            if(($selectedAsset['data']['method']??null)==='cost')$costPlan=$selectedAsset['data'];
            if(isset($old['asset_options']))$options=\Kulturhus\Verein\BalancePeriodSources::formOptions('depreciation',$old);
            elseif($costPlan!==null&&input($_GET,'valuation_preview')==='1')$options=['valuations'=>(new \Kulturhus\Verein\AssetValuationEvidence($db,(string)$_SESSION['actor']))->formEvents($_GET)];
            elseif(input($_GET,'disposal_preview')==='1'){
                $itemId=explode(':',$choice['id'],2)[0];$asset=(new \Kulturhus\Verein\AssetBooks($db,(string)$_SESSION['actor']))->get($itemId);
                $options=(new \Kulturhus\Verein\BalancePeriodDisposalEvidence($db,(string)$_SESSION['actor']))->fromForm($asset['data']??[],$_GET);
                if(isset($binding['proposal']['options']['sphere_changes']))$options['sphere_changes']=$binding['proposal']['options']['sphere_changes'];
            }
            elseif(input($_GET,'sphere_preview')==='1')$options=array_replace($options,(new \Kulturhus\Verein\BalancePeriodSphereEvidence($db,(string)$_SESSION['actor']))->fromForm($_GET,$options['sphere_changes']??[]));
        }
    }catch(DomainException $ex){$assetFormError=$ex->getMessage();}
    $preview=$sourceService->preview($r['period'],$choice['type'],$choice['id'],$options);
    if($choice['type']==='invoice'){
        $purchaseView=\Kulturhus\Verein\BalanceInvoiceClassification::view((int)($preview['snapshot']['source_catalog_year']??substr($r['period']['start'],0,4)),$preview['snapshot'],$old?:$_GET,$binding['proposal']['options']['invoice_classification']??null,$purchaseBound);
        if($purchaseView['request']!==null){$options['invoice_classification']=$purchaseView['request'];$preview=$sourceService->preview($r['period'],$choice['type'],$choice['id'],$options);}
        if($purchaseView['error']!=='')$preview['issues'][]=$purchaseView['error'];
    }
    if($assetFormError!=='')$preview['issues'][]=$assetFormError;
}
?>
<section class="card" id="period-sources"><h2><?= e($sourceText('2. Belege prüfen')) ?></h2>
<?= $sourceCatalogueHtml ?>
<?php if($choice&&$binding&&($choice['owner']['period_id']??null)===$periodId):
$sourceAcceptedEvents=array_values(array_filter($sourceHistory??(new \Kulturhus\Verein\BalancePeriodLedger($db,(string)$_SESSION['actor']))->history($periodId),static fn(array $event):bool=>in_array($event['payload']['kind'],['source_imported','source_corrected'],true)&&$event['payload']['data']['proposal']['type']===$choice['type']&&$event['payload']['data']['proposal']['id']===$choice['id']));
$sourceAcceptedLinks=[];foreach($sourceAcceptedEvents as$event)$sourceAcceptedLinks[(int)$event['revision']]=$event;ksort($sourceAcceptedLinks,SORT_NUMERIC);$sourceAcceptedFirst=reset($sourceAcceptedLinks);$sourceAcceptedLatest=end($sourceAcceptedLinks);
?><p><?php if($sourceAcceptedFirst): ?><a class="btn btn-outline text-wrap" data-period-source-first-outcome href="<?= e(url(['view'=>'finance','tab'=>'tax','balance'=>'1','periods'=>'1','period_id'=>$periodId,'period_original'=>(string)$sourceAcceptedFirst['revision'],'period_original_sha256'=>$sourceAcceptedFirst['sha256']]+($sourceCataloguePage['query']??[]))) ?>"><?= e($sourceText('Ursprüngliche Belegübernahme öffnen')) ?></a><?php endif ?><?php if($sourceAcceptedLatest&&$sourceAcceptedFirst['sha256']!==$sourceAcceptedLatest['sha256']): ?> <a class="btn btn-outline text-wrap" data-period-source-latest-outcome href="<?= e(url(['view'=>'finance','tab'=>'tax','balance'=>'1','periods'=>'1','period_id'=>$periodId,'period_original'=>(string)$sourceAcceptedLatest['revision'],'period_original_sha256'=>$sourceAcceptedLatest['sha256']]+($sourceCataloguePage['query']??[]))) ?>"><?= e($sourceText('Letzte ursprüngliche Berichtigung öffnen')) ?></a><?php endif ?></p><?php endif ?>
<?php if($choice): ?>
<?php if(!empty($preview['snapshot']['internal_movements']))require __DIR__.'/balance-own-movements.php'; ?>
<?php if($sourceCanWrite&&$choice['type']==='depreciation'){if($costPlan!==null)require __DIR__.'/balance-period-cost-values.php';else{require __DIR__.'/balance-period-disposal.php';require __DIR__.'/balance-period-sphere.php';}} ?>
<?php if($sourceCanWrite&&$choice['type']==='invoice'): ?><form method="get"><?= hidden('view','finance').hidden('tab','tax').hidden('balance','1').hidden('periods','1').hidden('period_id',$periodId).hidden('period_source',$selected).$sourceCatalogueQueryHidden ?>
<label><?= e($sourceText(($preview['snapshot']['entry']['kind']??'')==='contribution'?'Beitragsforderung entstanden am':'Geliefert oder geleistet am')) ?><input class="form-control" type="date" name="recognised_on" min="<?= e($r['period']['start']) ?>" max="<?= e($r['period']['end']) ?>" value="<?= e($recognised) ?>" required></label>
<?php if($preview['snapshot']['claim']??null): ?><label><?= e($sourceText('Vom Mitglied bezahlt am')) ?><input class="form-control" type="date" name="private_paid_on" min="<?= e($r['period']['start']) ?>" max="<?= e($r['period']['end']) ?>" value="<?= e($privatePaid) ?>" required></label><?php else: ?><?= hidden('private_paid_on','') ?><?php endif ?>
<?php require __DIR__.'/balance-invoice-classification.php'; ?>
<button class="btn btn-outline theme-secondary secondary"><?= e($sourceText('Angaben prüfen')) ?></button></form><?php endif ?>
<?php if($sourceCanWrite&&$choice['type']==='transaction'&&(!empty($preview['snapshot']['donations'])||$donationUse!=='')): ?>
<form method="get"><?= hidden('view','finance').hidden('tab','tax').hidden('balance','1').hidden('periods','1').hidden('period_id',$periodId).hidden('period_source',$selected).$sourceCatalogueQueryHidden ?>
<fieldset><legend><?= e($sourceText('Darf der Verein die Geldspenden frei für seine Satzungszwecke verwenden?')) ?></legend>
<label><input class="check" type="radio" name="donation_use" value="unrestricted" <?= $donationUse==='unrestricted'?'checked':'' ?> required><?= e($sourceText('Ja, ohne besondere Zweckbindung und ohne Gegenleistung')) ?></label>
<label><input class="check" type="radio" name="donation_use" value="needs_review" <?= $donationUse==='needs_review'?'checked':'' ?> required><?= e($sourceText('Nein oder noch unklar')) ?></label></fieldset>
<button class="btn btn-outline theme-secondary secondary"><?= e($sourceText('Verwendung prüfen')) ?></button>
<a href="<?= e('?'.http_build_query(['view'=>'finance','tab'=>'tax','balance'=>'1','periods'=>'1','period_id'=>$periodId,'period_source'=>$selected,'donation_use'=>'']).'#period-sources') ?>"><?= e($sourceText('Auswahl zurücksetzen')) ?></a></form><?php endif ?>
<p data-period-source-stale role="status" hidden><?= e($sourceText('Angaben geändert. Bitte erneut prüfen.')) ?></p>
<div data-period-source-result>
<?php foreach($preview['issues'] as $issue): ?><p role="alert"><?= e($sourceText($issue)) ?></p><?php endforeach ?>
<?php if(!$preview['issues']): ?>
<?php if(isset($preview['snapshot']['sphere_change'])):$sphereValues=$preview['snapshot']['sphere_change']; ?>
<h3><?= e($sourceText('Nutzung des Gegenstands')) ?></h3>
<ul><?php foreach($sphereValues['history'] as $change): ?><li><?= e($dateLabel($change['on']).' · '.\Kulturhus\Verein\TaxCatalog::SPHERES[$change['to_sphere']]) ?></li><?php endforeach ?></ul>
<details><summary><?= e($sourceText('Buchwerte und Steuerwerte vergleichen')) ?></summary><dl>
<?php foreach($sphereValues['book']['depreciation_by_sphere'] as $sphere=>$cents): if(!$cents)continue; ?><dt><?= e($sourceText('Abschreibung ·')) ?> <?= e(\Kulturhus\Verein\TaxCatalog::SPHERES[$sphere]) ?></dt><dd><?= $money($cents) ?></dd><?php endforeach ?>
<dt><?= e($sourceText('Steuerwert am Anfang')) ?></dt><dd><?= $money($sphereValues['tax']['opening_cents']) ?></dd>
<dt><?= e($sourceText('Einlage in den steuerpflichtigen Betrieb')) ?></dt><dd><?= $money($sphereValues['tax']['contributions_cents']) ?></dd>
<dt><?= e($sourceText('Entnahme aus dem steuerpflichtigen Betrieb')) ?></dt><dd><?= $money($sphereValues['tax']['withdrawals_cents']) ?></dd>
<dt><?= e($sourceText('Steuerlicher Bewertungsgewinn / -verlust')) ?></dt><dd><?= $money($sphereValues['tax']['valuation_result_cents']) ?></dd>
<dt><?= e($sourceText('Steuerliche Abschreibung')) ?></dt><dd><?= $money($sphereValues['tax']['depreciation_cents']) ?></dd>
<dt><?= e($sourceText('Steuerwert am Ende')) ?></dt><dd><?= $money($sphereValues['tax']['closing_cents']) ?></dd></dl></details>
<?php endif ?>
<?php if($choice['type']==='depreciation'):$schedule=$preview['snapshot']['schedule']; ?><dl><dt><?= e($sourceText('Buchwert am Anfang')) ?></dt><dd><?= $money($schedule['book_opening_cents']) ?></dd><?php if($costPlan===null): ?><dt><?= e($sourceText('Abschreibung in diesem Zeitraum')) ?></dt><dd><?= $money($schedule['depreciation_cents']) ?></dd><?php else: ?><dt><?= e($sourceText('Wertminderung')) ?></dt><dd><?= $money($schedule['impairment_cents']) ?></dd><dt><?= e($sourceText('Wertaufholung')) ?></dt><dd><?= $money($schedule['writeup_cents']) ?></dd><dt><?= e($sourceText('Steuerwert am Ende')) ?></dt><dd><?= $money($preview['snapshot']['valuation']['tax']['book_closing_cents']) ?></dd><?php endif ?><?php if(isset($preview['snapshot']['disposal'])||$costPlan!==null): ?><dt><?= e($sourceText('Restbuchwert des Abgangs')) ?></dt><dd><?= $money($schedule['book_disposals_cents']??0) ?></dd><?php endif ?><dt><?= e($sourceText('Buchwert am Ende')) ?></dt><dd><?= $money($schedule['book_closing_cents']) ?></dd></dl><?php endif ?>
<?php if($preview['snapshot']['claim']??null): ?><p><?= e($sourceText('Privat bezahlt von')) ?> <?= e($preview['snapshot']['claim']['contact_name']) ?>.</p><?php endif ?>
<?php if(isset($preview['snapshot']['assessment'])): ?><p><?= e($sourceText('Mitgliedsbeitrag')) ?> <?= (int)$preview['snapshot']['assessment']['year'] ?> · <?= $money((int)$preview['snapshot']['entry']['amount_cents']) ?></p><?php endif ?>
<?php if(isset($preview['snapshot']['sale_values'])):$saleValues=$preview['snapshot']['sale_values']; ?>
<dl><dt><?= e($sourceText('Verkaufspreis')) ?></dt><dd><?= $money($saleValues['gross_cents']) ?></dd><dt><?= e($sourceText('Umsatzsteuer')) ?></dt><dd><?= $money($saleValues['tax_cents']) ?></dd><dt><?= e($sourceText($saleValues['gain']?'Gewinn aus dem Verkauf':'Verlust aus dem Verkauf')) ?></dt><dd><?= $money(abs($saleValues['result_cents'])) ?></dd></dl>
<?php if($binding&&hash_equals($binding['proposal']['hash'],$preview['hash'])&&\Kulturhus\Verein\BalancePeriod::contains($r['period'],$preview['snapshot']['disposal']['date'])): ?>
<p><a class="btn btn-outline theme-secondary button secondary" href="<?= e('?'.http_build_query(['view'=>'finance','tab'=>'tax','balance'=>'1','periods'=>'1','period_id'=>$periodId,'period_source'=>'invoice:'.$preview['snapshot']['disposal']['sale_entry'],'recognised_on'=>$preview['snapshot']['disposal']['date']]).'#period-sources') ?>"><?= e($sourceText('Verkaufsrechnung prüfen')) ?></a></p>
<?php endif ?><?php endif ?>
<details><summary><?= e($sourceText('Vorgeschlagene Buchung ansehen')) ?></summary><?php foreach($preview['vouchers'] as $voucher): ?><h3><?= e($dateLabel($voucher['date']).' · '.(!empty($preview['snapshot']['internal_movements'])?$sourceText($voucher['description']):$voucher['description'])) ?></h3><?php foreach($voucher['lines'] as $line): ?><p><?= e(!empty($preview['snapshot']['internal_movements'])&&$line['account']===\Kulturhus\Verein\BalanceInternalMovementPostings::ACCOUNT?$sourceText($preview['accounts'][$line['account']]['name']):$preview['accounts'][$line['account']]['name']) ?><?= e($sourceText(': Soll')) ?> <?= $money($line['debit_cents']) ?> <?= e($sourceText('· Haben')) ?> <?= $money($line['credit_cents']) ?></p><?php endforeach ?><?php endforeach ?></details>
<?php if($choice['type']==='depreciation'&&$r['period']['end']>=date('Y-m-d')): ?><p><?= e($sourceText('Anlagenwerte nach Ende dieses Zeitraums bestätigen.')) ?></p>
<?php elseif($choice['owner']&&$choice['owner']['period_id']!==$periodId): ?><p><?= e($sourceText('Bereits in einem anderen Abschlusszeitraum übernommen.')) ?></p>
<?php elseif($binding&&hash_equals($binding['proposal']['hash'],$preview['hash'])): ?><p><?= e($sourceText('Bereits übernommen. Der Beleg ist unverändert.')) ?></p>
<?php elseif(!$sourceCanWrite): ?><p><?= e($sourceText('Lesestand: Belege und Vorschläge können geprüft werden. Eine Übernahme benötigt aktuellen Finanz-Schreibzugang und einen offenen Zeitraum.')) ?></p>
<?php else: ?>
<form method="post"><?= $form($binding?'balance-period-source-correct':'balance-period-source-import').hidden('source_type',$choice['type']).hidden('source_id',$choice['id']).hidden('source_hash',$preview['hash']).hidden('recognised_on',$recognised).hidden('private_paid_on',$privatePaid).$sourceCatalogueQueryHidden ?>
<?php if($choice['type']==='invoice'): ?><?= hidden('purchase_form','1').hidden('classify_purchases',isset($options['invoice_classification'])?'1':'') ?><?php foreach($options['invoice_classification']['uses']??[] as $ordinal=>$use): ?><?= hidden('purchase_uses['.(int)$ordinal.']',$use) ?><?php endforeach;endif ?>
<?php if($choice['type']==='depreciation'): ?><?= hidden('asset_options',json_encode($options,JSON_THROW_ON_ERROR)) ?><?php endif ?>
<?php if($choice['type']==='transaction'): ?><?= hidden('donation_use',$donationUse) ?><?php endif ?>
<?php if($binding): ?><?= hidden('previous_source_hash',$binding['proposal']['hash']) ?><label><?= e($sourceText('Grund der Berichtigung')) ?><input class="form-control" name="reason" value="<?= e(input($old,'reason')) ?>" maxlength="500" required></label><?php endif ?>
<?= hidden('journal_hash',$r['journal_sha256']) ?>
<label><input class="check" type="checkbox" name="reviewed" value="1" required><?= e($sourceText('Beleg, Datum und Zuordnung sind geprüft.')) ?></label><?= hidden('period_source_form_complete','1') ?><button class="btn btn-solid theme-primary"><?= e($sourceText($binding?'Belegübernahme berichtigen':'In diesen Zeitraum übernehmen')) ?></button></form>
<?php endif ?><?php endif ?></div><?php endif ?></section>
