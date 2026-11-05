<?php declare(strict_types=1);
use Kulturhus\Native\Shared\Db;
use Kulturhus\Verein\Finance\Contributions\{ContributionDeliveryInput,ContributionDeliveryRequests};

/** Twenty-six conscious decisions on one actual canonical job, never twenty-six sends. */
function ownContributionDeliveryHistory(PDO $db,callable $check,array $f,array $outbox):array
{
    require_once __DIR__.'/outgoing-invoice-snapshots.php';
    $requests=new ContributionDeliveryRequests($db,$f['actor']);$job=$outbox['otherJob']['id'];$firsts=[];
    for($index=0;$index<26;++$index){
        $previous=Db::run($db,'SELECT * FROM contribution_deliveries WHERE club_id=? AND id=?',[$f['club'],$job])->fetch();
        $old=ownOutgoingInvoiceEarlierRows($db,$f['tables']);$filter=static function(string $table)use($old,$job):array{[$where,$args]=$old($table);return $table==='contribution_deliveries'?[$where.' AND id<>?',[...$args,$job]]:[$where,$args];};$before=ownOriginalDatabaseHash($db,$f['tables'],$filter);
        $raw=ContributionDeliveryInput::initial($job);$raw['operation']=$previous['status']==='skipped'?'release':'cancel';$raw['data']['reason']='Own history 0 '.$index;$plan=$requests->prepare($raw);$first=$requests->accept($raw,($f['key'])(),$plan['hash'],true);$expected=$previous;
        if($raw['operation']==='release'){
            $expected['status']='pending';$expected['actor_id']=$f['actor'];$expected['scheduled_for']=\Kulturhus\Verein\Finance\Contributions\ContributionRunInput::today();$expected['recipient_contact_id']=$plan['source']['current']['recipient']['id'];$expected['recipient_email']=$plan['source']['current']['recipient_email'];$expected['attempted_at']=null;$expected['sent_at']=null;$expected['sent_remaining_cents']=null;$expected['reason']='Bewusst erneut vorgemerkt. '.$raw['data']['reason'];
        }else{$expected['status']='skipped';$expected['reason']='Vormerkung bewusst angehalten. '.$raw['data']['reason'];}
        $check($first['result']===$expected&&ownOriginalDatabaseHash($db,$f['tables'],$filter)===$before,'actual personal outbox decision '.($index+1).' retains every whole earlier row except its exact conscious canonical job fields');$firsts[]=$first;
    }
    $before=ownOriginalDatabaseHash($db,$f['tables']);$page1=$requests->history($job,['query'=>'Own history 0','page'=>'1']);$page2=$requests->history($job,['query'=>'Own history 0','page'=>'2']);
    $check($page1['total']===26&&count($page1['rows'])===25&&count($page2['rows'])===1&&$page2['page']===2,'twenty-six actual outbox decisions have genuine twenty-five-plus-one personal history');
    $missing=$requests->history($job,['query'=>'Own history 0','page'=>'999999']);$check($missing['page']===null&&$missing['rows']===[]&&$missing['page_input']==='999999','unavailable outbox decision page never substitutes another first');
    ($f['deny'])(fn()=>$requests->history($job,['query'=>"\n0",'page'=>'1']),422,'whole invalid outbox decision search is not silently replaced');
    foreach($firsts as$first)if($requests->receipt($first['key'])!==$first)throw new RuntimeException('Earlier personal outbox first changed.');
    $check($requests->receipt($outbox['first']['key'])===$outbox['first']&&$requests->receipt($outbox['released']['key'])===$outbox['released']&&ownOriginalDatabaseHash($db,$f['tables'])===$before,'every whole personal outbox first remains byte exact through all history reads');
    $listing=$requests->listing(['status'=>'skipped','year'=>'2026']);$check($listing['total']>=1&&count(array_filter($listing['rows'],static fn(array $row):bool=>$row['id']===$job))===1,'whole common outbox status and year filters return the actual existing canonical job');
    $absent=$requests->listing(['page'=>'999999']);$check($absent['page']===null&&$absent['rows']===[]&&$absent['page_input']==='999999','absent common outbox page never substitutes a different job');
    foreach([['status'=>'0'],['year'=>'0'],['query'=>"\n0 😀"],['page'=>'0']]as$bad)($f['deny'])(fn()=>$requests->listing($bad),422,'whole invalid common outbox control remains rejected: '.array_key_first($bad));
    $check(ownOriginalDatabaseHash($db,$f['tables'])===$before,'whole common catalogue reads change no actual job first invoice or payment');
    return compact('job','firsts');
}
