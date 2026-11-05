<?php declare(strict_types=1);
use Kulturhus\Native\Shared\Db;
use Kulturhus\Native\Delivery\DeliveryNotStarted;
use Kulturhus\Verein\{ContributionReminders,MailPolicy};
use Kulturhus\Verein\Finance\Contributions\{ContributionDeliveryInput,ContributionDeliveryRequests,ContributionRunInput};

/** Actual local preflights and a process exiting after its genuine durable claim. */
function ownContributionDeliveryInterruptions(PDO $db,callable $check,array $f,array $failure,array $config):array
{
    $name=(string)$db->query('SELECT DATABASE()')->fetchColumn();if(!preg_match('/\Akh_(?:test|native)_[a-f0-9]{24}\z/D',$name)||($config['host']??null)!=='127.0.0.1'||!in_array((int)($config['port']??0),[13371,13372],true))throw new RuntimeException('Owned contribution interruption database required.');
    $id=$failure['id'];$requests=new ContributionDeliveryRequests($db,$f['actor']);$origin='https://own-contribution.example.invalid';$variables=['APP_URL','APP_ENV','VEREIN_MODE','KULTURHUS_DISABLE_MAIL','KULTURHUS_LOCAL_DEMO'];$env=[];foreach($variables as$v){$env[$v]=getenv($v);putenv($v);}
    try{
        if(!MailPolicy::allowed($origin))throw new RuntimeException('Owned callback-only interruption policy required.');$worker=new ContributionReminders($db,$f['actor']);$calls=0;
        $sent=$worker->dispatch($origin,static function()use(&$calls):never{++$calls;throw new DeliveryNotStarted('mail_configuration_missing');},ContributionRunInput::today());$pending=Db::run($db,'SELECT * FROM contribution_deliveries WHERE club_id=? AND id=?',[$f['club'],$id])->fetch();
        $check($calls===1&&$sent===0&&$pending['status']==='pending'&&$pending['reason']==='Postfach einrichten','trusted native known-no-send preflight remains an honest pending job without claiming uncertain SMTP');
        $sent=$worker->dispatch($origin,static function()use(&$calls):never{++$calls;throw new DeliveryNotStarted('mail_message_invalid');},ContributionRunInput::today());$stopped=Db::run($db,'SELECT * FROM contribution_deliveries WHERE club_id=? AND id=?',[$f['club'],$id])->fetch();
        $check($calls===2&&$sent===0&&$stopped['status']==='skipped'&&$stopped['reason']==='Nachricht prüfen','trusted invalid local MIME is stopped as definitely unsent and requires conscious correction');
        $raw=ContributionDeliveryInput::initial($id);$raw['operation']='release';$raw['data']['reason']='Own corrected no-send preflight';$plan=$requests->prepare($raw);$released=$requests->accept($raw,($f['key'])(),$plan['hash'],true);
        $root=sys_get_temp_dir().'/dvm-owned-delivery-interruption-'.bin2hex(random_bytes(12));mkdir($root,0700);$script=$root.'/interrupt.php';$marker=$root.'/claim-observed.json';
        $source=dirname(__DIR__,3);$dsn='mysql:host=127.0.0.1;port='.$config['port'].';dbname='.$name.';charset=utf8mb4';
        $code= <<<'PHP'
<?php declare(strict_types=1);
ini_set('zend.exception_ignore_args','1');$f=json_decode(stream_get_contents(STDIN),true,32,JSON_THROW_ON_ERROR);
if(!preg_match('/\Amysql:host=127\.0\.0\.1;port=1337[12];dbname=kh_(?:test|native)_[a-f0-9]{24};charset=utf8mb4\z/D',$f['dsn'])||!str_starts_with($f['marker'],'/tmp/dvm-owned-delivery-interruption-'))throw new RuntimeException('Owned interrupted delivery required.');
require $f['source'].'/vendor/autoload.php';require $f['source'].'/vereinsverwaltung/native/bootstrap.php';require $f['source'].'/vereinsverwaltung/src/bootstrap.php';$db=new PDO($f['dsn'],$f['username'],$f['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
foreach(['APP_URL','APP_ENV','VEREIN_MODE','KULTURHUS_DISABLE_MAIL','KULTURHUS_LOCAL_DEMO']as$v)putenv($v);
(new \Kulturhus\Verein\ContributionReminders($db,$f['actor']))->dispatch('https://own-contribution.example.invalid',static function()use($f,$db):never{$q=$db->prepare('SELECT * FROM contribution_deliveries WHERE id=?');$q->execute([$f['id']]);$row=$q->fetch();file_put_contents($f['marker'],json_encode(['id'=>$row['id'],'status'=>$row['status'],'attempted_at'=>$row['attempted_at'],'smtp_connections'=>0],JSON_THROW_ON_ERROR));chmod($f['marker'],0600);exit(0);},\Kulturhus\Verein\Finance\Contributions\ContributionRunInput::today());
throw new RuntimeException('Owned canonical callback was not reached.');
PHP;
        file_put_contents($script,$code);chmod($script,0600);
        try{
            $process=proc_open([...\Kulturhus\Native\TestSupport::phpCommand(),$script],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);if(!is_resource($process))throw new RuntimeException('Owned interrupted worker unavailable.');
            fwrite($pipes[0],json_encode(['source'=>$source,'dsn'=>$dsn,'username'=>$config['username'],'password'=>$config['password'],'marker'=>$marker,'actor'=>$f['actor'],'id'=>$id],JSON_THROW_ON_ERROR));fclose($pipes[0]);$output=stream_get_contents($pipes[1]);$error=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$status=proc_close($process);if($status!==0)throw new RuntimeException('Owned interrupted worker failed: '.substr($error,0,500));
            $observed=json_decode(file_get_contents($marker),true,32,JSON_THROW_ON_ERROR);$sending=Db::run($db,'SELECT * FROM contribution_deliveries WHERE club_id=? AND id=?',[$f['club'],$id])->fetch();
            $check($observed['id']===$id&&$observed['status']==='sending'&&$sending['status']==='sending'&&$sending['sent_remaining_cents']===4800&&$observed['smtp_connections']===0,'actual independent canonical worker exits after its real durable sending claim before constructing any SMTP transport');
            $hold=ContributionDeliveryInput::initial($id);$hold['operation']='hold';$hold['data']['reason']='Own interrupted worker observed';$before=ownOriginalDatabaseHash($db,$f['tables']);($f['deny'])(fn()=>$requests->prepare($hold),409,'a genuinely fresh active sending claim cannot be reset or prematurely held');
            $check(ownOriginalDatabaseHash($db,$f['tables'])===$before,'fresh sending review never changes the actual interrupted claim');
            // Only the clock input is controlled here; status and attempted amount
            // came from the real independent worker above, never a fabricated claim.
            Db::run($db,'UPDATE contribution_deliveries SET attempted_at=? WHERE club_id=? AND id=?',[gmdate('Y-m-d\TH:i:s\Z',time()-901),$f['club'],$id]);$old=Db::run($db,'SELECT * FROM contribution_deliveries WHERE club_id=? AND id=?',[$f['club'],$id])->fetch();$plan=$requests->prepare($hold);$held=$requests->accept($hold,($f['key'])(),$plan['hash'],true);$expected=$old;$expected['status']='uncertain';$expected['reason']='Unterbrochener Versand zur Klärung vorgemerkt. '.$hold['data']['reason'];
            $check($held['result']===$expected&&$held['review']['source']['delivery']===$old,'controlled old attempt time permits only a conscious uncertain hold while preserving the exact genuinely claimed job');($f['deny'])(fn()=>$requests->prepare($raw),409,'holding an interrupted send never makes it directly retryable');
            $manual=ContributionDeliveryInput::initial($id);$manual['operation']='confirm_not_sent';$manual['data']['reason']='Own callback exited before transport';$manual['data']['evidence']='Owned independent callback marker recorded sending claim then exited before constructing SMTP. No delivery accepted.';$plan=$requests->prepare($manual);$notSent=$requests->accept($manual,($f['key'])(),$plan['hash'],true);
            $check($notSent['result']['status']==='skipped'&&$notSent['result']['sent_at']===null&&$requests->receipt($held['key'])===$held&&$requests->receipt($released['key'])===$released,'explicit evidence clarifies actual interrupted no-send without new transport or rewriting held and released originals');
        }finally{foreach([$script,$marker]as$file)if(is_file($file))unlink($file);rmdir($root);}
    }finally{foreach($env as$v=>$value)putenv($value===false?$v:$v.'='.$value);}
    return compact('held','notSent','released');
}
