<?php declare(strict_types=1);
use Kulturhus\Native\Shared\{Access,Db,SecretBox};
use Kulturhus\Native\Communication\{Attention,Replies,MessageReplyProvider};
use Kulturhus\Native\Integration\Accounts;

/** Synthetic exact originals only; never configure or invoke a provider or mailbox. */
function ownMailTimeline(PDO $db,SecretBox $box,Access $access,array $account,int $padding=200000,string $state='pending',string $kind='message'):array
{
    $actor=$access->require('social','manage');$club=$access->club();$suffix=bin2hex(random_bytes(5));$parent='own_timeline_'.$suffix;$binding=Accounts::binding($account);$id=MessageReplyProvider::threadId($club,'facebook',$parent);$date=gmdate('Y-m-d H:i:s',time()-60);
    $question=['id'=>'own_question_'.$suffix,'text'=>'Genaue ursprüngliche Timelinefrage ä🙂','incoming'=>true,'from_id'=>'own_guest_'.$suffix,'from_name'=>'Eigener Timelinegast '.$suffix,'created_at'=>$date];
    $payload=['conversation'=>$parent,'recipient'=>$question['from_id'],'page_id'=>$account['page_id'],'instagram_id'=>$account['instagram_id'],'connection_id'=>$binding,'messages'=>[$question]];
    if($kind==='message')Db::run($db,'INSERT INTO native_social_inbox VALUES(?,?,?,?,?,UTC_TIMESTAMP())',[$club,$id,'facebook',$box->seal('social-inbox:'.$club.':'.$id,$payload),$date]);
    elseif($kind==='comment')Db::run($db,"INSERT INTO native_social_objects(club_id,id,channel,remote_id,kind,caption,payload_json,last_seen) VALUES(?,?,'facebook',?,'post','Own synthetic timeline post',?,UTC_TIMESTAMP())",[$club,substr(hash('sha256',$parent),0,32),$parent,json_encode(['connection_id'=>$binding],JSON_THROW_ON_ERROR)]);
    else throw new RuntimeException('Own synthetic kind required.');
    (new Attention($db,$box))->observe($access,'facebook',$kind,$parent,[$question+['name'=>$question['from_name']]],true);$replyService=new Replies($db,$box);$source=$kind==='message'?$replyService->messageSource($access,$id):$replyService->source($access,substr(hash('sha256',$club.':facebook:comment:'.$parent.':'.$question['id']),0,32));$forward=bin2hex(random_bytes(16));
    Db::run($db,"INSERT INTO native_social_mail_threads(club_id,id,attention_id,kind,channel,parent_id,source_fingerprint,connection_id,binding,message_hash,status,version,ciphertext,created_by,created_at,updated_at) VALUES(?,?,?,?,'facebook',?,?,?,?,?,'accepted',1,?,?,UTC_TIMESTAMP(),UTC_TIMESTAMP())",[$club,$forward,$source['id'],$kind,$parent,$source['fingerprint'],$binding,bin2hex(random_bytes(16)),hash('sha256',$forward),$box->seal('social-mail-thread:'.$club.':'.$forward,['source'=>$source,'original_test'=>true]),$actor['id']]);
    $mails=[];$texts=[];$mailDate=gmdate('Y-m-d H:i:s',time()-30);
    for($i=0;$i<70;$i++){$mail=bin2hex(random_bytes(16));$text='Eigene Mailantwort '.str_pad((string)$i,3,'0',STR_PAD_LEFT).' ä🙂 <genauer Text>';$original=['text'=>$text,'original'=>base64_encode('Genaue synthetische MIME-Bytes '.$i."\r\n".str_repeat('M',$padding))];Db::run($db,'INSERT INTO native_social_mail_replies VALUES(?,?,?,?,?,?)',[$club,$mail,$forward,hash('sha256',$mail),$box->seal('social-mail-answer:'.$club.':'.$mail,$original),$mailDate]);Db::run($db,'INSERT INTO native_social_mail_dispatch(club_id,mail_id,state,created_at,updated_at) VALUES(?,?,?,?,?)',[$club,$mail,$state,$mailDate.'.'.str_pad((string)$i,6,'0',STR_PAD_LEFT),$mailDate.'.'.str_pad((string)$i,6,'0',STR_PAD_LEFT)]);$mails[]=$mail;$texts[]=$text;}
    return ['thread'=>$id,'source'=>$source,'connection'=>$binding,'question'=>$question,'mails'=>$mails,'texts'=>$texts];
}
