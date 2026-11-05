<?php declare(strict_types=1);
require dirname(__DIR__).'/native/bootstrap.php';
use Kulturhus\Native\TestSupport;
use Kulturhus\Native\Shared\{Access,Db,SecretBox,RuntimeSchema};
use Kulturhus\Native\Communication\{Attention,Replies,MessageReplyProvider};
use Kulturhus\Native\Integration\Accounts;

$db=TestSupport::db();TestSupport::seed($db);RuntimeSchema::install($db);
$access=new Access($db,'admin');$box=new SecretBox(str_repeat('m',32));$accounts=new Accounts($db,$box);
$settings=['page_id'=>'123','instagram_id'=>'456','api_version'=>'v25.0','token'=>'synthetic-message-only'];
$accounts->save($access,'meta',$settings,0);$account=$accounts->get($access->club(),'meta');
$attention=new Attention($db,$box);$calls=[];$remote=[];$mode='normal';$n=0;$postCount=0;$serial=0;
function check(bool $value,string $text):void{global$n;if(!$value)throw new RuntimeException($text);$n++;echo 'PASS '.$text."\n";}
function denied(callable $fn,string $text):void{try{$fn();}catch(DomainException){check(true,$text);return;}throw new RuntimeException($text);}
$prepare=static function(string $channel,int $age=60)use($db,$access,$box,$attention,&$remote,&$serial,&$account):array{
 $suffix=(string)++$serial;$conversation='thread_'.$suffix;$message='original_'.$suffix;$guest='guest_'.$suffix;$sender=$channel==='facebook'?'123':'456';$date=gmdate('Y-m-d H:i:s',time()-$age);
 $row=['id'=>$message,'text'=>'Original question '.$suffix,'from_name'=>'Original guest','from_id'=>$guest,'incoming'=>true,'created_at'=>$date];
 $thread=MessageReplyProvider::threadId($access->club(),$channel,$conversation);
 $payload=['conversation'=>$conversation,'recipient'=>$guest,'page_id'=>'123','instagram_id'=>'456','connection_id'=>Accounts::binding($account),'messages'=>[$row]];
 Db::run($db,'INSERT INTO native_social_inbox VALUES(?,?,?,?,?,UTC_TIMESTAMP())',[$access->club(),$thread,$channel,$box->seal('social-inbox:'.$access->club().':'.$thread,$payload),$date]);
 $attention->observe($access,$channel,'message',$conversation,[$row],true);
 $remote[$conversation]=['id'=>$conversation,'participants'=>['data'=>[['id'=>$guest],['id'=>$sender]]],'is_owner'=>true];
 $remote[$message]=['id'=>$message,'message'=>$row['text'],'created_time'=>str_replace(' ','T',$date).'+0000','from'=>['id'=>$guest],'to'=>['data'=>[['id'=>$sender]]]];
 return ['thread'=>$thread,'attention'=>substr(hash('sha256',$access->club().':'.$channel.':message:'.$conversation.':'.$message),0,32),'conversation'=>$conversation,'original'=>$message,'guest'=>$guest,'sender'=>$sender,'date'=>$date,'payload'=>$payload,'channel'=>$channel];
};
$http=static function(string $method,string $path,array $headers,?array $body)use(&$calls,&$remote,&$mode,&$postCount,$db,$access):array{
 $calls[]=[$method,$path,$body];check($headers===['Authorization: Bearer synthetic-message-only'],'fixed Page token stays in the authenticated header');
 $id=explode('?',substr($path,strlen('/v25.0/')))[0];
 if($method==='GET'){
  if($id==='me')return ['status'=>200,'body'=>['id'=>$mode==='wrong-token'?'999':'123','instagram_business_account'=>['id'=>$mode==='wrong-ig-account'?'999':'456']]];
  $value=$remote[$id]??[];
  if(str_starts_with($id,'thread_')){
   if($mode==='not-owner')$value['is_owner']=false;
   if($mode==='unknown-owner')$value['is_owner']=null;
   if($mode==='routing-disabled')unset($value['is_owner']);
   if($mode==='group')$value['participants']['data'][]=['id'=>'third_guest'];
   if($mode==='wrong-conversation')$value['id']='foreign_thread';
   if($mode==='wrong-participant')$value['participants']['data'][0]['id']='other_guest';
  }
  if(str_starts_with($id,'original_')){
   if($mode==='changed')$value['message']='Changed original';
   if($mode==='deleted')$value=[];
   if($mode==='wrong-author')$value['from']['id']='other_guest';
   if($mode==='wrong-original-target')$value['to']['data'][0]['id']='other_account';
   if($mode==='wrong-original-time')$value['created_time']='2050-01-01T00:00:00+0000';
   if($mode==='missing-time')unset($value['created_time']);
   if($mode==='revoke-during-read')Db::run($db,"DELETE FROM native_grants WHERE actor_id='admin' AND module='social' AND capability='publish'");
  }
  if(str_starts_with($id,'sent_')){
   if($mode==='lost-proof')$value['message']='Wrong proof';
   if($mode==='wrong-proof-author')$value['from']['id']='other_account';
   if($mode==='wrong-proof-target')$value['to']['data'][0]['id']='other_guest';
   if($mode==='multiple-proof-targets')$value['to']['data'][]=['id'=>'other_guest'];
   if($mode==='old-proof')$value['created_time']='2000-01-01T00:00:00+0000';
   if($mode==='future-proof')$value['created_time']='2050-01-01T00:00:00+0000';
  }
  return ['status'=>200,'body'=>$value];
 }
 $postCount++;
 check($path==='/v25.0/123/messages','both Facebook Login channels publish through the bound Page endpoint');
 $other=TestSupport::db();check((int)$other->query("SELECT COUNT(*) FROM native_social_replies WHERE status='sending'")->fetchColumn()===1,'exactly one committed send intent is independently visible before the POST');
 $intent=$other->query("SELECT id,version FROM native_social_replies WHERE status='sending'")->fetch();
 $parallel=new Replies($other,new SecretBox(str_repeat('m',32)),static function(){throw new RuntimeException('Concurrent sender must never call provider');});
 denied(fn()=>$parallel->send(new Access($other,'admin'),$intent['id'],(int)$intent['version'],true),'independent concurrent sender cannot duplicate the in-flight message');
 if($mode==='timeout')throw new RuntimeException('Synthetic timeout after write');
 if($mode==='reject')return ['status'=>403,'body'=>['error'=>['code'=>10]]];
 if($mode==='throttle')return ['status'=>429,'body'=>['error'=>['code'=>613]]];
 if($mode==='transient-flag')return ['status'=>400,'body'=>['error'=>['code'=>10,'is_transient'=>true]]];
 if($mode==='transient')return ['status'=>400,'body'=>['error'=>['code'=>2]]];
 if($mode==='server-error')return ['status'=>503,'body'=>['error'=>['code'=>1]]];
 $id='sent_'.$postCount;$target=$body['recipient']['id'];$sender=isset($body['messaging_type'])?'123':'456';
 $remote[$id]=['id'=>$id,'message'=>$body['message']['text'],'created_time'=>gmdate('Y-m-d\TH:i:sO'),'from'=>['id'=>$sender],'to'=>['data'=>[['id'=>$target]]]];
 return ['status'=>200,'body'=>['recipient_id'=>$mode==='wrong-recipient'?'another_guest':$target]+($mode==='missing-id'?[]:['message_id'=>$id])];
};
$service=new Replies($db,$box,$http);
foreach(['facebook','instagram']as$channel){
 $fixture=$prepare($channel);$source=$service->messageSource($access,$fixture['thread']);
 check($source['id']===$fixture['attention']&&$source['payload']['sender_id']===$fixture['guest'],'message source retains original sender and conversation '.$channel);
 $before=count($calls);$draft=$service->save($access,$source['id'],(int)$source['version'],'Human answer <script>not executable</script>');
 check(count($calls)===$before&&$draft['status']==='draft'&&$draft['payload']['kind']==='message','private human draft makes no API request '.$channel);
 denied(fn()=>$service->send($access,$draft['id'],1,false),'unchecked send makes no provider request '.$channel);
 check(count($calls)===$before,'confirmation failure is entirely local '.$channel);
 $sent=$service->send($access,$draft['id'],1,true);check($sent['status']==='sent'&&$sent['remote_id']!==null,'actual sender/recipient/text/time proof confirms the message '.$channel);
 $posts=array_values(array_filter($calls,static fn($call)=>$call[0]==='POST'));$post=end($posts);
 check($post[2]['recipient']===['id'=>$fixture['guest']]&&$post[2]['message']===['text'=>$draft['payload']['text']],'exact human message reaches the actual original recipient '.$channel);
 check($channel==='facebook'?($post[2]['messaging_type']??null)==='RESPONSE':!isset($post[2]['messaging_type']),'channel-specific request follows the documented Page-token contract '.$channel);
 $count=$postCount;denied(fn()=>$service->send($access,$sent['id'],(int)$sent['version'],true),'confirmed message cannot be replayed '.$channel);check($postCount===$count,'second sender causes no duplicate POST '.$channel);
 check($service->reconcile($access,$sent['id'],(int)$sent['version'])['confirmed'],'later GET-only proof confirms the same message '.$channel);
}
foreach(['facebook','instagram']as$channel){
 $fixture=$prepare($channel);$source=$service->messageSource($access,$fixture['thread']);$draft=$service->save($access,$source['id'],(int)$source['version'],'Human answer without conversation routing');$mode='routing-disabled';
 check($service->send($access,$draft['id'],1,true)['status']==='sent','documented absent ownership field allows ordinary conversations without routing '.$channel);$mode='normal';
}
foreach(['wrong-token','wrong-ig-account','not-owner','unknown-owner','group','wrong-conversation','wrong-participant','changed','deleted','wrong-author','wrong-original-target','wrong-original-time','missing-time','revoke-during-read']as$failure){
 $fixture=$prepare('instagram');$source=$service->messageSource($access,$fixture['thread']);$draft=$service->save($access,$source['id'],(int)$source['version'],'Own checked reply');$mode=$failure;$before=$postCount;
 denied(fn()=>$service->send($access,$draft['id'],1,true),'preflight stops '.$failure);check($postCount===$before,'preflight sends nothing for '.$failure);$mode='normal';
 Db::run($db,"INSERT IGNORE INTO native_grants VALUES('admin','social','publish')");
}
foreach([86400,86401,172800,-3600]as$age){$fixture=$prepare('facebook',$age);$source=$service->messageSource($access,$fixture['thread']);$draft=$service->save($access,$source['id'],(int)$source['version'],'Human reply');$count=$postCount;denied(fn()=>$service->send($access,$draft['id'],1,true),'closed or future original window stops '.$age);check($postCount===$count,'window refusal precedes any message POST '.$age);}
foreach(['reject','timeout','throttle','transient','transient-flag','server-error','wrong-recipient','missing-id','lost-proof','wrong-proof-author','wrong-proof-target','multiple-proof-targets','old-proof','future-proof']as$failure){
 $fixture=$prepare('facebook');$source=$service->messageSource($access,$fixture['thread']);$draft=$service->save($access,$source['id'],(int)$source['version'],'Own reply '.$failure);$mode=$failure;$result=$service->send($access,$draft['id'],1,true);
 check($result['status']===($failure==='reject'?'rejected':'uncertain'),'durable result remains accurate for '.$failure);
 $count=$postCount;denied(fn()=>$service->send($access,$result['id'],(int)$result['version'],true),'unreviewed replay refused for '.$failure);check($postCount===$count,'failed/uncertain state never causes a second POST for '.$failure);
 if($failure==='lost-proof'){$mode='normal';check($service->reconcile($access,$result['id'],(int)$result['version'])['confirmed'],'independent later proof restores success without another write');check($postCount===$count,'reconciliation is GET only');}
 $mode='normal';
}
$fixture=$prepare('instagram');$source=$service->messageSource($access,$fixture['thread']);
denied(fn()=>$service->save($access,$source['id'],(int)$source['version'],str_repeat('ä',501)),'Instagram UTF-8 byte limit is enforced while saving');
denied(fn()=>$service->save($access,$source['id'],(int)$source['version'],str_repeat('a',1000)),'Instagram conservative character limit is enforced while saving');
$draft=$service->save($access,$source['id'],(int)$source['version'],str_repeat('ä',500));check(strlen($draft['payload']['text'])===1000,'1000 exact UTF-8 bytes are retained');
denied(fn()=>$service->save($access,$source['id'],(int)$source['version'],'stale',$draft['id'],0),'stale message draft cannot overwrite the actual answer');
denied(fn()=>$service->get(new Access($db,'reader'),$draft['id']),'ordinary reader cannot inspect private messages or drafts');
denied(fn()=>$service->get(new Access($db,'other'),$draft['id']),'message drafts cannot cross clubs');
$fixtureSpace=$prepare('facebook');$remote[$fixtureSpace['original']]['message']="  Human original\n ";
$attention->observe($access,'facebook','message',$fixtureSpace['conversation'],[['id'=>$fixtureSpace['original'],'text'=>"  Human original\n ",'from_id'=>$fixtureSpace['guest'],'created_at'=>$fixtureSpace['date']]],true);
$sourceSpace=$service->messageSource($access,$fixtureSpace['thread']);$draftSpace=$service->save($access,$sourceSpace['id'],(int)$sourceSpace['version'],"  Human response\n ");
check($draftSpace['payload']['original']['text']==="  Human original\n "&&$draftSpace['payload']['text']==="  Human response\n ",'exact original and human whitespace is preserved rather than rewritten');
check($service->send($access,$draftSpace['id'],1,true)['status']==='sent','actual API proof accepts exact multiline originals and human answers');
$cipher=Db::run($db,'SELECT ciphertext FROM native_social_replies WHERE id=?',[$draft['id']])->fetchColumn();check(!str_contains($cipher,'Original guest')&&!str_contains($cipher,'guest_'),'message identities and original text remain encrypted at rest');
$db->exec("CREATE TRIGGER message_revoke_intent AFTER UPDATE ON native_social_replies FOR EACH ROW BEGIN IF NEW.status='sending' AND OLD.status='draft' THEN DELETE FROM native_grants WHERE actor_id='admin' AND module='social' AND capability='publish'; END IF; END");
$count=$postCount;denied(fn()=>$service->send($access,$draft['id'],(int)$draft['version'],true),'revocation after committed intent stops the write');check($postCount===$count&&$service->get($access,$draft['id'])['status']==='blocked','post-intent refusal is recorded as blocked and sends nothing');$db->exec('DROP TRIGGER message_revoke_intent');
Db::run($db,"INSERT IGNORE INTO native_grants VALUES('admin','social','publish')");
$flood=[];for($i=0;$i<201;$i++)$flood[]=['id'=>'filter_message_'.$i,'text'=>'Own incoming original '.$i,'from_id'=>'filter_guest','created_at'=>gmdate('Y-m-d H:i:s')];
$attention->observe($access,'facebook','message','filter_conversation',$flood,true);
$attention->observe($access,'facebook','comment','filter_post',[['id'=>'filter_comment','text'=>'Own comment remains available','name'=>'Own reader']],true);
$comments=$attention->listing($access,'all',['comment','reaction']);
check(count($comments)===1&&$comments[0]['payload']['remote_id']==='filter_comment','SQL filters comments before the 200-row limit even with 201 newer private messages');
denied(fn()=>$attention->listing($access,'all',[]),'empty activity-kind filter is rejected');
denied(fn()=>$attention->listing($access,'all',['unknown']),'unknown activity-kind filter is rejected');
$fixture=$prepare('facebook');$source=$service->messageSource($access,$fixture['thread']);$draft=$service->save($access,$source['id'],(int)$source['version'],'Bound account reply');
$status=$accounts->status($access,'meta');$accounts->save($access,'meta',array_replace($settings,['page_id'=>'789']),$status['version']);$count=$postCount;
denied(fn()=>$service->send($access,$draft['id'],1,true),'reconnected account cannot inherit an old conversation or draft');check($postCount===$count,'reconnection causes no provider POST');
check((int)$db->query('SELECT COUNT(*) FROM native_delivery_jobs')->fetchColumn()===0,'message answers never create an automatic job');
echo $n." native message reply checks passed; synthetic MySQL, no live provider action.\n";
