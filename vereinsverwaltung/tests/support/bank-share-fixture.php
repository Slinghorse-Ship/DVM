<?php declare(strict_types=1);
use Kulturhus\Verein\{Finance,BankImport};
/** Actual paid originals; portion proofs never stand in for receipt allocations. */
function ownBankShareCase(PDO $db,string $actor,string $tag,string $kind):array
{
    ownBankGroupDatabase($db);$f=new Finance($db,$actor);$key=static fn()=>bin2hex(random_bytes(16));
    if(str_starts_with($kind,'returned-')){
        $base=ownBankGroupCase($db,$actor,$tag,$kind);$base['transactions']=[$kind==='returned-original'?$base['original']:$base['counter']];$base['amounts']=['60','40'];$base['first_parts']=[$base['transactions'][0]=>'60'];$base['second_parts']=[$base['transactions'][0]=>'40'];$base['total']=(int)$f->transactionDetail($base['transactions'][0])['cents'];$base['first_total']=$base['total']>0?6000:-6000;return $base;
    }
    $bank=$f->createAccount('Own share '.$tag,'offline','1000','2020-01-01');$cash=null;$entries=[];$transactions=[];$income=!in_array($kind,['expense','withdrawal'],true);
    if(in_array($kind,['withdrawal','deposit'],true)){
        $cash=$f->createAccount('Own share closed cash '.$tag,'cash','1000','2020-01-01');$transactions[]=$f->createTransaction(['account'=>$bank,'booked_on'=>'2025-12-30','direction'=>$income?'income':'expense','amount'=>'150','description'=>'Own actual bank150 '.$tag],$key());$cashPart=$f->createTransaction(['account'=>$cash,'booked_on'=>'2026-01-02','direction'=>$income?'expense':'income','amount'=>'150','description'=>'Own actual cash150 '.$tag],$key());$f->linkInternalMovement($transactions[0],1,$cashPart,1,'150','Own complete retained cash transfer',$key());$f->closeCashbook($cash,'2026-01-05',Finance::decimal(array_column($f->accounts(),'balance_cents','id')[$cash]));
    }else{
        $type=match($kind){'expense'=>'expense','contribution'=>'contribution','donation'=>'donation',default=>'income'};$entry=$f->createEntry(['kind'=>$type,'category'=>$type==='contribution'?'membership':($type==='donation'?'donations':'general'),'amount'=>'150','description'=>'Own actual paid150 '.$tag,'counterparty'=>'Own share payer '.$tag,'document_date'=>'2025-12-01','due_date'=>'2025-12-30'],$key());$entries[]=$entry;$f->recordPayment($entry,1,$bank,'2025-12-30','150');$transactions[]=$f->allocations($entry)[0]['transaction_id'];
    }
    $first_parts=[$transactions[0]=>'100'];$second_parts=[$transactions[0]=>'50'];$total=$income?15000:-15000;$first_total=$income?10000:-10000;
    if($kind==='fee'){$entry=$f->createEntry(['kind'=>'expense','category'=>'general','amount'=>'1','description'=>'Own already recorded actual fee1 '.$tag,'document_date'=>'2025-12-01','due_date'=>'2025-12-30'],$key());$entries[]=$entry;$f->recordPayment($entry,1,$bank,'2025-12-30','1');$fee=$f->allocations($entry)[0]['transaction_id'];$transactions[]=$fee;$first_parts[$fee]='1';$first_total-=100;$total-=100;}
    $remote=['name'=>'Own physical partial bank '.$tag,'identification_hash'=>'own-bank-share-'.$tag];$mapping=(new BankImport($db,$actor))->map('fints','PRODUCTION',$remote,$bank);return compact('tag','kind','bank','cash','entries','transactions','first_parts','second_parts','total','first_total','remote','mapping');
}
