<?php declare(strict_types=1);
use Kulturhus\Native\Shared\Db;
use Kulturhus\Verein\Finance\Invoices\{OutgoingInvoiceInput,OutgoingInvoiceRequests};

final class OwnOutgoingInvoiceCommitLoss extends PDO
{
    public ?string $loseKey=null;
    public function commit():bool
    {
        $lose=$this->loseKey!==null&&Db::run($this,'SELECT 1 FROM native_settings_requests WHERE section=? AND request_key=?',[OutgoingInvoiceRequests::SECTION,$this->loseKey])->fetchColumn();
        $result=parent::commit();if($lose){$this->loseKey=null;throw new PDOException('Own actual invoice COMMIT acknowledgement lost.');}return $result;
    }
}

/** Actual SQL after business work, genuine COMMIT loss and independent current row locks. */
function ownOutgoingInvoiceFailures(PDO $db,callable $check,array $fixture,array $config):array
{
    $name=(string)$db->query('SELECT DATABASE()')->fetchColumn();
    if(!preg_match('/\Akh_(?:test|native)_[a-f0-9]{24}\z/D',$name)||($config['database']??null)!==$name||($config['host']??null)!=='127.0.0.1'||!in_array($config['port']??null,[13371,13372],true))throw new RuntimeException('Owned native invoice fault connections required.');
    $dsn='mysql:host=127.0.0.1;port='.$config['port'].';dbname='.$name.';charset=utf8mb4';$options=[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false];
    $caller=new PDO($dsn,$config['username'],$config['password'],$options);$fault=new OwnOutgoingInvoiceCommitLoss($dsn,$config['username'],$config['password'],$options);
    $requests=$fixture['requests'];$key=$fixture['key'];$tables=$fixture['tables'];$input=$fixture['input'];$input['data']['description']='Own actual issued archive failure target';
    $saved=$requests->execute($input,$key());$id=$saved['result']['id'];$reviewInput=$input;$reviewInput['operation']='review';$reviewInput['invoice']=$id;$reviewInput['version']='1';$review=$requests->execute($reviewInput,$key());
    $issue=['operation'=>'issue','invoice'=>$id,'version'=>'2','data'=>['reviewed_hash'=>$review['result']['reviewed_hash']]];
    $db->exec("CREATE TRIGGER own_native_invoice_original_failure BEFORE INSERT ON native_settings_requests FOR EACH ROW BEGIN IF NEW.section='outgoing-invoice' AND JSON_UNQUOTE(JSON_EXTRACT(NEW.original_json,'$.result.state'))='issued' AND JSON_UNQUOTE(JSON_EXTRACT(NEW.original_json,'$.result.id'))='".$id."' THEN IF (SELECT COUNT(*) FROM outgoing_invoices WHERE club_id='own-list-club' AND id='".$id."' AND state='issued' AND entry_id IS NOT NULL AND document_id IS NOT NULL)=1 AND (SELECT COUNT(*) FROM document_files f JOIN outgoing_invoices i ON i.club_id=f.club_id AND i.document_id=f.document_id WHERE i.club_id='own-list-club' AND i.id='".$id."' AND f.revision=1)=1 AND (SELECT COUNT(*) FROM outgoing_invoice_history WHERE club_id='own-list-club' AND invoice_id='".$id."' AND version=3 AND action='issued')=1 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Own genuine issued entry archive and history precede first failure'; ELSE SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Own failure occurred before actual invoice work'; END IF; END IF; END");
    $before=ownOriginalDatabaseHash($db,$tables);
    try{
        $caller->beginTransaction();$caller->exec("UPDATE clubs SET name='Own earlier invoice caller work' WHERE id='foreign-list-club'");$earlier=ownOriginalDatabaseHash($caller,$tables);$failed=false;
        try{(new OutgoingInvoiceRequests($caller,$fixture['actor']))->execute($issue,$key(),true);}catch(PDOException$failure){$failed=str_contains($failure->getMessage(),'Own genuine issued entry archive and history precede first failure');}
        $check($failed&&$caller->inTransaction()&&ownOriginalDatabaseHash($caller,$tables)===$earlier,'real SQL failure after issued entry original PDF and history rolls back the complete inner approval while preserving earlier caller work');
        $caller->rollBack();$check(ownOriginalDatabaseHash($db,$tables)===$before,'caller rollback after actual issued archive failure restores every whole earlier database row');
    }finally{if($caller->inTransaction())$caller->rollBack();$db->exec('DROP TRIGGER own_native_invoice_original_failure');}
    $lostKey=$key();$fault->loseKey=$lostKey;$lost=false;
    try{(new OutgoingInvoiceRequests($fault,$fixture['actor']))->execute($issue,$lostKey,true);}catch(PDOException$failure){$lost=str_contains($failure->getMessage(),'Own actual invoice COMMIT acknowledgement lost.');}
    $first=$requests->receipt($lostKey);$check($lost&&$first['result']['state']==='issued'&&$first['file']['revision']===1&&$first['file']['document_id']===$first['result']['document_id'],'real COMMIT acknowledgement loss leaves the actual issued number entry PDF and complete private first durable together');
    $before=ownOriginalDatabaseHash($db,$tables);$check($requests->execute($issue,$lostKey,true)===$first&&$requests->receipt($saved['key'])===$saved&&$requests->receipt($review['key'])===$review&&ownOriginalDatabaseHash($db,$tables)===$before,'exact accepted key after lost issue acknowledgement returns the same first without another number entry archive or audit');
    $input=$fixture['input'];$input['data']['description']='Own actual current row lock target';$target=$requests->execute($input,$key());$targetId=$target['result']['id'];$update=$input;$update['invoice']=$targetId;$update['version']='1';$update['data']['description']='Own entire blocked replacement 0';
    $caller->beginTransaction();Db::run($caller,'SELECT * FROM outgoing_invoices WHERE club_id=? AND id=? FOR UPDATE',['own-list-club',$targetId])->fetch();
    try{
        $before=ownOriginalDatabaseHash($db,$tables);$busy=false;$started=microtime(true);
        try{$requests->execute($update,$key());}catch(DomainException$failure){$previous=$failure->getPrevious();$busy=$failure->getCode()===503&&$previous instanceof PDOException&&(int)($previous->errorInfo[1]??0)===3572;}
        $check($busy&&microtime(true)-$started<2&&ownOriginalDatabaseHash($db,$tables)===$before,'actual independent invoice row lock returns NOWAIT 3572 as retryable 503 without a partial save audit or private original');
    }finally{$caller->rollBack();}
    $accepted=$requests->execute($update,$key());$check($accepted['result']['version']===2&&$accepted['result']['data']['description']===$update['data']['description']&&$requests->receipt($target['key'])===$target,'same complete draft can be consciously saved after the real current lock releases while its earlier first stays exact');
    return compact('id','first','target','accepted','dsn','options');
}
