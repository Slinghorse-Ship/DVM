<?php declare(strict_types=1);
require __DIR__.'/../src/bootstrap.php';
use Kulturhus\Verein\{Database,Registry,ActivityReport,WebsiteCalendar};
$db=\Kulturhus\Verein\Tests\MySqlFixture::connect();Database::migrate($db);
$db->exec("INSERT INTO clubs VALUES('a','Synthetischer Testverein'); INSERT INTO actors(id,club_id,role,is_superuser) VALUES('admin','a','member',1),('member','a','member',0)");
$registry=new Registry($db,'admin');
$registry->saveAppointment(null,0,['title'=>'Jahresversammlung','start'=>'2025-05-09T18:00','end'=>'2025-05-09T20:00','location'=>'Testort','category'=>'verein','description'=>'','activity_kind'=>'assembly','activity_state'=>'completed','report_summary'=>'Bericht des Vorstands.'],[]);
$row=static fn(int $id,string $title,string $status,string $date):array=>['id'=>$id,'external_id'=>'','title'=>$title,'summary'=>'','venue_name'=>'Testort','venue_address'=>'','path'=>'/test-'.$id.'/','status'=>$status,'start_at'=>strtotime($date.'T16:00:00Z'),'end_at'=>strtotime($date.'T18:00:00Z')];
$website=new WebsiteCalendar('a',static fn()=>['complete'=>true,'rows'=>[$row(1,'Jahresversammlung','published','2025-05-09'),$row(2,'Sommerkonzert','published','2025-06-10'),$row(3,'Ausgefallenes Konzert','cancelled','2025-07-10'),$row(4,'Spaeterer Termin','published','2027-06-10')]]);
$before=$db->query('SELECT * FROM appointments ORDER BY id')->fetchAll();
$report=(new ActivityReport($db,'admin',$website))->build(2025,2025);$n=0;
function check(bool $ok,string $message):void{global $n;if(!$ok)throw new RuntimeException($message);$n++;echo "PASS $message\n";}
check(str_contains($report['text'],"\nDurchgeführte Veranstaltungen\n"),'formal event heading is exact');
check(!str_contains($report['text'],'Website-Archiv'),'internal archive wording removed from report');
check(str_starts_with($report['text'],"Tätigkeitsbericht 2025\n")&&!str_contains($report['text'],'Aus den gespeicherten Vereinsdaten')&&!str_contains($report['text'],'vor Verwendung ergänzen'),'report uses formal wording without internal generation instructions');
check(count($report['activities'])===1&&count($report['website_completed'])===1,'calendar and website duplicates remain excluded');
check(str_contains($report['text'],'Sommerkonzert')&&!str_contains($report['text'],'Ausgefallenes Konzert')&&!str_contains($report['text'],'Spaeterer Termin'),'cancelled and out-of-year events remain excluded');
check(str_contains($report['text'],'Quelle:')&&str_contains($report['text'],'test-2/'),'source evidence remains available');
check($before===$db->query('SELECT * FROM appointments ORDER BY id')->fetchAll(),'report creation does not change existing activities');
try{(new ActivityReport($db,'member',$website))->build(2025,2025);throw new RuntimeException('Permission check missing');}catch(DomainException){check(true,'financial read permission remains required');}
echo "$n activity-report checks passed; synthetic MySQL, no external calls\n";
