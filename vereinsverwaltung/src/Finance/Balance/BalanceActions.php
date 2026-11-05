<?php declare(strict_types=1);
// This file is reached only after the central POST/CSRF check.
if(str_starts_with($action,'balance-period-'))require __DIR__.'/BalancePeriodActions.php';
$ledger=new \Kulturhus\Verein\BalanceLedger($db,(string)$_SESSION['actor']);
require_once __DIR__.'/BalanceSourceDecisionForm.php';
if(in_array($action,\Kulturhus\Verein\BalanceSourceDecisionForm::ACTIONS,true)){$request=\Kulturhus\Verein\BalanceSourceDecisionForm::read($_POST);$original=$ledger->applySourceDecision($request);redirectTo(['view'=>'finance','tab'=>'tax','balance'=>'1','balance_year'=>(string)$request['year'],'balance_source'=>$original['type'].':'.$original['id'],'source_original'=>(string)$original['revision'],'source_original_sha256'=>$original['sha256']]);}
require_once __DIR__.'/BalanceYearClosingForm.php';
require_once __DIR__.'/BalanceYearClosingRead.php';
if($action==='balance-close'){
    try{
        $closing=\Kulturhus\Verein\BalanceYearClosingForm::read($_POST);$ledger->close($closing['year'],$closing['version'],$closing['checks'],$closing['evidence']);
        $saved=(new \Kulturhus\Verein\BalanceYearClosingRead($db,(string)$_SESSION['actor']))->fromSubmitted($_POST);if($saved===null)throw new DomainException('Gespeicherten Jahresabschluss prüfen lassen.',503);
        redirectTo(['view'=>'finance','tab'=>'tax','balance'=>'1','balance_year'=>(string)$saved['year'],'year_closing_sha256'=>$saved['sha256']]);
    }catch(DomainException $failure){http_response_code(\Kulturhus\Verein\BalanceYearClosingForm::status($failure));throw$failure;}
}
require_once __DIR__.'/BalancePostingForm.php';
require_once __DIR__.'/BalancePostingOriginalRead.php';
if(in_array($action,\Kulturhus\Verein\BalancePostingForm::ACTIONS,true)){
    try{
        $posting=\Kulturhus\Verein\BalancePostingForm::metadata($_POST);if(!\Kulturhus\Verein\BalancePostingForm::complete($_POST))throw new DomainException('Ursprüngliche Buchungsangaben vollständig prüfen.',422);
        if($action==='balance-post'){
            $lines=[];foreach($_POST['lines']as$row){if($row['account']===''&&$row['debit']===''&&$row['credit']===''&&$row['sphere']==='')continue;$lines[]=['account'=>$row['account'],'debit_cents'=>\Kulturhus\Verein\Finance::cents($row['debit']?:'0'),'credit_cents'=>\Kulturhus\Verein\Finance::cents($row['credit']?:'0'),'sphere'=>$row['sphere']];}
            $ledger->post($posting['year'],$posting['version'],$posting['request_key'],$_POST['booked_on'],$_POST['description'],$_POST['evidence'],$lines);
        }else $ledger->reverse($posting['year'],$posting['version'],$_POST['entry'],$_POST['booked_on'],$_POST['evidence'],$posting['request_key']);
        $saved=(new \Kulturhus\Verein\BalancePostingOriginalRead($db,(string)$_SESSION['actor']))->fromSubmitted($_POST);if($saved===null)throw new DomainException('Gespeicherte Buchung prüfen lassen.',503);
        redirectTo(['view'=>'finance','tab'=>'tax','balance'=>'1','balance_year'=>(string)$saved['year'],'annual_posting'=>$saved['posting']['id'],'annual_posting_sha256'=>$saved['sha256']]);
    }catch(DomainException $failure){http_response_code(\Kulturhus\Verein\BalancePostingForm::status($failure));throw $failure;}
}
require_once __DIR__.'/BalanceSetupForm.php';
if(in_array($action,\Kulturhus\Verein\BalanceSetupForm::ACTIONS,true)){
    try{
        $setup=\Kulturhus\Verein\BalanceSetupForm::read($_POST);$year=$setup['year'];$version=$setup['version'];
        if($action==='balance-opening'){$setupOriginal=$ledger->report($year);if($setupOriginal['state']!=='setup')throw new DomainException('Dieser Jahresstand kann hier nicht geändert werden.',409);if($setupOriginal['version']!==$version)throw new DomainException('Jahresstand inzwischen geändert. Bitte neu laden.',409);}
        switch($action){
case 'balance-opening-reconcile':$ledger->reconcileOpening($year,$version,input($_POST,'prior_hash'),input($_POST,'reviewed')==='1',input($_POST,'evidence'));break;
case 'balance-create':$ledger->create($year);break;
case 'balance-account':$ledger->addAccount($year,$version,input($_POST,'account_id'),input($_POST,'account_name'),input($_POST,'account_group'),input($_POST,'skr_code'));break;
case 'balance-opening':
    if(array_key_exists('opening_amounts',$_POST)){
        require_once __DIR__.'/BalanceOpening.php';
        if(!is_array($_POST['opening_amounts'])||array_key_exists('opening',$_POST))throw new DomainException('Anfangsbestände prüfen.');
        $report=$ledger->report($year);$lines=\Kulturhus\Verein\BalanceOpening::lines($report['accounts'],$_POST['opening_amounts']);
        $ledger->setup($year,$version,$report['accounts'],$lines,$report['result_account'],input($_POST,'evidence'),input($_POST,'reviewed')==='1');break;
    }
    $report=$ledger->report($year);$amounts=$_POST['opening']??null;$lines=[];
    if(!is_array($amounts)||array_diff(array_keys($amounts),array_keys($report['accounts']??[])))throw new DomainException('Anfangsbestände prüfen.');
    foreach($report['accounts'] as $id=>$account){
        if(in_array($account['group'],['revenue','expense'],true))continue;
        $row=$amounts[$id]??null;if(!is_array($row)||array_diff(array_keys($row),['debit','credit'])||!is_string($row['debit']??null)||!is_string($row['credit']??null))throw new DomainException('Alle Anfangsbestände ausdrücklich angeben.');
        $d=\Kulturhus\Verein\Finance::cents(input($row,'debit'));$c=\Kulturhus\Verein\Finance::cents(input($row,'credit'));
        if($d!==0||$c!==0)$lines[]=['account'=>$id,'debit_cents'=>$d,'credit_cents'=>$c,'sphere'=>''];
    }
    $ledger->setup($year,$version,$report['accounts'],$lines,$report['result_account'],input($_POST,'evidence'),input($_POST,'reviewed')==='1');break;
        }
        redirectTo(['view'=>'finance','tab'=>'tax','balance'=>'1','balance_year'=>(string)$year]);
    }catch(DomainException $failure){http_response_code(\Kulturhus\Verein\BalanceSetupForm::status($failure,$_POST));throw$failure;}
}
require_once __DIR__.'/BalanceMappingForm.php';
if($action===\Kulturhus\Verein\BalanceMappingForm::ACTION){
    try{$mapping=\Kulturhus\Verein\BalanceMappingForm::read($_POST);(new \Kulturhus\Verein\BalanceMappingReview($db,(string)$_SESSION['actor']))->save($mapping['year'],$mapping['version'],$mapping['review_version'],$mapping['source_sha256'],$mapping['mapping'],$mapping['reviewed']);redirectTo(['view'=>'finance','tab'=>'tax','balance'=>'1','balance_year'=>(string)$mapping['year'],'annual_mapping_review'=>'current']);}
    catch(DomainException $failure){http_response_code(\Kulturhus\Verein\BalanceMappingForm::status($failure));throw$failure;}
}
$year=(int)input($_POST,'balance_year');$version=(int)input($_POST,'version');
if(str_starts_with($action,'balance-segment-'))require __DIR__.'/BalanceSegmentActions.php';
if(str_starts_with($action,'balance-clearing-'))require __DIR__.'/BalanceClearingActions.php';
if(str_starts_with($action,'balance-tax-reconciliation-'))require __DIR__.'/BalanceTaxReconciliationActions.php';
if(str_starts_with($action,'balance-correction-'))require __DIR__.'/BalanceClosingCorrectionActions.php';
switch($action){
case 'balance-source-preview':
    $type=input($_POST,'source_type');$id=input($_POST,'source_id');$options=\Kulturhus\Verein\BalanceSources::formOptions($type,$_POST);
    (new \Kulturhus\Verein\BalanceSources($db,(string)$_SESSION['actor']))->preview($year,$type,$id,$options);
    $query=$options;unset($query['invoice_classification']);
    if($type==='invoice')$query+=\Kulturhus\Verein\BalanceInvoiceClassification::fields($options['invoice_classification']??null);
    redirectTo(['view'=>'finance','tab'=>'tax','balance'=>'1','balance_year'=>(string)$year,'balance_source'=>$type.':'.$id]+$query);
case 'balance-source-import':
    $type=input($_POST,'source_type');$options=\Kulturhus\Verein\BalanceSources::formOptions($type,$_POST);
    $ledger->importSource($year,$version,$type,input($_POST,'source_id'),$options,input($_POST,'source_hash'),input($_POST,'reviewed')==='1');break;
case 'balance-source-correct':
    $type=input($_POST,'source_type');$options=\Kulturhus\Verein\BalanceSources::formOptions($type,$_POST);
    $ledger->correctSource($year,$version,$type,input($_POST,'source_id'),$options,input($_POST,'correction_hash'),input($_POST,'correction_reason'),input($_POST,'reviewed')==='1');break;
default:throw new DomainException('Unbekannter Bilanzvorgang.');
}
redirectTo(['view'=>'finance','tab'=>'tax','balance'=>'1','balance_year'=>(string)$year]);
