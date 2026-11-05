<?php declare(strict_types=1);
/** Private loopback fixture operations; never registered as a product route. */
function ownContributionBrowserOperation(array $fixture,array $config):never
{
    if(($config['owner']??null)!=='isolated-native-ci'||($config['label']??null)!=='native-contribution-run-interface'||(int)$config['port']!==13371||!preg_match('/\Akh_native_[a-f0-9]{24}\z/D',$config['database'])||($_SERVER['REQUEST_METHOD']??'')!=='POST'||!is_string($_POST['nonce']??null)||!hash_equals($fixture['data']['nonce'],$_POST['nonce'])||!is_string($_POST['case']??null)||!preg_match('/\A[0-8]\z/D',$_POST['case'])){http_response_code(403);exit;}
    $index=(int)$_POST['case'];$operation=$_POST['operation']??null;$path=$fixture['root'].'/contribution-case-'.$index.'.json';$db=\Kulturhus\Native\TestSupport::db();$club='own-contribution-native';$actor='own-contribution-finance';
    if($fixture['club']!==$club){http_response_code(403);exit;}
    if($operation==='seed'){
        if(is_file($path))$result=json_decode(file_get_contents($path),true,16,JSON_THROW_ON_ERROR);
        else{
            $payer='own-run-browser-'.$index;$contact=\Kulturhus\Native\Shared\Db::run($db,'SELECT id FROM contacts WHERE club_id=? AND id=?',[$club,$payer])->fetchColumn();if($contact){http_response_code(409);exit;}
            \Kulturhus\Native\Shared\Db::run($db,"INSERT INTO contacts(id,club_id,kind,name,email,address)VALUES(?,?,'person',?,?,?)",[$payer,$club,'Own browser contribution payer '.$index,$payer.'@example.invalid','Testweg 2, 00000 Musterort']);\Kulturhus\Native\Shared\Db::run($db,"INSERT INTO memberships(id,club_id,contact_id,member_number,status,starts_on)VALUES(?,?,?,?,'aktiv','2025-01-01')",['membership-'.$payer,$club,$payer,'OWN-RUN-'.$index]);
            $entry=(new \Kulturhus\Verein\Finance($db,$actor))->createAnnualContribution($payer,2026,'','2026-01-01');$invoice=(new \Kulturhus\Verein\ContributionInvoices($db,$actor))->issue($entry);$original=(new \Kulturhus\Verein\ContributionInvoices($db,$actor))->currentOriginal($entry);$result=['case'=>$index,'payer'=>$payer,'entry'=>$entry,'document'=>$invoice['document_id'],'revision'=>$original['file']['revision'],'sha256'=>$original['file']['sha256']];file_put_contents($path,json_encode($result,JSON_THROW_ON_ERROR));chmod($path,0600);
        }
    }elseif($operation==='legacy'){
        if($index!==8||!is_file($path)){http_response_code(403);exit;}$seed=json_decode(file_get_contents($path),true,16,JSON_THROW_ON_ERROR);$run=(new \Kulturhus\Verein\ContributionReminders($db,$actor))->run(\Kulturhus\Verein\Finance\Contributions\ContributionRunInput::today(),true);$row=\Kulturhus\Native\Shared\Db::run($db,"SELECT * FROM contribution_deliveries WHERE club_id=? AND entry_id=? AND type='reminder'",[$club,$seed['entry']])->fetch();if(!$row||$run['created']!==0||$run['queued']!==1){http_response_code(409);exit;}$result=['actual_canonical_queued'=>1,'delivery'=>$row['id'],'entry'=>$seed['entry']];
    }elseif(in_array($operation,['capture','restore'],true)){
        $cookie=$_COOKIE['KH_NATIVE_MANAGER']??null;if(!is_string($cookie)||!preg_match('/\A[a-zA-Z0-9,-]{16,128}\z/D',$cookie)){http_response_code(403);exit;}$session=$fixture['root'].'/sessions/sess_'.$cookie;$saved=$fixture['root'].'/contribution-session-'.$index.'.json';
        if($operation==='capture'){
            if(!is_file($session)||is_link($session)){http_response_code(403);exit;}$bytes=file_get_contents($session);file_put_contents($saved,json_encode(['cookie'=>$cookie,'bytes'=>base64_encode($bytes),'sha256'=>hash('sha256',$bytes)],JSON_THROW_ON_ERROR));chmod($saved,0600);$result=['whole_private_session_captured'=>true];
        }else{
            if(!is_file($saved)||is_link($saved)||!is_file($session)||is_link($session)){http_response_code(403);exit;}$previous=json_decode(file_get_contents($saved),true,8,JSON_THROW_ON_ERROR);$bytes=base64_decode($previous['bytes'],true);if($previous['cookie']!==$cookie||!is_string($bytes)||!hash_equals($previous['sha256'],hash('sha256',$bytes))){http_response_code(403);exit;}file_put_contents($session,$bytes);chmod($session,0600);$result=['exact_previous_private_session_restored'=>true];
        }
    }else{http_response_code(403);exit;}
    header('Content-Type: application/json');header('Cache-Control: private, no-store');echo json_encode($result,JSON_THROW_ON_ERROR);exit;
}
