<?php declare(strict_types=1);
require __DIR__.'/../src/bootstrap.php';
require __DIR__.'/../src/Web/View.php';
use Kulturhus\Verein\{Database,Registry};
set_error_handler(static function($severity,$message,$file,$line){throw new ErrorException($message,0,$severity,$file,$line);});
$db=\Kulturhus\Verein\Tests\MySqlFixture::connect(':memory:');Database::migrate($db);
$db->exec("INSERT INTO clubs VALUES('a','Synthetic'); INSERT INTO actors(id,club_id,role,is_superuser) VALUES('admin','a','members',1)");
$registry=new Registry($db,'admin');
function calendarHtml(string $mode,string $date,array $rows,string $query=''):string{
 global $registry,$db;
 $_SESSION=['actor'=>'admin'];
 $_GET=['q'=>$query];$day=new DateTimeImmutable($date,new DateTimeZone('Europe/Berlin'));
 $calendarMode=$mode;$first=$day->modify('first day of this month');$gridStart=$first->modify('monday this week');
 $tab='';$editingEvent=false;$events=$rows;
 $messages=\Kulturhus\Native\Language\ManagerMessages::messages('de');$calendarText=static fn(string $source):string=>\Kulturhus\Native\Language\ManagerMessages::text($source,$messages);$calendarFormat=static fn(string $source,array $values):string=>\Kulturhus\Native\Language\CalendarMessages::format($source,$values,$calendarText);
 ob_start();require __DIR__.'/../views/Events/calendar.php';return ob_get_clean();
}
function eventRow(string $id,string $from,string $to,string $title):array{return ['id'=>$id,'starts_at'=>$from.'T10:00:00+02:00','ends_at'=>$to.'T12:00:00+02:00','title'=>$title,'location'=>'Testort','category'=>'verein'];}
function boardText(string $html):string{$dom=new DOMDocument();libxml_use_internal_errors(true);$dom->loadHTML('<?xml encoding="UTF-8">'.$html);return (new DOMXPath($dom))->evaluate('string(//section[contains(@class,"calendar-board")])');}
function check(bool $condition,string $label):void{if(!$condition)throw new RuntimeException($label);echo "PASS $label\n";}
$rows=[eventRow('day','2026-09-07','2026-09-07','Äpfelfest'),eventRow('span','2026-09-06','2026-09-08','Mehrtagestermin'),eventRow('later','2026-09-18','2026-09-18','Späterer Termin'),eventRow('outside','2026-10-20','2026-10-20','Außerhalb')];
$html=calendarHtml('day','2026-09-07',$rows);
check(str_contains(boardText($html),'Äpfelfest')&&str_contains(boardText($html),'Mehrtagestermin')&&!str_contains(boardText($html),'Späterer Termin'),'day list matches displayed day and includes overlapping events');
$html=calendarHtml('week','2026-09-07',$rows);
check(str_contains(boardText($html),'Mehrtagestermin')&&!str_contains(boardText($html),'Späterer Termin'),'week list excludes other weeks');
$html=calendarHtml('month','2026-09-07',$rows);
check(str_contains(boardText($html),'Späterer Termin')&&!str_contains(boardText($html),'Außerhalb'),'month list matches full visible calendar grid');
check(str_contains($html,'Außerhalb')&&str_contains($html,'data-calendar-upcoming-month="2026-10"'),'left list includes following months while the grid stays in its chosen range');
$html=calendarHtml('day','2026-09-07',$rows,'äPFEL');
check(str_contains($html,'Äpfelfest')&&!str_contains($html,'Mehrtagestermin'),'search is case insensitive for German event titles');
$html=calendarHtml('week','2026-09-07',$rows,'kein Treffer');
check(str_contains($html,'keine Treffer für deine Suche')&&str_contains($html,'Suche zurücksetzen'),'empty search explains its range and offers reset');
$allDay=eventRow('all-day','2026-12-22','2026-12-22','Ganztag');$allDay['all_day']=true;$unknown=eventRow('unknown','2026-12-23','2026-12-24','Unbekanntes Ende');$unknown['end_known']=false;$unknown['ends_at']='2026-12-24T06:00:00+01:00';$html=calendarHtml('month','2026-11-15',[$allDay,$unknown]);$dom=new DOMDocument();libxml_use_internal_errors(true);$dom->loadHTML('<?xml encoding="UTF-8">'.$html);$xp=new DOMXPath($dom);$allDayText=$xp->evaluate('string(//*[@data-calendar-upcoming-event="all-day"]//small)');$unknownText=$xp->evaluate('string(//*[@data-calendar-upcoming-event="unknown"])');check(str_contains($allDayText,'Ganztägig')&&!str_contains($allDayText,'12:00'),'all-day future entries do not invent an ending time');check(str_contains($unknownText,'23.12.2026')&&!str_contains($unknownText,'24.12.2026')&&!str_contains($unknownText,'06:00'),'unknown ending suppresses artificial end dates and times');
echo "8 calendar usability checks passed; no persistent data or external calls\n";
