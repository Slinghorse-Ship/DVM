<?php declare(strict_types=1);
use Kulturhus\Native\Shared\Db;
use Kulturhus\Verein\{Finance,InvoiceApprovals,MemberBankVault,TransferOrders,ExpenseReimbursements};

function ownCreditRefundContracts(PDO $db,callable $check,array $f,array $firsts):array
{
    $tables=$db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);$key=static fn()=>bin2hex(random_bytes(16));
    $deny=static function(callable $work,string $label,?int $code=null)use($db,$tables,$check):void{
        $before=ownOriginalDatabaseHash($db,$tables);$caught=false;
        try{$work();}catch(DomainException $error){$caught=true;if($code!==null)$check($error->getCode()===$code,$label.' uses the expected status');}
        $check($caught&&ownOriginalDatabaseHash($db,$tables)===$before,$label.' preserves every complete earlier row');
    };
    $case=($f['make'])('person');$input=($f['inputFor'])($case['creditEntry'],'instant');$review=$f['native']->prepare($input);
    $deny(fn()=>$f['native']->accept($input,$key(),$review['hash'],false),'missing explicit credit consent',422);
    $free=$input;$free['data']['recipient_iban']='DE89370400440532013000';$deny(fn()=>\Kulturhus\Verein\Finance\Banking\NativeTransfer\TransferInput::capture($free),'injected free recipient account is rejected by the whole actual request contract',422);
    foreach([$f['reader'],$f['foreign']]as$actor)$deny(fn()=>(new TransferOrders($db,$actor,new MemberBankVault($db,$actor,$f['path'])))->preview($case['creditEntry'],$f['account'],$f['today'],'Own actual credit'),'known credit cannot be prepared by reader or foreign actor '.$actor);
    $deny(fn()=>$f['vault']->currentBankForCreditRefund($case['creditEntry'],$firsts[0]['case']['contact']),'credit boundary cannot read another known original buyer account',403);
    $deny(fn()=>(new ExpenseReimbursements($db,$f['actor']))->createForEntry($case['creditEntry'],$case['contact'],'member',$key()),'actual issued credit cannot become a fake private advance',409);
    $db->exec("INSERT INTO actors(id,club_id,role,is_superuser)VALUES('own-credit-writer','own-list-club','treasurer',0)");
    $deny(fn()=>(new TransferOrders($db,'own-credit-writer',new MemberBankVault($db,'own-credit-writer',$f['path'])))->preview($case['creditEntry'],$f['account'],$f['today'],'Own actual credit'),'finance writing alone cannot decrypt an original buyer account');
    $first=$f['native']->accept($input,$key(),$review['hash'],true);$order=$first['result']['order']['id'];$f['orders']->approve($order,1);$payload=$f['orders']->fintsPayload($order,2);$money=$f['finance']->transactions();
    $check(str_contains($payload['xml'],'<Cd>INST</Cd>')&&$first['result']['source']['total_cents']===3000,'real instant credit order retains exact original30 and never silently becomes standard');
    $deny(fn()=>$f['native']->prepare($input),'active credit order blocks another native payment',409);
    $baseline=ownOriginalDatabaseHash($db,$tables);
    $changed=static function(string $label,callable $mutate)use($db,$tables,$baseline,$f,$order,$deny,$check):void{
        $db->beginTransaction();try{$mutate();$deny(fn()=>$f['orders']->fintsPayload($order,2),$label);$check($db->inTransaction(),$label.' retains caller transaction');}finally{if($db->inTransaction())$db->rollBack();}
        $check(ownOriginalDatabaseHash($db,$tables)===$baseline,$label.' leaves all original rows exact after owned rollback');
    };
    $changed('changed original buyer identity',static function()use($db,$f,$case,$firsts):void{Db::run($db,"UPDATE outgoing_invoices SET payload=JSON_SET(payload,'$.contact_id',?) WHERE club_id=? AND id=?",[$firsts[0]['case']['contact'],$f['club'],$case['invoice']]);});
    $changed('changed actual credit amount without changing version',static function()use($db,$f,$case):void{Db::run($db,'UPDATE finance_entries SET amount_cents=amount_cents+1 WHERE club_id=? AND id=?',[$f['club'],$case['creditEntry']]);});
    foreach(['credit','invoice']as$original){
        $before=ownOriginalDatabaseHash($db,$tables);$document=$f['invoices']->get($case[$original])['document_id'];$refused=false;
        try{Db::run($db,"UPDATE document_files SET bytes=CONCAT(bytes,'own corrupt byte') WHERE club_id=? AND document_id=? AND revision=1",[$f['club'],$document]);}catch(PDOException $error){if(($error->errorInfo[0]??null)!=='45000')throw $error;$refused=true;}
        $check($refused&&ownOriginalDatabaseHash($db,$tables)===$before&&$f['orders']->fintsPayload($order,2)===$payload,'actual immutable '.$original.' PDF trigger refuses byte replacement and preserves every row and exact current bank payload');
    }
    $changed('changed frozen original invoice number',static function()use($db,$f,$case):void{Db::run($db,"UPDATE outgoing_invoices SET payload=JSON_SET(payload,'$.original_number','OWN-WRONG-ORIGINAL') WHERE club_id=? AND id=?",[$f['club'],$case['credit']]);});
    $changed('a draft cannot pretend to be an issued credit',static function()use($db,$f,$case):void{Db::run($db,"UPDATE outgoing_invoices SET state='draft' WHERE club_id=? AND id=?",[$f['club'],$case['credit']]);});
    $changed('archived original buyer',static function()use($db,$f,$case):void{Db::run($db,'UPDATE contacts SET archived_at=? WHERE club_id=? AND id=?',[gmdate('c'),$f['club'],$case['contact']]);});
    $writer=\Kulturhus\Native\TestSupport::db();$writer->beginTransaction();
    try{Db::run($writer,'SELECT contact_id FROM member_bank_records WHERE club_id=? AND contact_id=? FOR UPDATE NOWAIT',[$f['club'],$case['contact']])->fetchColumn();$deny(fn()=>$f['orders']->fintsPayload($order,2),'actual independent buyer-bank lock',503);}finally{$writer->rollBack();}
    $writer->beginTransaction();try{$document=$f['invoices']->get($case['invoice'])['document_id'];Db::run($writer,'SELECT document_id FROM document_files WHERE club_id=? AND document_id=? AND revision=1 FOR UPDATE NOWAIT',[$f['club'],$document])->fetchColumn();$deny(fn()=>$f['orders']->fintsPayload($order,2),'actual independent original invoice-file lock',503);}finally{$writer->rollBack();}
    $reader=\Kulturhus\Native\TestSupport::db();$reader->beginTransaction();$reader->query('SELECT COUNT(*) FROM member_bank_records')->fetchColumn();$newBank=$f['bank'];$newBank['holder']=mb_substr($newBank['holder'],0,-1).'Z';($case['save'])(1,$newBank);
    try{$deny(fn()=>(new TransferOrders($reader,$f['actor'],new MemberBankVault($reader,$f['actor'],$f['path'])))->fintsPayload($order,2),'actual bank holder change beyond70 characters supersedes an older independent reader');$check($reader->inTransaction(),'old reader rejection retains the earlier caller transaction');}finally{$reader->rollBack();}
    $check($f['native']->receipt($first['key'])===$first&&$f['orders']->get($order)['snapshot']['xml']===$payload['xml']&&$f['finance']->transactions()===$money&&hash_file('sha256',$f['path'])===$f['keyHash'],'changed current bank source preserves the entire personal first encrypted bank original all money and original key');
    foreach($firsts as$index=>$original){
        $old=\Kulturhus\Native\TestSupport::db();$old->beginTransaction();$old->query('SELECT COUNT(*) FROM finance_entries')->fetchColumn();$source=$original['case'];
        try{
            if($index===0){$allocation=Db::run($db,'SELECT * FROM finance_allocations WHERE club_id=? AND entry_id=? AND voided_at IS NULL',[$f['club'],$source['entry']])->fetch();$f['finance']->releaseAllocation($allocation['id'],(int)$f['finance']->transactionDetail($source['transaction'])['version'],(int)$f['finance']->entry($source['entry'])['version'],'Own actual original allocation correction');$label='genuine original incoming payment allocation correction';}
            else{$f['finance']->recordPayment($source['creditEntry'],(int)$f['finance']->entry($source['creditEntry'])['version'],$f['account'],$f['today'],'1');$label='genuine later one-euro credit payment';}
            $deny(fn()=>$f['orders']->decisionSnapshot($original['order'],true),$label.' blocks changed current source');
            $deny(fn()=>(new TransferOrders($old,$f['actor'],new MemberBankVault($old,$f['actor'],$f['path'])))->decisionSnapshot($original['order'],true),$label.' supersedes a real older independent reader');
            $proof=(new InvoiceApprovals($db,$f['actor']))->state($source['creditEntry']);$check($proof['original_count']===1&&$proof['status']==='changed','genuine issued credit original remains readable after current payment correction while bank source stays blocked');
            $check($f['native']->receipt($original['first']['key'])===$original['first']&&$f['orders']->get($original['order'])['sealed_snapshot']===$original['sealed'],$label.' preserves the whole first and exact encrypted original');
        }finally{$old->rollBack();}
    }
    $former=($f['make'])('person');Db::run($db,"UPDATE memberships SET status='ehemalig',ends_on=? WHERE club_id=? AND id=?",['2026-01-31',$f['club'],$former['membership']]);$before=ownOriginalDatabaseHash($db,$tables);$preview=$f['orders']->preview($former['creditEntry'],$f['account'],$f['date'],'Own refund debt after membership end');
    $check($preview['total_cents']===3000&&$preview['source']['credit_refund']['buyer_contact_id']===$former['contact']&&ownOriginalDatabaseHash($db,$tables)===$before,'real credit refund debt remains payable to its already saved original buyer after membership expiry without a mandate or invented member');
    $deny(fn()=>$f['vault']->currentBankForReimbursement($former['contact']),'new credit boundary does not loosen the separate current-membership reimbursement policy');
    $unlinked=($f['make'])('person',true,false);$before=ownOriginalDatabaseHash($db,$tables);$state=(new InvoiceApprovals($db,$f['actor']))->state($unlinked['creditEntry']);$readState=(new InvoiceApprovals($db,$f['reader']))->state($unlinked['creditEntry']);
    $check($state['status']==='approved'&&$state['original_count']===1&&$readState['original_count']===1&&!$readState['can_approve']&&$f['invoices']->get($unlinked['invoice'])['data']['contact_id']===''&&ownOriginalDatabaseHash($db,$tables)===$before,'actual issued credit with legitimately optional empty buyer contact remains a genuine readable original for writer and reader');
    $unlinkedInput=($f['inputFor'])($unlinked['creditEntry']);$deny(fn()=>$f['native']->prepare($unlinkedInput),'unlinked actual credit cannot infer a bank recipient from an identically named saved member',409);
    $deny(fn()=>$f['vault']->currentBankForCreditRefund($unlinked['creditEntry'],$unlinked['contact']),'unlinked original cannot decrypt the identically named unbound private account',403);
    return compact('case','input','first','order','payload','former','unlinked');
}
