<?php declare(strict_types=1);
require __DIR__.'/../src/bootstrap.php';
use Kulturhus\Verein\{Database,Registry,EventGema,GemaSetlist};
set_error_handler(static function($s,$m,$f,$l){throw new ErrorException($m,0,$s,$f,$l);});
$n=0;function ok(bool $v,string $m):void{global $n;if(!$v)throw new RuntimeException($m);echo 'PASS '.$m."\n";++$n;}
function denied(callable $f,string $m):void{try{$f();}catch(DomainException){ok(true,$m);return;}throw new RuntimeException($m);}
$db=\Kulturhus\Verein\Tests\MySqlFixture::connect(':memory:');Database::migrate($db);$db->exec("INSERT INTO clubs VALUES('a','A'),('b','B');INSERT INTO actors(id,club_id,role,is_superuser) VALUES('admin','a','members',1),('member','a','member',0),('other','b','members',1)");
$r=new Registry($db,'admin');$event=$r->saveAppointment(null,0,['title'=>'Setlist Test','start'=>'2027-06-01T18:00','end'=>'2027-06-01T21:00','category'=>'verein'],[]);$g=new EventGema($db,'admin',$r);
$song=['B'=>'=SUM(1,2) & Grüße','F'=>'Band <Test>','E'=>'03:45','A'=>'00123','K'=>'Live'];
$g->changeSong($event,0,'save','new',$song);$g->changeSong($event,1,'save','new',$song);ok(count($g->get($event)['songs'])===2,'repeated titles retained');
denied(fn()=>$g->changeSong($event,1,'remove','0'),'stale version cannot delete title');
$g->changeSong($event,2,'save','0',array_replace($song,['B'=>'First']));$g->changeSong($event,3,'down','0');ok($g->get($event)['songs'][1]['B']==='First','order changed');
$g->checklist($event,4,'yes',true,'pending');ok(count($g->get($event)['songs'])===2&&$g->get($event)['status']==='registered','checklist preserves songs');
$g->changeSong($event,5,'remove','1');ok($g->get($event)['setlist']==='pending','song editing does not mark submitted');
denied(fn()=>(new EventGema($db,'member',new Registry($db,'member')))->changeSong($event,6,'remove','0'),'ordinary member cannot edit');
denied(fn()=>(new EventGema($db,'other',new Registry($db,'other')))->get($event),'other club cannot read');
denied(fn()=>GemaSetlist::xlsx([]),'empty export blocked');denied(fn()=>GemaSetlist::xlsx([['B'=>'Incomplete']]),'incomplete export blocked');
denied(fn()=>GemaSetlist::normalize([['E'=>'3:99']]),'invalid duration blocked');denied(fn()=>GemaSetlist::normalize([['K'=>'invalid']]),'invalid template choice blocked');
denied(fn()=>GemaSetlist::normalize([['B'=>['array']]]),'nested field rejected');denied(fn()=>GemaSetlist::normalize(array_fill(0,201,$song)),'row limit enforced');
$path=tempnam(sys_get_temp_dir(),'gema-test-');file_put_contents($path,GemaSetlist::xlsx([$song,$song]));$zip=new ZipArchive();$zip->open($path);$original=new ZipArchive();$original->open(__DIR__.'/../resources/gema-setlist-template.xlsx');
for($i=0;$i<$original->numFiles;$i++){$name=$original->getNameIndex($i);if($name!=='xl/worksheets/sheet1.xml'&&$original->getFromName($name)!==$zip->getFromName($name))throw new RuntimeException('Template part changed: '.$name);}ok(true,'all other template parts preserved byte for byte');
$xml=new DOMDocument();$xml->loadXML($zip->getFromName('xl/worksheets/sheet1.xml'));$xp=new DOMXPath($xml);$xp->registerNamespace('s','http://schemas.openxmlformats.org/spreadsheetml/2006/main');
ok($xp->evaluate('string(//s:c[@r="B21"]/s:is/s:t)')===$song['B'],'title formula remains literal text');ok($xp->query('//s:c[@r="B21"]/s:f')->length===0,'no executable formula introduced');ok($xp->evaluate('string(//s:c[@r="E21"]/s:is/s:t)')==='03:45','duration kept in text-format template cell');ok($xp->evaluate('string(//s:c[@r="A21"]/s:is/s:t)')==='00123','work number leading zeros preserved');ok($xp->evaluate('string(//s:c[@r="F22"]/s:is/s:t)')===$song['F'],'repeat and XML characters preserved');
$zip->close();$original->close();unlink($path);echo "$n setlist checks passed\n";
