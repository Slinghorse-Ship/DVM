<?php declare(strict_types=1);
ob_start();
require dirname(__DIR__).'/native/bootstrap.php';require __DIR__.'/support/own-website-pdf.php';
use Kulturhus\Native\{TestSupport};
use Kulturhus\Native\Shared\{Access,Db};
use Kulturhus\Native\Cms\{Schema,WebsiteDocuments,Pages,PageTrash,PageCopies,VersionChanges};
use Kulturhus\Native\Web\{Manager,Site,Cache,Website\DocumentLinks,Website\VersionValues};
$db=TestSupport::db();TestSupport::seed($db);Schema::install($db);$managerClub='own-pdf-http-'.bin2hex(random_bytes(6));Db::run($db,"UPDATE native_users SET club_id=? WHERE id IN ('admin','editor','reader')",[Kulturhus\Native\Integration\ManagerBridge::clubKey($managerClub)]);$admin=new Access($db,'admin');$checks=0;
function check(bool $ok,string $label):void{global $checks;if(!$ok)throw new RuntimeException($label);$checks++;echo 'PASS '.$label."\n";}
function denied(callable $fn,string $label):void{try{$fn();}catch(DomainException|InvalidArgumentException|RuntimeException|LogicException){check(true,$label);return;}throw new RuntimeException($label);}
$root=dirname(getenv('KH_NATIVE_TEST_CONFIG')).'/website-documents-tests';mkdir($root,0700,true);
$a=ownWebsitePdf('Own original new PDF A');$b=ownWebsitePdf('Own original new PDF B');file_put_contents($root.'/a.pdf',$a);file_put_contents($root.'/b.pdf',$b);
$docs=new WebsiteDocuments($db,$admin,$root.'/website-documents');$pages=new Pages($db,$admin);
$old=$pages->save(['title'=>'Unverändertes eigenes Original','slug'=>'own-unchanged','body'=>'Ursprünglicher Text']);$pages->publish($old['id'],$old['version']);
$oldRows=[$db->query('SELECT * FROM khcms_pages ORDER BY id')->fetchAll(),$db->query('SELECT * FROM khcms_revisions ORDER BY page_id,revision')->fetchAll()];
$new=$docs->uploadMany([['path'=>$root.'/a.pdf','name'=>'Eigene neue Datei.pdf'],['path'=>$root.'/b.pdf','name'=>'Eigene zweite Datei.PDF']]);$id=$new[0]['id'];$second=$new[1]['id'];
check(count($new)===2&&$id!==$second,'simple multi-upload creates exactly two separate private PDFs');
check(file_get_contents($docs->file($id)['path'])===$a&&file_get_contents($docs->file($second)['path'])===$b,'both actual stored original byte sequences are exact');
check($docs->file($id)['sha256']===hash('sha256',$a)&&$docs->file($id)['bytes']===strlen($a),'exact original byte length and independent SHA captured');
check((fileperms($docs->file($id)['path'])&0777)===0600,'stored PDF is private outside the webroot');
check($docs->publicFile($id)===null,'upload alone never creates a public document');
$p=$pages->save(['title'=>'Eigene Dokumentenseite','slug'=>'verein/satzung','body_html'=>'<h2>Eigene Unterüberschrift</h2><ul><li>Eigener Inhalt</li></ul>','document_links'=>[['id'=>$id,'label'=>'Original öffnen','sha256'=>str_repeat('f',64)]]]);
check($p['document_links'][0]===['id'=>$id,'label'=>'Original öffnen','name'=>'Eigene neue Datei.pdf','bytes'=>strlen($a),'sha256'=>hash('sha256',$a)],'saved page captures server-owned exact PDF metadata instead of supplied fields');
check($docs->publicFile($id)===null,'saved private draft never exposes its document');
$pages->publish($p['id'],$p['version']);$public=$pages->publicPage('verein/satzung');$p=$pages->get($p['id']);$originalRevision=$p['version'];
check($docs->publicFile($id)!==null&&$docs->publicFile($second)===null,'only the exact current published original reference is public');
check(str_contains(DocumentLinks::render($public),'/website-document/'.$id)&&str_contains(DocumentLinks::render($public),'Original öffnen'),'public link uses the original saved title and controlled download route');
$before=Db::run($db,'SELECT * FROM khcms_revisions WHERE page_id=? ORDER BY revision',[$p['id']])->fetchAll();
$p['document_links']=[['id'=>$second,'label'=>'Neue private Fassung']];$p=$pages->save($p,$p['version']);
check($pages->publicPage('verein/satzung')===$public&&$docs->publicFile($id)!==null&&$docs->publicFile($second)===null,'private PDF replacement leaves the public original and link untouched');
$p=$pages->restore($p['id'],$originalRevision,$p['version']);
check($p['document_links']===$public['document_links'],'restore retains exact historical original metadata and ordering');
check($before===Db::run($db,'SELECT * FROM khcms_revisions WHERE page_id=? AND revision<=? ORDER BY revision',[$p['id'],$originalRevision])->fetchAll(),'every previous draft and published revision stays byte-identical');
$changes=VersionChanges::between(['document_links'=>[]],$public);
check(str_contains((new VersionValues($db,$admin))->render(['type'=>'document-links','value'=>$public['document_links']]),'/website-document-preview/'.$id),'original version viewer provides a private exact PDF download');
$p=$pages->unpublish($p['id'],$p['version']);check($docs->publicFile($id)===null,'withdrawal removes public access immediately');
$p=$pages->publish($p['id'],$p['version']);$p=$pages->archive($p['id'],$p['version']);check($docs->publicFile($id)===null,'archive removes public document access without editing original file');
$p=$pages->unarchive($p['id'],$p['version']);$p=$pages->publish($p['id'],$p['version']);$p=(new PageTrash($db,$admin))->move($p['id'],$p['version']);check($docs->publicFile($id)===null,'trash removes public access without destroying original document');
$p=(new PageTrash($db,$admin))->restore($p['id'],$p['version']);check($docs->publicFile($id)===null,'return from trash remains private until explicit publication');
$rows=$db->query('SELECT * FROM khcms_documents ORDER BY id')->fetchAll();$files=glob($root.'/website-documents/*.pdf');
denied(fn()=>$docs->uploadMany([['path'=>$root.'/a.pdf','name'=>'New rollback.pdf']],static fn()=>throw new RuntimeException('Own failed association')),'failed association rolls back the document row and new file');
check($rows===$db->query('SELECT * FROM khcms_documents ORDER BY id')->fetchAll()&&$files===glob($root.'/website-documents/*.pdf'),'rollback retains every prior file and exact immutable record');
file_put_contents($root.'/bad.pdf','Own incorrect PDF');
denied(fn()=>$docs->uploadMany([['path'=>$root.'/a.pdf','name'=>'Valid.pdf'],['path'=>$root.'/bad.pdf','name'=>'Invalid.pdf']]),'a mixed malformed batch leaves no partial page change');
check($rows===$db->query('SELECT * FROM khcms_documents ORDER BY id')->fetchAll()&&$files===glob($root.'/website-documents/*.pdf'),'mixed failure cleans only its own new copies');
file_put_contents($root.'/incomplete.pdf',"%PDF-1.4\nOwn missing end marker");
denied(fn()=>$docs->uploadMany([['path'=>$root.'/incomplete.pdf','name'=>'Incomplete.pdf']]),'truncated PDF is rejected');
foreach(['wrong.png',"Bad\nname.pdf",str_repeat('a',241).'.pdf']as$name)denied(fn()=>$docs->uploadMany([['path'=>$root.'/a.pdf','name'=>$name]]),'invalid PDF name rejected before association');
denied(fn()=>(new WebsiteDocuments($db,new Access($db,'reader'),$root.'/website-documents'))->uploadMany([['path'=>$root.'/a.pdf','name'=>'Own.pdf']]),'reader cannot upload a document');
denied(fn()=>(new WebsiteDocuments($db,new Access($db,'outsider'),$root.'/website-documents'))->file($id),'foreign club cannot read the private PDF');
check((new WebsiteDocuments($db,new Access($db,'outsider'),$root.'/website-documents'))->publicFile($id)===null,'foreign club cannot expose another published original');
denied(fn()=>$pages->save(['title'=>'Own foreign','slug'=>'own-foreign','document_links'=>[['id'=>str_repeat('f',32),'label'=>'']]]),'unknown or foreign document cannot be saved as a page link');
denied(fn()=>$pages->save(['title'=>'Own news','slug'=>'own-news','template'=>'news','news_date'=>'2035-01-13','document_links'=>[['id'=>$id,'label'=>'']]]),'PDF attachments cannot be misrepresented as an unsupported news renderer');
denied(fn()=>WebsiteDocuments::normalize([['id'=>$id,'label'=>''],['id'=>$id,'label'=>'']]),'duplicate document references are rejected');
denied(fn()=>WebsiteDocuments::normalize(array_fill(0,21,['id'=>$id,'label'=>''])),'page has a bounded twenty-document list');
denied(fn()=>$docs->uploadMany(array_fill(0,11,['path'=>$root.'/a.pdf','name'=>'Own.pdf'])),'request has a bounded ten-file upload');
denied(fn()=>DocumentLinks::uploads(['website_pdfs'=>['error'=>[UPLOAD_ERR_OK],'name'=>['Own.pdf'],'tmp_name'=>[$root.'/a.pdf']]]),'HTTP adapter requires actual PHP-uploaded files');
$manager=new Manager($db,$admin,$root.'/media','own-token');
denied(fn()=>$manager->action('website',['csrf'=>'invalid','operation'=>'page-save','title'=>'Own','slug'=>'own'],[]),'actual page action retains CSRF protection');
check(str_contains($manager->main('website',$p['id']),'name="website_pdfs[]"')&&str_contains($manager->main('website',$p['id']),'enctype="multipart/form-data"'),'actual editor supports simple single and multiple upload inside the same saved form');
check(!str_contains((new Manager($db,new Access($db,'reader'),$root.'/media','own-token'))->main('website',$p['id']),'name="website_pdfs[]"'),'reader sees no upload action');
check($oldRows[0]===Db::run($db,'SELECT * FROM khcms_pages WHERE id=?',[$old['id']])->fetchAll()&&$oldRows[1]===Db::run($db,'SELECT * FROM khcms_revisions WHERE page_id=? ORDER BY revision',[$old['id']])->fetchAll(),'unrelated pre-existing page and every original revision unchanged');
check(file_get_contents($docs->file($id)['path'])===$a&&file_get_contents($docs->file($second)['path'])===$b,'all lifecycle and error operations retain exact original PDF bytes');
$path=$docs->file($id)['path'];file_put_contents($path,substr($a,0,-1).'x');denied(fn()=>$docs->file($id),'changed original bytes cannot be served as a trusted document');file_put_contents($path,$a);
symlink($root.'/a.pdf',$root.'/linked.pdf');denied(fn()=>$docs->uploadMany([['path'=>$root.'/linked.pdf','name'=>'Own.pdf']]),'symlink input cannot bypass local upload boundaries');
denied(fn()=>new WebsiteDocuments($db,$admin,dirname(__DIR__).'/public'),'document storage cannot be inside the actual webroot');
check(str_contains(Kulturhus\Native\Integration\Routes::translate('/website-document-preview/'.$id),'native_endpoint=website-document-preview'),'integrated route retains the exact private document identity');

$p=$pages->get($p['id']);$p=$pages->publish($p['id'],$p['version']);
$web=$root.'/web';mkdir($web,0700);$socket=stream_socket_server('tcp://127.0.0.1:0',$errno,$error);if(!$socket)throw new RuntimeException('Own loopback socket required.');$address=stream_socket_get_name($socket,false);fclose($socket);$origin='http://'.$address;
$router=$root.'/http-router.php';file_put_contents($router,'<?php require '.var_export(dirname(__DIR__).'/native/bootstrap.php',true).'; require '.var_export(dirname(__DIR__).'/src/bootstrap.php',true).'; $db=\\Kulturhus\\Native\\TestSupport::db(); if(!\\Kulturhus\\Native\\Integration\\PublicSite::serve($db,new \\Kulturhus\\Native\\Shared\\Access($db,"reader"),'.var_export($root.'/media',true).','.var_export($root,true).','.var_export($managerClub,true).','.var_export($origin,true).')) http_response_code(404);');
$process=proc_open([...TestSupport::phpCommand(),'-S',$address,'-t',$web,$router],[0=>['pipe','r'],1=>['file',$root.'/server.log','a'],2=>['file',$root.'/server.log','a']],$pipes);if(!is_resource($process))throw new RuntimeException('Own isolated HTTP server required.');
$request=static function(string $path,string $method='GET')use($origin):array{$context=stream_context_create(['http'=>['method'=>$method,'ignore_errors'=>true,'timeout'=>10,'follow_location'=>0]]);$body=@file_get_contents($origin.$path,false,$context);$raw=$http_response_header??[];$status=0;$headers=[];foreach($raw as$header){if(preg_match('~^HTTP/\S+ (\d+)~',$header,$m))$status=(int)$m[1];elseif(str_contains($header,':')){[$key,$value]=explode(':',$header,2);$headers[strtolower($key)]=trim($value);}}return ['status'=>$status,'headers'=>$headers,'body'=>$body===false?'':$body];};
try{
    for($i=0;$i<40;$i++){if($request('/website-document/'.$id)['status']!==0)break;usleep(50000);}
    $r=$request('/website-document/'.$id);
    check($r['status']===200&&$r['body']===$a,'actual installed public controller returns exact anonymous PDF bytes');
    foreach(['/verein/satzung/','/site/verein/satzung']as$path){$pageResponse=$request($path);check($pageResponse['status']===200&&str_contains(html_entity_decode($pageResponse['body'],ENT_QUOTES|ENT_HTML5,'UTF-8'),'Eigene Unterüberschrift')&&str_contains($pageResponse['body'],'/website-document/'.$id),'actual original statute-page route supports own structured content and PDF links '.$path);}
    check(($r['headers']['content-type']??'')==='application/pdf'&&str_starts_with($r['headers']['content-disposition']??'','attachment;'),'actual HTTP response is an explicit PDF download');
    check(($r['headers']['cache-control']??'')==='private, no-store'&&($r['headers']['x-content-type-options']??'')==='nosniff'&&str_contains($r['headers']['content-security-policy']??'','sandbox'),'actual HTTP prevents persistent cache and inline script execution');
    $head=$request('/website-document/'.$id,'HEAD');check($head['status']===200&&$head['body']===''&&(int)$head['headers']['content-length']===strlen($a),'actual HEAD returns exact original length with no PDF body');
    foreach(['POST','PUT','OPTIONS']as$method){$refused=$request('/website-document/'.$id,$method);check($refused['status']===405&&($refused['headers']['allow']??'')==='GET, HEAD','actual PDF download rejects '.$method);}
    foreach(['/website-document/'.$second,'/website-document/'.str_repeat('f',32),'/website-document/invalid','/website-document-preview/'.$id]as$path)check($request($path)['status']===404,'actual public controller cannot expose private, missing or malformed document '.$path);
    $p=$pages->unpublish($p['id'],$p['version']);check($request('/website-document/'.$id)['status']===404,'actual withdrawal revokes the previously downloadable PDF');
    check(file_get_contents($docs->file($id)['path'])===$a,'real GET, HEAD, refused writes and withdrawal preserve original PDF bytes');
}finally{proc_terminate($process);proc_close($process);}


$originalRecords=$db->query('SELECT * FROM khcms_documents ORDER BY id')->fetchAll();$originalFiles=glob($root.'/website-documents/*.pdf');
denied(fn()=>$docs->uploadMany([['path'=>$root.'/a.pdf','name'=>'Own stale new PDF.pdf']],function($copies)use($pages,$p){$data=$p;$data['document_links']=[['id'=>$copies[0]['id'],'label'=>'New']];return $pages->save($data,0);}), 'actual stale page version rolls back its associated new PDF');
check($originalRecords===$db->query('SELECT * FROM khcms_documents ORDER BY id')->fetchAll()&&$originalFiles===glob($root.'/website-documents/*.pdf'),'stale form creates no orphan PDF, row or page revision');
$associated=$docs->uploadMany([['path'=>$root.'/a.pdf','name'=>'Own associated new PDF.pdf']],fn($copies)=>$pages->save(['title'=>'Own atomic page','slug'=>'own-atomic-documents','document_links'=>[['id'=>$copies[0]['id'],'label'=>'Atomic original']]]));
check(count($associated['document_links'])===1&&file_get_contents($docs->file($associated['document_links'][0]['id'])['path'])===$a&&$docs->publicFile($associated['document_links'][0]['id'])===null,'atomic upload and new page save preserve exact private original together');
$copy=(new PageCopies($db,$admin))->create($associated['id'],$associated['version'],['title'=>'Own document page copy','slug'=>'own-document-copy','basis'=>'draft']);
check($copy['document_links']===$associated['document_links']&&$copy['published_revision']===null,'page copy retains exact original document identity and metadata without publication');


$previousMethod=$_SERVER['REQUEST_METHOD']??null;$previousStatus=http_response_code();
try{$_SERVER['REQUEST_METHOD']='POST';http_response_code(422);$post=['operation'=>'page-save','document_links_present'=>'1','csrf'=>'own-token','id'=>$associated['id'],'title'=>'Own unsaved corrected title','excerpt'=>'Own pending input','version'=>(string)$associated['version']];
    $restored=DocumentLinks::rejectedPage($associated,$post,'own-token');check($restored['title']===$post['title']&&$restored['excerpt']===$post['excerpt']&&$restored['version']===$associated['version'],'rejected HTML PDF form retains exact unsaved text and original version');
    check(DocumentLinks::rejectedPage($associated,array_replace($post,['csrf'=>'wrong']),'own-token')===$associated,'failed CSRF cannot insert posted values into an error view');
    check(DocumentLinks::rejectedPage($associated,array_replace($post,['id'=>str_repeat('f',32)]),'own-token')===$associated,'rejected values cannot cross the exact original page identity');
    http_response_code(200);check(DocumentLinks::rejectedPage($associated,$post,'own-token')===$associated,'successful rendering cannot replace the actual saved original with old POST values');
}finally{if($previousMethod===null)unset($_SERVER['REQUEST_METHOD']);else $_SERVER['REQUEST_METHOD']=$previousMethod;http_response_code($previousStatus?:200);}

echo $checks." new website PDF, original, publication, access and rollback checks passed; own synthetic files, no transport.\n";

ob_end_flush();
