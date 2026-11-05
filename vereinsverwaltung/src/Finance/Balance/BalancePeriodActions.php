<?php declare(strict_types=1);
// Central dispatcher already verified POST and CSRF. Services repeat role/version checks.
require_once __DIR__.'/BalancePeriodLedger.php';require_once __DIR__.'/BalanceOpening.php';
if(str_starts_with($action,'balance-period-area-'))require __DIR__.'/BalancePeriodAreaActions.php';
if(str_starts_with($action,'balance-period-tax-'))require __DIR__.'/BalanceTaxReconciliationActions.php';
require_once __DIR__.'/BalancePeriodJournalForm.php';require_once __DIR__.'/BalancePeriodSourceForm.php';
$ordinaryPeriodForm=in_array($action,\Kulturhus\Verein\BalancePeriodJournalForm::ACTIONS,true)?\Kulturhus\Verein\BalancePeriodJournalForm::read($_POST):null;
$periodOriginalActions=['balance-period-reaffirm','balance-period-refresh-opening','balance-period-correction-prepare','balance-period-correction-confirm','balance-period-correction-discard'];
$periodOriginal=null;$sourceCatalogueReturn=null;$sourcePeriodForm=in_array($action,\Kulturhus\Verein\BalancePeriodSourceForm::ACTIONS,true)?\Kulturhus\Verein\BalancePeriodSourceForm::read($_POST):null;
if(in_array($action,$periodOriginalActions,true)){
    $sourceField=$action==='balance-period-refresh-opening'?'journal_hash':'closed_hash';
    foreach(['period_id','version',$sourceField]as$key)if(!is_string($_POST[$key]??null))throw new DomainException('Ursprüngliche Angaben zum Abschlusszeitraum vollständig prüfen.',422);
    if(!preg_match('/\A[0-9]{8}-[0-9]{8}\z/D',$_POST['period_id'])||!preg_match('/\A[1-9][0-9]{0,4}\z/D',$_POST['version'])||(int)$_POST['version']>=20000||!preg_match('/\A[a-f0-9]{64}\z/D',$_POST[$sourceField]))throw new DomainException('Ursprüngliche Angaben zum Abschlusszeitraum vollständig prüfen.',422);
}
$periodLedger=new \Kulturhus\Verein\BalancePeriodLedger($db,(string)$_SESSION['actor']);
$periodId=$ordinaryPeriodForm['period_id']??input($_POST,'period_id');$periodVersion=$ordinaryPeriodForm['version']??(int)input($_POST,'version');
switch($action){
case 'balance-period-create':
    $chosenPeriod=$ordinaryPeriodForm['period'];$dayReviewed=input($_POST,'day_allocation_reviewed')==='1';
    if(!\Kulturhus\Verein\BalancePeriod::isFullMonths($chosenPeriod)&&!$dayReviewed)throw new DomainException('Die interne tageweise Aufteilung der ursprünglichen Monatswerte ausdrücklich prüfen und bestätigen.');
    $periodId=$periodLedger->create($chosenPeriod,input($_POST,'evidence'),input($_POST,'reviewed')==='1',$dayReviewed,$ordinaryPeriodForm['expectation']);$periodOriginal=$periodLedger->creationOriginal($periodId);break;
case 'balance-period-opening':
    $amounts=$_POST['opening_amounts']??null;if(!is_array($amounts))throw new DomainException('Anfangsbestände vollständig angeben.');
    $r=$periodLedger->journalForAction($periodId,$periodVersion,$ordinaryPeriodForm['journal_hash']);$lines=\Kulturhus\Verein\BalanceOpening::lines($r['accounts'],$amounts);
    $periodOriginal=$periodLedger->setup($periodId,$periodVersion,$lines,input($_POST,'evidence'),input($_POST,'reviewed')==='1',$ordinaryPeriodForm['journal_hash']);break;
case 'balance-period-account':
    $periodOriginal=$periodLedger->addAccount($periodId,$periodVersion,input($_POST,'account_id'),input($_POST,'account_name'),input($_POST,'account_group'),input($_POST,'skr_code'),$ordinaryPeriodForm['journal_hash']);break;
case 'balance-period-post':
    $inputRows=$_POST['lines']??null;$lines=[];
    if(!is_array($inputRows)||!array_is_list($inputRows)||count($inputRows)>50)throw new DomainException('Buchungszeilen prüfen.');
    foreach($inputRows as $row){
        if(!is_array($row)||array_diff(array_keys($row),['account','debit','credit','sphere'])||count(array_filter($row,'is_string'))!==count($row))throw new DomainException('Buchungszeile prüfen.');
        if(input($row,'account')===''&&input($row,'debit')===''&&input($row,'credit')===''&&input($row,'sphere')==='')continue;
        $lines[]=['account'=>input($row,'account'),'debit_cents'=>\Kulturhus\Verein\Finance::cents(input($row,'debit','0')?:'0'),'credit_cents'=>\Kulturhus\Verein\Finance::cents(input($row,'credit','0')?:'0'),'sphere'=>input($row,'sphere')];
    }
    $periodOriginal=$periodLedger->post($periodId,$periodVersion,input($_POST,'request_key'),input($_POST,'booked_on'),input($_POST,'description'),input($_POST,'evidence'),$lines,null,$ordinaryPeriodForm['journal_hash']);break;
case 'balance-period-reverse':
    $periodOriginal=$periodLedger->post($periodId,$periodVersion,input($_POST,'request_key'),input($_POST,'booked_on'),'Storno',input($_POST,'evidence'),[],input($_POST,'entry'),$ordinaryPeriodForm['journal_hash']);break;
case 'balance-period-close':
    $checks=[];foreach(['complete','valuation','cutoff','tax'] as $key)$checks[$key]=input($_POST,$key)==='1';
    $periodOriginal=$periodLedger->close($periodId,$periodVersion,$ordinaryPeriodForm['journal_hash'],$checks,input($_POST,'evidence'));break;
case 'balance-period-reaffirm':
    $periodOriginal=$periodLedger->reaffirm($periodId,$periodVersion,input($_POST,'closed_hash'),input($_POST,'reviewed')==='1',input($_POST,'evidence'));break;
case 'balance-period-refresh-opening':
    $periodOriginal=$periodLedger->refreshPeriodOpening($periodId,$periodVersion,input($_POST,'journal_hash'),input($_POST,'reviewed')==='1',input($_POST,'evidence'));break;
case 'balance-period-correction-prepare':
    $lines=[];$inputRows=$_POST['lines']??[];
    if(!is_array($inputRows)||!array_is_list($inputRows)||count($inputRows)>50)throw new DomainException('Korrekturzeilen prüfen.');
    foreach($inputRows as $row){
        if(!is_array($row)||array_diff(array_keys($row),['account','debit','credit','sphere'])||count(array_filter($row,'is_string'))!==count($row))throw new DomainException('Korrekturzeile prüfen.');
        if(input($row,'account')===''&&input($row,'debit')===''&&input($row,'credit')===''&&input($row,'sphere')==='')continue;
        $lines[]=['account'=>input($row,'account'),'debit_cents'=>\Kulturhus\Verein\Finance::cents(input($row,'debit','0')?:'0'),'credit_cents'=>\Kulturhus\Verein\Finance::cents(input($row,'credit','0')?:'0'),'sphere'=>input($row,'sphere')];
    }
    $entries=$lines?[['id'=>input($_POST,'request_key'),'date'=>input($_POST,'booked_on'),'description'=>input($_POST,'description'),'evidence'=>input($_POST,'evidence'),'lines'=>$lines]]:[];
    $periodOriginal=$periodLedger->prepareClosingCorrection($periodId,$periodVersion,input($_POST,'closed_hash'),['kind'=>input($_POST,'correction_kind'),'reason'=>input($_POST,'reason'),'legal_basis'=>input($_POST,'legal_basis'),'confirmed'=>input($_POST,'reviewed')==='1','entries'=>$entries]);break;
case 'balance-period-correction-confirm':
    $periodOriginal=$periodLedger->confirmClosingCorrection($periodId,$periodVersion,input($_POST,'closed_hash'),input($_POST,'proof_hash'),input($_POST,'reviewed')==='1',input($_POST,'evidence'));break;
case 'balance-period-correction-discard':
    $periodOriginal=$periodLedger->discardClosingCorrection($periodId,$periodVersion,input($_POST,'closed_hash'),input($_POST,'evidence'));break;
case 'balance-period-source-import':
case 'balance-period-source-correct':
    require_once __DIR__.'/BalancePeriodSourceQuery.php';
    $sourceCatalogueReturn=\Kulturhus\Verein\BalancePeriodSourceQuery::read($_POST,input($_POST,'source_type'));
    $sourceCatalogueReturn['source_page']='1';
    $args=[$sourcePeriodForm['period_id'],$sourcePeriodForm['version'],$sourcePeriodForm['source_type'],$sourcePeriodForm['source_id'],$sourcePeriodForm['options'],$sourcePeriodForm['source_hash'],$sourcePeriodForm['reviewed']];
    if($action==='balance-period-source-correct')$periodOriginal=$periodLedger->correctSource(...[...$args,$sourcePeriodForm['previous_source_hash'],$sourcePeriodForm['reason'],$sourcePeriodForm['journal_hash']]);
    else $periodOriginal=$periodLedger->importSource(...[...$args,$sourcePeriodForm['journal_hash']]);
    break;
default:throw new DomainException('Unbekannter Zeitraumvorgang.');
}
if($sourceCatalogueReturn!==null)redirectTo(['view'=>'finance','tab'=>'tax','balance'=>'1','periods'=>'1','period_id'=>$periodId,'period_source'=>input($_POST,'source_type').':'.input($_POST,'source_id'),'period_original'=>(string)$periodOriginal['revision'],'period_original_sha256'=>$periodOriginal['sha256']]+$sourceCatalogueReturn);
if($periodOriginal!==null)redirectTo(['view'=>'finance','tab'=>'tax','balance'=>'1','periods'=>'1','period_id'=>$periodId,'period_original'=>(string)$periodOriginal['revision'],'period_original_sha256'=>$periodOriginal['sha256']]);
redirectTo(['view'=>'finance','tab'=>'tax','balance'=>'1','periods'=>'1','period_id'=>$periodId]);
