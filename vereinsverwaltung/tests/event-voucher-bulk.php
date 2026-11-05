<?php declare(strict_types=1);
require __DIR__.'/../src/bootstrap.php';require_once __DIR__.'/mysql-bootstrap.php';
use Kulturhus\Verein\{Database,Registry,EventVouchers,EventVoucherMail,EventVoucherSchema};
use Kulturhus\Verein\Tests\MySqlFixture;
set_error_handler(static function($n,$s,$f,$l){throw new ErrorException($s,0,$n,$f,$l);});
$n=0;function ok(bool $b,string $m):void{global $n;if(!$b)throw new RuntimeException($m);++$n;echo "PASS $m\n";}
function denied(callable $f,string $m):void{try{$f();}catch(DomainException){ok(true,$m);return;}throw new RuntimeException($m);}
$db=MySqlFixture::connect();Database::migrate($db);EventVoucherSchema::install($db);
$db->exec("INSERT INTO clubs VALUES('a','A'),('b','B'); INSERT INTO actors(id,club_id,role,is_superuser) VALUES('admin','a','members',1),('member','a','member',0),('helper','a','member',0),('foreign','b','members',1),('editor','a','editor',0)");
$r=new Registry($db,'admin');$s=new EventVouchers($db,'admin');$mail=new EventVoucherMail($db,'admin');$key=fn()=>bin2hex(random_bytes(16));
$people=[];foreach([['Mara Muster','shared@example.invalid'],['Mika Muster','shared@example.invalid'],['Ohne Mail',''],['Ohne Gutschein','none@example.invalid'],['Ehemaliges Mitglied','former@example.invalid']] as [$name,$email]){$id=$r->saveContact(null,0,'person',$name,$email,'');$r->addMembership($id,'','aktiv','2026-01-01');$people[]=$id;}
[$p1,$p2,$p3,$p4,$p5]=$people;$db->prepare("UPDATE actors SET contact_id=? WHERE id='member'")->execute([$p1]);
$source=$r->saveAppointment(null,0,['title'=>'Sammelversand-Testfest','start'=>'2026-10-09T18:00','end'=>'2026-10-09T22:00','location'=>'Testort','category'=>'verein','description'=>'','activity_kind'=>'event','activity_state'=>'planned'],[]);$event=$s->open($source,$key());
$drink=['kind'=>'drink','label'=>'Getränk','unit'=>'piece','amount'=>'2'];$s->grant($event,[$p1,$p2],[$drink],$key());$s->grant($event,[$p3,$p5],[['kind'=>'food','label'=>'Essen','unit'=>'piece','amount'=>'1']],$key());
$db->prepare("UPDATE memberships SET status='ehemalig',ends_on=? WHERE club_id=? AND contact_id=?")->execute(['2026-01-31','a',$p5]);
$s->configure($event,1,true,['helper'],$key());
$batch=$mail->batchPreview($event);ok(count($batch['ready'])===2&&count($batch['skipped'])===2,'only current members with vouchers and valid addresses are ready');
ok(!str_contains(json_encode($batch),'Ehemaliges Mitglied'),'former members excluded from all-member batch');
ok(count(array_filter($batch['skipped'],fn($p)=>str_contains($p['reason'],'E-Mail-Adresse')))===1&&count(array_filter($batch['skipped'],fn($p)=>$p['reason']==='Keine offenen Gutscheine'))===1,'skipped members have clear individual reasons');
foreach(['member','helper','editor','foreign'] as $actor)denied(fn()=>(new EventVoucherMail($db,$actor))->batchPreview($event),'bulk permission rejects '.$actor);
$env=[];foreach(['KULTURHUS_LOCAL_DEMO','KULTURHUS_DISABLE_MAIL','VEREIN_MODE','APP_ENV','APP_URL','VEREIN_PUBLIC_URL'] as $k){$env[$k]=getenv($k);putenv($k);}putenv('APP_URL=https://verein.example.invalid');putenv('VEREIN_PUBLIC_URL=https://verein.example.invalid/vereinsverwaltung');
try{
    putenv('KULTURHUS_DISABLE_MAIL=1');denied(fn()=>$mail->queueBatch($event,$batch['hash'],$key()),'demo safety gate also blocks bulk queue');putenv('KULTURHUS_DISABLE_MAIL');
    foreach(['member','helper','foreign'] as $actor)denied(fn()=>(new EventVoucherMail($db,$actor))->queueBatch($event,$batch['hash'],$key()),'bulk queue rejects '.$actor);
    $db->prepare('UPDATE contacts SET email=? WHERE id=?')->execute(['changed@example.invalid',$p2]);
    denied(fn()=>$mail->queueBatch($event,$batch['hash'],$key()),'changed recipient aborts the entire stale batch');
    ok((int)$db->query('SELECT COUNT(*) FROM event_voucher_outbox')->fetchColumn()===0,'stale preview leaves no partial queue');
    $db->prepare('UPDATE contacts SET email=? WHERE id=?')->execute(['shared@example.invalid',$p2]);
    // Force the second insert to fail and prove transaction-wide rollback.
    $db->exec("CREATE TRIGGER voucher_bulk_fail BEFORE INSERT ON event_voucher_outbox FOR EACH ROW BEGIN IF NEW.contact_id=".$db->quote($p2)." THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Synthetic batch rollback test'; END IF; END");
    try{$failed=false;try{$mail->queueBatch($event,$batch['hash'],$key());}catch(PDOException){$failed=true;}ok($failed&&(int)$db->query('SELECT COUNT(*) FROM event_voucher_outbox')->fetchColumn()===0,'second insert failure rolls back every queued member');}finally{$db->exec('DROP TRIGGER voucher_bulk_fail');}
    $p=$mail->preview($event,$p1);$single=$mail->queue($event,$p1,$p['hash'],$key());
    denied(fn()=>$mail->queueBatch($event,$batch['hash'],$key()),'competing single send invalidates old batch confirmation');
    $batch=$mail->batchPreview($event);ok(count($batch['ready'])===1&&$batch['ready'][0]['snapshot']['person']['id']===$p2,'pending individual mail skipped in refreshed batch');
    $batchKey=$key();$result=$mail->queueBatch($event,$batch['hash'],$batchKey);
    ok($result['queued']===1&&$result['skipped']===3,'one confirmation queues every eligible member');
    ok($mail->queueBatch($event,$batch['hash'],$batchKey)===$result&&(int)$db->query('SELECT COUNT(*) FROM event_voucher_outbox')->fetchColumn()===2,'double submission does not duplicate bulk messages');
    denied(fn()=>$mail->queueBatch($event,$batch['hash'],$key()),'second browser confirmation cannot duplicate pending messages');
    $before=MySqlFixture::rows($db,'event_vouchers');$sent=[];$send=static function(...$args)use(&$sent){$sent[]=$args;};
    ok(EventVoucherMail::dispatch($db,(string)getenv('VEREIN_PUBLIC_URL'),$send,null,'a')===2&&count($sent)===2,'existing worker dispatches separate single-recipient messages');
    $pdfHashes=[];foreach($sent as $message){
        ok($message[0]==='shared@example.invalid'&&(str_contains($message[3],'Mara Muster') xor str_contains($message[3],'Mika Muster')),'shared mailbox receives separate personal contents');
        $pdfs=array_values(array_filter($message[4],fn($f)=>$f['mime']==='application/pdf'));ok(count($pdfs)===1&&str_starts_with($pdfs[0]['bytes'],'%PDF-'),'every bulk recipient receives one personal PDF');$pdfHashes[]=hash('sha256',$pdfs[0]['bytes']);
    }
    ok(count(array_unique($pdfHashes))===2,'PDF attachments differ between members');
    ok(MySqlFixture::rows($db,'event_vouchers')===$before,'bulk mailing never alters voucher balances or codes');
    $after=$mail->batchPreview($event);ok(count($after['ready'])===0&&count(array_filter($after['skipped'],fn($p)=>$p['reason']==='Gutscheine bereits versendet'))===2,'already delivered vouchers skipped by default');
    $s->grant($event,[$p1],[['kind'=>'other','label'=>'Kuchen','unit'=>'piece','amount'=>'1']],$key());
    ok(count($mail->batchPreview($event)['ready'])===1,'newly allocated voucher makes that member eligible again');
    foreach(['uncertain','blocked'] as $state){$db->prepare('UPDATE event_voucher_outbox SET state=? WHERE id=?')->execute([$state,$single]);ok(count($mail->batchPreview($event)['ready'])===0,'bulk never retries '.$state.' delivery automatically');}
    $s->configure($event,2,false,['helper'],$key());denied(fn()=>$mail->batchPreview($event),'closed event blocks bulk preview');denied(fn()=>$mail->queueBatch($event,$batch['hash'],$key()),'closed event blocks bulk submission');
}finally{foreach($env as $k=>$v)putenv($v===false?$k:$k.'='.$v);}
$s->configure($event,3,true,['helper'],$key());
require_once __DIR__.'/../src/Web/View.php';$_SESSION=['actor'=>'admin','csrf'=>str_repeat('a',64)];$_GET=['voucher_event'=>$event,'mail_all'=>'1'];$_POST=[];$websiteCalendar=null;$registry=$r;
unset($voucherViewState,$voucherManageState);ob_start();require __DIR__.'/../views/Events/event-vouchers.php';$html=ob_get_clean();$start=strpos($html,'id="voucher-mail-all"');$section=substr($html,$start);ok(str_contains($html,'Persönlichen Sammelversand prüfen')&&str_contains($section,'Nicht versendet')&&!str_contains($section,'Ehemaliges Mitglied'),'batch UI shows action and skip reasons without former members');
ok(MySqlFixture::foreignKeyErrors($db)===[],'bulk queue preserves relational integrity');
echo "PASS $n bulk voucher checks; no real email sent\n";
