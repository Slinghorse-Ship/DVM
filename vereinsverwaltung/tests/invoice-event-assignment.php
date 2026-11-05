<?php declare(strict_types=1);
require __DIR__.'/../src/bootstrap.php';
use Kulturhus\Verein\{Database,Documents,Finance,InvoiceInbox,Registry,WebsiteCalendar,DocumentCatalog,EventGema};
$db=\Kulturhus\Verein\Tests\MySqlFixture::connect(':memory:');Database::migrate($db);$db->exec("INSERT INTO clubs VALUES('a','Synthetic A'),('b','Synthetic B');INSERT INTO actors(id,club_id,role,is_superuser) VALUES('admin','a','member',1),('foreign','b','member',1),('member','a','member',0)");
$n=0;function check($x,$s){global$n;if(!$x)throw new RuntimeException($s);++$n;echo "PASS $s\n";}function denied($f,$s){try{$f();}catch(DomainException){check(true,$s);return;}throw new RuntimeException($s);}
$key=fn()=>bin2hex(random_bytes(16));$d=new Documents($db,'admin');$f=new Finance($db,'admin');$in=new InvoiceInbox($db,'admin');
$row=['id'=>42,'external_id'=>'test42','title'=>'Synthetic concert','summary'=>'Test only','venue_name'=>'Test hall','venue_address'=>'Example street','path'=>'/test-only/','status'=>'published','start_at'=>1790000000,'end_at'=>1790007200];
$calendar=new WebsiteCalendar('a',fn()=>['complete'=>true,'rows'=>[$row]]);$registry=new Registry($db,'admin',$calendar);
$upload=fn()=>$d->upload('test.xml','<Invoice xmlns="urn:test"/>',['title'=>'Test original','kind'=>'receipt','scope'=>'finance','links'=>[]],$key());
$data=['description'=>'Test artist','amount'=>'119','counterparty'=>'Synthetic band','document_date'=>'2026-09-07','due_date'=>'2026-09-30','category'=>'artist'];
$doc=$upload();$k=$key();$entry=$in->create($doc,1,$data,$k,'artist|website:42','TEST',$registry);
check($in->eventAssignment($entry)['purpose']==='artist','artist classification persisted');
check(count($db->query('SELECT * FROM appointments')->fetchAll())===0,'website event not copied to calendar');
check(count($d->document($doc)['links'])===2,'same original links expense and event');
$source=$d->source('appointment','website:42');check(str_contains($source['path'],'Termine')&&str_contains($source['path'],'Synthetic concert'),'website event has regular event folder');
check(DocumentCatalog::raw($db,'b','appointment','website:42')===null,'snapshot tenant isolation');
check($in->create($doc,1,$data,$k,'gema|website:42|0','TEST',$registry)===$entry&&$in->eventAssignment($entry)['purpose']==='artist','replay cannot change event purpose');
check(count($db->query('SELECT * FROM event_gema')->fetchAll())===0,'artist classification never changes GEMA');
try{$db->exec("UPDATE finance_event_assignments SET event_title='Changed'");throw new RuntimeException('Mutable');}catch(PDOException){check(true,'snapshot immutable');}
denied(fn()=>$in->create($upload(),1,$data,$key(),'artist|website:999','TEST',$registry),'unknown website event denied');
check(count($f->entries())===1,'invalid event leaves no expense');
denied(fn()=>(new InvoiceInbox($db,'foreign'))->eventAssignment($entry),'foreign finance reader denied');
denied(fn()=>(new Documents($db,'member'))->source('appointment','website:42',true),'missing organisation write denied');
$gdoc=$upload();$ge=$in->create($gdoc,1,array_replace($data,['category'=>'gema']),$key(),'gema|website:42|0','TEST',$registry);
check((new EventGema($db,'admin',$registry))->get('website:42')['entry_id']===$ge,'explicit GEMA purpose links existing GEMA workflow');
check(count($d->document($gdoc)['links'])===2,'GEMA original also present in event folder');
foreach(['gema','artist','inventory','postage'] as $category){$e=$in->create($upload(),1,array_replace($data,['category'=>$category]),$key(),'general','TEST');$p=$f->positions('entry',$e);check($f->entry($e)['category']===$category&&$p[0]['account_code']===''&&$p[0]['sphere']==='','category '.$category.' creates no tax mapping');}
check(\Kulturhus\Verein\Tests\MySqlFixture::integrity($db)==='ok','integrity');echo "$n event assignment checks passed\n";
