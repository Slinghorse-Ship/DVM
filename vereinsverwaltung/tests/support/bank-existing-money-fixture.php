<?php declare(strict_types=1);
use Kulturhus\Verein\{Finance,BankImport};

/** Only fresh owned native MySQL fixtures; all account responses are injected locally. */
function ownExistingBankMoney(PDO $db,string $actor,string $tag,string $kind):array
{
    $cfg=\Kulturhus\Native\TestSupport::config();
    if(($cfg['owner']??'')!=='isolated-native-ci'||!in_array($cfg['label']??'',['native-bank-existing-money','native-recovery'],true)||!preg_match('/\Akh_native_[a-f0-9]{24}\z/D',(string)$db->query('SELECT DATABASE()')->fetchColumn()))throw new RuntimeException('Owned existing-money fixture required.');
    $key=static fn()=>bin2hex(random_bytes(16));$f=new Finance($db,$actor);$import=new BankImport($db,$actor);$bank=$f->createAccount('Own '.$tag,'offline','1000','2020-01-01');$entry=null;$cash=null;$counter=null;
    $expense=in_array($kind,['expense','partial-expense','raw-expense','withdrawal','refund-expense'],true);$direction=$expense?'expense':'income';
    if(in_array($kind,['income','expense','partial-income','partial-expense','refund-income','refund-expense'],true)){
        $entry=$f->createEntry(['kind'=>$expense?'expense':'income','category'=>'general','amount'=>'100','document_date'=>'2025-12-01','due_date'=>'2025-12-30','description'=>'Own original receipt '.$tag,'counterparty'=>'Own original party '.$tag],$key());$f->recordPayment($entry,(int)$f->entry($entry)['version'],$bank,'2025-12-30',str_starts_with($kind,'partial-')?'40':null);$source=$f->allocations($entry)[0]['transaction_id'];
        if(str_starts_with($kind,'refund-')){$review=$f->reversalSource($entry);$f->reverse($entry,(int)$f->entry($entry)['version'],'2026-01-02','Own actual money fully returned '.$tag,'',0,$review['counter_review']);$q=$db->prepare('SELECT id FROM finance_transactions WHERE reversal_of=?');$q->execute([$source]);$counter=$q->fetchColumn();}
    }else{
        $source=$f->createTransaction(['account'=>$bank,'booked_on'=>'2025-12-30','direction'=>$direction,'amount'=>'100','description'=>'Own actual bank movement '.$tag,'counterparty'=>'Own original party '.$tag],$key());
        if(in_array($kind,['withdrawal','deposit'],true)){$cash=$f->createAccount('Own original cash '.$tag,'cash','1000','2020-01-01');$counter=$f->createTransaction(['account'=>$cash,'booked_on'=>'2026-01-02','direction'=>$expense?'income':'expense','amount'=>'100','description'=>'Own actual cash movement '.$tag],$key());$f->linkInternalMovement($source,(int)$f->transactionDetail($source)['version'],$counter,(int)$f->transactionDetail($counter)['version'],'100','Own assigned original cash transfer '.$tag,$key());}
    }
    $remote=['name'=>'Own actual original bank '.$tag,'identification_hash'=>'owned-existing-money-'.$tag];$mapping=$import->map('fints','PRODUCTION',$remote,$bank);
    return compact('tag','kind','bank','entry','cash','source','counter','remote','mapping');
}

function ownExistingBankObserve(PDO $db,string $actor,array $case,string $transaction,string $suffix='first',string $day='2026-01-20',bool $reference=true):array
{
    $t=(new Finance($db,$actor))->transactionDetail($transaction);$import=new BankImport($db,$actor);$description='Own actual bank original '.$case['tag'].' '.$suffix;$raw=['entry_reference'=>$reference?'OWN-EXISTING-'.$case['tag'].'-'.$suffix:'','transaction_amount'=>['currency'=>'EUR','amount'=>Finance::decimal(abs((int)$t['cents']))],'credit_debit_indicator'=>(int)$t['cents']>0?'CRDT':'DBIT','status'=>'BOOK','booking_date'=>$day,'debtor'=>['name'=>$t['counterparty']],'creditor'=>['name'=>$t['counterparty']],'remittance_information'=>[$description]];
    $import->fetch($case['mapping'],'PRODUCTION',$case['remote'],'2025-12-01','2026-03-01',bin2hex(random_bytes(16)),static fn()=>['transactions'=>[$raw]]);$q=$db->prepare('SELECT id FROM bank_import_records WHERE mapping_id=? AND description=?');$q->execute([$case['mapping'],$description]);return ['record'=>(string)$q->fetchColumn(),'raw'=>$raw,'transaction'=>$transaction];
}
