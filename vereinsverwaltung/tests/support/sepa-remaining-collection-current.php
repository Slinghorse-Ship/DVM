<?php declare(strict_types=1);
/** Real independent source changes and actual transaction outcomes for the new partial producer. */
$cfg=\Kulturhus\Native\TestSupport::config();
$dsn='mysql:host='.$cfg['host'].';port='.$cfg['port'].';dbname='.$cfg['database'].';charset=utf8mb4';
$options=[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false];
$reader=new PDO($dsn,$cfg['username'],$cfg['password'],$options);
$changed=$make('same-demand-independent-funding','100');$raw=($fixture['inputFor'])($changed['entry']);$review=$native->prepare($raw);$first=$native->accept($raw,$key(),$review['hash'],true);$changedOrder=$first['result']['order']['id'];
$noticeRaw=$notices->initial($changed['entry'],$fixture['notice'],$fixture['date']);$noticeRaw['reason']='Own original forty remains the same demand';$noticePlan=$notices->prepare($noticeRaw);$sameDemand=$f->currentEntry($changed['entry']);
$other=$f->createEntry(['kind'=>'income','category'=>'general','description'=>'Own separate real forty of shared100','amount'=>'40','document_date'=>$fixture['notice'],'due_date'=>$fixture['notice']],$key());
$reader->beginTransaction();$reader->query('SELECT COUNT(*) FROM finance_transactions')->fetchColumn();
try{
    $f->allocate($changed['prior'],(int)$f->transactionDetail($changed['prior'])['version'],$other,(int)$f->entry($other)['version'],'40',$key());
    $before=ownOriginalDatabaseHash($db,$tables);
    $oldService=new \Kulturhus\Verein\SepaOrders($reader,$fixture['actor'],new \Kulturhus\Verein\MemberBankVault($reader,$fixture['actor'],$fixture['path']));
    $deny(fn()=>$oldService->approve($changedOrder,1),'older independent caller cannot approve forty from a physically changed shared source while its own prior sixty and demand remain identical');
    $check($reader->inTransaction()&&$f->currentEntry($changed['entry'])===$sameDemand&&$before===ownOriginalDatabaseHash($db,$tables),'same-demand current funding rejection preserves every independently updated whole row and earlier caller transaction');
    $deny(fn()=>$notices->accept($noticeRaw,$key(),$noticePlan['hash'],true),'earlier forty notice review observes an actual changed funding source even when face paid remaining and entry version are identical');
    $check($before===ownOriginalDatabaseHash($db,$tables),'changed actual shared funding creates no notice PDF order approval audit or personal first');
}finally{if($reader->inTransaction())$reader->rollBack();}

$schemaCase=$make('physical-maintenance-marker');$q=$db->query('SELECT * FROM schema_migrations WHERE version='.\Kulturhus\Verein\Database::SCHEMA_VERSION);$marker=$q->fetch();$reader->beginTransaction();$reader->query('SELECT COUNT(*) FROM schema_migrations')->fetchColumn();
try{
    $db->exec('DELETE FROM schema_migrations WHERE version='.\Kulturhus\Verein\Database::SCHEMA_VERSION);$before=ownOriginalDatabaseHash($db,$tables);
    $oldService=new \Kulturhus\Verein\SepaOrders($reader,$fixture['actor'],new \Kulturhus\Verein\MemberBankVault($reader,$fixture['actor'],$fixture['path']));
    $deny(fn()=>$oldService->preview([$schemaCase['entry']],$fixture['account'],$fixture['date'],$fixture['notice']),'older partial collection source observes the actual independently missing maintenance marker');
    $check($before===ownOriginalDatabaseHash($db,$tables),'partial collection maintenance refusal performs no implicit schema source baseline key or business write');
}finally{if($reader->inTransaction())$reader->rollBack();$db->prepare('INSERT INTO schema_migrations(version,applied_at)VALUES(?,?)')->execute([$marker['version'],$marker['applied_at']]);}

$failedCase=$make('actual-final-partial-abort');$raw=($fixture['inputFor'])($failedCase['entry']);$review=$native->prepare($raw);$request=$key();
$db->exec("CREATE TRIGGER own_remaining_final_abort BEFORE INSERT ON native_settings_requests FOR EACH ROW BEGIN IF NEW.request_key=".$db->quote($request)." THEN IF (SELECT total_cents FROM sepa_orders WHERE club_id=NEW.club_id AND request_key=NEW.request_key)=4000 AND JSON_EXTRACT(NEW.original_json,'$.review.source.preview.source.items[0].funding.paid_cents')=6000 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Own full partial collection abort'; ELSE SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Own missing partial collection proof'; END IF; END IF; END");
$before=ownOriginalDatabaseHash($db,$tables);$failed=false;
try{$native->accept($raw,$request,$review['hash'],true);}catch(PDOException $error){$failed=str_contains($error->getMessage(),'Own full partial collection abort');}finally{$db->exec('DROP TRIGGER own_remaining_final_abort');}
$check($failed&&$before===ownOriginalDatabaseHash($db,$tables),'actual final SQL failure after writing encrypted forty order and native item rolls back the whole partial producer while preserving prior sixty and original invoice');

final class OwnRemainingCommitLoss extends PDO
{
    public string $requestKey='';public bool $armed=true;
    public function commit():bool
    {
        $q=$this->prepare("SELECT 1 FROM native_settings_requests WHERE request_key=? AND section='sepa-order'");$q->execute([$this->requestKey]);$written=(bool)$q->fetchColumn();$result=parent::commit();
        if($written&&$this->armed){$this->armed=false;throw new RuntimeException('Own actual partial collection COMMIT acknowledgement lost');}return $result;
    }
}
$lossCase=$make('durable-partial-commit');$raw=($fixture['inputFor'])($lossCase['entry']);$review=$native->prepare($raw);$request=$key();$loss=new OwnRemainingCommitLoss($dsn,$cfg['username'],$cfg['password'],$options);$loss->requestKey=$request;
$lossNative=new \Kulturhus\Verein\Finance\Banking\NativeSepa\SepaRequests($loss,$fixture['actor'],new \Kulturhus\Verein\MemberBankVault($loss,$fixture['actor'],$fixture['path']));$lost=false;
try{$lossNative->accept($raw,$request,$review['hash'],true);}catch(RuntimeException $error){$lost=$error->getMessage()==='Own actual partial collection COMMIT acknowledgement lost';}
$durable=$native->receipt($request);$before=ownOriginalDatabaseHash($db,$tables);
$check($lost&&!$loss->inTransaction()&&$durable['result']['order']['total_cents']===4000&&$lossNative->accept($raw,$request,$review['hash'],true)===$durable&&$before===ownOriginalDatabaseHash($db,$tables),'actual durable partial collection COMMIT loss repeats the same private first without another forty order or change to its earlier sixty');
