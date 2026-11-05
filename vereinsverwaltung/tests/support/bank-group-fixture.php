<?php declare(strict_types=1);
use Kulturhus\Verein\{Finance,BankImport};

function ownBankGroupDatabase(PDO $db):void
{
    $cfg=\Kulturhus\Native\TestSupport::config();$name=(string)$db->query('SELECT DATABASE()')->fetchColumn();
    if(($cfg['owner']??'')!=='isolated-native-ci'||!in_array($cfg['label']??'',['native-bank-payment-groups','native-bank-payment-shares','native-bank-fee-evidence','native-payment-group-reversals','native-payment-returns','native-return-order-links','native-transfer-settlements','native-recovery'],true)||$name!==($cfg['database']??null)||!preg_match('/\Akh_native_[a-f0-9]{24}\z/D',$name))throw new RuntimeException('Exact owned bank-group fixture required.');
}
function ownBankGroupCase(PDO $db,string $actor,string $tag,string $kind):array
{
    ownBankGroupDatabase($db);$f=new Finance($db,$actor);$key=static fn()=>bin2hex(random_bytes(16));$bank=$f->createAccount('Own '.$tag,'offline','1000','2020-01-01');$transactions=[];$entries=[];$cash=null;$original=null;$counter=null;
    $entry=static function(string $type,string $amount,string $label)use($f,$key,$tag,&$entries):string{$id=$f->createEntry(['kind'=>$type,'category'=>$type==='contribution'?'membership':($type==='donation'?'donations':'general'),'amount'=>$amount,'description'=>'Own original '.$tag.' '.$label,'counterparty'=>'Own financial party '.$tag,'document_date'=>'2025-12-01','due_date'=>'2025-12-30'],$key());$entries[]=$id;return $id;};
    $pay=static function(string $id,string $amount)use($f,$bank):string{$before=array_column($f->allocations($id),'transaction_id');$f->recordPayment($id,(int)$f->entry($id)['version'],$bank,'2025-12-30',$amount);foreach($f->allocations($id)as$l)if(!in_array($l['transaction_id'],$before,true))return $l['transaction_id'];throw new RuntimeException('New actual payment required.');};
    if(in_array($kind,['withdrawal','deposit'],true)){
        $cash=$f->createAccount('Own closed cash '.$tag,'cash','1000','2020-01-01');for($i=0;$i<2;$i++){$out=$kind==='withdrawal';$source=$f->createTransaction(['account'=>$bank,'booked_on'=>'2025-12-30','direction'=>$out?'expense':'income','amount'=>'50','description'=>'Own actual bank part '.$tag.' '.$i],$key());$cashPart=$f->createTransaction(['account'=>$cash,'booked_on'=>'2026-01-02','direction'=>$out?'income':'expense','amount'=>'50','description'=>'Own actual cash part '.$tag.' '.$i],$key());$f->linkInternalMovement($source,(int)$f->transactionDetail($source)['version'],$cashPart,(int)$f->transactionDetail($cashPart)['version'],'50','Own unchanged cash-bank transfer',$key());$transactions[]=$source;}$f->closeCashbook($cash,'2026-01-05',Finance::decimal(array_column($f->accounts(),'balance_cents','id')[$cash]));
    }elseif(str_starts_with($kind,'returned-')){
        $expense=$kind==='returned-expense-counter';$id=$entry($expense?'expense':'income','100','actual returned100');$original=$pay($id,'100');$review=$f->reversalSource($id);$f->reverse($id,(int)$f->entry($id)['version'],'2026-01-02','Own actual complete return '.$tag,'',0,$review['counter_review']);$q=$db->prepare('SELECT id FROM finance_transactions WHERE reversal_of=?');$q->execute([$original]);$counter=$q->fetchColumn();
        if($kind==='returned-original'){$transactions=[$original,$pay($entry('income','50','separate real50'),'50')];}else{$transactions=[$counter,$pay($entry('expense','1','actual separate fee1'),'1')];}
    }elseif(str_starts_with($kind,'split-')){
        $id=$entry($kind==='split-expense'?'expense':'income','100','whole demand100');$transactions=[$pay($id,'50'),$pay($id,'50')];
    }elseif(str_starts_with($kind,'net-')){
        $id=$entry($kind==='net-expense'?'expense':'income','100','actual gross100');$transactions=[$pay($id,'100'),$pay($entry('expense','1','actual bank fee1'),'1')];
    }else{
        $type=match($kind){'many-expense'=>'expense','contributions'=>'contribution','donations'=>'donation',default=>'income'};for($i=0;$i<2;$i++)$transactions[]=$pay($entry($type,'50','independent receipt50 '.$i),'50');
    }
    $total=0;foreach($transactions as$id)$total+=(int)$f->transactionDetail($id)['cents'];$import=new BankImport($db,$actor);$remote=['name'=>'Own actual bank '.$tag,'identification_hash'=>'own-bank-group-'.$tag];$mapping=$import->map('fints','PRODUCTION',$remote,$bank);return compact('tag','kind','bank','cash','entries','transactions','original','counter','total','remote','mapping');
}
function ownBankGroupObserve(PDO $db,string $actor,array $case,string $suffix='first',string $day='2026-01-20',?int $amount=null):array
{
    ownBankGroupDatabase($db);$amount??=$case['total'];$reference='OWN-NET-'.$case['tag'].'-'.$suffix;$raw=['entry_reference'=>$reference,'transaction_amount'=>['currency'=>'EUR','amount'=>Finance::decimal(abs($amount))],'credit_debit_indicator'=>$amount>0?'CRDT':'DBIT','status'=>'BOOK','booking_date'=>$day,'debtor'=>['name'=>'Own financial party '.$case['tag']],'creditor'=>['name'=>'Own financial party '.$case['tag']],'remittance_information'=>['Own actual combined bank original '.$case['tag'].' '.$suffix]];$import=new BankImport($db,$actor);$import->fetch($case['mapping'],'PRODUCTION',$case['remote'],'2025-12-01','2026-03-01',bin2hex(random_bytes(16)),static fn()=>['transactions'=>[$raw]]);$q=$db->prepare('SELECT id FROM bank_import_records WHERE mapping_id=? AND reference=?');$q->execute([$case['mapping'],$reference]);return ['record'=>(string)$q->fetchColumn(),'raw'=>$raw];
}
