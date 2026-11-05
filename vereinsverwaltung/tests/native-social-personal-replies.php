<?php declare(strict_types=1);
require dirname(__DIR__) . '/native/bootstrap.php';
use Kulturhus\Native\TestSupport;
use Kulturhus\Native\Shared\{Access, Db, SecretBox, RuntimeSchema};
use Kulturhus\Native\Communication\{Attention, PersonalActivity, Replies};
use Kulturhus\Native\Integration\StadtLandFunk\{Connection, Login, Http};

$db=TestSupport::db();TestSupport::seed($db);RuntimeSchema::install($db);
$access=new Access($db,'admin');$box=new SecretBox(str_repeat('p',32));$club=$access->club();
$n=0;$calls=[];$mode='normal';$comments=[];$post='own_event';$next=0;$onWrite=null;
function check(bool $ok,string $label):void{global $n;if(!$ok)throw new RuntimeException($label);++$n;echo "PASS $label\n";}
function denied(callable $fn,string $label):void{try{$fn();}catch(DomainException){check(true,$label);return;}throw new RuntimeException($label);}
$identity=['account_id'=>'own_person','home_area_id'=>'own_area','connection_id'=>str_repeat('b',32)];
// Encrypted JSON key order differs deliberately from the normalized identity.
$data=array_reverse($identity,true)+['access_token'=>'synthetic-private-token','refresh_token'=>'synthetic-refresh','expires_at'=>time()+7200,'refresh_expires_at'=>time()+86400,'actor_id'=>'admin','authority'=>'native','username'=>'synthetic@example.test'];
$persist=static function(array $value)use($db,$box,$club):void{Db::run($db,"INSERT INTO native_private_settings(club_id,purpose,version,ciphertext,updated_by) VALUES(?,'api-stadtlandfunk',1,?,'admin') ON DUPLICATE KEY UPDATE ciphertext=VALUES(ciphertext),version=version+1",[$club,$box->seal('api:'.$club.':stadtlandfunk',$value)]);};
$persist($data);
$http=static function($method,$url,$headers,$body)use(&$calls,&$mode,&$comments,$db,$club,$post,&$next,&$onWrite,$persist,$data):array{
 $path=parse_url($url,PHP_URL_PATH);parse_str(parse_url($url,PHP_URL_QUERY)??'',$query);
 check(str_starts_with($url,Http::API.'/')&&in_array('Authorization: Bearer synthetic-private-token',$headers,true)&&in_array('appVariantIdentifier: '.Login::APP,$headers,true),'personal adapter uses its fixed app origin and private token');
 $calls[]=[$method,$path,$body];$response=static fn(array $value,int $status=200):array=>['status'=>$status,'headers'=>[],'body'=>json_encode($value,JSON_THROW_ON_ERROR)];
 if($method==='GET'&&$path==='/grapevine/post/own')return $response(['content'=>[['id'=>$post,'happening'=>['creator'=>['id'=>'own_person'],'title'=>'Eigener Originaltermin','text'=>'Unveränderter Originaltext','created'=>1791453600000,'startTime'=>1794663600000,'endTime'=>1794670800000,'likeCount'=>0,'commentCount'=>count($comments)]]],'page'=>['number'=>0,'size'=>50,'totalElements'=>1,'totalPages'=>1]]);
 if($method==='GET'&&$path==='/grapevine/comment'){
  check(($query['postId']??null)===$post,'independent read targets the exact original event');
  if($mode==='revoke-read')$db->exec("DELETE FROM native_grants WHERE actor_id='admin' AND module='social' AND capability='publish'");
  if($mode==='revoke-owner-read')$db->exec("DELETE FROM native_grants WHERE actor_id='admin' AND module='website' AND capability='manage'");
  if($mode==='reconnect-read')$persist(array_replace($data,['connection_id'=>str_repeat('d',32)]));
  $rows=array_values($comments);
  if($mode==='duplicate-read'&&$rows)$rows[]=$rows[0];
  if($mode==='foreign-read'&&$rows)$rows[0]['postId']='another_event';
  return $response($rows);
 }
 if($method!=='POST'||$path!=='/grapevine/comment/event/commentCreateRequest')throw new RuntimeException('Unexpected personal provider route.');
 $form=json_decode($body,true,16,JSON_THROW_ON_ERROR);
 check(array_keys($form)===['postId','replyToCommentId','text']&&$form['postId']===$post&&isset($comments[$form['replyToCommentId']]),'observed app write keeps exact post, original comment and approved text');
 $second=TestSupport::db();check((int)Db::run($second,"SELECT COUNT(*) FROM native_social_replies WHERE club_id=? AND status='sending'",[$club])->fetchColumn()===1,'send intent is committed before personal provider write');
 if($onWrite)($onWrite)();
 if($mode==='timeout')throw new RuntimeException('Synthetic lost response');
 if($mode==='reject')return $response(['errorCode'=>'COMMENT_NOT_ALLOWED'],403);
 if($mode==='server-error')return $response(['error'=>'synthetic'],503);
 if($mode==='unreadable')return ['status'=>403,'headers'=>[],'body'=>'not a complete provider error'];
 if($mode==='rate-limit')return $response(['errorCode'=>'RATE_LIMIT'],429);
 $id='own_reply_'.++$next;
 $comments[$id]=['id'=>$id,'postId'=>$post,'text'=>$mode==='wrong-text'?'Anbietertext weicht ab':$form['text'],'creator'=>['id'=>$mode==='wrong-author'?'foreign_person':'own_person'],'replyTo'=>['id'=>$mode==='wrong-recipient'?'foreign_guest':$comments[$form['replyToCommentId']]['creator']['id']]];
 if($mode==='missing-id')return $response(['comment'=>[]]);
 if($mode==='conflicting-id')return $response(['commentId'=>$id,'comment'=>['id'=>'another_reply']]);
 return $response(['commentId'=>$id,'comment'=>$comments[$id]]);
};
$connection=new Connection($db,$box,new Login($http));
$activity=new PersonalActivity($db,$box,$connection);$attention=new Attention($db,$box);
$replies=new Replies($db,$box,static function(){throw new RuntimeException('Personal reply must never call Meta.');},$connection);
$prepare=static function(string $suffix)use(&$comments,$activity,$attention,$access,$post):array{
 $id='question_'.$suffix;$comments[$id]=['id'=>$id,'postId'=>$post,'text'=>'Private ursprüngliche Frage '.$suffix,'creator'=>['id'=>'guest_'.$suffix,'firstName'=>'Synthetischer','lastName'=>'Gast'],'created'=>1791453600000];
 $activity->sync($access);foreach($attention->listing($access,'all')as$row)if($row['payload']['remote_id']===$id)return $row;throw new RuntimeException('Original not in current review.');
};
$save=static function(string $suffix)use($prepare,$replies,$access):array{$source=$prepare($suffix);return $replies->save($access,$source['id'],(int)$source['version'],'Geprüfte persönliche Antwort '.$suffix);};
$source=$prepare('first');$before=count($calls);$draft=$replies->save($access,$source['id'],(int)$source['version'],'Geprüfte private Antwort ä🙂');
check(count($calls)===$before&&$draft['status']==='draft'&&$draft['channel']==='stadtlandfunk','private personal draft makes no provider request');
check($draft['connection_id']===$identity['connection_id']&&$draft['payload']['original']['creator_id']==='guest_first','original person and exact personal connection are pinned');
$stored=Db::run($db,'SELECT ciphertext FROM native_social_replies WHERE id=?',[$draft['id']])->fetchColumn();
check(!str_contains($stored,'Geprüfte private Antwort')&&!str_contains($stored,'ursprüngliche Frage'),'original and reply text remain authenticated encrypted data');
denied(fn()=>(new Replies($db,new SecretBox(str_repeat('x',32))))->get($access,$draft['id']),'wrong key cannot decrypt personal reply');
denied(fn()=>$replies->get(new Access($db,'reader'),$draft['id']),'reader gets no private personal reply');
denied(fn()=>$replies->save($access,$source['id'],(int)$source['version'],'Duplicate'),'one original connection has one durable draft');
denied(fn()=>$replies->send($access,$draft['id'],1,false),'explicit personal reply confirmation required');
check(count($calls)===$before,'unconfirmed personal reply does not even read provider');
$sent=$replies->send($access,$draft['id'],1,true);
check($sent['status']==='sent'&&$sent['remote_id']==='own_reply_1','personal reply succeeds only after independent exact text/person/event proof');
check(count($calls)===$before+3&&array_column(array_slice($calls,$before),0)===['GET','POST','GET'],'personal send reads original, writes once, then independently reads reply');
check($replies->source($access,$source['id'])['state']==='done','only confirmed personal reply completes original review');
$before=count($calls);denied(fn()=>$replies->send($access,$draft['id'],1,true),'confirmed personal reply cannot be repeated');check(count($calls)===$before,'duplicate send makes no provider request');
denied(fn()=>$replies->save($access,$source['id'],(int)$source['version'],'Overwrite',$sent['id'],(int)$sent['version']),'confirmed original reply snapshot cannot be overwritten');
$proof=$replies->reconcile($access,$sent['id'],(int)$sent['version']);check($proof['confirmed']&&count($calls)===$before+1&&$calls[array_key_last($calls)][0]==='GET','personal reconciliation reads only');
foreach(['edited','deleted','author','flagged','duplicate-read','foreign-read','revoke-read','revoke-owner-read','reconnect-read']as$case){
 $mode='normal';$r=$save($case);$original=$r['payload']['original']['remote_id'];$copy=$comments[$original];
 if($case==='edited')$comments[$original]['text']='Anderer Originaltext';
 if($case==='deleted')unset($comments[$original]);
 if($case==='author')$comments[$original]['creator']['id']='another_guest';
 if($case==='flagged')$comments[$original]['filterStatus']='FLAGGED';
 $mode=in_array($case,['duplicate-read','foreign-read','revoke-read','revoke-owner-read','reconnect-read'],true)?$case:'normal';$before=count(array_filter($calls,fn($c)=>$c[0]==='POST'));
 denied(fn()=>$replies->send($access,$r['id'],1,true),'current '.$case.' stops personal reply');
 check(count(array_filter($calls,fn($c)=>$c[0]==='POST'))===$before&&$replies->get($access,$r['id'])['status']==='draft','no provider write or durable intent after '.$case);
 $comments[$original]=$copy;$mode='normal';$persist($data);$db->exec("INSERT IGNORE INTO native_grants VALUES('admin','social','publish'),('admin','website','manage')");
}
foreach(['reject','timeout','server-error','unreadable','rate-limit','wrong-text','wrong-author','wrong-recipient','missing-id','conflicting-id']as$case){
 $mode='normal';$r=$save($case);$mode=$case;$result=$replies->send($access,$r['id'],1,true);$expected=$case==='reject'?'rejected':'uncertain';
 check($result['status']===$expected,'personal '.$case.' preserves '.$expected.' result without retry');
 $before=count($calls);denied(fn()=>$replies->send($access,$r['id'],(int)$result['version'],true),'personal '.$case.' cannot accidentally replay');check(count($calls)===$before,'personal '.$case.' replay performs no network request');
 if(in_array($case,['wrong-text','wrong-author','wrong-recipient'],true)){
  $comments[$result['remote_id']]['text']=$result['payload']['text'];$comments[$result['remote_id']]['creator']['id']='own_person';$comments[$result['remote_id']]['replyTo']['id']=$result['payload']['original']['creator_id'];$mode='normal';
  $proof=$replies->reconcile($access,$r['id'],(int)$result['version']);check($proof['confirmed']&&$proof['reply']['status']==='sent'&&count($calls)===$before+1,'independent later '.$case.' proof confirms existing reply without re-send');
 }
}
$mode='normal';$r=$save('reconnect');$persist(array_replace($data,['connection_id'=>str_repeat('c',32)]));$before=count($calls);
denied(fn()=>$replies->send($access,$r['id'],1,true),'new connection cannot receive old prepared personal reply');check(count($calls)===$before,'changed personal target blocks before provider read');
$persist($data);
foreach(['account_id','home_area_id']as$key){$persist(array_replace($data,[$key=>'another_target']));denied(fn()=>$replies->connection($access,$r['attention_id']),'changed '.$key.' requires original re-sync even if binding was reused');$persist($data);}
$db->exec("UPDATE native_users SET active=0 WHERE id='admin'");denied(fn()=>$replies->send($access,$r['id'],1,true),'disabled current account stops personal reply');$db->exec("UPDATE native_users SET active=1 WHERE id='admin'");
$db->exec("CREATE TRIGGER personal_reply_authority_revoked AFTER UPDATE ON native_social_replies FOR EACH ROW BEGIN IF NEW.status='sending' AND OLD.status='draft' THEN DELETE FROM native_grants WHERE actor_id='admin' AND module='social' AND capability='publish'; END IF; END");
$before=count(array_filter($calls,fn($c)=>$c[0]==='POST'));denied(fn()=>$replies->send($access,$r['id'],1,true),'revocation after durable intent blocks personal write');
check($replies->get($access,$r['id'])['status']==='blocked'&&count(array_filter($calls,fn($c)=>$c[0]==='POST'))===$before,'blocked personal intent has no provider write');$db->exec('DROP TRIGGER personal_reply_authority_revoked');$db->exec("INSERT IGNORE INTO native_grants VALUES('admin','social','publish')");
$r=$save('parallel');
$onWrite=static function()use($r,$box):void{
 $second=TestSupport::db();$other=new Replies($second,$box,static function(){throw new RuntimeException('Concurrent Meta write forbidden.');});
 denied(fn()=>$other->send(new Access($second,'admin'),$r['id'],1,true),'concurrent second sender sees committed personal intent before any transport');
};
check($replies->send($access,$r['id'],1,true)['status']==='sent','one actual personal reply survives concurrent attempted sender');$onWrite=null;
$source=$prepare('versions');$before=count($calls);$r=$replies->save($access,$source['id'],(int)$source['version'],str_repeat('ä',2000));
check($r['payload']['text']===str_repeat('ä',2000)&&count($calls)===$before,'personal draft character limit preserves complete UTF-8 without provider read');
denied(fn()=>$replies->save($access,$source['id'],(int)$source['version'],str_repeat('ä',2001),$r['id'],1),'personal reply rejects excessive character length');
denied(fn()=>$replies->save($access,$source['id'],(int)$source['version']+1,'Stale comment',$r['id'],1),'personal draft cannot bind a stale source version');
$r=$replies->save($access,$source['id'],(int)$source['version'],'Aktuelle persönliche Antwort',$r['id'],1);
denied(fn()=>$replies->save($access,$source['id'],(int)$source['version'],'Stale reply',$r['id'],1),'stale personal draft cannot overwrite newer exact text');
denied(fn()=>$replies->send($access,$r['id'],1,true),'stale personal send version makes no request');
denied(fn()=>$replies->get(new Access($db,'outsider'),$r['id']),'different club cannot access personal reply');
$before=count($calls);denied(fn()=>(new Login($http))->reply('synthetic-private-token','../invalid','question','Text'),'personal writer rejects arbitrary provider paths');
check(count($calls)===$before,'invalid personal transport input sends no request');
check((int)$db->query('SELECT COUNT(*) FROM native_delivery_jobs')->fetchColumn()===0,'manual personal replies never enter automatic publishing or mailing queues');
echo "$n personal reply checks passed; no live provider writes.\n";
