<?php declare(strict_types=1);

/** Genuine AIS observation and conscious existing-money match, with no external transport. */
function ownReturnOrderBankProof(PDO $db,string $actor,string $account,array $transactions,string $tag):array
{
    ownBankGroupDatabase($db);
    $finance=new \Kulturhus\Verein\Finance($db,$actor);$import=new \Kulturhus\Verein\BankImport($db,$actor);$remote=['name'=>'Own return-order bank','identification_hash'=>'own-return-order-'.$account];$mapping=$import->map('fints','PRODUCTION',$remote,$account);$proofs=[];
    foreach($transactions as$transaction){
        $t=$finance->transactionDetail($transaction);$ref='OWN-RETURN-ORDER-'.$tag.'-'.$transaction;
        $raw=['entry_reference'=>$ref,'transaction_amount'=>['currency'=>'EUR','amount'=>\Kulturhus\Verein\Finance::decimal(abs((int)$t['cents']))],'credit_debit_indicator'=>$t['cents']>0?'CRDT':'DBIT','status'=>'BOOK','booking_date'=>$t['booked_on'],'remittance_information'=>['Own actual original '.$tag]];
        $import->fetch($mapping,'PRODUCTION',$remote,$t['booked_on'],$t['booked_on'],bin2hex(random_bytes(16)),static fn()=>['transactions'=>[$raw]]);
        $q=$db->prepare('SELECT id,version FROM bank_import_records WHERE mapping_id=? AND reference=?');$q->execute([$mapping,$ref]);$r=$q->fetch();if(!$r)throw new RuntimeException('Actual ingested original required.');
        $args=[$r['id'],(int)$r['version'],'existing',$transaction,'Own actual existing money and bank original reviewed'];$import->decide(...$args);$proofs[$transaction]=['record'=>$r['id'],'args'=>$args];
    }
    return $proofs;
}

function ownConfirmReturnedBankOrder(PDO $db,string $actor,string $account,string $source,string $counter,string $return,string $order,string $tag):array
{
    $proofs=ownReturnOrderBankProof($db,$actor,$account,[$source,$counter],$tag);$finance=new \Kulturhus\Verein\Finance($db,$actor);$reason="\nOwn actual returned bank order0 ä🙂\r\n";$evidence="\nOwn bank original and returned item explicitly identify this submitted order0 ä🙂\r\n";$review=$finance->paymentReturnOrderReview($source,$return,$order,$reason,$evidence);$args=[$source,$return,$order,$reason,$evidence,bin2hex(random_bytes(16)),$review['hash'],true];$link=$finance->linkReturnedBankOrder(...$args);$original=$finance->paymentReturnOrderOriginal($source,$link['id']);return compact('proofs','args','link','original');
}
