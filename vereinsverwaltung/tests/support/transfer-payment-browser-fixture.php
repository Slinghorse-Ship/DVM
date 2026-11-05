<?php declare(strict_types=1);
use Kulturhus\Native\Shared\Db;
use Kulturhus\Verein\{Documents,ExpenseReimbursements,Finance,InvoiceApprovals};

function ownTransferPaymentBrowser(PDO $db,callable $check,array $f,array $fixture):void
{
    $finance=new Finance($db,$f['actor']);$cases=[];$sources=[];$key=static fn():string=>bin2hex(random_bytes(16));
    for($i=0;$i<8;$i++){
        $source=($f['entry'])('Own simple payment browser '.$i);$sources[]=$source;$member=in_array($i%4,[1,2],true);
        if($i===0){
            Db::run($db,'UPDATE finance_entries SET notes=?,version=version+1 WHERE club_id=? AND id=?',["Zahlbar bis nicht auf Rechnung angegeben.\\nLieferanten-Rechnungsnummer: OWN-SUPPLIER-2026-0815\nOwn actual invoice note",$f['club'],$source['entry']]);
            $approval=new InvoiceApprovals($db,$f['actor']);$state=$approval->state($source['entry']);$approval->approve($source['entry'],$state['hash'],$state['decision_id'],$key(),true);
        }
        if($member){$claims=new ExpenseReimbursements($db,$f['actor']);$claim=$claims->createForEntry($source['entry'],'own-transfer-reimbursement-person','member',$key());$claims->approve($claim,1,$key());}
        $recipient=$member?(string)Db::run($db,'SELECT name FROM contacts WHERE club_id=? AND id=?',[$f['club'],'own-transfer-reimbursement-person'])->fetchColumn():'Own actual supplier';
        $cases[]=['index'=>$i,'entry'=>$source['entry'],'mode'=>$i%2?'instant':'standard','kind'=>$member?'member-reimbursement':'supplier','recipient'=>$recipient,'js'=>$i%4<2,'dispatcher'=>$i<4?'front':'direct'];
    }
    $cash=$finance->createAccount('Own already-paid hand cash','cash','1000.00','2020-01-01');$already=[];
    foreach(['cash'=>$cash,'bank'=>$f['account']]as$kind=>$account){
        $source=($f['entry'])('Own already-paid '.$kind.' without supplier bank details');$supplier='own-paid-'.$kind;
        Db::run($db,"INSERT INTO contacts(id,club_id,kind,name)VALUES(?,?,'organisation',?)",[$supplier,$f['club'],'Own paid supplier without IBAN']);
        Db::run($db,'UPDATE finance_supplier_contacts SET contact_id=?,contact_version=1,supplier_name=? WHERE club_id=? AND entry_id=?',[$supplier,'Own paid supplier without IBAN',$f['club'],$source['entry']]);
        $sources[]=$source;$already[]=['entry'=>$source['entry'],'account'=>$account,'kind'=>$kind];
    }
    $beforeMoney=$finance->transactions();$beforeOrders=(int)Db::run($db,'SELECT COUNT(*) FROM finance_transfer_orders WHERE club_id=?',[$f['club']])->fetchColumn();$fixture['data']['payment_cases']=$cases;$fixture['data']['already_paid']=$already;
    $node=proc_open(['node',dirname(__DIR__).'/transfer-payment-browser.mjs'],[0=>['pipe','r'],1=>STDOUT,2=>STDERR],$pipes);if(!is_resource($node))throw new RuntimeException('Owned payment browser unavailable.');fwrite($pipes[0],json_encode($fixture,JSON_THROW_ON_ERROR));fclose($pipes[0]);$check(proc_close($node)===0,'simple payment and already-paid browser completes on actual manager controllers');
    foreach($cases as$sample){$current=$f['native']->paymentOrder($sample['entry']);$payload=$f['service']->fintsPayload($current['order']['id'],2);$check($current['order']['state']==='approved'&&$current['source']['recipient_kind']===$sample['kind']&&$payload['transfer_mode']===$sample['mode']&&$current['source']['total_cents']===12550,'browser payment retains its actual mode, recipient and unpaid amount '.$sample['index']);}
    $check((int)Db::run($db,'SELECT COUNT(*) FROM finance_transfer_orders WHERE club_id=?',[$f['club']])->fetchColumn()===$beforeOrders+count($cases),'browser repeated and opposite-mode clicks create exactly one order per invoice');
    foreach($already as$sample){$entry=$finance->entry($sample['entry']);$allocations=$finance->allocations($sample['entry']);$check($entry['payment_state']==='paid'&&$entry['allocated_cents']===12550&&count($allocations)===1&&$f['native']->paymentOrder($sample['entry'])===null,'already-paid '.$sample['kind'].' actually records and allocates the payment without a transfer order or IBAN');}
    $check(count($finance->transactions())===count($beforeMoney)+count($already),'only the two explicitly recorded past payments change ledger balances');
    $documents=new Documents($db,$f['actor']);$unchanged=true;foreach($sources as$source)if($documents->file($source['document'])['bytes']!==$source['bytes'])$unchanged=false;$check($unchanged&&hash_file('sha256',$f['path'])===$f['keyHash'],'all actual invoice originals and the bank encryption key remain unchanged');
}
