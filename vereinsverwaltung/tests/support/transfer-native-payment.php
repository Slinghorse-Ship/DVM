<?php declare(strict_types=1);
use Kulturhus\Native\Shared\Db;
use Kulturhus\Verein\{Finance,InvoiceApprovals,MemberBankVault,Permissions,TransferOrders};
use Kulturhus\Verein\Finance\Banking\NativeTransfer\{PaymentForm,TransferRequests};

function ownTransferNativePayment(PDO $db,callable $check,array $f):void
{
    $native=$f['native'];$finance=new Finance($db,$f['actor']);$key=static fn():string=>bin2hex(random_bytes(16));$actor=Permissions::actor($db,$f['actor']);
    $deny=static function(callable $work,int $code,string $label)use($check):void{try{$work();}catch(DomainException $e){$check($e->getCode()===$code,$label);return;}throw new RuntimeException('Expected payment rejection: '.$label);};
    foreach(['standard','instant']as$mode){
        $source=($f['entry'])('Own direct '.$mode.' payment');$before=ownOriginalDatabaseHash($db,$f['tables']);$reviews=$native->paymentReviews($source['entry']);$session=[];$form=PaymentForm::open($session,$actor,$source['entry'],$reviews);
        $check(array_keys($reviews)===['standard','instant']&&$form===PaymentForm::open($session,$actor,$source['entry'],$reviews)&&ownOriginalDatabaseHash($db,$f['tables'])===$before,'payment choices automatically use the approved invoice without changing any business row '.$mode);
        $deny(fn()=>PaymentForm::get($session,Permissions::actor($db,'own-transfer-writer'),$source['entry'],$form['id'],$mode),404,'another actor cannot use the payment form '.$mode);
        $deny(fn()=>PaymentForm::get($session,$actor,$f['invoice']['entry'],$form['id'],$mode),404,'payment form cannot be switched to another invoice '.$mode);
        $deny(fn()=>PaymentForm::get($session,$actor,$source['entry'],$form['id'],'0'),422,'unknown transfer choice is never silently replaced '.$mode);
        $choice=PaymentForm::get($session,$actor,$source['entry'],$form['id'],$mode)['choice'];$first=$native->accept($choice['input'],$form['key'],$choice['hash'],true);PaymentForm::complete($session,$form,$first['key']);$order=$first['result']['order'];
        $check($order['state']==='approved'&&(int)$order['version']===2&&$first['input']['operation']==='pay'&&$first['result']['source']['transfer_mode']===$mode,'one local payment action prepares and approves the exact chosen mode '.$mode);
        $payload=$f['service']->fintsPayload($order['id'],2);$check($payload['transfer_mode']===$mode&&(str_contains($payload['xml'],'<Cd>INST</Cd>')===($mode==='instant')),'direct FinTS payload preserves normal or instant mode '.$mode);
        $after=ownOriginalDatabaseHash($db,$f['tables']);$check($native->accept($choice['input'],$form['key'],$choice['hash'],true)===$first&&ownOriginalDatabaseHash($db,$f['tables'])===$after,'repeated payment click returns exactly the first order without another write '.$mode);
        $opposite=$reviews[$mode==='standard'?'instant':'standard'];$deny(fn()=>$native->accept($opposite['input'],$form['key'],$opposite['hash'],true),409,'the other mode on the same payment form cannot create a second order '.$mode);
        $check($native->paymentOrder($source['entry'])['order']['id']===$order['id']&&$finance->entry($source['entry'])===$source['original_entry'],'payment resumes its current order and leaves the actual paid amount unchanged '.$mode);
        $deny(fn()=>$native->paymentReviews($source['entry']),409,'an existing approved payment prevents a second payment form '.$mode);
    }
    $source=($f['entry'])('Own changed direct payment');$review=$native->paymentReviews($source['entry'])['standard'];$approval=new InvoiceApprovals($db,$f['actor']);$state=$approval->state($source['entry']);$approval->withdraw($source['entry'],$state['hash'],$state['decision_id'],'Own stale-payment test',$key());$before=ownOriginalDatabaseHash($db,$f['tables']);$denied=false;
    try{$native->accept($review['input'],$key(),$review['hash'],true);}catch(DomainException){$denied=true;}
    $check($denied&&ownOriginalDatabaseHash($db,$f['tables'])===$before&&$native->paymentOrder($source['entry'])===null,'withdrawn invoice approval blocks a displayed payment without leaving a partially prepared order');
    $writer=new TransferRequests($db,'own-transfer-writer',new MemberBankVault($db,'own-transfer-writer',$f['path']));$deny(fn()=>$writer->paymentReviews($source['entry']),403,'automatic payment preparation still requires the current confirmed payment office');
    $source=($f['entry'])('Own atomic direct payment');$review=$native->paymentReviews($source['entry'])['instant'];$before=ownOriginalDatabaseHash($db,$f['tables']);
    $db->exec("CREATE TRIGGER own_payment_abort BEFORE INSERT ON native_settings_requests FOR EACH ROW BEGIN IF NEW.section='transfer-order' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Own payment rollback';END IF;END");$failed=false;
    try{$native->accept($review['input'],$key(),$review['hash'],true);}catch(PDOException $e){if(($e->errorInfo[0]??null)!=='45000')throw $e;$failed=true;}finally{$db->exec('DROP TRIGGER own_payment_abort');}
    $check($failed&&ownOriginalDatabaseHash($db,$f['tables'])===$before&&$native->paymentOrder($source['entry'])===null,'receipt failure rolls back automatic preparation, approval and their complete audits together');
    $legacy=($f['entry'])('Own earlier prepared payment');$input=($f['inputFor'])($legacy['entry'],'instant');$review=$native->prepare($input);$old=$native->accept($input,$key(),$review['hash'],true);$current=$native->paymentOrder($legacy['entry']);$reviews=$native->paymentReviews($legacy['entry'],$current);$form=PaymentForm::open($session,$actor,$legacy['entry'],$reviews);$choice=$form['input']['choices']['instant'];$first=$native->accept($choice['input'],$form['key'],$choice['hash'],true);
    $check(array_keys($reviews)===['instant']&&$first['result']['order']['id']===$old['result']['order']['id']&&$first['result']['order']['state']==='approved','earlier prepared payments continue in one action using the same order and immutable payment mode');
    $check(hash_file('sha256',$f['path'])===$f['keyHash'],'direct payment preparation preserves the real bank encryption key');
}
