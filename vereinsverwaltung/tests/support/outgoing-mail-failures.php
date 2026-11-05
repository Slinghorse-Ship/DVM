<?php declare(strict_types=1);
use Kulturhus\Verein\Finance\Invoices\{OutgoingMailInput,OutgoingMailRequests};
use Kulturhus\Native\Shared\Db;

final class OwnOutgoingMailCommitLoss extends PDO
{
    public ?string $loseKey=null;
    public function commit():bool
    {
        $lose=$this->loseKey!==null&&Db::run($this,'SELECT 1 FROM native_settings_requests WHERE request_key=?',[$this->loseKey])->fetchColumn();
        $result=parent::commit();if($lose){$this->loseKey=null;throw new PDOException('Own actual outgoing message COMMIT acknowledgement lost.');}return $result;
    }
}
/** Independent actual connections, SQL failures and current authority behind older caller snapshots. */
function ownOutgoingMailFailures(PDO $db,callable $check,array $fixture,array $config):array
{
    $name=(string)$db->query('SELECT DATABASE()')->fetchColumn();
    if(!preg_match('/\Akh_(?:test|native)_[a-f0-9]{24}\z/D',$name)||($config['database']??null)!==$name||($config['host']??null)!=='127.0.0.1'||!in_array($config['port']??null,[13371,13372],true))throw new RuntimeException('Own isolated outgoing message fault connections required.');
    $dsn='mysql:host=127.0.0.1;port='.$config['port'].';dbname='.$name.';charset=utf8mb4';$options=[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false];
    $caller=new PDO($dsn,$config['username'],$config['password'],$options);$fault=new OwnOutgoingMailCommitLoss($dsn,$config['username'],$config['password'],$options);
    $service=$fixture['service'];$actor=$fixture['actor'];$requests=$fixture['requests'];$key=static fn()=>bin2hex(random_bytes(16));$tables=$db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    $id=$service->save('',0,array_replace($fixture['data'],['description'=>'Own actual fault and current-source original invoice']),$key());$service->review($id,1);$service->issue($id,2);
    $input=OutgoingMailInput::initial($requests->context($id));$input['data']['message']="\nOwn whole original SQL intention **0**\n";$plan=$requests->prepare($input);$auditMax=(int)$db->query('SELECT COALESCE(MAX(sequence),0) FROM audit_events')->fetchColumn();
    $db->exec("CREATE TRIGGER own_outgoing_original_failure BEFORE INSERT ON native_settings_requests FOR EACH ROW BEGIN IF NEW.section='outgoing-mail' THEN IF (SELECT COUNT(*) FROM outgoing_invoice_deliveries WHERE club_id='own-list-club' AND invoice_id='".$id."' AND state='pending')=1 AND (SELECT COUNT(*) FROM audit_events WHERE sequence>".$auditMax." AND action='outgoing.mail.queued' AND object_id='".$id."')=1 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Own executed pending and audit precede original failure'; ELSE SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Own outgoing failure before canonical work'; END IF; END IF; END");
    $before=ownOriginalDatabaseHash($db,$tables);
    try{
        $db->beginTransaction();$db->exec("UPDATE clubs SET name='Own earlier unrelated invoice mail caller work' WHERE id='foreign-list-club'");$callerBefore=ownOriginalDatabaseHash($db,$tables);$failed=false;
        try{$requests->accept($input,$key(),$plan['hash'],true);}catch(PDOException $failure){$failed=str_contains((string)($failure->errorInfo[2]??''),'Own executed pending and audit precede original failure');}
        $check($failed&&$db->inTransaction()&&ownOriginalDatabaseHash($db,$tables)===$callerBefore,'real SQL failure after actual pending message and audit rolls back the whole approval while retaining every earlier caller row and transaction');
    }finally{if($db->inTransaction())$db->rollBack();$db->exec('DROP TRIGGER own_outgoing_original_failure');}
    $check(ownOriginalDatabaseHash($db,$tables)===$before,'outer rollback after failed invoice message restores every earlier entire business row');
    $caller->beginTransaction();$caller->exec("UPDATE clubs SET name='Own earlier composable invoice mail work' WHERE id='foreign-list-club'");
    try{$composed=new OutgoingMailRequests($caller,$actor);$accepted=$composed->accept($input,$key(),$plan['hash'],true);$check($caller->inTransaction()&&$composed->receipt($accepted['key'])===$accepted,'actual invoice message pending audit and private first original compose with the existing caller transaction');}finally{$caller->rollBack();}
    $check(ownOriginalDatabaseHash($db,$tables)===$before,'caller rollback removes its entire uncommitted message approval and leaves earlier invoice and business originals exact');
    $held=new PDO($dsn,$config['username'],$config['password'],$options);$held->beginTransaction();Db::run($held,'SELECT * FROM outgoing_invoices WHERE club_id=? AND id=? FOR UPDATE',['own-list-club',$id])->fetch();
    try{
        $caller->beginTransaction();$caller->exec("UPDATE clubs SET name='Own earlier held invoice mail caller work' WHERE id='foreign-list-club'");$callerBefore=ownOriginalDatabaseHash($caller,$tables);
        foreach(['read','accept','canonical-queue']as$operation){$busy=false;$started=microtime(true);try{$reader=new OutgoingMailRequests($caller,$actor);if($operation==='read')$reader->context($id);elseif($operation==='accept')$reader->accept($input,$key(),$plan['hash'],true);else(new \Kulturhus\Verein\OutgoingInvoices($caller,$actor))->queue($id,(int)$input['version']);}catch(DomainException $failure){$busy=$failure->getCode()===503&&$failure->getPrevious()instanceof PDOException&&(int)($failure->getPrevious()->errorInfo[1]??0)===3572;}$check($busy&&microtime(true)-$started<3&&$caller->inTransaction()&&ownOriginalDatabaseHash($caller,$tables)===$callerBefore,'independent actual invoice row returns immediate typed NOWAIT 3572 and retains earlier caller work '.$operation);}
    }finally{if($caller->inTransaction())$caller->rollBack();$held->rollBack();}
    $lostKey=$key();$fault->loseKey=$lostKey;$lost=false;try{(new OutgoingMailRequests($fault,$actor))->accept($input,$lostKey,$plan['hash'],true);}catch(PDOException){$lost=true;}
    $first=$requests->receipt($lostKey);$before=ownOriginalDatabaseHash($db,$tables);
    $check($lost&&!$fault->inTransaction()&&$first['result']['state']==='pending'&&$requests->accept($input,$lostKey,$plan['hash'],true)===$first&&ownOriginalDatabaseHash($db,$tables)===$before,'actual committed invoice message with lost COMMIT acknowledgement reads exact first JSON and same key never queues another delivery');
    // Old snapshots must not retain withdrawn write permission or lose an accepted read-only original.
    if(Db::run($db,'SELECT 1 FROM settings_role_assignments WHERE actor_id=?',[$actor])->fetchColumn())throw new RuntimeException('Independent original mail actor requires its own initially unassigned roles.');
    $db->exec("INSERT INTO settings_roles(club_id,id,name,description,members_access,finance_access,settings_access,version)VALUES('own-list-club','own-outgoing-read-only','Own current Finance read','','none','read',0,1),('own-list-club','own-outgoing-no-finance','Own current no Finance','','none','none',1,1)");$originalActor=Db::run($db,'SELECT * FROM actors WHERE id=?',[$actor])->fetch();
    foreach(['read-only','no-finance']as$withdrawal){
        $caller->beginTransaction();$caller->query('SELECT * FROM finance_entries')->fetchAll();$caller->exec("UPDATE clubs SET name='Own earlier old-snapshot outgoing role work' WHERE id='foreign-list-club'");
        Db::run($db,'INSERT INTO settings_role_assignments VALUES(?,?,?,1)',[$actor,'own-list-club','own-outgoing-'.$withdrawal]);Db::run($db,'UPDATE actors SET is_superuser=0 WHERE id=?',[$actor]);
        try{$reader=new OutgoingMailRequests($caller,$actor);$callerBefore=ownOriginalDatabaseHash($caller,$tables);$denied=false;try{$reader->prepare($input);}catch(DomainException $failure){$denied=$failure->getCode()===403;}
            if($withdrawal==='read-only')$read=$reader->receipt($first['key'])===$first&&$reader->accept($input,$first['key'],$plan['hash'],true)===$first;
            else{$read=false;try{$reader->receipt($first['key']);}catch(DomainException $failure){$read=$failure->getCode()===403;}}
            $check($denied&&$read&&$caller->inTransaction()&&ownOriginalDatabaseHash($caller,$tables)===$callerBefore,'actual current Finance withdrawal governs private original reads and new mail approval behind an older snapshot without changing caller work '.$withdrawal);
        }finally{$caller->rollBack();Db::run($db,'UPDATE actors SET is_superuser=? WHERE id=?',[$originalActor['is_superuser'],$actor]);Db::run($db,'DELETE FROM settings_role_assignments WHERE actor_id=?',[$actor]);}
        $check($requests->receipt($first['key'])===$first,'restored current Finance authority returns the exact original first message bytes '.$withdrawal);
    }
    return compact('id','input','plan','first');
}
