<?php declare(strict_types=1);ob_start();
require dirname(__DIR__).'/native/bootstrap.php';require __DIR__.'/support/own-website-pdf.php';require __DIR__.'/support/original-database-hash.php';
use Kulturhus\Native\TestSupport;
use Kulturhus\Native\Cms\{Schema,Media,MediaUploads,WebsiteDocuments,Pages,UploadTransaction,MediaQuota,Conflict};
use Kulturhus\Native\Shared\{Access,Db};
use Kulturhus\Native\Web\{Manager};
use Kulturhus\Native\Web\Website\PagePhotoUploads;
$db=TestSupport::db();TestSupport::seed($db);Schema::install($db);$access=new Access($db,'admin');$n=0;
function uploadCheck(bool $ok,string $label):void {global $n;if(!$ok)throw new RuntimeException($label);$n++;echo 'PASS '.$label."\n";}
function uploadReject(callable $fn,string $label,string $class=Throwable::class):void {try{$fn();}catch(Throwable $e){uploadCheck($e instanceof $class,$label);return;}throw new RuntimeException('Expected rejection: '.$label);}
$root=dirname(__DIR__,2).'/.local/page-photo-upload-'.bin2hex(random_bytes(8));mkdir($root,0700,true);
$source=$root.'/new.png';$im=imagecreatetruecolor(32,24);imagefill($im,0,0,imagecolorallocate($im,30,100,180));imagepng($im,$source);unset($im);file_put_contents($root.'/new.pdf',ownWebsitePdf('Own combined upload'));file_put_contents($root.'/bad.png','Own invalid bytes');file_put_contents($root.'/bad.pdf','Own invalid PDF');
$files=[['path'=>$source,'name'=>'Own one.png'],['path'=>$source,'name'=>'Own two.png']];$pdf=[['path'=>$root.'/new.pdf','name'=>'Own new.pdf']];
$media=new Media($db,$access,$root.'/media');$photos=new MediaUploads($db,$access,$root.'/media');$docs=new WebsiteDocuments($db,$access,$root.'/website-documents');$pages=new Pages($db,$access);
$old=$media->upload($source,'Own original.png','Original');$page=$pages->save(['title'=>'Own original page','slug'=>'own-original-page','media_ids'=>[$old['id']],'gallery_items'=>[['id'=>$old['id'],'caption'=>'Own exact caption','tags'=>['Own exact tag']]]]);$page=$pages->publish($page['id'],$page['version']);$published=$pages->publicPage($page['slug']);
$snapshot=static function()use($db,$root):array{$hashes=[];foreach([$root.'/media',$root.'/website-documents']as$dir)if(is_dir($dir))foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir,FilesystemIterator::SKIP_DOTS))as$f)if($f->isFile())$hashes[$f->getPathname()]=hash_file('sha256',$f->getPathname());ksort($hashes);return [ownOriginalDatabaseHash($db,['khcms_media','khcms_documents','khcms_pages','khcms_revisions']),$hashes];};
$base=$snapshot();
$saved=UploadTransaction::run($db,fn($tx)=>$photos->uploadMany($files,fn($copies)=>$docs->uploadMany($pdf,function($documents)use($pages,$page,$copies){$data=$page;$data['media_ids']=[...$page['media_ids'],...array_column($copies,'id')];$data['document_links']=[['id'=>$documents[0]['id'],'label'=>'Own linked PDF']];return $pages->save($data,$page['version']);},$tx),$tx));
uploadCheck($saved['version']===3&&count($saved['media_ids'])===3&&count($saved['document_links'])===1,'two photos one PDF and original page commit together');
uploadCheck($saved['media_ids'][0]===$old['id']&&$saved['gallery_items'][0]===$page['gallery_items'][0],'new photos append without rewriting original identity caption or tags');
uploadCheck($pages->publicPage($page['slug'])===$published,'combined private save keeps the exact earlier publication');
uploadCheck(file_get_contents($docs->file($saved['document_links'][0]['id'])['path'])===file_get_contents($root.'/new.pdf'),'new associated PDF is byte-identical and remains private');
foreach(array_slice($saved['media_ids'],1)as$id){uploadCheck($media->publicVariant($id,'thumb')===null,'new photo stays private before publication');foreach(['original','large','medium','thumb']as$variant){$f=$media->variant($id,$variant);uploadCheck($f['width']===32&&$f['height']===24&&(fileperms($f['path'])&0077)===0,'new actual pixel variant and permissions '.$variant);}}
$baseline=$snapshot();
$failures=[
 ['bad second photo',fn()=>$photos->uploadMany([$files[0],['path'=>$root.'/bad.png','name'=>'Bad.png']])],
 ['bad PDF after new photos',fn()=>UploadTransaction::run($db,fn($tx)=>$photos->uploadMany($files,fn()=>$docs->uploadMany([['path'=>$root.'/bad.pdf','name'=>'Bad.pdf']],null,$tx),$tx))],
 ['association failure after photos and PDFs',fn()=>UploadTransaction::run($db,fn($tx)=>$photos->uploadMany($files,fn()=>$docs->uploadMany($pdf,static function(){throw new DomainException('Own rejected association');},$tx),$tx))],
 ['late outer failure after successful inner copies',fn()=>UploadTransaction::run($db,function($tx)use($photos,$docs,$files,$pdf){$photos->uploadMany($files,null,$tx);$docs->uploadMany($pdf,null,$tx);throw new DomainException('Own outer rollback');})],
 ['stale actual page version',fn()=>UploadTransaction::run($db,fn($tx)=>$photos->uploadMany($files,fn($copies)=>$docs->uploadMany($pdf,fn($documents)=>$pages->save(array_replace($saved,['media_ids'=>array_column($copies,'id'),'document_links'=>[['id'=>$documents[0]['id'],'label'=>'Own stale PDF']]]),2),$tx),$tx))],
];
foreach($failures as[$label,$operation]){uploadReject($operation,$label.' rejects');uploadCheck($snapshot()===$baseline,$label.' leaves all rows and exact files unchanged');}
UploadTransaction::run($db,function($tx)use($photos,$docs,$files,$pdf){try{$photos->uploadMany($files,fn()=>$docs->uploadMany($pdf,static function(){throw new DomainException('Own caught failure');},$tx),$tx);}catch(DomainException){}});
uploadCheck($snapshot()===$baseline,'caught inner failure cannot commit rows or orphan files');
$escaped=null;UploadTransaction::run($db,function($tx)use(&$escaped){$escaped=$tx;});
uploadReject(fn()=>$photos->uploadMany($files,null,$escaped),'expired transaction cannot upload',LogicException::class);
UploadTransaction::run($db,function($tx)use($db){uploadReject(fn()=>$tx->require(TestSupport::db()),'another PDO cannot reuse the active context',LogicException::class);uploadReject(fn()=>serialize($tx),'transaction cannot be serialized',LogicException::class);uploadReject(fn()=>clone $tx,'transaction cannot be cloned',Error::class);});
uploadReject(fn()=>$photos->uploadMany(array_fill(0,11,$files[0])),'oversized photo batch rejected',InvalidArgumentException::class);
foreach([[['path'=>'https://example.invalid/x','name'=>'New.png']],[['path'=>$source,'name'=>'New.png','unexpected'=>'x']],[['path'=>$source,'name'=>[]]]]as$bad)uploadReject(fn()=>$photos->uploadMany($bad),'malformed source rejected',InvalidArgumentException::class);
uploadCheck($snapshot()===$baseline,'malformed batches cannot change stored originals');
$quota=new MediaQuota($db,$access);$q=$quota->get();$quota->save(1,$q['version']);
$noise=imagecreatetruecolor(250,250);$bytes=random_bytes(250*250*3);$at=0;for($y=0;$y<250;$y++)for($x=0;$x<250;$x++){imagesetpixel($noise,$x,$y,(ord($bytes[$at++])<<16)|(ord($bytes[$at++])<<8)|ord($bytes[$at++]));}imagepng($noise,$root.'/own-noise.png');unset($noise,$bytes);
$large=[['path'=>$root.'/own-noise.png','name'=>'Own noise one.png'],['path'=>$root.'/own-noise.png','name'=>'Own noise two.png']];
uploadReject(fn()=>$photos->uploadMany($large),'current quota rejects the second copy without partial first');uploadCheck($snapshot()===$baseline,'quota failure rolls back every new row and file');
$q=$quota->get();$quota->save(0,$q['version']);
uploadCheck(PagePhotoUploads::fromFiles([])===[],'empty multipart selection is harmless');
uploadReject(fn()=>PagePhotoUploads::fromFiles(['page_photos'=>['error'=>[UPLOAD_ERR_OK],'tmp_name'=>[$source],'name'=>['Own.png']]]),'local CLI path cannot impersonate a real HTTP upload',InvalidArgumentException::class);
$manager=new Manager($db,$access,$root.'/media','own-token');$post=['operation'=>'page-photo-upload','id'=>$saved['id'],'version'=>(string)$saved['version'],'csrf'=>'own-token'];
uploadReject(fn()=>$manager->action('website',array_replace($post,['csrf'=>'bad']),[]),'direct photo route checks actual CSRF',DomainException::class);
uploadReject(fn()=>$manager->action('website',array_replace($post,['version'=>'2']),[]),'direct photo route checks exact original version',Conflict::class);
uploadReject(fn()=>$manager->action('website',array_replace($post,['version'=>[]]),[]),'direct photo route rejects malformed version');
uploadReject(fn()=>$manager->action('website',$post,['photo'=>['error'=>UPLOAD_ERR_OK,'tmp_name'=>$source,'name'=>'Own.png']]),'direct route requires an actual uploaded file',InvalidArgumentException::class);
$foreign=(new Pages($db,new Access($db,'outsider')))->save(['title'=>'Own foreign fixture','slug'=>'foreign-upload']);
uploadReject(fn()=>$manager->action('website',array_replace($post,['id'=>$foreign['id'],'version'=>'1']),[]),'direct route cannot target another club');
Db::run($db,"DELETE FROM native_grants WHERE actor_id='admin' AND module='website' AND capability='edit'");
uploadReject(fn()=>$photos->uploadMany($files),'batch checks immediate edit revocation',DomainException::class);
uploadReject(fn()=>$manager->action('website',$post,[]),'direct route checks immediate edit revocation',DomainException::class);
echo json_encode(['suite'=>'native-page-photo-upload','checks'=>$n,'external_requests'=>0])."\n";ob_end_flush();
