<?php declare(strict_types=1);
ini_set('zend.exception_ignore_args','1');
require dirname(__DIR__).'/native/bootstrap.php';
require dirname(__DIR__).'/src/bootstrap.php';
require __DIR__.'/support/original-database-hash.php';
use Kulturhus\Native\{TestSupport,Shared\Access,Shared\Db,Installer\SchemaRegistry};
use Kulturhus\Native\Communication\{Workspace,EditionSaveRequests};

final class OwnSocialCommitFault extends PDO
{
    public string $mode='none';
    public function commit():bool
    {
        $mode=$this->mode;$this->mode='none';
        if($mode==='declined'){parent::rollBack();throw new PDOException('Own declined social COMMIT');}
        $ok=parent::commit();
        if($mode==='accepted')throw new PDOException('Own accepted social COMMIT acknowledgement lost');
        if($mode==='kill')posix_kill(posix_getpid(),SIGKILL);
        return $ok;
    }
}
$cfg=TestSupport::config();
if(($cfg['owner']??'')!=='isolated-native-ci'||($cfg['label']??'')!=='native-social-save-requests')throw new RuntimeException('Own social save fixture required.');
$db=TestSupport::db();
$connection=static fn()=>new OwnSocialCommitFault('mysql:host=127.0.0.1;port=13371;dbname='.$cfg['database'].';charset=utf8mb4',$cfg['username'],$cfg['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
if(in_array($argv[1]??'',['--race','--kill'],true)){
    $input=json_decode(stream_get_contents(STDIN),true,64,JSON_THROW_ON_ERROR);
    $db=$argv[1]==='--kill'?$connection():$db;
    if($argv[1]==='--kill')$db->mode='kill';
    if(isset($input['start']))while(microtime(true)<$input['start'])usleep(1000);
    $saved=(new EditionSaveRequests($db,new Access($db,'admin'),null,'social'))->save($input['data'],0,$input['key']);
    echo json_encode(['id'=>$saved['id'],'version'=>$saved['version']],JSON_THROW_ON_ERROR);exit;
}
$n=0;
$check=static function(bool $ok,string $label)use(&$n):void{if(!$ok)throw new RuntimeException($label);++$n;echo 'PASS '.$label.PHP_EOL;};
$deny=static function(callable $fn,string $label)use($check):void{try{$fn();}catch(DomainException){$check(true,$label);return;}throw new RuntimeException($label);};
$check($db->query('SHOW TABLES')->fetchAll()===[],'fresh owned database only');
SchemaRegistry::install($db);TestSupport::seed($db);$access=new Access($db,'admin');
$work=new Workspace($db,$access,'social');
$old=$work->save(['title'=>'Own earlier social original 0 ä🙂','body'=>'Own earlier literal Suchen Text','kind'=>'post']);
$oldRows=ownOriginalDatabaseHash($db,['khn_social_items','khn_social_revisions'],static fn(string $table):array=>[' WHERE '.($table==='khn_social_items'?'id':'item_id').'=?',[$old['id']]]);
$sharedKey=bin2hex(random_bytes(16));$newsletter=new EditionSaveRequests($db,$access);$other=$newsletter->save(['title'=>'Own earlier newsletter original','body'=>'Own retained newsletter'],0,$sharedKey);
Db::run($db,"INSERT INTO native_users(id,club_id,name) VALUES('own-social-reader',?,'Own read only'),('own-social-editor',?,'Own other editor'),('own-social-foreign','own-social-foreign-club','Own foreign')",[$access->club(),$access->club()]);
foreach(['own-social-reader'=>['read'],'own-social-editor'=>['read','edit'],'own-social-foreign'=>['read','edit']]as$actor=>$capabilities)foreach($capabilities as$capability)Db::run($db,'INSERT INTO native_grants(actor_id,module,capability) VALUES(?,?,?)',[$actor,'social',$capability]);
$protected=array_values(array_diff($db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN),['khn_social_items','khn_social_revisions','native_communication_save_requests']));$before=ownOriginalDatabaseHash($db,$protected);
$otherReceipts=ownOriginalDatabaseHash($db,['native_communication_save_requests'],static fn()=>[" WHERE module<>'social'",[]]);
$service=new EditionSaveRequests($db,$access,null,'social');
$counts=static fn()=>[(int)$db->query('SELECT COUNT(*) FROM khn_social_items')->fetchColumn(),(int)$db->query('SELECT COUNT(*) FROM khn_social_revisions')->fetchColumn(),(int)$db->query("SELECT COUNT(*) FROM native_communication_save_requests WHERE module='social'")->fetchColumn()];
$base=['id'=>'','title'=>'Own exact social title ä🙂 <literal>','summary'=>'Own human caption 0','body_html'=>'<p>0 Own <strong>exact</strong> body ä🙂</p>','blocks'=>[['type'=>'heading','text'=>'Own heading Text']],'channels'=>['facebook','instagram'],'image_design'=>['layout'=>'editorial','palette'=>'forest','focal_x'=>25,'focal_y'=>75],'destination_url'=>'','story_link_requested'=>false];
foreach(['post','story','reel','carousel']as$kind){
    $input=$base+['kind'=>$kind,'template'=>'social-'.$kind,'story'=>$kind==='story'?[['title'=>'Own story title','text'=>'Own story body 0','media_ref'=>'']]:[],'carousel'=>$kind==='carousel'?[['title'=>'Own first card','text'=>'Own first literal text','media_ref'=>''],['title'=>'Own second card','text'=>'Own second text ä🙂','media_ref'=>'']]:[]];
    if($kind==='story'){$input['destination_url']='https://example.invalid/own-story?text=0';$input['story_link_requested']=true;}
    $key=$kind==='post'?$sharedKey:bin2hex(random_bytes(16));$numbers=$counts();$saved=$service->save($input,0,$key);$after=$counts();
    $check($saved['kind']===$kind&&$saved['status']==='draft'&&$saved['version']===1&&$after===array_map(static fn(int $v):int=>$v+1,$numbers),'one real original draft and receipt for '.$kind);
    $check($saved['title']===$input['title']&&$saved['body']==='0 Own exact body ä🙂'&&$saved['channels']===$input['channels']&&$saved['image_design']['focal_x']===25,'human text channels and image positioning remain original for '.$kind);
    $check($service->save($input,0,$key)===$saved&&$service->review($key)===$saved&&$counts()===$after,'exact replay and read-only lookup preserve '.$kind);
    $reverse=array_reverse($input,true);$reverse['image_design']=array_reverse($reverse['image_design'],true);
    $check($service->save($reverse,0,$key)===$saved&&$counts()===$after,'object key order changes no original meaning for '.$kind);
    foreach(['title'=>['title'=>'Own changed title'],'channel'=>['channels'=>['facebook']],'image'=>['image_design'=>['layout'=>'editorial','palette'=>'forest','focal_x'=>26,'focal_y'=>75]]]as$change=>$different)$deny(fn()=>$service->save(array_replace($input,$different),0,$key),'same key refuses changed '.$change.' for '.$kind);
    if($kind==='story')$deny(fn()=>$service->save(array_replace($input,['destination_url'=>'https://example.invalid/other']),0,$key),'same story key refuses a substituted link sticker destination');
    if($kind==='carousel'){$changed=$input;$changed['carousel']=array_reverse($input['carousel']);$deny(fn()=>$service->save($changed,0,$key),'same carousel key refuses changed card order');}
    $check($counts()===$after,'rejected changes write nothing for '.$kind);
    if($kind==='post'){
        $postInput=$input;$postKey=$key;$postSaved=$saved;
        $check($newsletter->review($sharedKey)===$other&&$saved['id']!==$other['id'],'same actor key in newsletter remains isolated from social');
    }
}
$numbers=$counts();$second=$work->save(['id'=>$postSaved['id'],'title'=>'Own later approved post','body'=>'Own later exact content','kind'=>'post'],1);$approved=$work->approve($second['id'],2);$numbers=$counts();
$check($approved['status']==='approved'&&$service->save($postInput,0,$postKey)===$postSaved&&$work->get($approved['id'])===$approved&&$counts()===$numbers,'old accepted save cannot revoke or replace a later approved social original');
$deny(fn()=>(new EditionSaveRequests($db,new Access($db,'own-social-reader'),null,'social'))->save($base,0,bin2hex(random_bytes(16))),'read-only access never gains social write authority');
foreach(['own-social-reader','own-social-editor','own-social-foreign']as$actor)$check((new EditionSaveRequests($db,new Access($db,$actor),null,'social'))->review($postKey)===null,'another identity cannot look up the actor-bound receipt '.$actor);
$deny(fn()=>$service->save($postInput,0,'not-an-own-key'),'malformed request key rejected');
$deny(fn()=>$service->save($postInput,0,bin2hex(random_bytes(16)),[['path'=>__FILE__,'name'=>'own.php']]),'social files require their separate actual media workflow');
$deny(fn()=>$service->save($postInput+['document_attachments'=>[]],0,bin2hex(random_bytes(16))),'social save cannot accept a newsletter attachment contract');
$fault=$connection();$faultService=new EditionSaveRequests($fault,new Access($fault,'admin'),null,'social');
foreach(['accepted','declined']as$mode){
    $key=bin2hex(random_bytes(16));$input=array_replace($postInput,['title'=>'Own '.$mode.' acknowledgement']);$numbers=$counts();$fault->mode=$mode;$declined=false;
    try{$saved=$faultService->save($input,0,$key);}catch(PDOException){$declined=true;}
    if($mode==='accepted')$check(!$declined&&$service->review($key)===$saved&&$faultService->save($input,0,$key)===$saved&&$counts()===array_map(static fn(int $v):int=>$v+1,$numbers),'actual accepted COMMIT with lost acknowledgement returns one original');
    else{$check($declined&&$service->review($key)===null&&$counts()===$numbers,'actual declined COMMIT retains no partial item revision or receipt');$saved=$faultService->save($input,0,$key);$check($saved['version']===1,'conscious retry after definitely declined COMMIT creates one first original');}
}
$key=bin2hex(random_bytes(16));$numbers=$counts();$db->exec("CREATE TRIGGER own_social_receipt_abort BEFORE INSERT ON native_communication_save_requests FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Own social receipt abort'");$failed=false;
try{$service->save($postInput,0,$key);}catch(PDOException){$failed=true;}finally{$db->exec('DROP TRIGGER own_social_receipt_abort');}
$check($failed&&$counts()===$numbers&&$service->review($key)===null,'actual receipt SQL failure rolls back item and revision together');
$key=bin2hex(random_bytes(16));$numbers=$counts();$workers=[];$start=microtime(true)+.3;
for($i=0;$i<2;$i++){$process=proc_open([...TestSupport::phpCommand(),__FILE__,'--race'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);if(!is_resource($process))throw new RuntimeException('Own social worker unavailable.');fwrite($pipes[0],json_encode(['data'=>$postInput,'key'=>$key,'start'=>$start],JSON_THROW_ON_ERROR));fclose($pipes[0]);$workers[]=[$process,$pipes];}
$results=[];foreach($workers as[$process,$pipes]){$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);if(proc_close($process)!==0||$err!=='')throw new RuntimeException('Own social parallel worker failed.');$results[]=json_decode($out,true,32,JSON_THROW_ON_ERROR);}
$check($results[0]===$results[1]&&$counts()===array_map(static fn(int $v):int=>$v+1,$numbers),'two actual PHP workers create exactly one same social original');
$key=bin2hex(random_bytes(16));$numbers=$counts();$process=proc_open([...TestSupport::phpCommand(),__FILE__,'--kill'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);if(!is_resource($process))throw new RuntimeException('Own killed worker unavailable.');fwrite($pipes[0],json_encode(['data'=>$postInput,'key'=>$key],JSON_THROW_ON_ERROR));fclose($pipes[0]);$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$exit=proc_close($process);$saved=$service->review($key);
$check($exit!==0&&$out===''&&$err===''&&$saved!==null&&$service->save($postInput,0,$key)===$saved&&$counts()===array_map(static fn(int $v):int=>$v+1,$numbers),'real process kill after COMMIT preserves exact original and releases the connection lock');
$row=Db::run($db,"SELECT * FROM native_communication_save_requests WHERE module='social' AND request_key=?",[$postKey])->fetch();Db::run($db,"UPDATE native_communication_save_requests SET snapshot_json=? WHERE module='social' AND request_key=?",['{}',$postKey]);$deny(fn()=>$service->review($postKey),'altered receipt cannot substitute another social original');Db::run($db,"UPDATE native_communication_save_requests SET snapshot_json=? WHERE module='social' AND request_key=?",[$row['snapshot_json'],$postKey]);
Db::run($db,"DELETE FROM native_grants WHERE actor_id='admin' AND module='social' AND capability='edit'");$deny(fn()=>$service->save($postInput,0,$postKey),'current revoked edit authority also blocks an accepted replay');Db::run($db,"INSERT INTO native_grants VALUES('admin','social','edit')");
$check(ownOriginalDatabaseHash($db,$protected)===$before&&ownOriginalDatabaseHash($db,['native_communication_save_requests'],static fn()=>[" WHERE module<>'social'",[]])===$otherReceipts,'every other business row and earlier edition receipt remains exact');
$check(ownOriginalDatabaseHash($db,['khn_social_items','khn_social_revisions'],static fn(string $table):array=>[' WHERE '.($table==='khn_social_items'?'id':'item_id').'=?',[$old['id']]])===$oldRows,'all earlier unrelated social item and revision bytes remain exact');
$form=['id'=>'','version'=>'0','title'=>'Own retained form','summary'=>'0','body'=>'0 exact plain body ä🙂','kind'=>'story','template'=>'social-story','save_request_key'=>bin2hex(random_bytes(16)),'channels'=>['facebook'],'story_slides'=>[['title'=>'Own first','text'=>'Own first text','media_ref'=>''],['title'=>'Own second','text'=>'Own second text','media_ref'=>'']]];
$restored=Kulturhus\Native\Web\Communication\SocialComposerState::restore($form)['document'];$check($restored['save_request_key']===$form['save_request_key']&&$restored['body']===$form['body']&&!isset($restored['body_html']),'ordinary plain form recovery retains exact body and save key');
$state=Kulturhus\Native\Web\Communication\SocialComposerState::browseStory($form+['story_browse'=>'up:1'])['document'];$check($state['story']===array_reverse($form['story_slides'])&&$state['save_request_key']===$form['save_request_key'],'NoJS slide movement preserves exact human rows and original save key');
$state=Kulturhus\Native\Web\Communication\SocialComposerState::browseStory($form+['story_browse'=>'add'])['document'];$check(count($state['story'])===3&&$state['story'][2]===['title'=>'','text'=>'','media_ref'=>''],'NoJS adds one editable empty placeholder without saving');
$check(Kulturhus\Native\Web\Communication\SocialComposerState::slides(['story_slides'=>[['title'=>'','text'=>'','media_ref'=>'']]],true)===[],'a wholly empty NoJS placeholder is not invented authored content');
$deny(fn()=>Kulturhus\Native\Web\Communication\SocialComposerState::browseStory(array_replace($form,['story_slides'=>array_fill(0,10,$form['story_slides'][0]),'story_browse'=>'add'])),'NoJS keeps the same ten-slide maximum');
$deny(fn()=>Kulturhus\Native\Web\Communication\SocialComposerState::restore(array_replace($form,['save_request_key'=>'bad'])),'invalid keys cannot be substituted during form recovery');
if(getenv('DVM_SOCIAL_SAVE_BROWSER')==='1'){
    require __DIR__.'/support/social-save-browser-fixture.php';
    $browser=ownSocialSaveBrowser($db);
    $tables=$db->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_TYPE='BASE TABLE' ORDER BY TABLE_NAME")->fetchAll(PDO::FETCH_COLUMN);
    $oldIds=$db->query('SELECT id FROM khn_social_items')->fetchAll(PDO::FETCH_COLUMN);
    $oldKeys=$db->query('SELECT request_key FROM native_communication_save_requests')->fetchAll(PDO::FETCH_COLUMN);
    $sessionHashes=array_map(static fn(string $token):string=>hash('sha256',$token),array_values($browser['input']['tokens']));
    $filter=static function(string $table)use($oldIds,$oldKeys,$sessionHashes):array{
        if($table==='native_auth_sessions')return [' WHERE token_hash NOT IN (?,?)',$sessionHashes];
        if(in_array($table,['khn_social_items','khn_social_revisions'],true))return [' WHERE '.($table==='khn_social_items'?'id':'item_id').' IN ('.implode(',',array_fill(0,count($oldIds),'?')).')',$oldIds];
        if($table==='native_communication_save_requests')return [' WHERE request_key IN ('.implode(',',array_fill(0,count($oldKeys),'?')).')',$oldKeys];
        return ['',[]];
    };
    $wholeBefore=ownOriginalDatabaseHash($db,$tables,$filter);$beforeCounts=$counts();
    $keyHashes=[];foreach(['auth','private']as$name)$keyHashes[$name]=hash_file('sha256',$browser['root'].'/'.$name.'.key');
    $proof=sys_get_temp_dir().'/dvm-social-save-'.gmdate('Ymd-His').'-'.getmypid();mkdir($proof,0700);
    try{
        $process=proc_open(['node',__DIR__.'/social-save-requests-browser.mjs'],[0=>['pipe','r'],1=>STDOUT,2=>STDERR],$pipes);
        if(!is_resource($process))throw new RuntimeException('Own social browser unavailable.');
        fwrite($pipes[0],json_encode($browser['input'],JSON_THROW_ON_ERROR));fclose($pipes[0]);$exit=proc_close($process);
        foreach(['social-save-results.json','social-save-failure.png','social-save-failure.html','http.log']as$file)if(is_file($browser['root'].'/'.$file))copy($browser['root'].'/'.$file,$proof.'/'.$file);
        echo json_encode(['social_save_browser_evidence'=>$proof],JSON_THROW_ON_ERROR).PHP_EOL;
        if($exit!==0)throw new RuntimeException('Actual social save browser failed.');
        $result=json_decode(file_get_contents($proof.'/social-save-results.json'),true,64,JSON_THROW_ON_ERROR);
        $number=getenv('DVM_SOCIAL_SAVE_SMOKE')==='1'?2:32;
        $check(count($result['saves'])===$number&&$result['external_actions']===0&&$result['browser_errors']===[],'every actual format and personal language workflow finishes without external actions or browser errors');
        $check(ownOriginalDatabaseHash($db,$tables,$filter)===$wholeBefore,'all prior complete business rows schema grants accounts and receipt originals remain exact after actual HTTP replay and lookup');
        foreach($keyHashes as$name=>$hash)$check(hash_file('sha256',$browser['root'].'/'.$name.'.key')===$hash,'original private '.$name.' key remains exact');
        $check($counts()===[$beforeCounts[0]+$number,$beforeCounts[1]+2*$number,$beforeCounts[2]+2*$number],'each actual browser workflow creates one draft and exactly two conscious revisions and receipts');
        $seenIds=[];$seenKeys=[];
        foreach($result['saves']as$save){
            $actor=$save['mode']==='native'?$browser['input']['native_actors'][$save['locale']]:$browser['input']['manager_ids'][$save['locale']];
            $ownAccess=new Access($db,$actor);$workspace=new Workspace($db,$ownAccess,'social');$first=$workspace->revision($save['id'],1);$current=$workspace->get($save['id']);
            $check($first['title']===$save['title']&&$current['title']===$save['title'].' later'&&$first['body']===$save['body']&&$current['body']===$save['body']&&$current['kind']===$save['kind']&&$current['version']===2&&$current['status']==='draft','actual '.$save['mode'].'/'.$save['locale'].'/'.($save['js']?'JS':'NoJS').'/'.$save['kind'].' retains exact first and consciously edited originals');
            foreach(['key'=>1,'edit_key'=>2]as$field=>$version){
                $receipt=Db::run($db,'SELECT * FROM native_communication_save_requests WHERE club_id=? AND module=? AND actor_id=? AND request_key=?',[$ownAccess->club(),'social',$actor,$save[$field]])->fetch();
                $check($receipt&&$receipt['item_id']===$save['id']&&(int)$receipt['item_version']===$version&&!in_array($save[$field],$oldKeys,true)&&hash_equals($receipt['snapshot_sha256'],hash('sha256',$receipt['snapshot_json'])),'actual browser form key binds one intact own social version '.$version);
                $seenKeys[]=$save[$field];
            }
            if(in_array($save['kind'],['story','carousel'],true)){
                $cards=$current[$save['kind']==='story'?'story':'carousel'];$prefix=$save['js']?'Own first ':'Own second ';
                $check(count($cards)===2&&$cards[0]['title']===$prefix.$save['kind'],'actual visible slide order survives save and retry');
            }
            $seenIds[]=$save['id'];
        }
        $check(count(array_unique($seenIds))===$number&&count(array_unique($seenKeys))===2*$number,'every real new draft and conscious edit is represented by its own distinct original key');
    }finally{proc_terminate($browser['process']);proc_close($browser['process']);fclose($browser['log']);}
}
echo json_encode(['suite'=>'native-social-save-requests','checks'=>$n,'parallel_workers'=>2,'real_process_kills'=>1,'external_actions'=>0],JSON_THROW_ON_ERROR).PHP_EOL;
