<?php declare(strict_types=1);
require __DIR__.'/../src/bootstrap.php';require __DIR__.'/../src/Web/View.php';
use Kulturhus\Verein\{Database,Registry,EventGema,Finance,CalendarInvitations,Documents};
set_error_handler(static function($s,$m,$f,$l){throw new ErrorException($m,0,$s,$f,$l);});
function check(bool $ok,string $label):void{if(!$ok)throw new RuntimeException($label);echo "PASS $label\n";}
$db=\Kulturhus\Verein\Tests\MySqlFixture::connect(':memory:');Database::migrate($db);$db->exec("INSERT INTO clubs VALUES('a','Test');INSERT INTO actors(id,club_id,role,is_superuser) VALUES('admin','a','members',1)");
$_SESSION=['actor'=>'admin','csrf'=>str_repeat('a',64)];$_GET=['year'=>'2027'];$_POST=[];
$registry=new Registry($db,'admin');$eventId=$registry->saveAppointment(null,0,['title'=>'Bandabend <Test>','start'=>'2027-06-01T18:00','end'=>'2027-06-01T21:00','category'=>'verein'],[]);$event=$registry->appointment($eventId);
$invitations=new CalendarInvitations($db,'admin',$registry);$finance=new Finance($db,'admin');$documents=new Documents($db,'admin');
ob_start();require __DIR__.'/../views/Events/event-gema.php';$html=ob_get_clean();
check(str_contains($html,'Die Band reicht die Liste ein'),'band submission guidance appears');
check(str_contains($html,'Werknummern und Urheber nicht aus dem Kopf kennen'),'unknown-data guidance appears');
check(str_contains($html,'Bandabend &lt;Test&gt;'),'event title escaped in copy template');
check(str_contains($html,'id="gema-help-A"')&&str_contains($html,'sonst leer lassen'),'work number explicitly optional');
check(strpos($html,'id="gema-music"')<strpos($html,'id="gema-invoice"'),'music workflow precedes invoice');
libxml_use_internal_errors(true);$dom=new DOMDocument();@$dom->loadHTML('<?xml encoding="UTF-8">'.$html);$xp=new DOMXPath($dom);
check($xp->query('//details[summary[contains(.,"Musikliste hier selbst vorbereiten")]][not(@open)]')->length===1,'empty local preparation initially collapsed');
check($xp->query('//input[@name="A"]/ancestor::details[summary[contains(.,"Weitere Angaben")]]')->length===1,'work number is an advanced field');
check($xp->query('//form[input[@value="gema-setlist-export"]]/button[@disabled]')->length===1,'empty export explained and disabled');
$service=new EventGema($db,'admin',$registry);$service->changeSong($eventId,0,'save','new',['B'=>'Bekanntes Lied','F'=>'Originalinterpret']);
$_POST=['action'=>'gema-song','operation'=>'save','song_index'=>'0','version'=>'0','B'=>'Mein Entwurf','F'=>'Meine Angabe'];
ob_start();require __DIR__.'/../views/Events/event-gema.php';$html=ob_get_clean();@$dom->loadHTML('<?xml encoding="UTF-8">'.$html);$xp=new DOMXPath($dom);
check($xp->evaluate('string(//form[input[@name="operation" and @value="save"]]/input[@name="version"]/@value)')==='0','stale song edit retains original version');
check($xp->evaluate('string(//input[@name="B"]/@value)')==='Mein Entwurf','song draft text retained');
check($xp->query('//form[input[@value="gema-setlist-export"]]/button[@disabled]')->length===0,'saved title and artist export without work number');
check($xp->query('//button[@value="remove"]/ancestor::form[@data-confirm]')->length===1,'removal asks specifically about removing a title');
$_POST=['action'=>'save-gema-check','version'=>'0','needed'=>'yes','done'=>'1','setlist'=>'pending'];
ob_start();require __DIR__.'/../views/Events/event-gema.php';$html=ob_get_clean();@$dom->loadHTML('<?xml encoding="UTF-8">'.$html);$xp=new DOMXPath($dom);
check($xp->evaluate('string(//form[input[@value="save-gema-check"]]/input[@name="version"]/@value)')==='0','checklist retains original conflict version');
check($xp->evaluate('string(//select[@name="needed"]/option[@selected]/@value)')==='yes','checklist selection retained after failure');
foreach([['yes',true,'pending'],['yes',false,'submitted'],['no',false,'not_required'],['unknown',false,'check']] as [$needed,$done,$setlist]){
    $service->checklist($eventId,(int)$service->get($eventId)['version'],$needed,$done,$setlist,$needed==='no'?'Ohne Musiknutzung; ursprüngliche Betreiberprüfung.':'');$_POST=[];
    ob_start();require __DIR__.'/../views/Events/event-gema.php';$html=ob_get_clean();@$dom->loadHTML('<?xml encoding="UTF-8">'.$html);$xp=new DOMXPath($dom);
    check($xp->evaluate('string(//select[@name="needed"]/option[@selected]/@value)')===$needed,'saved GEMA need displayed after fresh render: '.$needed);
    check($xp->evaluate('string(//select[@name="done"]/option[@selected]/@value)')===($done?'1':'0'),'saved GEMA done state displayed after fresh render: '.$needed.'/'.(int)$done);
    check($xp->evaluate('string(//select[@name="setlist"]/option[@selected]/@value)')===$setlist,'saved setlist state displayed after fresh render: '.$setlist);
}
check((int)$db->query('SELECT COUNT(*) FROM club_outbox')->fetchColumn()===0,'help rendering sends no mail');
