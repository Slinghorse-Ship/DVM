<?php declare(strict_types=1);
use Kulturhus\Verein\Finance;
require_once __DIR__.'/bank-group-fixture.php';require_once __DIR__.'/bank-fee-fixture.php';
function ownPaymentGroupReversalCase(PDO $db,string $actor,string $tag,string $kind='income',array $amounts=['100','50'],string $account=''):array
{
    ownBankGroupDatabase($db);
    $f=new Finance($db,$actor);$key=static fn()=>bin2hex(random_bytes(16));$account=$account!==''?$account:$f->createAccount('Own shared fixture '.$tag,'offline','1000','2020-01-01');$entries=[];$documents=[];$total=0;
    foreach($amounts as$i=>$amount){
        if($kind==='expense'){$original=ownBankFeeOriginal($db,$actor,'shared-original-'.$tag.'-'.$i,$amount);$id=$original['entry'];$documents[$id]=$original;}
        else $id=$f->createEntry(['kind'=>$kind,'category'=>$kind==='contribution'?'membership':($kind==='donation'?'donations':'general'),'description'=>'Own shared original '.$tag.' '.$i,'amount'=>$amount,'counterparty'=>'Own shared original customer','document_date'=>'2026-01-01','due_date'=>'2026-01-01'],$key());
        $entries[]=$id;$total+=Finance::cents($amount);
    }
    $source=$f->createTransaction(['account'=>$account,'direction'=>$kind==='expense'?'expense':'income','booked_on'=>'2026-01-23','amount'=>Finance::decimal($total),'description'=>'Own one shared original '.$tag],$key());
    foreach($entries as$i=>$id)$f->allocate($source,(int)$f->transactionDetail($source)['version'],$id,(int)$f->entry($id)['version'],$amounts[$i],$key());
    $returned=$f->createTransaction(['account'=>$account,'direction'=>$kind==='expense'?'income':'expense','booked_on'=>'2026-01-24','amount'=>Finance::decimal($total),'description'=>'Own already recorded full shared return '.$tag],$key());
    return compact('tag','kind','account','entries','documents','source','returned','total');
}
