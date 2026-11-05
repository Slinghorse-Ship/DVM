<?php declare(strict_types=1);
require dirname(__DIR__).'/native/bootstrap.php';
require dirname(__DIR__,2).'/vendor/autoload.php';
use Kulturhus\Native\TestSupport;
use Kulturhus\Native\Shared\{Access,Db,SecretBox,RuntimeSchema};
use Kulturhus\Native\Communication\{Attention,Replies,MessageReplyProvider};
use Kulturhus\Native\Communication\Mail\{Settings,Bridge,Dispatcher,ReplyText,Mime};
use Kulturhus\Native\Integration\Accounts;
use Kulturhus\Native\Integration\StadtLandFunk\{Connection,Login};

$db=TestSupport::db();TestSupport::seed($db);RuntimeSchema::install($db);
$box=new SecretBox(str_repeat('d',32));$access=new Access($db,'admin');$club=$access->club();$n=0;
function check(bool $ok,string $label):void{global$n;if(!$ok)throw new RuntimeException($label);++$n;echo "PASS $label\n";}
function denied(callable $fn,string $label):void{try{$fn();}catch(DomainException){check(true,$label);return;}throw new RuntimeException($label);}
$settings=new Settings($db,$box);$config=['enabled'=>'1','receive_enabled'=>'1','auto_send'=>'1','target'=>'human@example.invalid','reply_to'=>'return@example.invalid','imap_host'=>'imap.example.invalid','imap_user'=>'return','imap_password'=>'synthetic-only','since'=>'2026-10-01','folder'=>'INBOX'];$settings->save($access,$config,0);
$accounts=new Accounts($db,$box);$meta=['page_id'=>'123','instagram_id'=>'456','api_version'=>'v25.0','token'=>'synthetic-only'];$accounts->save($access,'meta',$meta,0);$account=$accounts->get($club,'meta');$attention=new Attention($db,$box);$remote=[];$personalComments=[];$posts=[];$mode='normal';$serial=0;
$identity=['account_id'=>'own_person','home_area_id'=>'own_area','connection_id'=>str_repeat('b',32)];
Db::run($db,"INSERT INTO native_private_settings(club_id,purpose,version,ciphertext,updated_by) VALUES(?,'api-stadtlandfunk',1,?,'admin')",[$club,$box->seal('api:'.$club.':stadtlandfunk',$identity+['access_token'=>'synthetic-personal','refresh_token'=>'synthetic-refresh','expires_at'=>time()+7200,'refresh_expires_at'=>time()+86400,'actor_id'=>'admin','authority'=>'native'])]);
$intent=static function(string $text)use($db,$club,$box,&$posts):void{
 $other=TestSupport::db();$row=Db::run($other,"SELECT r.* FROM native_social_replies r JOIN native_social_mail_dispatch d ON d.club_id=r.club_id AND d.reply_id=r.id WHERE r.club_id=? AND r.status='sending' AND d.state='ready' ORDER BY r.updated_at DESC LIMIT 1",[$club])->fetch();
 check((bool)$row&&$row['reply_slot']!=='','independent connection sees committed mail send intent');
 check((int)Db::run($other,'SELECT COUNT(*) FROM native_social_mail_dispatch WHERE club_id=? AND mail_id=? AND reply_id=?',[$club,$row['reply_slot'],$row['id']])->fetchColumn()===1,'mail and social command are durably linked before POST');
 check($box->open('social-reply:'.$club.':'.$row['id'],$row['ciphertext'])['text']===$text,'exact human reply is pinned before network write');
 $before=count($posts);$parallel=new Dispatcher($other,$box,new Replies($other,$box,static fn()=>throw new RuntimeException('Duplicate POST')));check($parallel->run(new Access($other,'admin'))['sent']===0&&count($posts)===$before,'second worker cannot claim current mail or publish twice');
};
$http=static function(string $method,string $path,array $headers,?array $body)use(&$remote,&$posts,&$mode,$intent,$db):array{
 $id=explode('?',substr($path,strlen('/v25.0/')))[0];
 if($method==='GET'){
  if($id==='me')return ['status'=>200,'body'=>['id'=>'123','instagram_business_account'=>['id'=>'456']]];
  $value=$remote[$id]??[];
  if($mode==='changed-original'&&!str_starts_with($id,'sent_')&&!str_starts_with($id,'conversation_')){if(isset($value['message']))$value['message']='Changed';if(isset($value['text']))$value['text']='Changed';}
  if($mode==='lost-proof'&&str_starts_with($id,'sent_')){if(isset($value['message']))$value['message']='Wrong';if(isset($value['text']))$value['text']='Wrong';}
  if($mode==='revoke-read')Db::run($db,"DELETE FROM native_grants WHERE actor_id='admin' AND module='social' AND capability='publish'");
  if($mode==='revoke-website-read')Db::run($db,"DELETE FROM native_grants WHERE actor_id='admin' AND module='website' AND capability='manage'");
  return ['status'=>200,'body'=>$value];
 }
 $text=$body['message']['text']??$body['message'];$intent($text);$posts[]=[$path,$body];
 if($mode==='timeout')throw new RuntimeException('Lost provider response');
 if($mode==='reject')return ['status'=>403,'body'=>['error'=>['code'=>10]]];
 $sent='sent_'.count($posts);
 if($id==='123/messages'){
  $sender=isset($body['messaging_type'])?'123':'456';$recipient=$body['recipient']['id'];
  $remote[$sent]=['id'=>$sent,'message'=>$text,'from'=>['id'=>$sender],'to'=>['data'=>[['id'=>$recipient]]],'created_time'=>gmdate('Y-m-d\TH:i:sO')];
  return ['status'=>200,'body'=>['message_id'=>$sent,'recipient_id'=>$recipient]];
 }
 $remote[$sent]=['id'=>$sent,'message'=>$text,'text'=>$text];return ['status'=>200,'body'=>['id'=>$sent]];
};
$personalHttp=static function($method,$url,$headers,$body)use(&$personalComments,&$posts,$intent):array{
 $path=parse_url($url,PHP_URL_PATH);$result=static fn($data)=>['status'=>200,'headers'=>[],'body'=>json_encode($data,JSON_THROW_ON_ERROR)];
 if($method==='GET'&&$path==='/grapevine/comment')return $result(array_values($personalComments));
 if($method!=='POST'||$path!=='/grapevine/comment/event/commentCreateRequest')throw new RuntimeException('Unexpected personal route');
 $request=json_decode($body,true,16,JSON_THROW_ON_ERROR);$intent($request['text']);$posts[]=[$path,$request];$id='personal_sent_'.count($posts);
 $personalComments[$id]=['id'=>$id,'postId'=>$request['postId'],'text'=>$request['text'],'creator'=>['id'=>'own_person'],'replyTo'=>['id'=>$personalComments[$request['replyToCommentId']]['creator']['id']]];
 return $result(['commentId'=>$id,'comment'=>$personalComments[$id]]);
};
$replies=new Replies($db,$box,$http,new Connection($db,$box,new Login($personalHttp)));$batch=['uidvalidity'=>1,'messages'=>[]];$smtp=0;
$bridge=new Bridge($db,$box,static function($club,$config,$message)use(&$smtp){++$smtp;return $message['message_id'];},static function()use(&$batch){return $batch;},$replies);
$prepare=static function(string $channel,string $kind='comment',int $age=60)use($db,$access,$club,$box,$account,$attention,$replies,$bridge,&$remote,&$personalComments,&$serial,$identity):array{
 $suffix=(string)++$serial;$parent=$kind==='message'?'conversation_'.$suffix:'post_'.$suffix;$original='original_'.$suffix;$date=gmdate('Y-m-d H:i:s',time()-$age);$guest='guest_'.$suffix;$text='Original question '.$suffix;
 if($kind==='message'){
  $thread=MessageReplyProvider::threadId($club,$channel,$parent);$sender=$channel==='facebook'?'123':'456';
  $message=['id'=>$original,'text'=>$text,'incoming'=>true,'created_at'=>$date,'from_id'=>$guest,'from_name'=>'Original guest'];
  Db::run($db,'INSERT INTO native_social_inbox VALUES(?,?,?,?,?,UTC_TIMESTAMP())',[$club,$thread,$channel,$box->seal('social-inbox:'.$club.':'.$thread,['page_id'=>'123','instagram_id'=>'456','connection_id'=>Accounts::binding($account),'conversation'=>$parent,'recipient'=>$guest,'messages'=>[$message]]),$date]);
  $attention->observe($access,$channel,'message',$parent,[$message],true);
  $remote[$parent]=['id'=>$parent,'participants'=>['data'=>[['id'=>$guest],['id'=>$sender]]]];$remote[$original]=['id'=>$original,'message'=>$text,'from'=>['id'=>$guest],'to'=>['data'=>[['id'=>$sender]]],'created_time'=>str_replace(' ','T',$date).'+0000'];
 }else{
  $payload=$channel==='stadtlandfunk'?$identity:['connection_id'=>Accounts::binding($account)];
  Db::run($db,'INSERT INTO native_social_objects(club_id,id,channel,remote_id,kind,caption,payload_json,last_seen) VALUES(?,?,?,?,?,?,?,UTC_TIMESTAMP())',[$club,substr(hash('sha256',$parent),0,32),$channel,$parent,$channel==='stadtlandfunk'?'event':'post','Original',json_encode($payload,JSON_THROW_ON_ERROR)]);
  $attention->observe($access,$channel,'comment',$parent,[['id'=>$original,'text'=>$text,'name'=>'Original guest','creator_id'=>$guest]],true);
  $remote[$original]=['id'=>$original,'message'=>$text,'text'=>$text];
  if($channel==='stadtlandfunk')$personalComments[$original]=['id'=>$original,'postId'=>$parent,'text'=>$text,'creator'=>['id'=>$guest]];
 }
 $source=$replies->source($access,substr(hash('sha256',$club.':'.$channel.':'.$kind.':'.$parent.':'.$original),0,32));
 $forward=$bridge->prepare($access,$source['id'],(int)$source['version'],'');$sent=$bridge->send($access,$forward['id'],1,true);
 return ['source'=>$source,'forward'=>$sent];
};
$receive=static function(array $fixture,string $text,string $suffix)use(&$batch,$bridge,$access):array{
 $raw="From: Human <human@example.invalid>\r\nTo: return@example.invalid\r\nMessage-ID: <own-return-".$suffix."@example.invalid>\r\nIn-Reply-To: ".$fixture['forward']['payload']['message_id']."\r\nContent-Type: text/plain; charset=UTF-8\r\n\r\n".$text;
 $batch=['uidvalidity'=>$batch['uidvalidity']+1,'messages'=>[['uid'=>1,'original'=>$raw]]];return [$bridge->fetch($access),$raw];
};
foreach([['facebook','comment'],['instagram','comment'],['stadtlandfunk','comment'],['facebook','message'],['instagram','message']]as[$channel,$kind]){
 if($channel==='stadtlandfunk')$personalComments=[];
 $f=$prepare($channel,$kind);$before=count($posts);$body='Meine echte Antwort '.$channel.' '.$kind;
 [$result,$raw]=$receive($f,$body,$channel.'-'.$kind);check($result['delivery']['sent']===1&&count($posts)===$before+1,'real mail is immediately sent to '.$channel.' '.$kind);
 $history=$bridge->history($access,$f['source']);$mail=$history[0]['answers'][0];$social=$replies->get($access,$mail['delivery']['reply_id']);
 check($social['status']==='sent'&&$social['payload']['text']===$body&&$social['reply_slot']===$mail['id'],'direct answer is confirmed and never a draft '.$channel.' '.$kind);
 check(base64_decode($mail['payload']['original'])===$raw&&$social['payload']['original']===$f['source']['payload'],'exact received MIME and original question retained '.$channel.' '.$kind);
 check($replies->forSource($access,$f['source'],$social['connection_id'])===null,'automatic mail does not occupy manual draft slot '.$channel.' '.$kind);
 check(count($replies->mailHistory($access,$f['source'],$social['connection_id']))===1,'sent answer appears immediately in actual conversation '.$channel.' '.$kind);
 denied(fn()=>$bridge->useAnswer($access,$mail['id'],$f['source']['id'],1,$body),'direct command cannot also be copied and resent as draft');
 $batch['uidvalidity']++;$again=$bridge->fetch($access);check($again['existing']===1&&$again['delivery']['sent']===0&&count($posts)===$before+1,'UID reset or repeated read never sends again '.$channel.' '.$kind);
 $body='Zweite echte Mailantwort '.$channel;[$second]=$receive($f,$body,'second-'.$channel.'-'.$kind);check($second['delivery']['sent']===1&&count($posts)===$before+2,'second genuine mail in same conversation gets one own send '.$channel.' '.$kind);
 check(count($replies->mailHistory($access,$f['source'],$social['connection_id']))===2,'both exact replies remain in conversation '.$channel.' '.$kind);
 $manual=$replies->save($access,$f['source']['id'],(int)$replies->source($access,$f['source']['id'])['version'],'Eigener vorhandener Entwurf');check($manual['status']==='draft','independent manual draft still possible after direct mails');
}
foreach(['timeout','lost-proof','reject','changed-original','revoke-read','revoke-website-read']as$case){
 $f=$prepare('facebook','message');$mode=$case;$before=count($posts);[$result]=$receive($f,'Meine echte Antwort '.$case,$case);$expected=match($case){'reject'=>'rejected','changed-original','revoke-read','revoke-website-read'=>'blocked',default=>'uncertain'};
 check($result['delivery'][$expected]===1,'accurate durable direct result '.$case);$mode='normal';Db::run($db,"INSERT IGNORE INTO native_grants VALUES('admin','social','publish')");
 Db::run($db,"INSERT IGNORE INTO native_grants VALUES('admin','website','manage')");$count=count($posts);$batch['uidvalidity']++;$bridge->fetch($access);(new Dispatcher($db,$box,$replies))->run($access);check(count($posts)===$count,'no automatic retry after '.$case);
 check($count===$before+(in_array($expected,['uncertain','rejected'],true)?1:0),'preflight and uncertain write count match '.$case);
}
$f=$prepare('instagram','message',86401);$before=count($posts);[$result]=$receive($f,'Echte Antwort nach Frist','expired');check($result['delivery']['blocked']===1&&count($posts)===$before,'expired 24-hour window never sends');
$f=$prepare('instagram','message');$before=count($posts);[$result]=$receive($f,str_repeat('ä',501),'too-long');check($result['delivery']['blocked']===1&&count($posts)===$before,'Instagram byte limit never silently truncates human mail');
$f=$prepare('facebook','message');$before=count($posts);[$result]=$receive($f,"> Only quoted original\r\n",'quote-only');check($result['delivery']['blocked']===1&&count($posts)===$before,'quoted-only reply cannot publish original question as answer');
$f=$prepare('facebook','message');[$result]=$receive($f,"Genaue menschliche Antwort\r\n\r\nOn Friday, Human wrote:\r\n> Unveränderte Frage",'quote-strip');$history=$bridge->history($access,$f['source']);$mail=$history[0]['answers'][0];check($replies->get($access,$mail['delivery']['reply_id'])['payload']['text']==='Genaue menschliche Antwort','only new human answer is sent, quoted original retained privately');
$firstChain=$prepare('facebook','message');$secondChain=$prepare('instagram','message');$before=count($posts);
$chainRaw=static fn(string $parent,string $suffix,string $references,string $text):string=>"From: human@example.invalid\r\nTo: return@example.invalid\r\nMessage-ID: <own-chain-".$suffix."@example.invalid>\r\nIn-Reply-To: ".$parent."\r\nReferences: ".$references."\r\n\r\n".$text;
$references=$firstChain['forward']['payload']['message_id'].' '.$secondChain['forward']['payload']['message_id'];$raw=$chainRaw($secondChain['forward']['payload']['message_id'],'immediate',$references,'Antwort an das genaue zweite Gespräch');$batch=['uidvalidity'=>$batch['uidvalidity']+1,'messages'=>[['uid'=>1,'original'=>$raw]]];$result=$bridge->fetch($access);
check($result['delivery']['sent']===1&&count($posts)===$before+1,'unique immediate mail parent wins over historical references to another conversation');$firstHistory=$bridge->history($access,$firstChain['source']);$secondHistory=$bridge->history($access,$secondChain['source']);check(count($firstHistory[0]['answers'])===0&&count($secondHistory[0]['answers'])===1,'immediate reply is attached only to its actual social conversation');
$raw=$chainRaw('<own-chain-immediate@example.invalid>','continued','<unknown-older@example.invalid>','Fortgesetzte echte menschliche Antwort');$batch=['uidvalidity'=>$batch['uidvalidity']+1,'messages'=>[['uid'=>1,'original'=>$raw]]];$result=$bridge->fetch($access);check($result['delivery']['sent']===1&&count($posts)===$before+2,'reply to previously received human mail continues exactly the same conversation');$secondHistory=$bridge->history($access,$secondChain['source']);check(count($secondHistory[0]['answers'])===2,'both real replies retain exact same thread with distinct send commands');
$raw=$chainRaw($references,'ambiguous',$firstChain['forward']['payload']['message_id'],'Do not guess');$batch=['uidvalidity'=>$batch['uidvalidity']+1,'messages'=>[['uid'=>1,'original'=>$raw]]];$result=$bridge->fetch($access);check($result['ignored']===1&&count($posts)===$before+2,'multiple immediate mail parents cannot fall back to a guessed single historical target');
$raw=$chainRaw('<unrecorded-relay@example.invalid>','fallback',$firstChain['forward']['payload']['message_id'],'Echte Antwort mit erhaltenem Originalbezug');$batch=['uidvalidity'=>$batch['uidvalidity']+1,'messages'=>[['uid'=>1,'original'=>$raw]]];$result=$bridge->fetch($access);check($result['delivery']['sent']===1&&count($posts)===$before+3,'single original reference works when immediate mail parent belongs to an unrecorded relay');
$f=$prepare('facebook','message');$before=count($posts);$thread=$f['forward'];$raw="From: attacker@example.invalid\r\nTo: return@example.invalid\r\nMessage-ID: <forged@example.invalid>\r\nIn-Reply-To: ".$thread['payload']['message_id']."\r\n\r\nForeign answer";$batch=['uidvalidity'=>$batch['uidvalidity']+1,'messages'=>[['uid'=>1,'original'=>$raw]]];$result=$bridge->fetch($access);check($result['ignored']===1&&count($posts)===$before,'foreign sender creates no social command');
$f=$prepare('facebook','message');$source=$f['source'];$manual=$replies->save($access,$source['id'],(int)$source['version'],'Privater manueller Originalentwurf');$manualBytes=Db::run($db,'SELECT * FROM native_social_replies WHERE club_id=? AND id=?',[$club,$manual['id']])->fetch();
[$result]=$receive($f,'Direkte Antwort neben eigenem Entwurf','parallel-draft');check($result['delivery']['sent']===1&&Db::run($db,'SELECT * FROM native_social_replies WHERE club_id=? AND id=?',[$club,$manual['id']])->fetch()===$manualBytes,'automatic mail never replaces or changes an existing exact manual draft');
$mailRow=Db::run($db,'SELECT d.* FROM native_social_mail_dispatch d JOIN native_social_mail_replies m ON m.club_id=d.club_id AND m.id=d.mail_id WHERE m.thread_id=?',[$f['forward']['id']])->fetch();$before=count($posts);
Db::run($db,"UPDATE native_social_mail_dispatch SET state='ready' WHERE club_id=? AND mail_id=?",[$club,$mailRow['mail_id']]);$resumed=(new Dispatcher($db,$box,$replies))->run($access);check($resumed['sent']===1&&count($posts)===$before,'restart after provider success but before mail receipt completion never repeats POST');
Db::run($db,"UPDATE native_social_mail_dispatch SET state='ready' WHERE club_id=? AND mail_id=?",[$club,$mailRow['mail_id']]);Db::run($db,"UPDATE native_social_replies SET status='sending' WHERE club_id=? AND id=?",[$club,$mailRow['reply_id']]);$resumed=(new Dispatcher($db,$box,$replies))->run($access);check($resumed['uncertain']===1&&count($posts)===$before,'restart with committed uncertain intent is read-only and never retries');
$f=$prepare('facebook','message');$raw="From: human@example.invalid\r\nTo: return@example.invalid\r\nMessage-ID: <queued-before-stop@example.invalid>\r\nIn-Reply-To: ".$f['forward']['payload']['message_id']."\r\n\r\nAntwort nach Neustart";$batch=['uidvalidity'=>$batch['uidvalidity']+1,'messages'=>[['uid'=>1,'original'=>$raw]]];
$paused=new Bridge($db,$box,null,static function()use(&$batch){return $batch;});$paused->fetch($access);check(count($posts)===$before&&(int)$db->query("SELECT COUNT(*) FROM native_social_mail_dispatch WHERE state='pending'")->fetchColumn()===1,'development transport gate retains a committed pending command without network action');
$batch=['uidvalidity'=>$batch['uidvalidity'],'messages'=>[]];$resumed=$bridge->fetch($access);check($resumed['new']===0&&$resumed['delivery']['sent']===1&&count($posts)===$before+1,'empty next mailbox read resumes original unsent command once after restart');$before=count($posts);
$f=$prepare('facebook','message');$raw="From: human@example.invalid\r\nTo: return@example.invalid\r\nMessage-ID: <module-off-ready@example.invalid>\r\nIn-Reply-To: ".$f['forward']['payload']['message_id']."\r\n\r\nNicht nach Abschalten senden";$batch=['uidvalidity'=>$batch['uidvalidity']+1,'messages'=>[['uid'=>1,'original'=>$raw]]];$paused->fetch($access);
$queuedId=Db::run($db,'SELECT m.id FROM native_social_mail_replies m WHERE m.thread_id=?',[$f['forward']['id']])->fetchColumn();$queued=Db::atomic($db,function()use($db,$club,$access,$replies,$f,$queuedId):array{$reply=$replies->queueMail($access,$f['source']['id'],(int)$f['source']['version'],'Nicht nach Abschalten senden',$queuedId);Db::run($db,"UPDATE native_social_mail_dispatch SET state='ready',reply_id=? WHERE club_id=? AND mail_id=?",[$reply['id'],$club,$queuedId]);return $reply;});
$current=$settings->configuration($access);$off=$config;$off['auto_send']='0';$settings->save($access,$off,$current['version']);denied(fn()=>$replies->send($access,$queued['id'],(int)$queued['version'],true),'direct service or forged web request cannot bypass disabled automatic module');check(count($posts)===$before,'disabled module gate stops before any provider write');
(new Dispatcher($db,$box,$replies))->run($access);$settings->save($access,$config,$current['version']+1);
$f=$prepare('facebook','message');$raw="From: human@example.invalid\r\nTo: return@example.invalid\r\nMessage-ID: <old-owner-ready@example.invalid>\r\nIn-Reply-To: ".$f['forward']['payload']['message_id']."\r\n\r\nAntwort des ursprünglichen Verantwortlichen";$batch=['uidvalidity'=>$batch['uidvalidity']+1,'messages'=>[['uid'=>1,'original'=>$raw]]];$paused->fetch($access);
Db::run($db,"INSERT INTO native_users(id,club_id,name) VALUES('another-owner',?,'Eigener anderer Verantwortlicher')",[$club]);$db->exec("INSERT INTO native_grants(actor_id,module,capability) SELECT 'another-owner',module,capability FROM native_grants WHERE actor_id='admin'");$current=$settings->configuration($access);$settings->save(new Access($db,'another-owner'),$config,$current['version']);$resumed=(new Dispatcher($db,$box,$replies))->run($access);check($resumed['blocked']===1&&count($posts)===$before,'configuration takeover cannot inherit prior mail send authority');$settings->save($access,$config,$current['version']+1);
$current=$settings->configuration($access);$off=$config;$off['auto_send']='0';$settings->save($access,$off,$current['version']);$f=$prepare('facebook','message');[$result]=$receive($f,'Private answer','disabled-direct');check(!isset($result['delivery'])&&count($posts)===$before,'direct sending can be disabled without publishing old receipts');$settings->save($access,$config,$current['version']+1);$batch['uidvalidity']++;$bridge->fetch($access);check(count($posts)===$before,'later activation cannot automatically send pre-existing private mail');
$f=$prepare('facebook','message');$state=$settings->configuration($access);$changed=$config;$changed['target']='another@example.invalid';$settings->save($access,$changed,$state['version']);[$result]=$receive($f,'No redirect','old-binding');check($result['ignored']===1&&count($posts)===$before,'mail configuration change cannot redirect old reply');
denied(fn()=>(new Dispatcher($db,$box,$replies))->run(new Access($db,'reader')),'reader cannot execute private direct mail commands');
foreach(["  Meine Antwort\n ","Echte Antwort\r\n\r\n-- \r\nPrivate signature", "Echte Antwort\n\nVon: Someone <one@example.invalid>\nAn: Other <other@example.invalid>\nBetreff: Original\nOld text"]as$i=>$body)check(ReplyText::extract($body)===($i===0?$body:'Echte Antwort'),'real human content and common quote/signature boundary '.$i);
foreach(['<p>Echte HTML-Antwort ä🙂</p><blockquote>Ursprüngliche Frage</blockquote>','<p>Echte HTML-Antwort ä🙂</p><div class="gmail_quote">Ursprüngliche Frage</div>','<p>Echte HTML-Antwort ä🙂</p><div><div id="divRplyFwdMsg">Von: Original</div><p>Ursprüngliche Frage</p></div><div>Ursprünglicher Anhangstext</div>']as$i=>$html){
 $raw="From: human@example.invalid\r\nTo: return@example.invalid\r\nMessage-ID: <html-".$i."@example.invalid>\r\nContent-Type: text/html; charset=UTF-8\r\n\r\n".$html;$parsed=Mime::parse($raw);
 check(str_contains($parsed['text'],'Ursprüngliche Frage')&&base64_decode($parsed['original'])===$raw,'full HTML and quoted original remain retained '.$i);
 check(ReplyText::extract($parsed['reply_text'])==="Echte HTML-Antwort ä🙂\n",'HTML mail sends only genuine human text above quoted content '.$i);
}
check((int)$db->query("SELECT COUNT(*) FROM native_social_replies WHERE reply_slot<>'' AND status='draft'")->fetchColumn()===0,'no received direct mail ever becomes a draft');
echo $n." direct mail checks passed; actual MIME/MySQL and provider contracts, no live transport.\n";
