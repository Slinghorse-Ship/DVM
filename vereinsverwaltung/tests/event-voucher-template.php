<?php declare(strict_types=1);
require __DIR__.'/../src/bootstrap.php';require_once __DIR__.'/mysql-bootstrap.php';
use Kulturhus\Verein\{Database,Registry,EventVouchers,EventVoucherMail,EventVoucherSchema,EventVoucherPdf};
use Kulturhus\Verein\Tests\MySqlFixture;
set_error_handler(static function($n,$s,$f,$l){throw new ErrorException($s,0,$n,$f,$l);});
$n=0;function ok(bool $b,string $m):void{global $n;if(!$b)throw new RuntimeException($m);++$n;echo "PASS $m\n";}
function denied(callable $f,string $m):void{try{$f();}catch(DomainException){ok(true,$m);return;}throw new RuntimeException($m);}
$db=MySqlFixture::connect();Database::migrate($db);EventVoucherSchema::install($db);
$db->exec("INSERT INTO clubs VALUES('a','A'),('b','B'); INSERT INTO actors(id,club_id,role,is_superuser) VALUES('admin','a','members',1),('member','a','member',0),('helper','a','member',0),('foreign','b','members',1),('editor','a','editor',0)");
$r=new Registry($db,'admin');$s=new EventVouchers($db,'admin');$mail=new EventVoucherMail($db,'admin');$key=fn()=>bin2hex(random_bytes(16));
$people=[];foreach(['Mara Muster','Mika Muster'] as $name){$p=$r->saveContact(null,0,'person',$name,'shared@example.invalid','');$r->addMembership($p,'','aktiv','2026-01-01');$people[]=$p;}
[$p1,$p2]=$people;$db->prepare("UPDATE actors SET contact_id=? WHERE id='member'")->execute([$p1]);
$source=$r->saveAppointment(null,0,['title'=>'Vorlagen-Testfest','start'=>'2026-10-09T18:00','end'=>'2026-10-09T22:00','location'=>'Testort','category'=>'verein','description'=>'','activity_kind'=>'event','activity_state'=>'planned'],[]);$event=$s->open($source,$key());
$s->grant($event,$people,[['kind'=>'drink','label'=>'Getränk','unit'=>'piece','amount'=>'2']],$key());$s->configure($event,1,true,['helper'],$key());
$defaults=EventVoucherMail::templateDefaults();ok($mail->template($event)===['version'=>0,'data'=>$defaults],'unsaved event has a usable default template without writes');
$template=['subject'=>'Gutscheine für {{NAME}} · {{VERANSTALTUNG}}','message'=>"Hallo {{VORNAME}},\n\nwir freuen uns auf dich am {{DATUM}} um {{UHRZEIT}} in {{ORT}}.\nVielen Dank für deine Hilfe!"];
foreach(['member','helper','editor','foreign'] as $actor){$other=new EventVoucherMail($db,$actor);denied(fn()=>$other->template($event),'template read rejects '.$actor);denied(fn()=>$other->saveTemplate($event,0,$template,$key()),'template edit rejects '.$actor);}
foreach([['subject'=>''],['message'=>''],['subject'=>"Titel\nBcc: injected@example.invalid"],['message'=>'{{EMAIL}}'],['message'=>'{{NAME}'],['message'=>'{{name}}'],['subject'=>str_repeat('x',201)],['message'=>str_repeat('x',6001)],['message'=>['bad']],['extra'=>'field']] as $bad)denied(fn()=>EventVoucherMail::normalizeTemplate(array_replace($template,$bad)),'invalid or unsafe template rejected');
$staleSingle=$mail->preview($event,$p1);$staleBatch=$mail->batchPreview($event);$before=MySqlFixture::rows($db,'event_vouchers');$saveKey=$key();
$mail->saveTemplate($event,0,$template,$saveKey);$mail->saveTemplate($event,0,$template,$saveKey);
ok($mail->template($event)===['version'=>1,'data'=>$template],'save persists per-event text and repeated confirmation is idempotent');
ok((int)$db->query('SELECT COUNT(*) FROM settings_revisions')->fetchColumn()===1,'template revision recorded exactly once');
ok((int)$db->query('SELECT COUNT(*) FROM event_voucher_outbox')->fetchColumn()===0&&MySqlFixture::rows($db,'event_vouchers')===$before,'saving does not queue messages or change vouchers');
$unchanged=MySqlFixture::snapshot($db);denied(fn()=>$mail->saveTemplate($event,0,$defaults,$key()),'stale editor cannot overwrite newer text');ok(MySqlFixture::snapshot($db)===$unchanged,'stale save has no partial writes');
$p=$mail->preview($event,$p1);$content=EventVoucherMail::content($p['snapshot'],'https://verein.example.invalid/vereinsverwaltung/');
ok(str_contains($content['subject'],'Mara Muster')&&str_contains($content['html'],'09.10.2026 um 18:00 in Testort')&&str_contains($content['text'],'Vielen Dank für deine Hilfe!'),'subject and message placeholders render in HTML and text');
ok(str_contains($content['html'],'<h1 style="font: bold 32px Georgia,serif">'.htmlspecialchars($content['subject'],ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8').'</h1>'),'personalized subject is also the visible mail heading');
ok(!str_contains($content['html'].$content['text'],'Originalgröße')&&!str_contains($content['html'].$content['text'],'100 %'),'mail has no print-size instruction');
ok(str_contains($content['html'],'Hallo Mara,')&&str_contains($content['text'],'Hallo Mara,')&&!str_contains($content['html'],'Hallo Mara Muster,'),'first-name token addresses only the recipient first name');
ok(!str_contains($content['html'],'{{NAME}}')&&!str_contains($content['text'],'Mika Muster'),'placeholders resolve only for the addressed person');
ok(str_contains($content['html'],'DVM – DeinVereinsManager')&&str_contains($content['html'],'Mein Verein')&&str_contains($content['html'],'#meine-gutscheine'),'DVM, separate club identity and personal document link remain');
$pdfs=array_values(array_filter($content['attachments'],fn($f)=>$f['mime']==='application/pdf'));
ok(count($pdfs)===1&&$pdfs[0]['bytes']===EventVoucherPdf::file($db,'admin',$event,$p1),'editable text cannot change personal PDF or QR content');
$unsafe=$p['snapshot'];$unsafe['mail_template']['message']='<script>alert(1)</script> {{NAME}}';$unsafe['person']['name']="Mara\nBcc: blocked";
$safe=EventVoucherMail::content($unsafe,'https://verein.example.invalid/vereinsverwaltung');
ok(!str_contains($safe['html'],'<script>')&&str_contains($safe['html'],'&lt;script&gt;')&&!preg_match('/[\r\n]/',$safe['subject']),'user text is escaped and substituted subject remains one line');
$env=[];foreach(['KULTURHUS_LOCAL_DEMO','KULTURHUS_DISABLE_MAIL','VEREIN_MODE','APP_ENV','APP_URL','VEREIN_PUBLIC_URL'] as $k){$env[$k]=getenv($k);putenv($k);}putenv('APP_URL=https://verein.example.invalid');putenv('VEREIN_PUBLIC_URL=https://verein.example.invalid/vereinsverwaltung');
try{
    denied(fn()=>$mail->queue($event,$p1,$staleSingle['hash'],$key()),'changed template invalidates old single preview');
    denied(fn()=>$mail->queueBatch($event,$staleBatch['hash'],$key()),'changed template invalidates old batch preview');
    $batch=$mail->batchPreview($event);ok($mail->queueBatch($event,$batch['hash'],$key())['queued']===2,'one reviewed template queues two separately personalized messages');
    $frozen=MySqlFixture::rows($db,'event_voucher_outbox');$mail->saveTemplate($event,1,['subject'=>'Späterer Betreff','message'=>'Nachträglich geändert'],$key());
    ok(MySqlFixture::rows($db,'event_voucher_outbox')===$frozen,'editing does not rewrite already approved queue records');
    $sent=[];$send=static function(...$args)use(&$sent){$sent[]=$args;};$url=(string)getenv('VEREIN_PUBLIC_URL');
    ok(EventVoucherMail::dispatch($db,$url,$send,null,'a')===2,'worker uses frozen template despite later edits');
    foreach($sent as $message)ok(str_contains($message[1],'Gutscheine für ')&&str_contains($message[2],'Vielen Dank für deine Hilfe!')&&!str_contains($message[3],'Nachträglich geändert'),'confirmed message, not new template, reaches recorder');
    ok(EventVoucherMail::dispatch($db,$url,$send,null,'a')===0&&count($sent)===2,'normal worker does not repeat template mail');
    // Reproduce a queued record from before this feature; private helper retains old snapshot shape.
    $legacy=(new ReflectionMethod(EventVoucherMail::class,'previewUsingTemplate'))->invoke($mail,$event,$p1,null);
    ok(!isset($legacy['snapshot']['mail_template']),'legacy fixture has the original snapshot shape');
    $db->prepare('INSERT INTO event_voucher_outbox(club_id,id,event_id,contact_id,actor_id,email,snapshot_json,fingerprint,updated_at) VALUES(?,?,?,?,?,?,?,?,?)')->execute(['a',$key(),$event,$p1,'admin','shared@example.invalid',json_encode($legacy['snapshot'],JSON_THROW_ON_ERROR),$legacy['hash'],gmdate('c')]);
    ok(EventVoucherMail::dispatch($db,$url,$send,null,'a')===1&&str_starts_with($sent[2][1],'Deine Gutscheine · '),'previously approved legacy message still dispatches with original text');
    $fresh=$mail->preview($event,$p1);$id=$mail->queue($event,$p1,$fresh['hash'],$key());
    $bad=$fresh['snapshot'];$bad['mail_template']['message']='Tampered without confirmation';$db->prepare('UPDATE event_voucher_outbox SET snapshot_json=? WHERE id=?')->execute([json_encode($bad,JSON_THROW_ON_ERROR),$id]);
    ok(EventVoucherMail::dispatch($db,$url,$send,null,'a')===0&&count($sent)===3,'modified saved snapshot is blocked before transport');
}finally{foreach($env as $k=>$v)putenv($v===false?$k:$k.'='.$v);}
$db->exec("CREATE TRIGGER voucher_template_fail BEFORE INSERT ON settings_revisions FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Synthetic rollback check'");
try{$failed=false;try{$mail->saveTemplate($event,2,$defaults,$key());}catch(PDOException){$failed=true;}ok($failed&&$mail->template($event)['version']===2,'revision failure rolls back template update');}finally{$db->exec('DROP TRIGGER voucher_template_fail');}
$mail->saveTemplate($event,2,$template,$key());
require_once __DIR__.'/../src/Web/View.php';$_SESSION=['actor'=>'admin','csrf'=>str_repeat('a',64)];$_GET=['voucher_event'=>$event,'mail_template'=>'1','mail_to'=>$p1];$_POST=[];$websiteCalendar=null;$registry=$r;
unset($voucherViewState,$voucherManageState);ob_start();require __DIR__.'/../views/Events/event-vouchers.php';$html=ob_get_clean();
ok(str_contains($html,'name="mail_subject"')&&str_contains($html,'name="mail_message"')&&str_contains($html,'Vorlage speichern'),'manager can edit subject and message directly at event');
ok(str_contains($html,'Hallo {{VORNAME}},')&&str_contains($html,'Vornamen des jeweiligen Mitglieds')&&str_contains($html,'{{NAME}}</code> fügt den vollständigen Namen')&&str_contains($html,'Weitere Textbausteine'),'first and full name placeholders have an inline usage example and explanation');
ok(str_contains($html,'Betreff:</strong> Gutscheine für Mara Muster')&&str_contains($html,'Persönliche Gutscheine, QR-Codes und PDF'),'preview shows expanded subject and automatic attachments');
file_put_contents(__DIR__.'/event-voucher-template-ui.html',$html);
$_POST=['action'=>'event-voucher-mail-template','template_version'=>'1','mail_subject'=>'Ungespeicherter Entwurf','mail_message'=>'Bitte {{FALSCH}} korrigieren'];unset($voucherViewState,$voucherManageState);ob_start();require __DIR__.'/../views/Events/event-vouchers.php';$errorHtml=ob_get_clean();
ok(str_contains($errorHtml,'Ungespeicherter Entwurf')&&str_contains($errorHtml,'Bitte {{FALSCH}} korrigieren')&&str_contains($errorHtml,'name="template_version" value="1"'),'validation error retains typed content and stale version');
$_SESSION['actor']='member';$_POST=[];unset($voucherViewState,$voucherManageState);ob_start();require __DIR__.'/../views/Events/event-vouchers.php';$memberHtml=ob_get_clean();
ok(!str_contains($memberHtml,'name="mail_message"')&&!str_contains($memberHtml,'Mailvorlage bearbeiten'),'member view never exposes template controls');
ok(MySqlFixture::rows($db,'event_vouchers')===$before&&!MySqlFixture::foreignKeyErrors($db),'template workflow preserves voucher balances and relationships');
echo "Passed $n template checks; recorder transport only, no real mail.\n";
