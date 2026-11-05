<?php declare(strict_types=1);
require __DIR__.'/../src/bootstrap.php';
use Kulturhus\Verein\{Database,ClubMailings};
$db=\Kulturhus\Verein\Tests\MySqlFixture::connect(':memory:');Database::migrate($db);
$db->exec("INSERT INTO clubs VALUES('a','A'),('b','B');INSERT INTO actors(id,club_id,role,is_superuser) VALUES('board','a','members',1),('member','a','member',0);INSERT INTO contacts(id,club_id,kind,name,email,address) VALUES('a','a','person','Active','shared@example.invalid','Test'),('p','a','person','Passive','shared@example.invalid','Test'),('old','a','person','Former','old@example.invalid','Test'),('c','a','person','Contact','c@example.invalid','Test'),('foreign','b','person','Foreign','foreign@example.invalid','Test')");
$db->exec("INSERT INTO memberships(id,club_id,contact_id,member_number,status,starts_on,ends_on) VALUES('ma','a','a','1','aktiv','2020-01-01',NULL),('mp','a','p','2','passiv','2020-01-01',NULL),('mo','a','old','3','ehemalig','2010-01-01','2020-01-01')");
function check($v,$m){if(!$v)throw new RuntimeException($m);echo "PASS $m\n";}
function denied($f,$m){try{$f();}catch(DomainException){check(true,$m);return;}throw new RuntimeException($m);}
$s=new ClubMailings($db,'board');$people=array_column($s->contacts(),null,'id');
check($people['a']['recipient_group']==='members'&&$people['p']['recipient_group']==='members','active and passive members grouped');
check($people['old']['recipient_group']==='other'&&$people['c']['recipient_group']==='contacts','former members never classified as contacts');
check(!isset($people['foreign']),'tenant boundary');
$key=bin2hex(random_bytes(16));$id=$s->save('','Synthetic','Body',['a','p'],$key);check(count($s->preview($id)['recipients'])===1,'shared email deduplicated in preview');
check($s->save('','Synthetic','Body',['a','p'],$key)===$id,'draft replay stable');
denied(fn()=>$s->save('','Synthetic','Body',['foreign'],bin2hex(random_bytes(16))),'foreign recipient rejected');
denied(fn()=>(new ClubMailings($db,'member'))->contacts(),'member cannot read outgoing recipients');
check((int)$db->query('SELECT count(*) FROM club_outbox')->fetchColumn()===0,'draft and preview create no outgoing deliveries');
