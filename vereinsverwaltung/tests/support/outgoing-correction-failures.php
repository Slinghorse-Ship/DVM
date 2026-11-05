<?php declare(strict_types=1);
use Kulturhus\Native\Shared\Db;
use Kulturhus\Verein\Finance\Invoices\{OutgoingCorrectionInput,OutgoingCorrectionRequests};

final class OwnOutgoingCorrectionCommitLoss extends PDO
{
    public ?string $loseKey=null;
    public function commit():bool
    {
        $lose=$this->loseKey!==null&&Db::run($this,'SELECT 1 FROM native_settings_requests WHERE section=? AND request_key=?',[OutgoingCorrectionRequests::SECTION,$this->loseKey])->fetchColumn();
        $result=parent::commit();if($lose){$this->loseKey=null;throw new PDOException('Own actual correction COMMIT acknowledgement lost.');}return $result;
    }
}

/** Real post-archive SQL failure, committed acknowledgement loss, and current independent row lock. */
function ownOutgoingCorrectionFailures(PDO $db,callable $check,array $fixture,array $config):void
{
    $name=(string)$db->query('SELECT DATABASE()')->fetchColumn();
    if(!preg_match('/\Akh_(?:test|native)_[a-f0-9]{24}\z/D',$name)||($config['database']??null)!==$name||($config['host']??null)!=='127.0.0.1'||!in_array($config['port']??null,[13371,13372],true))throw new RuntimeException('Owned correction fault connections required.');
    $dsn='mysql:host=127.0.0.1;port='.$config['port'].';dbname='.$name.';charset=utf8mb4';$options=[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false];
    $caller=new PDO($dsn,$config['username'],$config['password'],$options);$fault=new OwnOutgoingCorrectionCommitLoss($dsn,$config['username'],$config['password'],$options);
    $service=$fixture['service'];$requests=$fixture['requests'];$key=$fixture['key'];$tables=$fixture['tables'];$actor=$fixture['actor'];
    $newParent=static function(string$description)use($service,$fixture,$key):string{$id=$service->save('',0,array_replace($fixture['data'],['description'=>$description]),$key());$service->review($id,1);$service->issue($id,2);return$id;};
    $id=$newParent('Own actual correction post-archive failure');$input=OutgoingCorrectionInput::initial($service->get($id));$input['data']['reason']='Own complete actual correction archive failure';$input['data']['date']='2025-12-04';$review=$requests->prepare($input);
    $db->exec("CREATE TRIGGER own_native_correction_original_failure BEFORE INSERT ON native_settings_requests FOR EACH ROW BEGIN IF NEW.section='outgoing-correction' AND JSON_UNQUOTE(JSON_EXTRACT(NEW.input_json,'$.invoice'))='".$id."' THEN IF (SELECT COUNT(*) FROM outgoing_invoices WHERE club_id='own-list-club' AND id='".$id."' AND state='cancelled' AND version=4)=1 AND (SELECT COUNT(*) FROM document_files f JOIN outgoing_invoices i ON i.club_id=f.club_id AND i.document_id=f.document_id WHERE i.club_id='own-list-club' AND i.parent_id='".$id."' AND i.kind='cancellation' AND i.state='issued' AND i.version=1 AND f.revision=1)=1 AND (SELECT COUNT(*) FROM outgoing_invoice_history h JOIN outgoing_invoices i ON i.club_id=h.club_id AND i.id=h.invoice_id WHERE i.club_id='own-list-club' AND i.parent_id='".$id."' AND h.version=1 AND h.action='issued')=1 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Own genuine correction original and history precede first failure'; ELSE SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Own correction failed before actual original work'; END IF; END IF; END");
    $before=ownOriginalDatabaseHash($db,$tables);
    try{
        $caller->beginTransaction();$caller->exec("UPDATE clubs SET name='Own earlier correction caller work' WHERE id='own-list-club'");$earlier=ownOriginalDatabaseHash($caller,$tables);$failed=false;
        try{(new OutgoingCorrectionRequests($caller,$actor))->accept($input,$key(),$review['hash'],true);}catch(PDOException$failure){$failed=str_contains($failure->getMessage(),'Own genuine correction original and history precede first failure');}
        $check($failed&&$caller->inTransaction()&&ownOriginalDatabaseHash($caller,$tables)===$earlier,'real SQL failure after correction original and history rolls back the entire inner approval and retains earlier caller work');
        $caller->rollBack();$check(ownOriginalDatabaseHash($db,$tables)===$before,'caller rollback after actual correction archive failure restores every whole earlier row number and document');
    }finally{if($caller->inTransaction())$caller->rollBack();$db->exec('DROP TRIGGER own_native_correction_original_failure');}
    $lostKey=$key();$fault->loseKey=$lostKey;$lost=false;
    try{(new OutgoingCorrectionRequests($fault,$actor))->accept($input,$lostKey,$review['hash'],true);}catch(PDOException$failure){$lost=str_contains($failure->getMessage(),'Own actual correction COMMIT acknowledgement lost.');}
    $first=$requests->receipt($lostKey);$check($lost&&$first['result']['kind']==='cancellation'&&$first['original_after']['state']==='cancelled'&&$first['file']['revision']===1,'real correction COMMIT acknowledgement loss keeps the actual cancelled parent new original number and private first durable together');
    $before=ownOriginalDatabaseHash($db,$tables);$check($requests->accept($input,$lostKey,$review['hash'],true)===$first&&ownOriginalDatabaseHash($db,$tables)===$before,'accepted correction key after lost COMMIT returns the exact first without another number archive or audit');
    $id=$newParent('Own actual current correction row lock');$input=OutgoingCorrectionInput::initial($service->get($id));$input['data']['reason']='Own whole blocked correction 0';$input['data']['date']='2025-12-04';$review=$requests->prepare($input);
    $caller->beginTransaction();Db::run($caller,'SELECT * FROM outgoing_invoices WHERE club_id=? AND id=? FOR UPDATE',['own-list-club',$id])->fetch();
    try{
        $before=ownOriginalDatabaseHash($db,$tables);$busy=false;$started=microtime(true);
        try{$requests->accept($input,$key(),$review['hash'],true);}catch(DomainException$failure){$previous=$failure->getPrevious();$busy=$failure->getCode()===503&&$previous instanceof PDOException&&(int)($previous->errorInfo[1]??0)===3572;}
        $check($busy&&microtime(true)-$started<2&&ownOriginalDatabaseHash($db,$tables)===$before,'actual independent original invoice lock returns NOWAIT 3572 as retryable 503 without partial correction work');
    }finally{$caller->rollBack();}
    $accepted=$requests->accept($input,$key(),$review['hash'],true);$check($accepted['result']['kind']==='cancellation'&&$requests->receipt($first['key'])===$first,'same whole correction can be consciously approved after the actual lock releases and retains its earlier original');
}
