<?php declare(strict_types=1);
require __DIR__.'/../src/bootstrap.php';require __DIR__.'/../src/Web/View.php';
use Kulturhus\Verein\{Database,InvoiceMailbox,InvoiceMailboxTransport,Documents};
$db=\Kulturhus\Verein\Tests\MySqlFixture::connect(':memory:');Database::migrate($db);Database::migrate($db);
$db->exec("INSERT INTO clubs VALUES('a','Synthetic'),('b','Other');INSERT INTO actors(id,club_id,role,is_superuser) VALUES('admin','a','members',1),('reader','a','member',0),('foreign','b','members',1)");
$key=str_repeat('synthetic-key-',4);$service=new InvoiceMailbox($db,'admin',$key);$n=0;
function ok(bool $yes,string $label):void{global $n;if(!$yes)throw new RuntimeException($label);echo "PASS $label\n";++$n;}
function denied(callable $f,string $label):void{try{$f();}catch(DomainException){ok(true,$label);return;}throw new RuntimeException($label);}
$d=InvoiceMailbox::defaults();ok(!$service->state()['data']['enabled']&&!$service->state()['data']['smtp_enabled'],'new mailbox is off with no invented credentials');
denied(fn()=>(new InvoiceMailbox($db,'reader',$key))->state(),'member cannot read mailbox settings');
$d+=[];$d['email']='rechnung@example.test';$d['imap_host']='imap.example.test';$d['imap_user']='rechnung@example.test';$d['enabled']=true;
denied(fn()=>$service->save($d,0),'enabled intake requires password');
$d['imap_password']='Synthetic-private-123';$service->save($d,0);
$beforeStatusRead=$db->query("SELECT * FROM invoice_mailboxes WHERE club_id='a'")->fetch();
$readOnly=InvoiceMailbox::imapConfiguration($db,'a',$key);ok($readOnly['imap_password']===$d['imap_password']&&$readOnly['imap_host']===$d['imap_host']&&$readOnly['enabled']&&!isset($readOnly['smtp_password']),'internal status resolver reads only current encrypted IMAP credentials');
ok($db->query("SELECT * FROM invoice_mailboxes WHERE club_id='a'")->fetch()===$beforeStatusRead,'read-only status resolver preserves UID validity, message cursor and mailbox bytes');
denied(fn()=>InvoiceMailbox::imapConfiguration($db,'b',$key),'another club has no invoice status credentials');
$row=$db->query("SELECT * FROM invoice_mailboxes WHERE club_id='a'")->fetch();ok(!str_contains(json_encode($row),$d['imap_password'])&&!str_contains(json_encode($service->state()),$d['imap_password']),'password encrypted and absent from rendered state');
ok((int)$db->query('SELECT COUNT(*) FROM settings_revisions')->fetchColumn()===0,'no password or settings copy in generic history');
denied(fn()=>$service->save($d,0),'stale config version rejected');
denied(fn()=>(new InvoiceMailbox($db,'admin',str_repeat('wrong-',10)))->save($d,1),'wrong key cannot recover existing secrets');
$changed=$d;unset($changed['imap_password']);$changed['imap_host']='other.example.test';denied(fn()=>$service->save($changed,1),'host change cannot reuse old password');
$other=new InvoiceMailbox($db,'foreign',$key);ok($other->recent()===[]&&$other->state()['version']===0,'other club cannot see config or imports');
$fixture=['uidvalidity'=>15,'messages'=>[['uid'=>10,'subject'=>'Synthetic Rechnung','sender'=>'Supplier <sender@example.test>','date'=>'2026-09-07','original'=>"Subject: Synthetic\r\n\r\nOriginal invoice mail body\r\n",'attachments'=>[['name'=>'rechnung.pdf','bytes'=>'%PDF-1.4 Synthetic invoice'],['name'=>'shell.php','bytes'=>'<?php evil()']]]]];
$calls=0;$fetch=function($c,$v,$last)use(&$calls,$fixture){++$calls;if($c['imap_password']!=='Synthetic-private-123')throw new RuntimeException('secret lost');return $fixture;};
putenv('APP_URL=https://example.test');putenv('VEREIN_MODE=synthetic');ok($service->fetch($fetch)['status']==='local_disabled'&&$calls===0,'local flag stops network before adapter');
putenv('VEREIN_MODE=production');putenv('APP_ENV=production');putenv('KULTURHUS_LOCAL_DEMO=0');putenv('KULTURHUS_DISABLE_MAIL=0');
$r=$service->fetch($fetch);ok($r['new']===1&&$r['failed']===1,'valid invoice imported and executable attachment rejected');
$originalRow=$db->query('SELECT * FROM invoice_mail_originals')->fetch();ok($originalRow['bytes']===$fixture['messages'][0]['original']&&hash_equals($originalRow['sha256'],hash('sha256',$fixture['messages'][0]['original'])),'original RFC822 bytes and hash preserved privately');
$imports=$service->recent();$doc=(new Documents($db,'admin'))->document($imports[0]['document_id']);ok($doc['kind']==='receipt'&&$doc['links']===[]&&str_contains($doc['path'],'Eingang - noch zuordnen'),'invoice goes into finance inbox without posting or implied association');
$fixture['messages'][0]['uid']=11;$fixture['messages'][0]['original'].='Additional correction note';$r=$service->fetch(fn()=>$fixture);ok($r['new']===0&&$r['existing']===1,'forwarded identical attachment has no duplicate');
$fixture['uidvalidity']=16;$fixture['messages'][0]['uid']=1;$r=$service->fetch(fn()=>$fixture);ok($r['existing']===1&&count($service->recent())===1,'UIDVALIDITY reset still deduplicates attachment');
$db->exec("UPDATE invoice_mailboxes SET lock_until=".(time()+120));denied(fn()=>$service->fetch(fn()=>$fixture),'concurrent fetch blocked');denied(fn()=>$service->save($d,1),'save blocked during active fetch');$db->exec('UPDATE invoice_mailboxes SET lock_until=0');
$old=$db->query('SELECT last_uid FROM invoice_mailboxes')->fetchColumn();denied(fn()=>$service->fetch(fn()=>throw new RuntimeException('secret must not leak')),'adapter failure produces generic error');ok($db->query('SELECT last_uid FROM invoice_mailboxes')->fetchColumn()===$old&&(int)$db->query('SELECT lock_until FROM invoice_mailboxes')->fetchColumn()===0,'failed fetch retains cursor and releases lease');
ok((int)$db->query('SELECT COUNT(*) FROM invoice_mail_originals')->fetchColumn()===3&&(int)$db->query('SELECT COUNT(*) FROM invoice_mail_original_links')->fetchColumn()===3,'same attachment retains every distinct original email and correction note');
$d['enabled']=false;unset($d['imap_password']);$service->save($d,1);ok($service->fetch($fetch)['status']==='disabled','saved disable prevents further intake');
$d['enabled']=true;$service->save($d,2);$fixture['messages']=[];$service->fetch(function($c)use($fixture){if($c['imap_password']!=='Synthetic-private-123')throw new RuntimeException('missing password');return $fixture;});ok(true,'blank password retains encrypted credential');
$d['enabled']=false;$d['clear_imap_password']='1';$service->save($d,3);$d['enabled']=true;unset($d['clear_imap_password']);denied(fn()=>$service->save($d,4),'explicit password removal requires reentry before activation');
foreach(['127.0.0.1','localhost','imap.example.test/novalidate-cert','x}INBOX','[::1]'] as $host){$invalid=$d;$invalid['imap_host']=$host;denied(fn()=>InvoiceMailbox::normalize($invalid),'unsafe host syntax rejected');}
$invalid=$d;$invalid['folder']='INBOX}other';denied(fn()=>InvoiceMailbox::normalize($invalid),'folder cannot inject IMAP options');
$part=(object)['parts'=>[(object)['bytes'=>10,'encoding'=>3,'dparameters'=>[(object)['attribute'=>'filename','value'=>'invoice.pdf']]]]];
ok(InvoiceMailboxTransport::parts($part)[0]['part']==='1','MIME attachment paths extracted without trusting MIME type');
$encoded=[(object)['attribute'=>'filename*','value'=>"UTF-8''Rechnung%20M%C3%A4rz.pdf"]];ok(InvoiceMailboxTransport::filename($encoded)==='Rechnung März.pdf','RFC2231 extended filename preserved');
$continued=[(object)['attribute'=>'filename*0*','value'=>"utf-8''Rechnung%20"],(object)['attribute'=>'filename*1*','value'=>'M%C3%A4rz.pdf']];ok(InvoiceMailboxTransport::filename($continued)==='Rechnung März.pdf','RFC2231 filename continuations preserved');
$_SESSION=['actor'=>'admin','csrf'=>'synthetic'];putenv('APP_SECRET=strictly-synthetic-different-render-key-123');
// Rendering does not decrypt credentials or echo password values, even after a failed POST.
$_POST=['action'=>'settings-invoice-mailbox','version'=>'1','data'=>array_merge($d,['imap_password'=>'Never-echo-this'])];
ob_start();require __DIR__.'/../views/Settings/settings-invoice-mailbox.php';$html=ob_get_clean();ok(!str_contains($html,'Never-echo-this')&&str_contains($html,'name="version" value="1"'),'failed form preserves original version but never password');
ok(\Kulturhus\Verein\Tests\MySqlFixture::integrity($db)==='ok'&&!(\Kulturhus\Verein\Tests\MySqlFixture::foreignKeyErrors($db)[0]??false),'migration and import preserve database integrity');
$ordinaryCalls=0;$ordinary=static function(...$args)use(&$ordinaryCalls){++$ordinaryCalls;};
$router=InvoiceMailbox::generalInvoiceSender($db,'a',$key,'https://example.test',$ordinary);$router('recipient@example.invalid','Synthetic','Text','<p>Text</p>',[]);
ok($ordinaryCalls===1,'disabled invoice SMTP retains ordinary transport');
$brokenConfig=$service->state()['data'];$brokenConfig['smtp_enabled']=true;$db->prepare('UPDATE invoice_mailboxes SET config=? WHERE club_id=?')->execute([json_encode($brokenConfig),'a']);
$brokenRouter=InvoiceMailbox::generalInvoiceSender($db,'a',str_repeat('wrong-key-',5),'https://example.test',$ordinary);
denied(fn()=>$brokenRouter('recipient@example.invalid','Synthetic','Text','<p>Text</p>',[]),'enabled broken invoice adapter does not silently select default transport');
ok($ordinaryCalls===1,'broken invoice adapter never called fallback');
// A new IMAP source must not inherit the previous source's successful status.
$db->exec("INSERT INTO clubs VALUES('state-test','Synthetic state');INSERT INTO actors(id,club_id,role,is_superuser) VALUES('state-admin','state-test','members',1)");
$stateKey=random_bytes(32);$stateService=new InvoiceMailbox($db,'state-admin',$stateKey);$stateConfig=array_replace(InvoiceMailbox::defaults(),['email'=>'state@example.invalid','imap_host'=>'imap.example.test','imap_user'=>'state@example.invalid','since'=>'2026-01-01']);$stateService->save($stateConfig,0);
$markStatus=static function()use($db){$db->exec("UPDATE invoice_mailboxes SET last_run='2026-09-07T12:00:00Z',last_status='Successful old fetch',uidvalidity=99,last_uid=42 WHERE club_id='state-test'");};
foreach(['imap_host'=>'changed.example.test','imap_user'=>'changed@example.invalid','folder'=>'Invoices','since'=>'2026-02-01'] as $field=>$value){$markStatus();$stateConfig[$field]=$value;$stateService->save($stateConfig,$stateService->state()['version']);$reloaded=(new InvoiceMailbox($db,'state-admin',$stateKey))->state();$cursor=$db->query("SELECT uidvalidity,last_uid FROM invoice_mailboxes WHERE club_id='state-test'")->fetch();ok($reloaded['last_run']===''&&$reloaded['last_status']===''&&(int)$cursor['uidvalidity']===0&&(int)$cursor['last_uid']===0,'changed '.$field.' clears previous fetch status and cursor after reload');}
foreach(['unchanged'=>[], 'SMTP only'=>['smtp_host'=>'smtp.example.test'], 'password only'=>['imap_password'=>'Synthetic-replacement-password']] as $label=>$update){$markStatus();$stateConfig=array_replace($stateConfig,$update);$stateService->save($stateConfig,$stateService->state()['version']);$reloaded=(new InvoiceMailbox($db,'state-admin',$stateKey))->state();$cursor=$db->query("SELECT uidvalidity,last_uid FROM invoice_mailboxes WHERE club_id='state-test'")->fetch();ok($reloaded['last_run']==='2026-09-07T12:00:00Z'&&$reloaded['last_status']==='Successful old fetch'&&(int)$cursor['uidvalidity']===99&&(int)$cursor['last_uid']===42,$label.' preserves same-source fetch status and cursor');}
echo "$n invoice mailbox checks passed; fake fetch only, no real mail\n";
