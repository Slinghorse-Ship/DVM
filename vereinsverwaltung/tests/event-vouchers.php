<?php declare(strict_types=1);
require __DIR__.'/../src/bootstrap.php';
require_once __DIR__.'/mysql-bootstrap.php';
use Kulturhus\Verein\{Database,Registry,EventVouchers,EventVoucherMail,WebsiteCalendar,WorkspaceAccess,ManagerNavigation};
use Kulturhus\Verein\Tests\MySqlFixture;
set_error_handler(static function($severity,$message,$file,$line){throw new ErrorException($message,0,$severity,$file,$line);});
if(($argv[1]??'')==='redeem-race'){
    $db=MySqlFixture::existing($argv[2]);
    try{(new EventVouchers($db,'helper'))->change($argv[3],$argv[4],1,'redeem','1','',bin2hex(random_bytes(16)));echo 'redeemed';}
    catch(DomainException){echo 'denied';}exit;
}
$n=0;function ok(bool $v,string $m):void{global $n;if(!$v)throw new RuntimeException($m);++$n;echo "PASS $m\n";}
function denied(callable $f,string $m):void{try{$f();}catch(DomainException){ok(true,$m);return;}throw new RuntimeException($m);}
$db=MySqlFixture::connect();Database::migrate($db);Database::migrate($db);
\Kulturhus\Verein\EventVoucherSchema::install($db);\Kulturhus\Verein\EventVoucherSchema::install($db);
$db->exec("INSERT INTO clubs VALUES('a','A'),('b','B'); INSERT INTO actors(id,club_id,role,is_superuser) VALUES('admin','a','members',1),('member','a','member',0),('second','a','member',0),('helper','a','member',0),('outsider','a','member',0),('editor','a','editor',0),('foreign','b','members',1)");
$r=new Registry($db,'admin');$key=fn()=>bin2hex(random_bytes(16));
$p=$r->saveContact(null,0,'person','Mara Muster','shared@example.invalid','Testweg 1');$r->addMembership($p,'','aktiv','2026-01-01');
$p2=$r->saveContact(null,0,'person','Mika Muster','shared@example.invalid','Testweg 1');$r->addMembership($p2,'','passiv','2026-01-01');
$p3=$r->saveContact(null,0,'person','Ohne Mail','','Testweg 1');$r->addMembership($p3,'','aktiv','2026-01-01');
$nonmember=$r->saveContact(null,0,'person','Kontakt','contact@example.invalid','');
$db->prepare('UPDATE actors SET contact_id=? WHERE id=?')->execute([$p,'member']);$db->prepare('UPDATE actors SET contact_id=? WHERE id=?')->execute([$p2,'second']);
$eventData=['title'=>'Sommerfest · Testdaten','start'=>'2026-09-20T18:00','end'=>'2026-09-20T22:00','location'=>'Testgelände','category'=>'verein','description'=>'','activity_kind'=>'event','activity_state'=>'planned'];
$source=$r->saveAppointment(null,0,$eventData,[]);$s=new EventVouchers($db,'admin');$openKey=$key();$ev=$s->open($source,$openKey);
ok($s->open($source,$openKey)===$ev&&$s->open($source,$key())===$ev,'existing calendar event never copied twice');
$member=new EventVouchers($db,'member');$helper=new EventVouchers($db,'helper');$foreign=new EventVouchers($db,'foreign');
denied(fn()=>$member->open($source,$key()),'member cannot issue event');
denied(fn()=>$member->event($ev),'unassigned member cannot inspect event');
$benefits=[['label'=>'Essen','kind'=>'food','unit'=>'piece','amount'=>'1'],['label'=>'Getränke','kind'=>'drink','unit'=>'piece','amount'=>'2'],['label'=>'Verzehrguthaben','kind'=>'other','unit'=>'eur','amount'=>'12,50']];
$grantKey=$key();$ids=$s->grant($ev,[$p,$p2],$benefits,$grantKey);ok(count($ids)===6,'food drinks and euro credit individually allocated');
ok($s->grant($ev,[$p2,$p],$benefits,$grantKey)===$ids,'repeated allocation request creates no extra credit');
denied(fn()=>$s->grant($ev,[$p],$benefits,$key()),'new form cannot silently duplicate same active benefit');
denied(fn()=>$s->grant($ev,[$p3,$nonmember],$benefits,$key()),'nonmember rejected atomically');ok(count($s->vouchers($ev))===6,'failed batch leaves no partial grants');
ok(count($member->vouchers($ev))===3&&count((new EventVouchers($db,'second'))->vouchers($ev))===3,'members with shared email have separate own balances');
denied(fn()=>$foreign->event($ev),'foreign club cannot see event');
ok(!(new EventVouchers($db,'editor'))->canRead(),'editor boundary retained');
ok((new WorkspaceAccess($db,'member'))->allows(['view'=>'portal','tab'=>'vouchers']),'member portal route allowed');
ok(ManagerNavigation::key(['view'=>'portal','tab'=>'vouchers'])==='portal:vouchers','navigation selects correct item');
denied(fn()=>$member->change($ev,$ids[0],1,'redeem','1','',$key()),'member cannot redeem own benefits');
$s->configure($ev,1,true,['helper'],$key());ok($helper->canRedeem($ev)&&$helper->canRead(),'explicit event helper can redeem without member-admin rights');
denied(fn()=>$helper->members(),'helper cannot enumerate member directory');
$vs=$member->vouchers($ev);$drink=array_values(array_filter($vs,fn($v)=>$v['kind']==='drink'))[0];$food=array_values(array_filter($vs,fn($v)=>$v['kind']==='food'))[0];$eur=array_values(array_filter($vs,fn($v)=>$v['unit']==='eur'))[0];
$redeemKey=$key();$move=$helper->change($ev,$drink['id'],1,'redeem','1','',$redeemKey);
ok($helper->change($ev,$drink['id'],1,'redeem','1','',$redeemKey)===$move,'double click redeemed only once');
denied(fn()=>$helper->change($ev,$drink['id'],1,'redeem','1','',$key()),'stale balance rejected');
denied(fn()=>$helper->change($ev,$drink['id'],2,'redeem','2','',$key()),'overspending rejected');
foreach(['-1','0','1.5','1e2','1000001'] as $bad)denied(fn()=>EventVouchers::amount($bad,'piece'),'invalid quantity rejected '.$bad);
ok(EventVouchers::amount('0,01','eur')===1&&EventVouchers::amount('12.50','eur')===1250,'euro amounts use exact cents');
$helper->change($ev,$eur['id'],1,'redeem','2,55','',$key());
ok((int)array_values(array_filter($member->vouchers($ev),fn($v)=>$v['id']===$eur['id']))[0]['remaining']===995,'euro redemption leaves precise credit');
denied(fn()=>$helper->reverse($ev,$move,'Fehler',$key()),'helper cannot reverse without manager authority');
$s->reverse($ev,$move,'Versehentlich geklickt',$key());denied(fn()=>$s->reverse($ev,$move,'Noch einmal',$key()),'redemption cannot be reversed twice');
// Two independent processes contend for a single remaining meal.
$dsn=MySqlFixture::dsn($db);$jobs=[];
foreach([1,2] as $i){$proc=proc_open([PHP_BINARY,__FILE__,'redeem-race',$dsn,$ev,$food['id']],[0=>['file',PHP_OS_FAMILY==='Windows'?'NUL':'/dev/null','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);if(!is_resource($proc))throw new RuntimeException('Race child unavailable');$jobs[]=[$proc,$pipes];}
$race=[];foreach($jobs as [$proc,$pipes]){$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$exit=proc_close($proc);if($exit!==0)throw new RuntimeException('Race test failed '.$err);$race[]=$out;}sort($race);ok($race===['denied','redeemed'],'concurrent redemption cannot spend the same meal twice');
ok(count($helper->vouchers($ev,implode('-',str_split($food['code'],4))))===1,'printed voucher code finds exact voucher');
$s->configure($ev,2,true,[],$key());denied(fn()=>$helper->change($ev,$drink['id'],3,'redeem','1','',$key()),'revoked helper loses event redemption');
$s->configure($ev,3,true,['helper'],$key());
$mail=new EventVoucherMail($db,'admin');$preview=$mail->preview($ev,$p);ok($preview['can_send']&&count($preview['snapshot']['vouchers'])===3,'mail contains remaining individual drink tickets and euro credit');
$content=EventVoucherMail::content($preview['snapshot'],'https://verein.example.invalid/vereinsverwaltung');
ok(str_contains($content['html'],'DVM – DeinVereinsManager')&&str_contains($content['html'],'Mein Verein')&&str_contains($content['html'],'Meine Gutscheine'),'mail uses DVM, separate club identity and personal portal button');
$documentsLink='https://verein.example.invalid/vereinsverwaltung/?view=portal&tab=documents#meine-gutscheine';
ok(str_contains($content['html'],'href="'.htmlspecialchars($documentsLink,ENT_QUOTES,'UTF-8').'"')&&str_contains($content['text'],$documentsLink),'HTML and plain-text mail lead directly to own document binder');
ok(!str_contains($content['html'],'tab=vouchers')&&!str_contains($content['text'],'tab=vouchers'),'member mail does not link to voucher management');
ok(!str_contains($content['text'],'Mika Muster'),'shared mailbox does not merge personal vouchers');
$images=array_values(array_filter($content['attachments'],fn($f)=>$f['mime']==='image/png'));
$pdfs=array_values(array_filter($content['attachments'],fn($f)=>$f['mime']==='application/pdf'));
ok(count($images)===3&&substr_count($content['html'],'src="cid:voucher-')===3,'mail contains one embedded QR per open voucher');
foreach($images as $attachment)ok(str_starts_with($attachment['bytes'],"\x89PNG\r\n\x1a\n"),'QR mail attachment is a PNG');
ok(count($pdfs)===1&&!isset($pdfs[0]['cid'])&&str_starts_with($pdfs[0]['bytes'],'%PDF-'),'mail includes exactly one downloadable personal PDF');
ok($pdfs[0]['bytes']===\Kulturhus\Verein\EventVoucherPdf::file($db,'admin',$ev,$p),'mail PDF matches authenticated personal print PDF exactly');
ok(str_contains($content['html'],'Druck-PDF im Anhang')&&str_contains($content['text'],'Druck-PDF im Anhang')&&!str_contains($content['html'].$content['text'],'Originalgröße'),'mail mentions attached PDF without print-size instructions');
denied(fn()=>(new EventVoucherMail($db,'member'))->preview($ev,$p2),'member cannot prepare another member mail');
$s->grant($ev,[$p3],[$benefits[0]],$key());ok(!$mail->preview($ev,$p3)['can_send'],'missing email keeps printable voucher');
$old=[];foreach(['KULTURHUS_LOCAL_DEMO','KULTURHUS_DISABLE_MAIL','VEREIN_MODE','APP_ENV','APP_URL','VEREIN_PUBLIC_URL'] as $k){$old[$k]=getenv($k);putenv($k);}
// Transport below is a recorder only; no SMTP, mail(), HTTP or external submission is called.
putenv('VEREIN_PUBLIC_URL=https://verein.example.invalid/vereinsverwaltung');putenv('APP_URL=https://verein.example.invalid');
try{
    putenv('KULTURHUS_DISABLE_MAIL=1');denied(fn()=>$mail->queue($ev,$p,$preview['hash'],$key()),'demo mail safety gate');putenv('KULTURHUS_DISABLE_MAIL');
    $mailKey=$key();$mailId=$mail->queue($ev,$p,$preview['hash'],$mailKey);ok($mail->queue($ev,$p,$preview['hash'],$mailKey)===$mailId,'double click queues one personal mail');
    denied(fn()=>$mail->queue($ev,$p,$preview['hash'],$key()),'second pending delivery rejected');
    $sent=[];$send=static function(...$args)use(&$sent){$sent[]=$args;};$url=(string)getenv('VEREIN_PUBLIC_URL');$before=MySqlFixture::rows($db,'event_vouchers');
    ok(EventVoucherMail::dispatch($db,$url,$send,null,'a')===1&&count($sent)===1,'worker sends one queued personal message');
    ok(EventVoucherMail::dispatch($db,$url,$send,null,'a')===0,'next worker does not resend');
    $mail->queue($ev,$p,$preview['hash'],$key());ok(EventVoucherMail::dispatch($db,$url,$send,null,'a')===1,'explicit resend works');
    ok(MySqlFixture::rows($db,'event_vouchers')===$before,'resend changes neither codes nor balances');
    $p2preview=$mail->preview($ev,$p2);$mail->queue($ev,$p2,$p2preview['hash'],$key());
    $db->prepare('UPDATE contacts SET email=? WHERE club_id=? AND id=?')->execute(['changed@example.invalid','a',$p2]);
    ok(EventVoucherMail::dispatch($db,$url,$send,null,'a')===0,'changed email blocks stale queued delivery');
    $mail->queue($ev,$p,$preview['hash'],$key());ok(EventVoucherMail::dispatch($db,$url,static function(){throw new RuntimeException('Ambiguous SMTP outcome');},null,'a')===0,'unknown transport outcome is not blindly retried');
    denied(fn()=>$mail->queue($ev,$p,$preview['hash'],$key()),'unknown prior delivery requires manual check');
    $uncertain=array_values(array_filter($mail->deliveries($ev),fn($d)=>$d['state']==='uncertain'))[0];$mail->cancel($ev,$uncertain['id'],$key());
    $mail->queue($ev,$p,$preview['hash'],$key());$s->configure($ev,4,false,['helper'],$key());
    ok(EventVoucherMail::dispatch($db,$url,$send,null,'a')===0,'closed event blocks mail');
}finally{foreach($old as $k=>$v)putenv($v===false?$k:$k.'='.$v);}
denied(fn()=>$helper->change($ev,$drink['id'],3,'redeem','1','',$key()),'closed event blocks redemption');
$s->configure($ev,5,true,['helper'],$key());
$s->change($ev,$eur['id'],2,'revoke','','Fehlerhafte Zuteilung',$key());denied(fn()=>$helper->change($ev,$eur['id'],3,'redeem','1','',$key()),'revoked voucher cannot be spent');
$r->saveAppointment($source,1,array_replace($eventData,['activity_state'=>'cancelled']),[]);
denied(fn()=>$s->grant($ev,[$p3],[$benefits[1]],$key()),'cancelled internal event blocks further allocation');
denied(fn()=>$helper->change($ev,$drink['id'],3,'redeem','1','',$key()),'cancelled internal event blocks redemption');
// Website events remain linked to their original source, not an additional calendar appointment.
$websiteRow=['id'=>10,'external_id'=>'','title'=>'Website-Fest','summary'=>'','venue_name'=>'Bühne','venue_address'=>'','path'=>'/fest/','status'=>'published','start_at'=>strtotime('2026-09-21T16:00:00Z'),'end_at'=>strtotime('2026-09-21T20:00:00Z')];
$website=new WebsiteCalendar('a',static fn()=>['complete'=>true,'rows'=>[$websiteRow]]);$webService=new EventVouchers($db,'admin',$website);$webId=$webService->open('website:10',$key());$webService->grant($webId,[$p],[$benefits[0]],$key());
ok(count($webService->vouchers($webId))===1,'website event supports personal vouchers');
denied(fn()=>(new EventVouchers($db,'admin'))->grant($webId,[$p2],[$benefits[0]],$key()),'missing website source fails closed');
$unavailable=new WebsiteCalendar('a',static fn()=>['complete'=>false,'rows'=>[]]);denied(fn()=>(new EventVoucherMail($db,'admin',$unavailable))->preview($webId,$p),'incomplete website response blocks mail');
// Render actual templates using only this synthetic fixture (desktop/member/mail QA).
require_once __DIR__.'/../src/Web/View.php';$registry=new Registry($db,'admin',$website);$websiteCalendar=$website;
foreach(['admin','member'] as $persona){
    $_SESSION=['actor'=>$persona,'csrf'=>str_repeat('a',64)];$_POST=[];$_GET=['voucher_event'=>$webId];if($persona==='admin')$_GET['mail_to']=$p;
    unset($voucherViewState,$voucherManageState);ob_start();require __DIR__.'/../views/Events/event-vouchers.php';$html=ob_get_clean();
    ok(str_contains($html,'Essen')&&!str_contains($html,'Mika Muster')||$persona==='admin','member template contains own benefit only');
    ok($persona==='admin'||!str_contains($html,'name="helpers[]"'),'member template omits management controls');
    file_put_contents(__DIR__.'/event-vouchers-ui-'.$persona.'.html','<!doctype html><html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><link rel="stylesheet" href="style.css"></head><body>'.$html.'</body></html>');
}
// Individual tickets: selecting one never consumes another; event binding and print privacy.
$webService->grant($webId,[$p,$p2],[$benefits[1]],$key());
$tickets=array_values(array_filter($webService->vouchers($webId),fn($v)=>$v['kind']==='drink'&&$v['contact_id']===$p))[0];
ok(count($tickets['tickets'])===2&&$tickets['tickets'][0]['code']!==$tickets['tickets'][1]['code'],'two drinks have distinct permanent QR codes');
$t1=$tickets['tickets'][0];$t2=$tickets['tickets'][1];
$lookup=$webService->vouchers($webId,\Kulturhus\Verein\EventVoucherQr::payload($webId,$t2['code']));
ok(count($lookup)===1&&count($lookup[0]['tickets'])===1&&$lookup[0]['tickets'][0]['id']===$t2['id'],'scan looks up exactly the selected ticket');
denied(fn()=>$webService->vouchers($webId,\Kulturhus\Verein\EventVoucherQr::payload($ev,$t2['code'])),'QR from another event rejected');
denied(fn()=>$webService->change($webId,$tickets['id'],1,'redeem','1','',$key(),$t1['id'].'x'),'foreign ticket identity rejected atomically');
$used=$webService->change($webId,$tickets['id'],1,'redeem','1','',$key(),$t2['id']);
denied(fn()=>$webService->change($webId,$tickets['id'],2,'redeem','1','',$key(),$t2['id']),'rescanning used ticket does not consume unused sibling');
$remaining=\Kulturhus\Verein\EventVoucherTickets::list($db,$tickets);ok($remaining[0]['state']==='active'&&$remaining[1]['state']==='redeemed','only selected second drink consumed');
$webService->reverse($webId,$used,'Testkorrektur',$key());
ok(\Kulturhus\Verein\EventVoucherTickets::list($db,$tickets)[1]['state']==='active','reverse restores the exact same ticket and code');
$webService->change($webId,$tickets['id'],3,'revoke','','Gutschein gelöscht',$key(),$t1['id']);
$remaining=\Kulturhus\Verein\EventVoucherTickets::list($db,$tickets);ok($remaining[0]['state']==='revoked'&&$remaining[1]['state']==='active','delete invalidates only one selected ticket');
denied(fn()=>$webService->change($webId,$tickets['id'],4,'redeem','1','',$key(),$t1['id']),'deleted ticket cannot be redeemed');
$_SESSION=['actor'=>'admin','csrf'=>str_repeat('a',64)];$_POST=[];$_GET=['voucher_event'=>$webId,'print_person'=>$p];
unset($voucherViewState,$voucherManageState);ob_start();require __DIR__.'/../views/Events/event-vouchers.php';$printHtml=ob_get_clean();
ok(str_contains($printHtml,'Mara Muster')&&!str_contains($printHtml,'Mika Muster')&&!str_contains($printHtml,'event-voucher-delete')&&!str_contains($printHtml,'event-voucher-grant'),'person print contains only selected member and no admin forms');
ok(str_contains($printHtml,'data-voucher-person-print')&&str_contains($printHtml,'2 verfügbare Karten')&&!str_contains($printHtml,'<img'),'native person print shows its bounded current original metadata without eagerly expanding physical QR images');
ok(str_contains($printHtml,'voucher_manager_pdf=')&&str_contains($printHtml,'Verfügbare Karten dieser Person als PDF'),'print view offers business-card PDF');
$printPdf=\Kulturhus\Verein\EventVoucherPdf::file($db,'admin',$webId,$p,$website);ok(str_starts_with($printPdf,'%PDF-'),'personal business-card PDF renders on the server');
file_put_contents(__DIR__.'/event-vouchers-ui-print.pdf',$printPdf);
// A renderer failure must stop before transport; no mail without its promised PDF.
$pdfEnv=[];foreach(['KULTURHUS_LOCAL_DEMO','KULTURHUS_DISABLE_MAIL','VEREIN_MODE','APP_ENV','APP_URL','VEREIN_PUBLIC_URL','VEREIN_PYTHON'] as $k)$pdfEnv[$k]=getenv($k);
try{
    foreach(array_keys($pdfEnv) as $k)if($k!=='VEREIN_PYTHON')putenv($k);
    putenv('VEREIN_PUBLIC_URL=https://verein.example.invalid/vereinsverwaltung');putenv('APP_URL=https://verein.example.invalid');
    $pdfMail=new EventVoucherMail($db,'admin',$website);$pdfPreview=$pdfMail->preview($webId,$p);$pdfMail->queue($webId,$p,$pdfPreview['hash'],$key());
    putenv('VEREIN_PYTHON=/nonexistent/voucher-pdf-test');$transportCalls=0;
    ok(EventVoucherMail::dispatch($db,'https://verein.example.invalid/vereinsverwaltung',function()use(&$transportCalls){++$transportCalls;},$website,'a')===0&&$transportCalls===0,'PDF failure blocks mail before transport');
    ok($pdfMail->deliveries($webId)[0]['state']==='blocked','PDF failure is visible as blocked delivery');
}finally{foreach($pdfEnv as $k=>$v)putenv($v===false?$k:$k.'='.$v);}
denied(fn()=>\Kulturhus\Verein\EventVoucherPdf::file($db,'member',$webId,$p2,$website),'PDF download does not bypass member privacy');
file_put_contents(__DIR__.'/event-vouchers-ui-print.html','<!doctype html><html lang="de"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><link rel="stylesheet" href="style.css"><body>'.$printHtml.'</body></html>');
$_SESSION['actor']='member';$_GET=['voucher_event'=>$webId,'print_person'=>$p2];
require_once __DIR__.'/../src/Events/EventVoucherViewState.php';
$voucherViewState=\Kulturhus\Verein\EventVoucherViewState::prepare(new EventVouchers($db,'member',$websiteCalendar),$_GET);unset($voucherManageState);ob_start();require __DIR__.'/../views/Events/event-vouchers.php';$refusedPrint=ob_get_clean();ok($voucherViewState['http_status']===403&&$voucherViewState['cards']===null&&!str_contains($refusedPrint,'Mika Muster')&&!str_contains($refusedPrint,'data-personal-voucher-card '),'member native view refuses another recipient print proposal without exposing original cards');unset($voucherViewState);
ok(MySqlFixture::foreignKeyErrors($db)===[]&&MySqlFixture::integrity($db)==='ok','voucher relational integrity');
// Restore includes balances, movement history, helper grants and delivery state.
$snap=MySqlFixture::snapshot($db);$restored=MySqlFixture::restoreBackup($db);ok(MySqlFixture::snapshot($restored)===$snap,'MySQL backup roundtrip preserves all voucher data');
echo "PASS $n voucher checks; no external messages or live-data changes\n";
