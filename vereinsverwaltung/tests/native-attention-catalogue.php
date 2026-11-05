<?php declare(strict_types=1);
require dirname(__DIR__).'/native/bootstrap.php';
use Kulturhus\Native\TestSupport;
use Kulturhus\Native\Shared\{Access,Db,SecretBox,RuntimeSchema};
use Kulturhus\Native\Communication\{Attention,AttentionCatalogue};
require __DIR__.'/support/original-database-hash.php';
$db=TestSupport::db();$box=new SecretBox(str_repeat('c',32));$catalogue=new AttentionCatalogue($db,$box);
if(($argv[1]??'')==='probe'){
 $r=$catalogue->listing(new Access($db,'admin'),['q'=>'Kataloggast','kind'=>'comment']);
 if($r['total']!==225||count($r['rows'])!==6||isset($r['rows'][0]['payload']))throw new RuntimeException('Incomplete or unbounded projection.');
 echo json_encode(['memory'=>memory_get_peak_usage(true)]);exit;
}
TestSupport::seed($db);RuntimeSchema::install($db);$access=new Access($db,'admin');$club=$access->club();$attention=new Attention($db,$box);$ids=[];$n=0;
function check(bool $ok,string $label):void{global$n;if(!$ok)throw new RuntimeException($label);++$n;echo "PASS $label\n";}
function denied(callable $fn,string $label):void{try{$fn();}catch(DomainException){check(true,$label);return;}throw new RuntimeException($label);}
for($i=0;$i<290;$i++){
 $channel=['facebook','instagram','stadtlandfunk'][$i%3];$kind=$i<225?'comment':($i<255?'reaction':'message');$parent='catalogue_parent_'.$i;$remote='original_'.$i;
 $entry=['id'=>$remote,'text'=>$i===224?'Originale Frage ä🙂':str_repeat('Original ',2200),'name'=>'Kataloggast '.str_pad((string)$i,3,'0',STR_PAD_LEFT),'title'=>'Ursprünglicher Beitrag '.($i===224?'Alttitel':'normal'),'count'=>1];
 $attention->observe($access,$channel,$kind,$parent,[$entry]);$id=substr(hash('sha256',$club.':'.$channel.':'.$kind.':'.$parent.':'.$remote),0,32);$ids[$i]=$id;
 Db::run($db,'UPDATE native_social_attention SET observed_at=?,state=? WHERE club_id=? AND id=?',[gmdate('Y-m-d H:i:s',time()-$i*86400),['new','reply_needed','done'][$i%3],$club,$id]);
}
$proof=ownOriginalDatabaseHash($db,['native_social_attention','native_social_replies']);
$first=$catalogue->listing($access);check($first['total']===255&&$first['pages']===43&&count($first['rows'])===6,'all 255 comments and reactions counted before bounded six-row page');
check(!isset($first['rows'][0]['payload'])&&!isset($first['rows'][0]['ciphertext']),'list never contains complete encrypted original payloads');
$all=[];for($i=1;$i<=43;$i++)$all=array_merge($all,array_column($catalogue->listing($access,['page'=>$i])['rows'],'id'));
check(count(array_unique($all))===255&&array_diff(array_slice($ids,0,255),$all)===[],'every original beyond former 200-entry cutoff reachable exactly once');
check(!array_intersect(array_slice($ids,255),$all),'private messages never enter comment catalogue');
$found=$catalogue->listing($access,['q'=>'Kataloggast 224']);check($found['total']===1&&$found['rows'][0]['id']===$ids[224],'encrypted search reaches original beyond first 200 entries');
check($catalogue->listing($access,['q'=>'Alttitel'])['total']===1,'original parent title participates in complete search');check($catalogue->listing($access,['q'=>'ä🙂'])['total']===1,'literal Unicode original text stays searchable');check($catalogue->listing($access,['q'=>'%_unknown'])['total']===0,'wildcards are literal text');
foreach(['facebook','instagram','stadtlandfunk']as$channel){$r=$catalogue->listing($access,['channel'=>$channel]);check($r['total']===85&&array_unique(array_column($r['rows'],'channel'))===[$channel],'platform filter before count and page '.$channel);}
foreach(['comment'=>225,'reaction'=>30]as$kind=>$count)check($catalogue->listing($access,['kind'=>$kind])['total']===$count,'kind filter before page '.$kind);
foreach(['new'=>85,'reply_needed'=>85,'done'=>85,'open'=>170]as$state=>$count)check($catalogue->listing($access,['state'=>$state])['total']===$count,'exact status total '.$state);
$selected=$catalogue->attention($access,$ids[224]);check($selected['payload']['text']==='Originale Frage ä🙂'&&$selected['fingerprint']!==''&&$selected['kind']==='comment','deep link retrieves exact original outside current list page');
check($catalogue->attention($access,$ids[254])['kind']==='reaction','selected reaction has its original type and cannot become a comment');
foreach([['page'=>44],['page'=>0],['page'=>[]],['channel'=>'mail'],['kind'=>'message'],['state'=>'saved'],['q'=>[]],['q'=>str_repeat('ä',201)],['q'=>"bad\0text"],['q'=>"\xff"]]as$q)denied(fn()=>$catalogue->listing($access,$q),'invalid comment catalogue query rejected');
denied(fn()=>$catalogue->listing(new Access($db,'reader')),'ordinary reader cannot inspect private comments');denied(fn()=>$catalogue->attention(new Access($db,'outsider'),$ids[224]),'original cannot cross club binding');denied(fn()=>$catalogue->attention($access,$ids[255]),'message deep link cannot enter comment view');
$process=proc_open([...TestSupport::phpCommand(),'-d','memory_limit=16M',__FILE__,'probe'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);fclose($pipes[0]);$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$code=proc_close($process);$result=json_decode($out,true);check($code===0&&$err===''&&$result['memory']<16*1024*1024,'actual 16-MB child searches all large encrypted originals with bounded retained memory');
check($proof===ownOriginalDatabaseHash($db,['native_social_attention','native_social_replies']),'all catalogue pages and searches preserve exact original bytes');
function saved(PDO $db,SecretBox $box,string $club,string $id,string $attention,string $kind,string $date,string $slot=''):void{
 $payload=['kind'=>$kind,'text'=>'Eigene gespeicherte Antwort','original'=>['remote_id'=>'retained_original','name'=>'Gespeicherter Gast','text'=>'Genaue vorherige Frage','title'=>'Gespeicherter Beitrag','created_at'=>'2000-01-01']];
 Db::run($db,"INSERT INTO native_social_replies(club_id,id,attention_id,channel,parent_id,connection_id,source_fingerprint,reply_slot,status,version,ciphertext,created_by,updated_by,created_at,updated_at) VALUES(?,?,?,'facebook','retained_parent','retained_connection',?,?,'draft',1,?,'admin','admin',?,?)",[$club,$id,$attention,hash('sha256',$id),$slot,$box->seal('social-reply:'.$club.':'.$id,$payload),$date,$date]);
}
$missing=str_repeat('a',32);$old=str_repeat('b',32);$latest=str_repeat('c',32);
saved($db,$box,$club,$old,$missing,'comment','2000-01-01 00:00:00');saved($db,$box,$club,$latest,$missing,'comment','2000-01-02 00:00:00');
saved($db,$box,$club,str_repeat('d',32),str_repeat('e',32),'message','2000-01-03 00:00:00');saved($db,$box,$club,str_repeat('f',32),str_repeat('0',32),'comment','2000-01-04 00:00:00',str_repeat('1',32));
saved($db,$box,$club,str_repeat('2',32),$ids[0],'comment','2000-01-05 00:00:00');
$retained=$catalogue->listing($access,['q'=>'Gespeicherter Gast']);check($retained['total']===1&&$retained['rows'][0]['id']===$missing&&$retained['rows'][0]['reply_id']===$latest,'latest retained manual reply keeps one original-bound orphan conversation');
check($catalogue->listing($access)['total']===256,'private saved messages, mail slots and duplicate active replies never inflate comment catalogue');
check($catalogue->listing($access,['state'=>'open'])['total']===170,'missing provider original is never labeled currently open');
check($catalogue->listing($access,['kind'=>'reaction'])['total']===30,'saved comments cannot enter reactions filter');
// Equal timestamps still page deterministically by original ID.
Db::run($db,'UPDATE native_social_attention SET observed_at=? WHERE club_id=?',['2020-01-01 00:00:00',$club]);
$a=$catalogue->listing($access);$b=$catalogue->listing($access,['page'=>2]);check(!array_intersect(array_column($a['rows'],'id'),array_column($b['rows'],'id')),'equal provider timestamps use stable original ID without duplicate pages');
$source=$catalogue->attention($access,$ids[0]);$objectId=bin2hex(random_bytes(16));$objectPayload=['permalink'=>'https://www.facebook.com/events/123456789/','connection_id'=>str_repeat('9',32)];Db::run($db,"INSERT INTO native_social_objects(club_id,id,channel,remote_id,kind,caption,payload_json,last_seen) VALUES(?,?,?,?,'event',?,?,UTC_TIMESTAMP())",[$club,$objectId,$source['channel'],$source['parent_id'],'Own original event title ä🙂',json_encode($objectPayload,JSON_THROW_ON_ERROR)]);
$context=$catalogue->origin($access,$source);check($context['id']===$objectId&&$context['kind']==='event'&&$context['text']==='Own original event title ä🙂'&&$context['url']===$objectPayload['permalink'],'comment origin identifies its actual owned event caption and confirmed provider link');check($catalogue->origin($access,$source+['_archived'=>true])===null,'archived original cannot silently use a current replacement event context');check($catalogue->origin($access,$source+['_history_connection'=>str_repeat('8',32)])===null,'changed connection cannot lend its context to an earlier comment');denied(fn()=>$catalogue->origin(new Access($db,'reader'),$source),'comment parent context retains private interaction permission');
echo $n." attention catalogue checks passed; no live actions.\n";
