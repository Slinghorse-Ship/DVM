<?php declare(strict_types=1);
ini_set('zend.exception_ignore_args','1');
require dirname(__DIR__).'/native/bootstrap.php';
require dirname(__DIR__).'/src/bootstrap.php';
require __DIR__.'/support/original-database-hash.php';

use Kulturhus\Native\TestSupport;
use Kulturhus\Native\Shared\Db;
use Kulturhus\Verein\ClubMailings;
use Kulturhus\Verein\Web\MailingForm;

$cfg=TestSupport::config();
if(($cfg['owner']??'')!=='isolated-native-ci'||($cfg['label']??'')!=='native-mailings-interface')throw new RuntimeException('Own mailing interface fixture required.');
$db=TestSupport::db();
$previousSecret=getenv('APP_SECRET');
putenv('APP_SECRET='.bin2hex(random_bytes(32)));
$checks=0;$failures=[];
$check=static function(bool $ok,string $label)use(&$checks,&$failures):void{
    $checks++;echo($ok?'PASS ':'FAIL ').$label.PHP_EOL;
    if(!$ok)$failures[]=$label;
};

final class OwnMailingUiCommit extends PDO
{
    public bool $lose=false;
    public function commit():bool
    {
        $accepted=parent::commit();
        if($this->lose){$this->lose=false;throw new PDOException('Own mailing COMMIT accepted before response loss');}
        return $accepted;
    }
}

final class OwnMailingUiRace extends PDO
{
    public string $directory='';
    public string $tag='';
    public bool $pause=true;
}
final class OwnMailingUiRaceStatement extends PDOStatement
{
    protected function __construct(private OwnMailingUiRace $owner){}
    public function execute(?array $params=null):bool
    {
        $ok=parent::execute($params);
        if($this->owner->pause&&$this->queryString==='SELECT * FROM club_mailings WHERE club_id=? AND id=?'){
            $this->owner->pause=false;
            file_put_contents($this->owner->directory.'/ready-'.$this->owner->tag,'ready',LOCK_EX);
            $deadline=microtime(true)+15;
            while(!is_file($this->owner->directory.'/release')){
                if(microtime(true)>$deadline)throw new RuntimeException('Own mailing race barrier expired.');
                usleep(10000);
            }
        }
        return $ok;
    }
}
if(($argv[1]??'')==='--race'){
    $input=json_decode(stream_get_contents(STDIN),true,16,JSON_THROW_ON_ERROR);
    $directory=$input['directory']??'';$tag=$input['tag']??'';
    if(!is_string($directory)||!str_starts_with($directory,dirname((string)getenv('KH_NATIVE_TEST_CONFIG')).'/mailing-race-')||realpath($directory)!==$directory||!in_array($tag,['A','B'],true))throw new RuntimeException('Owned mailing race required.');
    $race=new OwnMailingUiRace('mysql:host=127.0.0.1;port=13371;dbname='.$cfg['database'].';charset=utf8mb4',$cfg['username'],$cfg['password'],[
        PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false,
    ]);
    $race->directory=$directory;$race->tag=$tag;
    $race->setAttribute(PDO::ATTR_STATEMENT_CLASS,[OwnMailingUiRaceStatement::class,[$race]]);
    try{
        $id=(new ClubMailings($race,'own-mailing-ui-root'))->save($input['id'],'Own concurrent mailing '.$tag,'Own complete concurrent body '.$tag,['own-mailing-ui-person'],$input['key']);
    echo json_encode(['accepted'=>true,'same_target'=>$id===$input['id'],'tag'=>$tag],JSON_THROW_ON_ERROR).PHP_EOL;
    }catch(DomainException $failure){echo json_encode(['accepted'=>false,'kind'=>'domain','code'=>$failure->getCode(),'tag'=>$tag],JSON_THROW_ON_ERROR).PHP_EOL;}
    catch(Throwable $failure){echo json_encode(['accepted'=>false,'kind'=>'error','class'=>get_class($failure),'tag'=>$tag],JSON_THROW_ON_ERROR).PHP_EOL;exit(2);}
    exit;
}

Kulturhus\Native\Installer\SchemaRegistry::install($db);
try{
    Db::run($db,"INSERT INTO clubs VALUES('own-mailing-ui-model','Own mailing model')");
    Db::run($db,"INSERT INTO clubs VALUES('own-mailing-ui-foreign','Foreign mailing model')");
    Db::run($db,"INSERT INTO actors(id,club_id,role,is_superuser) VALUES('own-mailing-ui-root','own-mailing-ui-model','member',1)");
    Db::run($db,"INSERT INTO actors(id,club_id,role,is_superuser) VALUES('own-mailing-ui-foreign-root','own-mailing-ui-foreign','member',1)");
    Db::run($db,"INSERT INTO actors(id,club_id,role,is_superuser) VALUES('own-mailing-ui-unprivileged','own-mailing-ui-model','member',0)");
    Db::run($db,"INSERT INTO contacts(id,club_id,kind,name,email,address) VALUES('own-mailing-ui-person','own-mailing-ui-model','person','Own literal recipient ä🙂','recipient@example.invalid','Own complete address')");
    $service=new ClubMailings($db,'own-mailing-ui-root');
    $earlier=$service->save('','Own earlier subject','Own earlier complete body',['own-mailing-ui-person'],bin2hex(random_bytes(16)));
    $earlierRow=Db::run($db,'SELECT * FROM club_mailings WHERE id=?',[$earlier])->fetch();
    $tables=array_values(array_diff($db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN),['club_mailings']));
    $before=ownOriginalDatabaseHash($db,$tables);

    $same=ownOriginalDatabaseHash($db,['club_mailings']);
    $sameId=$service->save($earlier,'Own earlier subject','Own earlier complete body',['own-mailing-ui-person'],$earlierRow['preview_hash']);
    $check($sameId===$earlier&&ownOriginalDatabaseHash($db,['club_mailings'])===$same,'consciously saving an unchanged original draft succeeds without a false conflict or changed row');

    $beforeZero=ownOriginalDatabaseHash($db,['club_mailings']);
    $zeroKey=bin2hex(random_bytes(16));$zeroAccepted=false;
    try{$service->save('0','Own unaccepted zero target','Own retained zero intention',['own-mailing-ui-person'],$zeroKey);$zeroAccepted=true;}
    catch(DomainException){}
    $check(!$zeroAccepted&&ownOriginalDatabaseHash($db,['club_mailings'])===$beforeZero,'literal nonempty zero mailing target cannot silently create a new draft');

    $fault=new OwnMailingUiCommit('mysql:host=127.0.0.1;port=13371;dbname='.$cfg['database'].';charset=utf8mb4',$cfg['username'],$cfg['password'],[
        PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES=>false,
    ]);
    $key=bin2hex(random_bytes(16));$fault->lose=true;$caught=null;
    try{(new ClubMailings($fault,'own-mailing-ui-root'))->save('','Own accepted response-loss subject','Own complete accepted response-loss body',['own-mailing-ui-person'],$key);}
    catch(Throwable $failure){$caught=$failure;}
    $accepted=Db::run($db,'SELECT * FROM club_mailings WHERE club_id=? AND request_key=?',['own-mailing-ui-model',$key])->fetch();
    $check($accepted!==false&&$accepted['subject']==='Own accepted response-loss subject'&&$accepted['body']==='Own complete accepted response-loss body','actual MySQL mailing COMMIT durably stores the original before its response is lost');
    $check($caught instanceof PDOException&&$caught->getMessage()==='Own mailing COMMIT accepted before response loss','accepted mailing response loss retains its original error without a second rollback masking it');
    $check(!$fault->inTransaction(),'accepted mailing response loss leaves no open transaction');

    $raceId=$service->save('','Own original race subject','Own original race body',['own-mailing-ui-person'],bin2hex(random_bytes(16)));
    $raceKey=$service->get($raceId)['preview_hash'];
    $directory=dirname((string)getenv('KH_NATIVE_TEST_CONFIG')).'/mailing-race-'.bin2hex(random_bytes(8));mkdir($directory,0700);
    $workers=[];$raceResults=[];
    try{
        foreach(['A','B']as$tag){
            $process=proc_open([...TestSupport::phpCommand(),__FILE__,'--race'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
            if(!is_resource($process))throw new RuntimeException('Own mailing writer unavailable.');
            fwrite($pipes[0],json_encode(['id'=>$raceId,'key'=>$raceKey,'directory'=>$directory,'tag'=>$tag],JSON_THROW_ON_ERROR));fclose($pipes[0]);
            $workers[$tag]=[$process,$pipes];
        }
        $deadline=microtime(true)+12;
        while((!is_file($directory.'/ready-A')||!is_file($directory.'/ready-B'))&&microtime(true)<$deadline)usleep(10000);
        $check(is_file($directory.'/ready-A')&&is_file($directory.'/ready-B'),'two independent actual mailing writers read the same original before either writes');
    }finally{
        file_put_contents($directory.'/release','release',LOCK_EX);
        foreach($workers as$tag=>[$process,$pipes]){
            $stdout=stream_get_contents($pipes[1]);$stderr=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$exit=proc_close($process);
            $result=json_decode(trim($stdout),true);$raceResults[$tag]=is_array($result)?$result:['accepted'=>false,'kind'=>'invalid'];
            $check($exit===0&&$stderr===''&&is_array($result),'independent mailing writer completes its actual transaction '.$tag);
        }
    }
    $acceptedWriters=array_filter($raceResults,static fn(array $result):bool=>$result['accepted']===true);
    $refusedWriters=array_filter($raceResults,static fn(array $result):bool=>$result['accepted']===false&&($result['kind']??'')==='domain'&&($result['code']??0)===409);
    $check(count($acceptedWriters)===1&&count($refusedWriters)===1,'one actual changed mailing is accepted and the competing original is refused with 409');
    if(count($acceptedWriters)===1){$winner=array_values($acceptedWriters)[0];$saved=$service->get($raceId);$check($winner['same_target']&&$saved['subject']==='Own concurrent mailing '.$winner['tag']&&$saved['body']==='Own complete concurrent body '.$winner['tag'],'the one accepted mailing remains the exact durable winning intention');}

    $recovered=$service->byRequest($key);
    $check($recovered!==null&&$recovered['id']===$accepted['id']&&$recovered['subject']===$accepted['subject'],'conscious read by the original create key finds the actually accepted record without a new draft');
    $check($service->byRequest(bin2hex(random_bytes(16)))===null&&(new ClubMailings($db,'own-mailing-ui-foreign-root'))->byRequest($key)===null,'missing or another club create key never selects a substitute mailing');
    $zeroId=$service->save('','0','0',['own-mailing-ui-person'],bin2hex(random_bytes(16)));
    Db::run($db,"UPDATE club_mailings SET id='0' WHERE id=?",[$zeroId]);$zero=$service->get('0');
    $check($service->save('0','0','0',['own-mailing-ui-person'],$zero['preview_hash'])==='0'&&$service->get('0')['subject']==='0','an actually existing literal-zero identifier and human values remain an update of that same original');
    $oldest=null;
    for($i=1;$i<=26;$i++){
        $id=$service->save('','Own literal catalogue %_! ä🙂 '.$i,'Own earlier complete catalogue body',['own-mailing-ui-person'],bin2hex(random_bytes(16)));
        Db::run($db,'UPDATE club_mailings SET created_at=? WHERE id=?',['2026-01-01T00:00:'.str_pad((string)$i,2,'0',STR_PAD_LEFT).'+00:00',$id]);
        if($i===1)$oldest=$id;
    }
    $first=$service->catalogue();$last=$service->catalogue('','',5);$literal=$service->catalogue('%_! ä🙂');
    $check(count($first['rows'])===6&&$first['total']===30&&$first['pages']===5,'actual mailing catalogue is bounded to six rows across all thirty retained records');
    $check(in_array($oldest,array_column($last['rows'],'id'),true)&&$last['page']===5,'actual fifth page reaches the earliest record beyond the first twenty-five mailings');
    $check($literal['total']===26&&count($literal['rows'])===6,'mailing search treats percent underscore exclamation and Unicode as literal subject text');
    $rawBefore=ownOriginalDatabaseHash($db,['club_mailings']);
    $query=['view'=>'messages','new_mailing'=>'1'];
    $post=['action'=>'mailing-save','mailing_complete'=>'1','mailing'=>'','request_key'=>bin2hex(random_bytes(16)),'subject'=>'0','body'=>'0','merge_fields'=>'','people'=>['own-mailing-ui-person'],'mail_attachments'=>[]];
    $command=MailingForm::command($query,$post);
    $check($command['id']===''&&$command['subject']==='0'&&$command['body']==='0'&&$command['key']===$post['request_key'],'typed mailing form retains literal human zero and the exact originally opened create key');
    $zeroPost=array_replace($post,['mailing'=>'0','request_key'=>$zero['preview_hash']]);
    $check(MailingForm::command(['view'=>'messages','mailing'=>'0'],$zeroPost)['id']==='0','typed literal-zero original cannot become the new-mailing mode');
    $badQueries=[['mailing_page'=>null],['mailing_page'=>'0'],['mailing_page'=>'01'],['mailing_page'=>['1']],['mailing_state'=>null],['mailing_state'=>['draft']],['mailing'=>[]],['new_mailing'=>null],['edit_mailing'=>'1'],['other'=>'1']];
    foreach($badQueries as$i=>$extra){$refused=false;try{MailingForm::query(array_replace(['view'=>'messages'],$extra));}catch(DomainException $failure){$refused=$failure->getCode()===422;}$check($refused,'complete invalid mailing query selects no default page or record '.$i);}
    foreach(['request_key'=>['own'],'subject'=>['whole'=>'Original ä🙂'],'body'=>null,'people'=>null,'mail_attachments'=>null,'mailing_complete'=>true,'action'=>' mailing-save ']as$field=>$value){$refused=false;try{MailingForm::command($query,array_replace($post,[$field=>$value]));}catch(DomainException $failure){$refused=$failure->getCode()===422;}$check($refused,'typed mailing refusal does not coerce the original '.$field);}
    $refused=false;try{MailingForm::command(['view'=>'members'],$post);}catch(DomainException $failure){$refused=$failure->getCode()===422;}$check($refused,'wrong actual page cannot execute a mailing intention');
    $packet=json_encode(MailingForm::originals(array_replace($post,['csrf'=>'Own secret CSRF never reflected','password'=>'Own secret password never reflected','body'=>"Own invalid UTF8 \xff"])),JSON_THROW_ON_ERROR);
    $decoded=json_decode($packet,true,64,JSON_THROW_ON_ERROR);$retained=[];foreach($decoded['entries']as$entry)$retained[$entry['key']['value']]=$entry['value'];
    $check($retained['body']['type']==='bytes'&&base64_decode($retained['body']['base64'],true)==="Own invalid UTF8 \xff"&&!array_key_exists('csrf',$retained)&&!array_key_exists('password',$retained)&&!str_contains($packet,'Own secret CSRF')&&!str_contains($packet,'Own secret password')&&$retained['request_key']['value']===$post['request_key'],'lossless original packet preserves invalid bytes and exact intention without authentication secrets');
    $check(ownOriginalDatabaseHash($db,['club_mailings'])===$rawBefore,'all invalid query form and original-packet reads leave every whole mailing row exact');
    $check(Db::run($db,'SELECT * FROM club_mailings WHERE id=?',[$earlier])->fetch()===$earlierRow,'every earlier whole mailing row remains exact through later errors');
    $check(ownOriginalDatabaseHash($db,$tables)===$before,'mailing edge cases preserve every unrelated whole business and schema row');
        $pageBefore=ownOriginalDatabaseHash($db,$db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN));
    $retained=array_replace($post,['subject'=>str_repeat('ä',130),'body'=>'Own entire editable correction <&> 🙂']);
    $state=\Kulturhus\Verein\Web\MailingPage::prepare($db,'own-mailing-ui-root',$query,$retained,'Betreff vollständig prüfen.',422);
    $check($state['mode']==='edit'&&$state['form']['subject']===$retained['subject']&&$state['form']['key']===$post['request_key'],'recoverable current metadata keeps all overlong editable human values and the exact original create key');
    $state=\Kulturhus\Verein\Web\MailingPage::prepare($db,'own-mailing-ui-root',$query,$retained,'Own unknown outcome',503);
    $check($state['mode']==='retained'&&!$state['retry']&&$state['form']['body']===$retained['body']&&$state['form']['key']===$post['request_key'],'unknown result renders the original human values read-only without manufacturing a retry intention');
    $peek=array_replace($post,['request_key'=>$key,'mailing_recheck'=>'1']);
    $state=\Kulturhus\Verein\Web\MailingPage::prepare($db,'own-mailing-ui-root',$query,$peek);
    $check($state['mode']==='retained'&&$state['mailing']['id']===$accepted['id']&&!$state['retry'],'explicit read-only create outcome check finds the accepted original without an editable duplicate');
    $state=\Kulturhus\Verein\Web\MailingPage::prepare($db,'own-mailing-ui-foreign-root',['view'=>'messages','mailing'=>$accepted['id']]);
    $check($state['status']===404&&$state['mailing']===null&&$state['form']===null,'foreign saved mailing does not become an editable substitute or disclose source values');
    $state=\Kulturhus\Verein\Web\MailingPage::prepare($db,'own-mailing-ui-unprivileged',$query,$retained);
    $check($state['status']===403&&$state['mailing']===null&&$state['form']===null&&$state['originals']!==null,'current unprivileged caller keeps only their submitted packet without any saved source lookup');
    $state=\Kulturhus\Verein\Web\MailingPage::prepare($db,'own-mailing-ui-root',['view'=>'messages','mailing'=>'0']);
    $check($state['mode']==='detail'&&$state['mailing']['id']==='0'&&$state['mailing']['subject']==='0'&&$state['form']===null,'actual saved literal-zero page opens that original and never creates a draft key');
    $peekRefused=false;try{MailingForm::command($query,$peek);}catch(DomainException $failure){$peekRefused=$failure->getCode()===422;}
    $check($peekRefused,'read-only recheck payload cannot be passed to the mutating command parser');
    $check(ownOriginalDatabaseHash($db,$db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN))===$pageBefore,'all outcome and correction page preparations preserve every complete original table');
    // Queueing writes SQL only. No delivery worker or transport is called.
    $environment=[];
    foreach(['KULTURHUS_LOCAL_DEMO'=>'0','KULTURHUS_DISABLE_MAIL'=>'0','VEREIN_MODE'=>'production','APP_ENV'=>'production','APP_URL'=>'https://mailing.example.invalid','VEREIN_PUBLIC_URL'=>'https://mailing.example.invalid']as$name=>$value){$environment[$name]=getenv($name);putenv($name.'='.$value);}
    try{
        $queuedId=$service->save('','Own queued COMMIT fault','Own exact queue message',['own-mailing-ui-person'],bin2hex(random_bytes(16)));
        $queuedHash=$service->get($queuedId)['preview_hash'];$fault->lose=true;$queueFailure=null;
        try{(new ClubMailings($fault,'own-mailing-ui-root'))->queue($queuedId,$queuedHash);}catch(Throwable $failure){$queueFailure=$failure;}
        $queueOriginal=Db::run($db,'SELECT * FROM club_outbox WHERE mailing_id=?',[$queuedId])->fetch();
        $check($service->get($queuedId)['state']==='queued'&&$queueOriginal['body']==="Guten Tag Own literal recipient ä🙂,\n\nOwn exact queue message"&&$queueFailure instanceof PDOException&&$queueFailure->getMessage()==='Own mailing COMMIT accepted before response loss','accepted queue COMMIT retains the exact original outbox and original lost acknowledgement');
        $repeat=false;try{$service->queue($queuedId,$queuedHash);}catch(DomainException $failure){$repeat=$failure->getCode()===409;}
        $check($repeat&&Db::run($db,'SELECT * FROM club_outbox WHERE mailing_id=?',[$queuedId])->fetch()===$queueOriginal,'conscious retry after accepted queue acknowledgement loss creates no second original outbox row');
        $trigger='own_mailing_cancel_fault';
        $db->exec("CREATE TRIGGER `$trigger` BEFORE UPDATE ON club_outbox FOR EACH ROW BEGIN IF OLD.mailing_id='".$queuedId."' AND NEW.state='cancelled' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Own cancellation second write refused'; END IF; END");
        $failed=false;try{$service->cancel($queuedId,$queuedHash);}catch(PDOException){$failed=true;}finally{$db->exec("DROP TRIGGER `$trigger`");}
        $check($failed&&Db::run($db,'SELECT * FROM club_outbox WHERE mailing_id=?',[$queuedId])->fetch()===$queueOriginal&&$service->get($queuedId)['state']==='queued','actual cancellation second SQL failure leaves both original mailing and every complete pending outbox row exact');
        // Restore only this intentionally owned pending row for the independent stale-basis check.
        Db::run($db,"UPDATE club_outbox SET state='pending',updated_at=? WHERE id=?",[$queueOriginal['updated_at'],$queueOriginal['id']]);
        $stale=false;try{$service->cancel($queuedId,str_repeat('f',64));}catch(DomainException $failure){$stale=$failure->getCode()===409;}
        $check($stale&&$service->get($queuedId)['state']==='queued'&&Db::run($db,'SELECT * FROM club_outbox WHERE mailing_id=?',[$queuedId])->fetch()===$queueOriginal,'stale cancellation basis cannot change a queued original');
    }finally{foreach($environment as$name=>$value)putenv($value===false?$name:$name.'='.$value);}
    echo json_encode([
        'suite'=>'native-mailings-interface',
        'checks'=>$checks,
        'failed'=>$failures,
        'actual_nonempty_zero_accepted_as_create'=>$zeroAccepted,
        'actual_accepted_commit_original_stored'=>$accepted!==false,
        'accepted_commit_error_class'=>$caught===null?null:get_class($caught),
        'accepted_commit_error_is_original'=>$caught?->getMessage()==='Own mailing COMMIT accepted before response loss',
        'actual_concurrent_writers'=>$raceResults,
        'protected_tables'=>count($tables),
        'external_actions'=>0,
    ],JSON_THROW_ON_ERROR).PHP_EOL;
    if(getenv('DVM_MAILINGS_INTERFACE_BROWSER')==='1')require __DIR__.'/support/mailings-interface-browser-fixture.php';
}finally{putenv($previousSecret===false?'APP_SECRET':'APP_SECRET='.$previousSecret);}
if($failures)throw new RuntimeException('Mailing original and recovery contracts failed.');
