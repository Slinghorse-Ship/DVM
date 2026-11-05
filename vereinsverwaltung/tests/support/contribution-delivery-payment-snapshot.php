<?php declare(strict_types=1);
use Kulturhus\Native\Shared\Db;
use Kulturhus\Verein\{ContributionInvoices,ContributionReminders};
use Kulturhus\Verein\Finance\Contributions\ContributionRunInput;

/** Synchronize real independent finance writes with the real preparation reads. */
final class OwnContributionDeliverySnapshotPDO extends PDO
{
    public ?Closure $beforeSource=null;
    public ?Closure $insideMessage=null;
    public function exec(string $statement):int|false
    {
        if($this->beforeSource!==null&&strtoupper(trim($statement))==='START TRANSACTION')foreach(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS,10)as$call)if(($call['class']??null)==='Kulturhus\\Verein\\ContributionReminders'&&($call['function']??null)==='dispatch'){if(($this->beforeSource)())$this->beforeSource=null;break;}
        return parent::exec($statement);
    }
    public function prepare(string $query,array $options=[]):PDOStatement|false
    {
        if($this->insideMessage!==null&&str_contains($query,'JOIN finance_entries'))foreach(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS,8)as$call)if(($call['class']??null)==='Kulturhus\\Verein\\ContributionMail'&&($call['function']??null)==='build'){$hook=$this->insideMessage;$this->insideMessage=null;$hook();break;}
        return parent::prepare($query,$options);
    }
}

function ownContributionDeliveryPaymentSnapshot(PDO $db,callable $check,array $f,array $config):array
{
    $name=(string)$db->query('SELECT DATABASE()')->fetchColumn();if(!preg_match('/\Akh_(?:test|native)_[a-f0-9]{24}\z/D',$name)||($config['host']??null)!=='127.0.0.1'||!in_array((int)($config['port']??0),[13371,13372],true))throw new RuntimeException('Owned payment snapshot database required.');
    $clockMethod=new ReflectionMethod(ContributionReminders::class,'now');
    $check($clockMethod->invoke(null,'2026-10-22',new DateTimeImmutable('2026-10-21T22:30:00Z'))==='2026-10-21T22:30:00Z','actual Berlin midnight keeps the real preceding UTC date instead of creating a future delivery attempt');
    $check($clockMethod->invoke(null,'2026-01-15',new DateTimeImmutable('2026-01-14T23:30:00Z'))==='2026-01-14T23:30:00Z','winter Berlin midnight also retains the true UTC date for interrupted-send age');
    $check($clockMethod->invoke(null,'2026-01-01',new DateTimeImmutable('2026-10-21T22:30:00Z'))==='2026-01-01T22:30:00Z','explicit historical canonical worker-day clock remains compatible with controlled scheduling and thirty-day tests');
    $dsn='mysql:host=127.0.0.1;port='.$config['port'].';dbname='.$name.';charset=utf8mb4';$options=[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false];$variables=['APP_URL','APP_ENV','VEREIN_MODE','KULTURHUS_DISABLE_MAIL','KULTURHUS_LOCAL_DEMO'];$env=[];foreach($variables as$v){$env[$v]=getenv($v);putenv($v);}$timeout=(int)$db->query('SELECT @@session.innodb_lock_wait_timeout')->fetchColumn();$db->exec('SET SESSION innodb_lock_wait_timeout=1');$proofs=[];
    try{
        $texts=$f['settings']->section('texts_contributions');$templates=$texts['data'];$templates['reminder']['attach_pdf']=true;$f['settings']->save('texts_contributions',$templates,$texts['version']);
        foreach(['before-source','inside-message']as$index=>$mode){
            $payer='own-prepared-payment-'.$index;($f['payer'])($payer);$entry=$f['finance']->createAnnualContribution($payer,2026,'','2026-01-01');$original=(new ContributionInvoices($db,$f['actor']))->issue($entry);$originalFile=(new ContributionInvoices($db,$f['actor']))->currentOriginal($entry)['file'];$run=(new ContributionReminders($db,$f['actor']))->run(ContributionRunInput::today(),true);$check($run['queued']===1,'prepared-payment '.$mode.' probe uses one genuinely newly queued canonical reminder');$job=Db::run($db,'SELECT * FROM contribution_deliveries WHERE club_id=? AND entry_id=?',[$f['club'],$entry])->fetch();
            $source=new OwnContributionDeliverySnapshotPDO($dsn,$config['username'],$config['password'],$options);$writes=0;$attempts=0;$blocked=false;$pay=static function()use($f,$entry,&$writes):void{$current=$f['finance']->entry($entry);$f['finance']->recordPayment($entry,(int)$current['version'],$f['account'],ContributionRunInput::today(),'20.00');++$writes;};
            if($mode==='before-source')$source->beforeSource=static function()use($db,$job,$f,$pay,&$attempts):bool{$status=Db::run($db,'SELECT status FROM contribution_deliveries WHERE club_id=? AND id=?',[$f['club'],$job['id']])->fetchColumn();if($status!=='sending')return false;++$attempts;$pay();return true;};
            else $source->insideMessage=static function()use($pay,&$attempts,&$blocked):void{++$attempts;try{$pay();}catch(PDOException $failure){if(!in_array((int)($failure->errorInfo[1]??0),[1205,3572],true))throw $failure;$blocked=true;}};
            $captured=null;$sent=(new ContributionReminders($source,$f['actor']))->dispatch('https://own-contribution.example.invalid',static function($to,$subject,$text,$html,$attachments)use(&$captured,$db,$job,$f,$source,&$blocked,$pay):bool{
                $row=Db::run($db,'SELECT * FROM contribution_deliveries WHERE club_id=? AND id=?',[$f['club'],$job['id']])->fetch();$captured=['no_open_transaction'=>!$source->inTransaction(),'recorded'=>$row['sent_remaining_cents'],'state'=>$row['status'],'has28'=>str_contains($text,'28,00 €'),'has48'=>str_contains($text,'48,00 €'),'pdf_sha256'=>hash('sha256',$attachments[0]['bytes'])];if($blocked)$pay();return false;
            },ContributionRunInput::today());$after=Db::run($db,'SELECT * FROM contribution_deliveries WHERE club_id=? AND id=?',[$f['club'],$job['id']])->fetch();$amount=$mode==='before-source'?2800:4800;
            $check($attempts===1&&$writes===1&&($f['finance']->entry($entry)['remaining_cents']===2800),'prepared-payment '.$mode.' race performs exactly one real independent partial payment without synthetic ledger substitutions');
            $check($captured!==null&&$captured['no_open_transaction']&&$captured['state']==='sending'&&$captured['recorded']===$amount&&$captured[$amount===2800?'has28':'has48']&&!$captured[$amount===2800?'has48':'has28'],'actual prepared message and durable attempt amount agree before callback with no financial locks held during transport boundary: '.$mode);
            $check($sent===0&&$after['status']==='uncertain'&&$after['sent_remaining_cents']===$amount&&$captured['pdf_sha256']===$originalFile['sha256'],'actual unknown acknowledgement retains exactly the prepared amount and original PDF after independent payment: '.$mode);
            $check($blocked===($mode==='inside-message'),'the atomic source and message snapshot excludes a real interleaving finance writer only while preparing: '.$mode);$proofs[]=$after;
        }
        $db->beginTransaction();try{$db->exec("UPDATE clubs SET name='Own earlier caller pending save' WHERE id='own-contribution-native'");$before=ownOriginalDatabaseHash($db,$f['tables']);$calls=0;($f['deny'])(fn()=>(new ContributionReminders($db,$f['actor']))->dispatch('https://own-contribution.example.invalid',static function()use(&$calls):bool{++$calls;return true;}),503,'a caller transaction cannot perform external delivery before its durable job claim');$check($db->inTransaction()&&$calls===0&&ownOriginalDatabaseHash($db,$f['tables'])===$before,'rejected caller transaction retains all earlier work and invokes no delivery callback');}finally{if($db->inTransaction())$db->rollBack();}
    }finally{$db->exec('SET SESSION innodb_lock_wait_timeout='.$timeout);foreach($env as$v=>$value)putenv($value===false?$v:$v.'='.$value);}
    return $proofs;
}
