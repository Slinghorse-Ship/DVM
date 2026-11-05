<?php declare(strict_types=1);
use Kulturhus\Native\Shared\Db;
use Kulturhus\Verein\{Finance,ContributionInvoices,ContributionReminders};
use Kulturhus\Verein\Finance\Contributions\{ContributionDeliveryInput,ContributionDeliveryRequests,ContributionRunInput};

final class OwnContributionDeliveryCommitLoss extends PDO
{
    public bool $loseNextCommit=false;
    public function commit():bool{$result=parent::commit();if($this->loseNextCommit){$this->loseNextCommit=false;throw new RuntimeException('Own accepted outbox commit acknowledgement lost.');}return $result;}
}

/** Current independent payments and roles, real accepted COMMIT loss and NOWAIT. */
function ownContributionDeliveryFailures(PDO $db,callable $check,array $f,array $outbox,array $config):array
{
    $name=(string)$db->query('SELECT DATABASE()')->fetchColumn();if(!preg_match('/\Akh_(?:test|native)_[a-f0-9]{24}\z/D',$name)||($config['host']??null)!=='127.0.0.1'||!in_array((int)($config['port']??0),[13371,13372],true))throw new RuntimeException('Owned contribution delivery failure database required.');
    $dsn='mysql:host=127.0.0.1;port='.$config['port'].';dbname='.$name.';charset=utf8mb4';$username=$config['username'];$password=$config['password'];
    $options=[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false];$reader=new PDO($dsn,$username,$password,$options);$requests=new ContributionDeliveryRequests($db,$f['actor']);
    $job=$outbox['otherJob'];$raw=ContributionDeliveryInput::initial($job['id']);$raw['operation']='release';$raw['data']['reason']='Own actual current payment review';
    $reader->beginTransaction();$reader->query('SELECT COUNT(*) FROM finance_entries')->fetchColumn();
    try{
        $entry=$f['finance']->entry($job['entry_id']);$f['finance']->recordPayment($job['entry_id'],(int)$entry['version'],$f['account'],ContributionRunInput::today(),'20.00');$before=ownOriginalDatabaseHash($db,$f['tables']);
        $current=(new ContributionDeliveryRequests($reader,$f['actor']))->prepare($raw);$check($current['source']['current']['remaining_cents']===2800&&str_contains($current['source']['message']['text'],'28,00 €')&&ownOriginalDatabaseHash($db,$f['tables'])===$before,'outbox release behind an older caller snapshot reads the real independent partial payment and current message without writing');
    }finally{if($reader->inTransaction())$reader->rollBack();}
    $entry=$f['finance']->entry($job['entry_id']);$f['finance']->recordPayment($job['entry_id'],(int)$entry['version'],$f['account'],ContributionRunInput::today(),'28.00');$before=ownOriginalDatabaseHash($db,$f['tables']);$key=($f['key'])();$paid=false;
    try{$requests->accept($raw,$key,$current['hash'],true);}catch(DomainException $failure){$paid=str_contains($failure->getMessage(),'bezahlt');}
    $check($paid&&ownOriginalDatabaseHash($db,$f['tables'])===$before,'actual independent full payment rejects stale release with no job first or audit change');($f['deny'])(fn()=>$requests->receipt($key),404,'rejected paid outbox release invents no private original');

    ($f['payer'])('own-delivery-fault-payer');$entry=$f['finance']->createAnnualContribution('own-delivery-fault-payer',2026,'','2026-01-01');(new ContributionInvoices($db,$f['actor']))->issue($entry);$run=(new ContributionReminders($db,$f['actor']))->run(ContributionRunInput::today(),true);
    $check($run['queued']===1,'failure probes use one genuinely newly queued canonical reminder after all prior actual payment outcomes');$id=(string)Db::run($db,'SELECT id FROM contribution_deliveries WHERE club_id=? AND entry_id=?',[$f['club'],$entry])->fetchColumn();
    $pause=ContributionDeliveryInput::initial($id);$pause['operation']='cancel';$pause['data']['reason']='Own commit-loss preparation';$plan=$requests->prepare($pause);$paused=$requests->accept($pause,($f['key'])(),$plan['hash'],true);
    $release=ContributionDeliveryInput::initial($id);$release['operation']='release';$release['data']['reason']='Own real accepted commit';$review=$requests->prepare($release);$key=($f['key'])();$loss=new OwnContributionDeliveryCommitLoss($dsn,$username,$password,$options);$loss->loseNextCommit=true;$lost=false;
    try{(new ContributionDeliveryRequests($loss,$f['actor']))->accept($release,$key,$review['hash'],true);}catch(RuntimeException $failure){$lost=$failure->getMessage()==='Own accepted outbox commit acknowledgement lost.';}
    $first=$requests->receipt($key);$check($lost&&$first['result']['status']==='pending'&&$first['result']['id']===$id,'actual committed canonical job and private first survive a lost COMMIT acknowledgement');$before=ownOriginalDatabaseHash($db,$f['tables']);
    $check($requests->accept($release,$key,$review['hash'],true)===$first&&ownOriginalDatabaseHash($db,$f['tables'])===$before,'same outbox key after real COMMIT loss reads exact first without another job or audit');
    $actorRole=Db::run($db,'SELECT * FROM settings_roles WHERE club_id=? AND id=?',[$f['club'],$f['financeRole']])->fetch();$reader->beginTransaction();Db::run($reader,'SELECT * FROM settings_roles WHERE club_id=? AND id=?',[$f['club'],$f['financeRole']])->fetch();
    try{
        $f['settings']->saveRole($f['financeRole'],(int)$actorRole['version'],array_replace($f['financeRoleData'],['finance_access'=>'read']));$before=ownOriginalDatabaseHash($db,$f['tables']);($f['deny'])(fn()=>(new ContributionDeliveryRequests($reader,$f['actor']))->prepare($pause),403,'independent real write-role withdrawal closes new outbox decisions behind an older caller snapshot');
        $check($requests->accept($release,$key,$review['hash'],true)===$first&&ownOriginalDatabaseHash($db,$f['tables'])===$before,'actual read-only author still reads the accepted original after COMMIT loss without writing');
    }finally{if($reader->inTransaction())$reader->rollBack();$f['settings']->saveRole($f['financeRole'],(int)$actorRole['version']+1,$f['financeRoleData']);}
    foreach(['clubs'=>['SELECT id FROM clubs WHERE id=? FOR UPDATE',[$f['club']]],'contribution_deliveries'=>['SELECT id FROM contribution_deliveries WHERE club_id=? AND id=? FOR UPDATE',[$f['club'],$id]],'finance_entries'=>['SELECT id FROM finance_entries WHERE club_id=? AND id=? FOR UPDATE',[$f['club'],$entry]]]as$table=>[$sql,$args]){
        $before=ownOriginalDatabaseHash($db,$f['tables']);$reader->beginTransaction();
        try{Db::run($reader,$sql,$args)->fetch();($f['deny'])(fn()=>$requests->prepare($pause),503,'actual independent '.$table.' lock rejects outbox review as busy without claiming known no-send');}finally{if($reader->inTransaction())$reader->rollBack();}
        $check(ownOriginalDatabaseHash($db,$f['tables'])===$before,'outbox contention on '.$table.' preserves all complete actual rows');
    }
    $base=ContributionDeliveryInput::initial($id);$base['operation']='confirm_sent';$base['data']['reason']='0';$base['data']['evidence']="0\nOwn complete evidence";$base['data']['sent_at']='2025-10-26T02:30';
    ($f['deny'])(fn()=>ContributionDeliveryInput::normalize($base),422,'ambiguous Berlin local delivery minute requires an explicit clock-change choice');$summer=$base;$summer['data']['sent_offset']='+02:00';$winter=$base;$winter['data']['sent_offset']='+01:00';
    $summerResult=ContributionDeliveryInput::normalize($summer);$winterResult=ContributionDeliveryInput::normalize($winter);$check($summerResult['sent_at']==='2025-10-26T00:30:00Z'&&$winterResult['sent_at']==='2025-10-26T01:30:00Z','explicit two Berlin occurrences retain different actual UTC minutes without guessing a fold');
    foreach(['2026-03-29T02:30',"2026-01-01T12:00\0",'2026-02-31T12:00','2099-01-01T12:00']as$bad){$raw=$base;$raw['data']['sent_at']=$bad;($f['deny'])(fn()=>ContributionDeliveryInput::normalize($raw),422,'whole nonexistent invalid NUL or future delivery minute is rejected before any decision');}
    $raw=$base;$raw['data']['reason']=str_repeat('🙂',2001);$check(ContributionDeliveryInput::capture($raw)===$raw,'whole overlimit Unicode decision remains capturable without clipping');($f['deny'])(fn()=>ContributionDeliveryInput::normalize($raw),422,'two-thousand-and-one complete Unicode characters require correction');
    $check($requests->receipt($first['key'])===$first&&$requests->receipt($paused['key'])===$paused&&$f['requests']->receipt($f['first']['key'])===$f['first'],'current payment role lock and clock probes retain every earlier private decision and annual first');
    return compact('id','entry','first','paused');
}
