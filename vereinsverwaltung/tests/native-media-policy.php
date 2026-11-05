<?php declare(strict_types=1);
require dirname(__DIR__).'/native/bootstrap.php';
use Kulturhus\Native\TestSupport;
use Kulturhus\Native\Shared\{Access,Db,SecretBox};
use Kulturhus\Native\Cms\{Schema,Media,MediaPolicy,MediaMetadata,Pages,Conflict};
use Kulturhus\Native\Communication\ImageUsage;
use Kulturhus\Native\Web\MediaLibrary;
$db=TestSupport::db();TestSupport::seed($db);Schema::install($db);$a=new Access($db,'admin');$r=new Access($db,'reader');$e=new Access($db,'editor');$n=0;
function check(bool $ok,string $label):void{global $n;if(!$ok)throw new RuntimeException($label);++$n;echo 'PASS '.$label."\n";}
function denied(callable $f,string $label):void{try{$f();}catch(DomainException|InvalidArgumentException|Conflict){check(true,$label);return;}throw new RuntimeException($label);}
$root=dirname(getenv('KH_NATIVE_TEST_CONFIG')).'/media-policy';mkdir($root,0700,true);$image=imagecreatetruecolor(32,24);imagefill($image,0,0,imagecolorallocate($image,40,120,180));imagepng($image,$root.'/own.png');unset($image);
$media=new Media($db,$a,$root.'/media');$asset=$media->upload($root.'/own.png','Eigene Aufnahme.png','');$id=$asset['id'];$club=$a->club();$policy=new MediaPolicy($db,$a);$meta=new MediaMetadata($db,$a,new SecretBox(str_repeat('s',32)));
check($asset['alt']==='Eigene Aufnahme','single upload needs no rights or metadata form');
check($policy->get()===['version'=>0,'check_rights'=>false,'require_details'=>false],'global checks are optional and disabled by default');
$original=Db::run($db,'SELECT * FROM khcms_media WHERE id=?',[$id])->fetch();$hashes=[];foreach(['original','large','medium','thumb']as$size)$hashes[$size]=hash_file('sha256',$media->variant($id,$size)['path']);
$pages=new Pages($db,$a);$p=$pages->save(['title'=>'Eigene unveröffentlichte Prüfung','slug'=>'own-policy','body'=>'Eigener Originaltext','media_ids'=>[$id]]);$pages->publish($p['id'],$p['version']);$published=$pages->publicPage($p['slug']);$snapshot=Db::run($db,'SELECT payload_json FROM khcms_revisions WHERE page_id=? AND revision=?',[$p['id'],$p['version']])->fetchColumn();
check($media->publicVariant($id,'medium',$published)!==null,'optional checks permit an unchecked original on the website');
$guard=static function(array $usage,string $channel,bool $versions=true)use($db,$club):void{if($channel==='website'){if($versions)MediaMetadata::assertPublication($db,$club,['media_ids'=>array_keys($usage['media']),'media_metadata'=>$usage['media']],$channel);else MediaMetadata::assertRights($db,$club,array_keys($usage['media']),$channel);}else ImageUsage::guard($db,$club,$usage,$channel,$versions);};
$usages=[];foreach(array_keys(MediaMetadata::CHANNELS)as$channel){$usages[$channel]=['schema'=>1,'channel'=>$channel,'media'=>MediaMetadata::capture($db,$club,['media_ids'=>[$id]])];$guard($usages[$channel],$channel);check(true,'optional checks permit actual '.$channel.' source guard');}
$setting=$policy->save('1','0',0);check($setting['check_rights']&&!$setting['require_details'],'rights check activates separately from required details');
check($media->publicVariant($id,'medium',$published)===null,'current policy applies to the actual public image endpoint');
foreach(array_keys(MediaMetadata::CHANNELS)as$channel)denied(fn()=>$guard($usages[$channel],$channel,false),'active global check blocks unchecked '.$channel.' use');
$second=$media->upload($root.'/own.png','Zweite Aufnahme.png','');check($second['alt']==='Zweite Aufnahme','active rights check still allows an uncomplicated new upload');
$m=$meta->get($id);$m=$meta->save($id,array_replace($m,['rights_state'=>'cleared','consent_state'=>'not_required']),0,'Eigener privater Nachweis');
foreach(array_keys(MediaMetadata::CHANNELS)as$channel){MediaMetadata::assertRights($db,$club,[$id],$channel);check(true,'cleared rights allow actual '.$channel.' guard without mandatory details');}
$setting=$policy->save('0','1',$setting['version']);
foreach(array_keys(MediaMetadata::CHANNELS)as$channel)denied(fn()=>MediaMetadata::assertRights($db,$club,[$id],$channel),'required details separately block incomplete '.$channel.' source');
$m=$meta->save($id,array_replace($m,['credit'=>'Eigene Fotografin','source_name'=>'Eigene Aufnahme','license_name'=>'Eigene Nutzungsvereinbarung']),$m['version']);
foreach(array_keys(MediaMetadata::CHANNELS)as$channel){MediaMetadata::assertRights($db,$club,[$id],$channel);check(true,'complete source details allow '.$channel.' use');}
check($meta->privateProof($id,1)==='Eigener privater Nachweis','optional policy retains the exact private encrypted proof');
$oldPolicy=Db::run($db,'SELECT * FROM khcms_media_policy_history WHERE club_id=? AND version=1',[$club])->fetch();
denied(fn()=>$policy->save('1','1',0),'stale settings cannot overwrite another original policy');
foreach([$e,$r]as$actor)denied(fn()=>(new MediaPolicy($db,$actor))->save('0','0',$setting['version']),'editor and reader cannot change global image checks');
foreach([null,[],2,'yes','01',' 0',1.5]as$bad)denied(fn()=>$policy->save($bad,'0',$setting['version']),'malformed settings cannot change checks');
$setting=$policy->save('0','0',$setting['version']);check(!$setting['check_rights']&&!$setting['require_details'],'both optional checks can be disabled again');
$m=$meta->save($id,array_replace($m,['rights_state'=>'unknown','consent_state'=>'unknown','credit'=>'','source_name'=>'','license_name'=>'']),$m['version']);
foreach(array_keys(MediaMetadata::CHANNELS)as$channel){MediaMetadata::assertRights($db,$club,[$id],$channel);check(true,'disabled checks allow incomplete '.$channel.' metadata again');}
$m=$meta->save($id,array_replace($m,['rights_state'=>'withdrawn']),$m['version']);
foreach(array_keys(MediaMetadata::CHANNELS)as$channel)denied(fn()=>MediaMetadata::assertRights($db,$club,[$id],$channel),'explicitly withdrawn '.$channel.' original remains blocked');
check(Db::run($db,'SELECT * FROM khcms_media_policy_history WHERE club_id=? AND version=1',[$club])->fetch()===$oldPolicy,'earlier policy audit row remains byte-identical');
check(Db::run($db,'SELECT * FROM khcms_media WHERE id=?',[$id])->fetch()===$original&&Db::run($db,'SELECT payload_json FROM khcms_revisions WHERE page_id=? AND revision=?',[$p['id'],$p['version']])->fetchColumn()===$snapshot,'policy changes preserve original upload and published revision bytes');
foreach($hashes as$size=>$hash)check(hash_file('sha256',$media->variant($id,$size)['path'])===$hash,'original '.$size.' pixels remain unchanged');
$ui=new MediaLibrary($db,$a,$root.'/media','own-token');$html=$ui->manager();check(str_contains($html,'Bildprüfung für alle Bereiche')&&str_contains($html,'multiple required data-media-upload')&&!str_contains($html,'name="alt" required'),'native form offers multiple images without mandatory upload metadata');
check(!str_contains((new MediaLibrary($db,$r,$root.'/media','own-token'))->manager(),'operation" value="media-policy-save"'),'readers have no policy write form');
denied(fn()=>$ui->action(['operation'=>'media-policy-save','policy_version'=>(string)$setting['version'],'check_rights'=>'1','require_details'=>'1','csrf'=>'wrong'],[]),'policy controller retains CSRF protection');
check((new MediaPolicy($db,new Access($db,'outsider')))->get()['version']===0,'other club never inherits this club policy');
$result=(new \Kulturhus\Native\Web\Manager($db,$a,$root.'/media','own-token'))->action('media',['operation'=>'media-policy-save','policy_version'=>(string)$setting['version'],'check_rights'=>'0','require_details'=>'0','csrf'=>'own-token'],[]);check($result['notice']==='Bildprüfung gespeichert.'&&$policy->get()['version']===$setting['version']+1,'actual shared manager dispatches the global policy operation');
$count=(int)$db->query('SELECT COUNT(*) FROM khcms_media')->fetchColumn();
foreach([[], '',str_repeat('a',31),str_repeat('a',33),str_repeat('A',32),'../own','ä',str_repeat('f',5000)]as$bad)denied(fn()=>$ui->action(['csrf'=>'own-token','operation'=>'media-upload','upload_id'=>$bad],[]),'malformed single library identity rejected before any write');
denied(fn()=>$ui->action(['csrf'=>'own-token','operation'=>'media-upload','upload_id'=>bin2hex(random_bytes(16))],['image'=>['name'=>['own.png','own2.png']]]),'one library identifier cannot be reused for a native multi-file batch');
denied(fn()=>$ui->action(['csrf'=>'wrong','operation'=>'media-upload','upload_id'=>bin2hex(random_bytes(16))],[]),'identified library upload retains actual CSRF protection');
denied(fn()=>(new MediaLibrary($db,$r,$root.'/media','own-token'))->action(['csrf'=>'own-token','operation'=>'media-upload','upload_id'=>bin2hex(random_bytes(16))],[]),'reader cannot start an identified library upload');
check((int)$db->query('SELECT COUNT(*) FROM khcms_media')->fetchColumn()===$count&&Db::run($db,'SELECT * FROM khcms_media WHERE id=?',[$id])->fetch()===$original,'rejected identified library uploads preserve all stored identities and the exact original row');
check(str_contains($html,'data-library-photo-status-url="/page-photo-status"')&&str_contains($html,'data-library-upload-cancel')&&str_contains($html,'data-library-upload-check')&&str_contains($html,'data-library-upload-forget'),'actual library exposes explicit abort and read-only verification controls');
check(!str_contains((new MediaLibrary($db,$r,$root.'/media','own-token'))->manager(),'data-library-photo-status-url'),'reader receives no library verification or upload control');
echo $n." media policy checks passed; optional global publication checks, immutable originals, no provider transport.\n";
