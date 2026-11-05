<?php declare(strict_types=1);
require dirname(__DIR__).'/native/bootstrap.php';require __DIR__.'/support/original-database-hash.php';
use Kulturhus\Native\TestSupport;
use Kulturhus\Native\Shared\{Access,Db};
use Kulturhus\Native\Cms\{AlbumCatalogue,AlbumOrder,Conflict,DataModel,Media,Pages,PageTrash,Schema};
use Kulturhus\Native\Web\Gallery;
$db=TestSupport::db();$access=new Access($db,'admin');
if(($argv[1]??'')==='bounded-memory'){
 $view=new Gallery($db,$access,$argv[2],'own-token');$html=$view->manager('',[]);
 $move=(new AlbumOrder($db,$access))->move($argv[3],'down',1);
 echo json_encode(['rows'=>substr_count($html,'data-album-row='),'order_version'=>$move['version'],'memory'=>memory_get_peak_usage(true)]);exit;
}
TestSupport::seed($db);Schema::install($db);$checks=0;
function catalogueCheck(bool $ok,string $label):void{global $checks;if(!$ok)throw new RuntimeException('FAIL '.$label);$checks++;echo 'PASS '.$label."\n";}
function catalogueReject(callable $fn,string $class,string $label):void{try{$fn();}catch(Throwable $e){catalogueCheck($e instanceof $class,$label.' ('.$e::class.')');return;}throw new RuntimeException('Accepted '.$label);}
$root=dirname(__DIR__,2).'/.local/album-catalogue-'.bin2hex(random_bytes(8));mkdir($root,0700,true);
$pixel=imagecreatetruecolor(28,20);imagefill($pixel,0,0,imagecolorallocate($pixel,20,90,130));imagepng($pixel,$root.'/own.png');unset($pixel);
$asset=(new Media($db,$access,$root.'/media'))->upload($root.'/own.png','own.png','Own original');
(new DataModel($db,$access))->saveTemplate(['key'=>'own_album','label'=>'Own album renderer','renderer'=>'gallery','fields'=>[]]);
$pages=new Pages($db,$access);$created=[];
for($i=0;$i<80;$i++){
 $title=$i>=72?['2','10','15a','20','1.5','1e2','Ä','ä'][$i-72]:'Own album '.sprintf('%03d',79-$i).' Ä';
 $page=$pages->save(['title'=>$title,'slug'=>'galerie/own-'.$i,'template'=>$i%3===0?'own_album':'gallery','nav_order'=>intdiv($i,8),'body'=>str_repeat('unused-body-only ',5500),'excerpt'=>'Own excerpt '.$i,'media_ids'=>[$asset['id']],'gallery_items'=>[['id'=>$asset['id'],'caption'=>'Own caption '.$i.($i===37?' 100%_! Σ Straße <marker>':''),'tags'=>['Own tag '.$i]]]]);
 if($i%4===0)$page=$pages->publish($page['id'],$page['version']);$created[]=$page;
}
$originalPublic=$pages->publicPage($created[0]['slug']);$pages->save(array_replace($created[0],['title'=>'Own private first Ä','nav_order'=>9999]),$created[0]['version']);
$pages->save(['title'=>'Own gallery root','slug'=>'galerie','template'=>'gallery']);
$archived=$pages->save(['title'=>'Own archived album','slug'=>'galerie/own-archived','template'=>'gallery']);$pages->archive($archived['id'],1);
$trashed=$pages->save(['title'=>'Own trashed album','slug'=>'galerie/own-trashed','template'=>'gallery']);(new PageTrash($db,$access))->move($trashed['id'],1);
(new Pages($db,new Access($db,'outsider')))->save(['title'=>'Own foreign secret','slug'=>'galerie/own-foreign','template'=>'gallery']);
$view=new Gallery($db,$access,$root.'/media','own-token');$reference=$view->albums();$ids=array_column($reference,'id');$catalogue=new AlbumCatalogue($db,$access);
catalogueCheck(count($reference)===80,'complete reference excludes root, archive, trash and other club');
for($i=1;$i<=4;$i++){
 $result=$catalogue->listing(['album_list_page'=>(string)$i]);
 catalogueCheck(array_column($result['items'],'id')===array_slice($ids,($i-1)*25,25)&&$result['total']===80&&$result['last']===4,'short metadata page '.$i.' preserves complete published baseline ordering');
 catalogueCheck(array_column($result['items'],'rank')===range(($i-1)*25,min($i*25,80)-1)&&array_unique(array_column($result['items'],'image_count'))===[1]&&array_unique(array_column($result['items'],'cover_id'))===[$asset['id']],'page '.$i.' retains global positions, exact counts and cover identities');
}
catalogueCheck($catalogue->listing(['album_list_page'=>'999999999'])['page']===4,'high page clamps to complete final window');
foreach(['caption 37','tag 37','100%_!','ς','straße','STRASSE','own excerpt 12','<marker>','unused-body-only','own private first']as$q){
 $matches=array_values(array_filter($reference,static function($p)use($q){$text=$p['title']."\n".$p['slug']."\n".$p['excerpt'];foreach($p['gallery_items']as$item)$text.="\n".$item['caption']."\n".implode(' ',$item['tags']);return preg_match('/'.preg_quote($q,'/').'/iu',$text)===1;}));
 $result=$catalogue->listing(['q'=>$q]);catalogueCheck($result['total']===count($matches)&&array_column($result['items'],'id')===array_column(array_slice($matches,0,25),'id'),'literal Unicode search preserves original caption/tag semantics: '.$q);
}
catalogueCheck($catalogue->listing(['status'=>'published'])['total']===20&&$catalogue->listing(['status'=>'draft'])['total']===60,'published draft changes stay in exact published status partition');
$tables=$db->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_TYPE='BASE TABLE' ORDER BY TABLE_NAME")->fetchAll(PDO::FETCH_COLUMN);$before=ownOriginalDatabaseHash($db,$tables);$source=hash_file('sha256',$root.'/own.png');
foreach([['q'=>[]],['q'=>"\xff"],['q'=>"bad\ntext"],['q'=>str_repeat('x',121)],['status'=>'archive'],['status'=>[]],['album_list_page'=>0],['album_list_page'=>'01'],['album_list_page'=>'1.0'],['album_list_page'=>[]],['album_list_page'=>'1000000001']]as$bad)catalogueReject(fn()=>$catalogue->listing($bad),InvalidArgumentException::class,'invalid filter rejected');
$reader=new Gallery($db,new Access($db,'reader'),$root.'/media');$html=$reader->manager('',[]);
catalogueCheck(substr_count($html,'data-album-row=')===25&&!str_contains($html,'value="album-order"')&&!str_contains($html,'Neues Album'),'reader sees a bounded complete list without editorial actions');
catalogueCheck((new AlbumCatalogue($db,new Access($db,'outsider')))->listing()['total']===1,'other club receives only its own metadata');
catalogueCheck(ownOriginalDatabaseHash($db,$tables)===$before,'every metadata read and rejected filter leaves all original rows unchanged');
for($i=0;$i<215;$i++)$pages->save(['title'=>'Own unrelated draft '.$i,'slug'=>'own-unrelated-'.$i,'body'=>str_repeat('x',99000),'nav_visible'=>false]);
$unusedBytes=(int)Db::run($db,"SELECT SUM(OCTET_LENGTH(CAST(draft_json AS CHAR))) FROM khcms_pages WHERE club_id=? AND slug LIKE 'own-unrelated-%'",[$access->club()])->fetchColumn();
$protected=array_values(array_diff($tables,['khcms_state','khcms_album_order','khcms_album_order_revisions']));$before=ownOriginalDatabaseHash($db,$protected);
$moved=(new AlbumOrder($db,$access))->move($ids[25],'up',0);$expected=$ids;[$expected[24],$expected[25]]=[$expected[25],$expected[24]];
catalogueCheck($moved['ids']===$expected&&$moved['version']===1&&array_column($catalogue->listing()['items'],'id')===array_slice($expected,0,25),'actual global move crosses a short page boundary once');
$withOrder=ownOriginalDatabaseHash($db,$tables);catalogueReject(fn()=>(new AlbumOrder($db,$access))->move($ids[25],'down',0),Conflict::class,'stale order cannot mutate any original row');catalogueCheck(ownOriginalDatabaseHash($db,$tables)===$withOrder,'stale global order rejection is byte exact');
$proc=proc_open([...TestSupport::phpCommand(),'-d','memory_limit=16M',__FILE__,'bounded-memory',$root.'/media',$ids[25]],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);if(!is_resource($proc))throw new RuntimeException('Own memory process required.');fclose($pipes[0]);$out=stream_get_contents($pipes[1]);$errors=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$code=proc_close($proc);$proof=json_decode($out,true,64,JSON_THROW_ON_ERROR);
catalogueCheck($code===0&&$errors===''&&$proof['rows']===25&&$proof['order_version']===2&&$proof['memory']<16*1024*1024&&$unusedBytes>20*1024*1024,'actual overview and order mutation work under 16 MiB with more than 20 MiB unrelated drafts');
catalogueCheck((new AlbumOrder($db,$access))->state()['ids']===$ids&&ownOriginalDatabaseHash($db,$protected)===$before&&$pages->publicPage($created[0]['slug'])===$originalPublic,'both actual order mutations preserve every original page/media/revision and prior public album');
catalogueCheck(Db::run($db,'SELECT ids_json FROM khcms_album_order_revisions WHERE club_id=? AND version=1',[$access->club()])->fetchColumn()===json_encode($expected,JSON_THROW_ON_ERROR),'earlier independent order revision remains immutable');
for($i=0;$i<425;$i++)$pages->save(['title'=>'Own extra album '.$i,'slug'=>'galerie/own-extra-'.$i,'template'=>'gallery']);
catalogueCheck($catalogue->listing()['total']===505&&count($catalogue->order())===501,'complete catalogue remains reachable beyond the existing sort boundary');$beforeLimit=ownOriginalDatabaseHash($db,$tables);
catalogueReject(fn()=>(new AlbumOrder($db,$access))->move($ids[25],'up',2),InvalidArgumentException::class,'existing 500 album movement boundary rejects without full payloads');
catalogueCheck(ownOriginalDatabaseHash($db,$tables)===$beforeLimit,'rejected large movement leaves every earlier row and independent history unchanged');
catalogueCheck(hash_file('sha256',$root.'/own.png')===$source,'owned source PNG remains byte exact');
Db::run($db,"DELETE FROM native_grants WHERE actor_id='admin' AND module='website' AND capability='read'");catalogueReject(fn()=>$catalogue->listing(),DomainException::class,'current read revocation immediately removes catalogue');
echo json_encode(['suite'=>'native-album-catalogue','checks'=>$checks,'memory_proof'=>$proof,'unused_draft_bytes'=>$unusedBytes,'external_requests'=>0])."\n";
