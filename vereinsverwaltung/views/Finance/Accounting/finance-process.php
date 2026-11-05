<?php declare(strict_types=1);
/** Links follow the actual receipt and its permissions; opening them records no payment. */
use Kulturhus\Native\Language\ManagerLabels;
$financeProcessTab=(string)($tab??'');$financeProcessRequested=input($_GET,'entry');
$financeProcessEntry=$financeProcessRequested!==''&&isset($financeEntry['id'])&&$financeEntry['id']===$financeProcessRequested;
if(array_intersect(['new_entry','edit_positions','outgoing_einvoice'],array_keys($_GET)))return;
if($financeProcessRequested!==''&&!$financeProcessEntry)return;
if(!$financeProcessEntry&&$financeProcessTab!=='inbox')return;
$financeProcessText=static fn(string $key):string=>ManagerLabels::financeText('process_'.$key,$managerLanguageMessages??[]);
if($financeProcessEntry){
    $financeProcessClosed=in_array($financeEntry['payment_state'],['paid','reversed','cancelled'],true);$financeProcessWriter=$finance->canWrite();
    $financeProcessBase=receiptPaymentContext($financeEntry['id'],$_GET,false);unset($financeProcessBase['payment_match']);
    $financeProcessHistory=array_replace($financeProcessBase,['payment_history'=>'1']);
    $financeProcessSteps=[['document','document_hint',$financeProcessBase,'receipt-overview']];
    if($financeEntry['kind']==='expense'){
        $financeProcessApproval='';if(!$financeProcessClosed)try{$financeProcessApproval=(string)(new \Kulturhus\Verein\InvoiceApprovals($db,(string)$_SESSION['actor']))->state($financeEntry['id'])['status'];}catch(\DomainException){/* An unavailable approval never indicates payment readiness. */}
        $financeProcessCurrent=$financeProcessClosed||$financeProcessApproval==='approved'?3:2;
        $financeProcessSteps[]=['review','review_hint',$financeProcessBase,'invoice-approval'];
        $financeProcessSteps[]=$financeProcessClosed?['history','history_hint',$financeProcessHistory,'invoice-payment']:($financeProcessWriter?['payment','payment_hint',$financeProcessBase,'invoice-transfer']:['payment','status_hint',$financeProcessHistory,'invoice-payment']);
    }else{
        $financeProcessCurrent=2;
        $financeProcessSteps[]=$financeProcessClosed?['history','history_hint',$financeProcessHistory,'invoice-payment']:($financeProcessWriter?['payment','match_hint',array_replace($financeProcessBase,['payment_match'=>'1']),'invoice-payment']:['payment','status_hint',$financeProcessHistory,'invoice-payment']);
    }
}else{
    $financeProcessCurrent=1;
    $financeProcessSteps=[['intake','intake_hint',['view'=>'finance','tab'=>'inbox'],''],['review','review_hint',['view'=>'finance','tab'=>'receipts'],''],['payment','payment_hint',['view'=>'finance','tab'=>'bank'],'']];
}
?>
<link rel="stylesheet" href="finance-process.css?v=1">
<section class="card finance-process<?= $financeProcessEntry?' is-entry':'' ?>" aria-labelledby="finance-process-title">
<h2 id="finance-process-title" class="sr-only"><?= e($financeProcessText('title')) ?></h2>
<ol class="finance-process-steps">
<?php foreach($financeProcessSteps as $index=>$step):$number=$index+1;$active=$financeProcessCurrent===$number;$financeProcessLabel=$financeProcessText($step[0]);$financeProcessHint=$financeProcessText($step[1]); ?>
<li class="<?= $active?'is-current':'' ?>"><a href="<?= e(url($step[2]).($step[3]!==''?'#'.$step[3]:'')) ?>" aria-label="<?= e($financeProcessLabel.' – '.$financeProcessHint) ?>" <?= $active?'aria-current="step"':'' ?>><span class="finance-process-number" aria-hidden="true"><?= $number ?></span><span><strong><?= e($financeProcessLabel) ?></strong><small class="sr-only"><?= e($financeProcessHint) ?></small></span></a></li>
<?php endforeach ?>
</ol></section>
