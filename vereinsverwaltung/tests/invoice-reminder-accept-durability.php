<?php declare(strict_types=1);
require __DIR__.'/invoice-reminder-delivery.php';
use Kulturhus\Verein\Finance\Invoices\Reminder\{ReminderRequests,ReminderDecisions,ReminderDecisionInput};

/** Faults follow a real MySQL COMMIT; they do not replace the write operation. */
final class OwnReminderAcceptPDO extends PDO
{
    public string $target='';public bool $armed=false;public int $commits=0;public int $afterCommit=0;public ?Closure $afterRead=null;
    public function commit():bool
    {
        $committedFirst=false;if($this->armed&&$this->target!==''){$q=$this->prepare('SELECT 1 FROM native_settings_requests WHERE club_id=? AND request_key=?');$q->execute(['own-reminder-prototype',$this->target]);$committedFirst=(bool)$q->fetchColumn();}
        $ok=parent::commit();$this->commits++;
        if($this->armed&&$committedFirst){$this->armed=false;throw new RuntimeException('Own actual committed approval acknowledgement lost');}
        if($this->afterRead&&$this->commits===$this->afterCommit){$next=$this->afterRead;$this->afterRead=null;$next();}
        return $ok;
    }
}
$acceptConnection=static fn():OwnReminderAcceptPDO=>new OwnReminderAcceptPDO(\Kulturhus\Verein\Tests\MySqlFixture::dsn($db),'root','isolated-regression-only',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
$invoice=$newInvoice('Own genuine lost accepted reminder acknowledgement');$raw=$requests->initial($invoice,'2026-10-22');$raw['channel']='mail';$raw['reason']="\n0\r\nOwn whole acknowledged reminder request 🙂";$review=$requests->prepare($raw);$acceptKey=$key();$fault=$acceptConnection();$fault->target=$acceptKey;$fault->armed=true;$faultRequests=new ReminderRequests($fault,'own-reminder-finance',$mailKey);$lost=false;
try{$faultRequests->accept($raw,$acceptKey,$review['hash'],true);}catch(RuntimeException $failure){$lost=$failure->getMessage()==='Own actual committed approval acknowledgement lost';}
$first=$requests->receipt($acceptKey);$mime=$requests->eml($acceptKey);$check($lost&&$worker->get($first['result']['delivery_id'])['state']==='pending'&&hash('sha256',$mime)===$first['result']['mail_sha256'],'actual lost approval COMMIT acknowledgement leaves one canonical first PDF and exact pending MIME');
$issued=$invoices->get($invoice);$finance->recordPayment($issued['entry_id'],1,$account,'2025-12-06');$settings->assignRole('own-reminder-finance',$reader,3);$wholeAfter=ownOriginalDatabaseHash($db,$db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN));
$check($faultRequests->accept($raw,$acceptKey,$review['hash'],true)===$first&&$faultRequests->eml($acceptKey)===$mime&&ownOriginalDatabaseHash($db,$db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN))===$wholeAfter,'known actual first after lost approval replays before current paid-source and revoked-write checks without a second outcome');$settings->assignRole('own-reminder-finance',$writeRole,4);

$atomicInvoice=$newInvoice('Own actual SQL failure after queued MIME');$atomic=$requests->initial($atomicInvoice,'2026-10-22');$atomic['channel']='mail';$atomic['reason']='Own complete actual outer transaction test';$atomicReview=$requests->prepare($atomic);$all=$db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
$db->exec("CREATE TRIGGER own_reminder_after_queue BEFORE INSERT ON audit_events FOR EACH ROW BEGIN IF NEW.action='invoice.reminder.accepted' AND EXISTS(SELECT 1 FROM native_invoice_reminder_deliveries WHERE club_id=NEW.club_id AND document_id=NEW.object_id) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='own actual queued MIME exists before final audit'; END IF; END");
try{
    $db->exec('START TRANSACTION');$db->exec("INSERT INTO clubs VALUES('own-reminder-caller-earlier','Own earlier caller work preserved')");$before=ownOriginalDatabaseHash($db,$all);$failed=false;
    try{$requests->accept($atomic,$key(),$atomicReview['hash'],true);}catch(PDOException $failure){$failed=($failure->errorInfo[0]??'')==='45000';}
    $check($failed&&$db->inTransaction()&&ownOriginalDatabaseHash($db,$all)===$before,'actual final-audit failure after canonical PDF private first and sealed queue restores every whole row while preserving earlier caller transaction work');
}finally{if($db->inTransaction())$db->rollBack();$db->exec('DROP TRIGGER own_reminder_after_queue');}
$check(!$db->query("SELECT 1 FROM clubs WHERE id='own-reminder-caller-earlier'")->fetchColumn(),'original caller remains free to roll back its earlier unrelated work');

$changedInvoice=$newInvoice('Own independently paid after rendered preview');$changed=$requests->initial($changedInvoice,'2026-10-22');$changed['channel']='mail';$changed['reason']='Own source changed after actual PDF rendering';$changedReview=$requests->prepare($changed);$changeDb=$acceptConnection();$changeDb->afterCommit=3;$changedEntry=$invoices->get($changedInvoice)['entry_id'];$changedAfter=null;
$changeDb->afterRead=static function()use($observer,$finance,$account,$changedEntry,$db,$all,&$changedAfter):void{(new \Kulturhus\Verein\Finance($observer,'own-reminder-admin'))->recordPayment($changedEntry,1,$account,'2025-12-06');$changedAfter=ownOriginalDatabaseHash($db,$all);};
$changedRequests=new ReminderRequests($changeDb,'own-reminder-finance',$mailKey);$changedFailure=false;
try{$changedRequests->accept($changed,$key(),$changedReview['hash'],true);}catch(DomainException $failure){$changedFailure=in_array($failure->getCode(),[409,422],true);}
$check($changedFailure&&$changedAfter!==null&&ownOriginalDatabaseHash($db,$all)===$changedAfter,'actual independent payment after PDF rendering and configured MIME preparation prevents every unapproved output at the final current-source check');

$decisions=new ReminderDecisions($db,'own-reminder-finance',$mailKey);$cancel=ReminderDecisionInput::initial($first['result']['delivery_id']);$cancel['operation']='cancel';$cancel['data']['reason']='Own explicit pending cancellation after real payment';$cancelReview=$decisions->prepare($cancel);$decisionKey=$key();$decisionDb=$acceptConnection();$decisionDb->target=$decisionKey;$decisionDb->armed=true;$faultDecisions=new ReminderDecisions($decisionDb,'own-reminder-finance',$mailKey);$decisionLost=false;
try{$faultDecisions->accept($cancel,$decisionKey,$cancelReview['hash'],true);}catch(RuntimeException $failure){$decisionLost=$failure->getMessage()==='Own actual committed approval acknowledgement lost';}
$decisionFirst=$decisions->receipt($decisionKey);$after=ownOriginalDatabaseHash($db,$all);$check($decisionLost&&$decisionFirst['result']['state']==='cancelled'&&$faultDecisions->accept($cancel,$decisionKey,$cancelReview['hash'],true)===$decisionFirst&&ownOriginalDatabaseHash($db,$all)===$after,'actual lost cancellation COMMIT acknowledgement replays its exact private first before a changed current-state check');
echo json_encode(['suite'=>'invoice-reminder-accept-durability','checks'=>$n,'external_actions'=>0,'real_smtp_connections'=>0],JSON_THROW_ON_ERROR)."\n";
