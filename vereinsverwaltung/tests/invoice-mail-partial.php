<?php declare(strict_types=1);
namespace {
    // The transport is exercised with namespace-local protocol stubs, never a socket.
    if(!function_exists('imap_open')){function imap_open() {throw new \LogicException('Global IMAP must never be called');}}
    foreach(['IMAP_OPENTIMEOUT','IMAP_READTIMEOUT','IMAP_WRITETIMEOUT','OP_READONLY','SA_UIDVALIDITY','SE_UID','FT_UID','FT_PEEK'] as $i=>$name)if(!defined($name))define($name,1<<$i);
}
namespace Kulturhus\Verein {
    function dns_get_record($host,$type){return [['ip'=>'8.8.8.8']];}
    function imap_timeout(...$args){return true;}
    function imap_open(...$args){return (object)['synthetic'=>true];}
    function imap_status(...$args){return (object)['uidvalidity'=>99];}
    function imap_errors(){return false;}
    function imap_alerts(){return false;}
    function imap_search(...$args){return [1,2];}
    function imap_fetch_overview($stream,$uid,$flags){return [(object)['size'=>500,'subject'=>'Synthetic','from'=>'synthetic@example.invalid','udate'=>1788780000]];}
    function imap_mime_header_decode($text){return [(object)['charset'=>'UTF-8','text'=>$text]];}
    function imap_fetchstructure($stream,$uid,$flags){
        $leaf=static fn($name,$encoding)=>(object)['parameters'=>[(object)['attribute'=>'name','value'=>$name]],'bytes'=>50,'encoding'=>$encoding];
        return $uid===1?(object)['parts'=>[$leaf('first.pdf',0),$leaf('broken.pdf',3)]]:$leaf('next.pdf',0);
    }
    function imap_fetchbody($stream,$uid,$part,$flags){return $uid===1&&$part==='2'?'!invalid base64!':'%PDF-1.4 synthetic '.$uid;}
    function imap_fetchheader(...$args){return "Subject: Synthetic\r\n\r\n";}
    function imap_body(...$args){return 'Synthetic original';}
    function imap_close(...$args){return true;}
}
namespace {
    require __DIR__.'/../src/bootstrap.php';
    use Kulturhus\Verein\{Database,InvoiceMailbox,InvoiceMailboxTransport};
    $n=0;function check(bool $ok,string $name):void{global $n;if(!$ok)throw new \RuntimeException($name);++$n;echo "PASS $name\n";}
    $config=array_replace(InvoiceMailbox::defaults(),['enabled'=>true,'email'=>'inbox@example.invalid','imap_host'=>'imap.example.invalid','imap_user'=>'inbox','imap_password'=>'Synthetic-only']);
    $batch=InvoiceMailboxTransport::read($config,99,0);
    check(count($batch['messages'])===2,'later mail is retained after broken attachment');
    check($batch['messages'][0]['error']===true&&$batch['messages'][0]['attachments']===[],'partial attachments discarded as an entire failed message');
    check(isset($batch['messages'][1]['original'])&&count($batch['messages'][1]['attachments'])===1,'next complete message retains attachment and original');
    $db=\Kulturhus\Verein\Tests\MySqlFixture::connect(':memory:');Database::migrate($db);$db->exec("INSERT INTO clubs VALUES('a','Synthetic');INSERT INTO actors(id,club_id,role,is_superuser) VALUES('admin','a','members',1)");
    $box=new InvoiceMailbox($db,'admin',str_repeat('synthetic-key-',4));$box->save($config,0);
    foreach(['VEREIN_MODE'=>'production','APP_ENV'=>'production','APP_URL'=>'https://example.invalid','KULTURHUS_DISABLE_MAIL'=>'0','KULTURHUS_LOCAL_DEMO'=>'0'] as $key=>$value)putenv($key.'='.$value);
    $r=$box->fetch(fn()=>$batch);
    check($r['failed']===1&&$r['new']===1,'bad mail reported and next valid attachment imported');
    check((int)$db->query('SELECT last_uid FROM invoice_mailboxes')->fetchColumn()===2,'cursor advances past malformed message instead of permanent retry loop');
    check((int)$db->query('SELECT COUNT(*) FROM document_files')->fetchColumn()===1,'no partial first attachment stored');
    echo "$n partial MIME regression checks passed; no network or mail\n";
}
