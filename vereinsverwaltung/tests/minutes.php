<?php declare(strict_types=1);
require __DIR__.'/../native/bootstrap.php';require __DIR__.'/../src/bootstrap.php';
use Kulturhus\Verein\{Database,Minutes,Permissions,Settings};
require __DIR__.'/../src/Web/View.php';

$checks=0;
function check(bool $value,string $label):void{global $checks;if(!$value)throw new RuntimeException($label);++$checks;echo "PASS $label\n";}
function denied(callable $fn,string $label):void{try{$fn();}catch(DomainException){check(true,$label);return;}throw new RuntimeException('Not denied: '.$label);}
function renderMinutes(PDO $db,string $actor,array $query,array $post=[]):string{$_SESSION=['actor'=>$actor,'csrf'=>'synthetic-csrf'];$_GET=['view'=>'minutes']+$query;$_POST=$post;ob_start();try{require __DIR__.'/../views/Governance/minutes.php';return (string)ob_get_contents();}finally{ob_end_clean();}}
$db=\Kulturhus\Verein\Tests\MySqlFixture::connect(':memory:');Database::migrate($db);Database::migrate($db);
$db->exec("INSERT INTO clubs VALUES('a','Synthetic A'),('b','Synthetic B'); INSERT INTO actors(id,club_id,role,is_superuser,minutes_access) VALUES('manager','a','members',0,NULL),('super','a','member',1,NULL),('writer','a','member',0,'write'),('reader','a','member',0,NULL),('blocked','a','member',0,'none'),('foreign','b','member',0,'write'),('editor','a','editor',0,'write'),('finance','a','treasurer',0,NULL),('staff','a','member',0,NULL)");
$key=static fn()=>bin2hex(random_bytes(16));
$write=new Minutes($db,'writer');$read=new Minutes($db,'reader');$foreign=new Minutes($db,'foreign');
$base=['title'=>'Synthetische Mitgliederversammlung','meeting_on'=>'2026-08-05','source_kind'=>'editor','body_html'=>'<h2>Eröffnung</h2><p>Protokoll mit <strong>Beschluss</strong>.</p>'];
check($write->canRead()&&$write->canWrite(),'explicit minutes writer can edit');
check($read->canRead()&&!$read->canWrite(),'ordinary member reads published minutes without member-management rights');
check((new Minutes($db,'finance'))->canRead()&&!(new Minutes($db,'finance'))->canWrite(),'treasurer gains no automatic writing');
check((new Minutes($db,'manager'))->canRead()&&!(new Minutes($db,'manager'))->canWrite(),'settings manager gains no automatic writing');
check((new Minutes($db,'super'))->canWrite(),'explicit superuser may manage minutes');
foreach(['blocked','editor','missing'] as $actor){$svc=new Minutes($db,$actor);check(!$svc->canRead()&&!$svc->canWrite(),'blocked minutes access: '.$actor);denied(fn()=>$svc->listing(),'listing denied: '.$actor);}

$dirty='<h2 style="color:red" onclick="alert(1)">Titel</h2><p class="x">Text <b>fett</b> <i>kursiv</i> <a href="javascript:alert(1)">Linktext</a><img src=x onerror=alert(1)></p><script>alert("SECRET")</script><style>body{display:none}</style><svg onload="alert(1)"><text>SVG_SECRET</text></svg><iframe src="https://example.invalid">FRAME_SECRET</iframe><!-- HIDDEN -->';
$clean=Minutes::sanitize($dirty);
check($clean==='<h2>Titel</h2><p>Text <strong>fett</strong> <em>kursiv</em> Linktext</p>','sanitizer rebuilds only allowed formatting and visible link text');
check(Minutes::sanitize('<div>Grüße &amp; Kultur<br>zweite Zeile</div>')==='<p>Grüße &amp; Kultur<br>zweite Zeile</p>','UTF-8 entities and editor line breaks preserved');
check(Minutes::sanitize($clean)===$clean,'sanitization is stable when applied repeatedly');
check(Minutes::sanitize('<div><p>Absatz eins</p><p>Absatz zwei</p></div>')==='<p>Absatz eins</p><p>Absatz zwei</p>','nested editor blocks do not create invalid nested paragraphs');
check(!str_contains(Minutes::sanitize('<math><mtext><table><mglyph><style><!--</style><img title="--><img src=x onerror=alert(1)>">'),'<img'),'malformed foreign markup never creates active output');
denied(fn()=>Minutes::sanitize(str_repeat('A',100001)),'oversized editor input rejected');
denied(fn()=>Minutes::sanitize("text\0text"),'editor control characters rejected');
denied(fn()=>Minutes::sanitize("\xff"),'invalid UTF-8 rejected');

$token=$key();$id=$write->save('',0,array_replace($base,['body_html'=>$dirty]),$token);$draft=$write->entry($id);
check($draft['state']==='draft'&&$draft['version']===1&&$draft['body_html']===$clean,'new editor content is sanitized and stored as draft');
check(!isset($draft['request_key'],$draft['request_hash'],$draft['bytes']),'entry omits request keys and binary data');
check($write->save('',0,array_replace($base,['body_html'=>$dirty]),$token)===$id&&count($write->listing())===1,'new-entry retry deduplicates');
denied(fn()=>$write->save('',0,array_replace($base,['title'=>'Different']),$token),'changed retry request rejected');
check($read->listing()===[]&&$read->listing(['state'=>'all','q'=>'Synthetische'])===[],'unpublished titles and counts absent from reader listings');
denied(fn()=>$read->entry($id),'member cannot read guessed draft ID');
denied(fn()=>$read->history($id),'member cannot inspect private draft history');
denied(fn()=>$read->save('',0,$base,$key()),'member cannot create drafts');
denied(fn()=>$read->transition($id,1,'publish'),'member cannot publish');
denied(fn()=>$foreign->entry($id),'foreign writer cannot read draft');
denied(fn()=>$foreign->save($id,1,$base,$key()),'foreign writer cannot edit draft');
denied(fn()=>$foreign->transition($id,1,'publish'),'foreign writer cannot publish draft');
denied(fn()=>$write->transition($id,0,'publish'),'stale publish rejected');
$write->transition($id,1,'publish');
check($read->entry($id)['body_html']===$clean&&count($read->listing())===1,'publication exposes sanitized content to members');
check($read->entry($id)['published_by']==='writer'&&$read->entry($id)['published_at']!==null,'publication records person and timestamp');
check($read->listing(['state'=>'draft'])===[]&&$read->listing(['year'=>'2025'])===[],'reader filters cannot expose drafts and year filter works');
check(count($read->listing(['year'=>'2026','q'=>'Mitgliederversammlung']))===1,'published title search works');
denied(fn()=>$write->save($id,2,$base,$key()),'published minutes cannot be silently rewritten');
denied(fn()=>$foreign->entry($id),'foreign reader cannot read published minutes');
$write->transition($id,2,'withdraw');
denied(fn()=>$read->entry($id),'withdrawal immediately removes direct read access');
check($read->listing()===[],'withdrawal immediately removes listing');
$write->save($id,3,array_replace($base,['body_html'=>'<p>Korrigiert</p>']),$key());
check($write->entry($id)['version']===4&&$write->entry($id)['body_html']==='<p>Korrigiert</p>','withdrawn draft can be corrected');
denied(fn()=>$write->save($id,3,$base,$key()),'stale edit rejected');
$write->transition($id,4,'archive');
check($write->entry($id)['state']==='archived'&&count($write->listing(['state'=>'archived']))===1,'writer can archive and list archived minutes');
denied(fn()=>$read->entry($id),'archived content hidden from members');
denied(fn()=>$write->save($id,5,$base,$key()),'archive requires restore before editing');
$write->transition($id,5,'restore');
check($write->entry($id)['state']==='draft'&&$read->listing()===[],'restore returns to private draft, never auto-publishes');
denied(fn()=>$write->transition($id,6,'restore'),'invalid state transition rejected');
check(array_column($write->history($id),'action')===['restore','archive','updated','withdraw','publish','created'],'all meaningful actions audited');
$retryHtml=renderMinutes($db,'writer',['mid'=>$id,'compose'=>'1'],['action'=>'minutes-save','mid'=>$id,'body_mode'=>'html','version'=>'5','title'=>'Unsaved correction','meeting_on'=>'2026-08-05','source_kind'=>'editor','body_html'=>'<p>Unsaved old-version text</p>','request_key'=>$key()]);
check(str_contains($retryHtml,'name="version" value="5"')&&str_contains($retryHtml,'Unsaved old-version text'),'failed POST preserves original version and unsaved text instead of silently upgrading concurrency token');
$write->transition($id,6,'publish');$readerHtml=renderMinutes($db,'reader',['mid'=>$id,'compose'=>'1']);
check(!str_contains($readerHtml,'data-minutes-form')&&!str_contains($readerHtml,'minutes-transition')&&str_contains($readerHtml,'<p>Korrigiert</p>'),'readonly reader cannot obtain editor or publish form through compose query');
$write->transition($id,7,'withdraw');

// A real, public-domain template is used only as a harmless PDF fixture.
$bytes=(string)file_get_contents(__DIR__.'/../resources/donation-forms/money-original.pdf');
$upload=['name'=>'Synthetisches Protokoll.pdf','bytes'=>$bytes];$pdfData=array_replace($base,['title'=>'PDF-Protokoll','source_kind'=>'pdf','body_html'=>'<script>ignored</script>']);
$pdfId=$write->save('',0,$pdfData,$key(),$upload);
check($write->entry($pdfId)['body_html']===''&&$write->entry($pdfId)['file_name']===$upload['name'],'PDF content is exclusive and metadata retained');
check($write->file($pdfId)['bytes']===$bytes&&$write->file($pdfId)['sha256']===hash('sha256',$bytes),'PDF bytes and checksum stored privately');
denied(fn()=>$read->file($pdfId),'draft PDF cannot be downloaded by guessed ID');
denied(fn()=>$foreign->file($pdfId),'foreign club cannot download PDF');
$write->transition($pdfId,1,'publish');
check($read->file($pdfId)['bytes']===$bytes,'published PDF can be downloaded by member');
$write->transition($pdfId,2,'archive');
denied(fn()=>$read->file($pdfId),'archiving revokes PDF download immediately');
$write->transition($pdfId,3,'restore');$write->save($pdfId,4,array_replace($pdfData,['title'=>'PDF-Titel korrigiert']),$key());
check($write->file($pdfId)['sha256']===hash('sha256',$bytes)&&$write->entry($pdfId)['version']===5,'PDF metadata can be edited without re-upload');
$write->save($pdfId,5,array_replace($base,['title'=>'Jetzt Editor']),$key());
denied(fn()=>$write->file($pdfId),'switching to editor removes stale PDF download');
check($db->query('SELECT COUNT(*) FROM minutes_files')->fetchColumn()===0,'switching source removes obsolete stored PDF');
denied(fn()=>$write->save($pdfId,6,$pdfData,$key()),'switching back to PDF requires new file');
denied(fn()=>$write->save('',0,$base,$key(),$upload),'mixed editor and upload rejected');
foreach([
    ['name'=>'bad.html','bytes'=>$bytes],['name'=>'../bad.pdf','bytes'=>$bytes],['name'=>'bad\\name.pdf','bytes'=>$bytes],['name'=>'bad.pdf','bytes'=>'<html>no</html>'],['name'=>'bad.pdf','bytes'=>substr($bytes,0,-30)],['name'=>'huge.pdf','bytes'=>'%PDF-1.7'.str_repeat('A',Minutes::MAX_PDF_BYTES).'%%EOF'],
] as $bad)denied(fn()=>$write->save('',0,$pdfData,$key(),$bad),'invalid or excessive PDF rejected');
foreach([['title'=>''],['title'=>str_repeat('A',201)],['meeting_on'=>'2026-02-30'],['source_kind'=>'html']] as $bad)denied(fn()=>$write->save('',0,array_replace($base,$bad),$key()),'invalid metadata rejected');
$empty=$write->save('',0,array_replace($base,['body_html'=>'<p>&nbsp;</p>']),$key());
denied(fn()=>$write->transition($empty,1,'publish'),'empty editor draft cannot be published');

$settings=new Settings($db,'manager');$roleData=['name'=>'Schriftwart','members_access'=>'none','finance_access'=>'none','inventory_access'=>'none','loans_access'=>'none','documents_access'=>'none','press_access'=>'none','minutes_access'=>'write','settings_access'=>false];
$role=$settings->saveRole('',0,$roleData);$settings->assignRole('staff',$role,0);$staff=new Minutes($db,'staff');
check($staff->canWrite()&&Permissions::actor($db,'staff')['members_access']==='none'&&Permissions::actor($db,'staff')['documents_access']==='none','minutes role is independent from member and general-document access');
$settings->saveRole($role,1,array_diff_key($roleData,['minutes_access'=>true]));
check($staff->canWrite(),'editing older role payload preserves minutes grant');
$settings->saveRole($role,2,array_replace($roleData,['minutes_access'=>'read']));
check($staff->canRead()&&!$staff->canWrite(),'minutes write grant can be revoked to read');
denied(fn()=>$staff->save('',0,$base,$key()),'revoked writer cannot save');
$db->exec("UPDATE actors SET active=0 WHERE id='writer'");
check(!$write->canRead()&&!$write->canWrite(),'inactive account loses all minutes access');
denied(fn()=>$write->entry($id),'inactive account cannot read stored draft');
check(\Kulturhus\Verein\Tests\MySqlFixture::integrity($db)==='ok'&&\Kulturhus\Verein\Tests\MySqlFixture::foreignKeyErrors($db)===[],'minutes storage preserves database integrity');
echo "$checks minutes checks passed\n";
