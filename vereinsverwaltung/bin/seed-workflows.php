<?php declare(strict_types=1);
require __DIR__.'/../src/bootstrap.php';
use Kulturhus\Verein\Database;
use Kulturhus\Verein\Finance;
if(PHP_SAPI!=='cli'||($argv[1]??'')!=='--synthetic'){fwrite(STDERR,"Nur lokal: --synthetic erforderlich.\n");exit(1);}
$db=Database::localDemo();Database::migrate($db);
$db->exec("INSERT OR IGNORE INTO actors (id,club_id,role) VALUES ('demo-finance','kulturhus-demo','treasurer')");
// Explicit grant for this local test actor only; no role gains implicit finance access.
$db->exec("INSERT OR IGNORE INTO actor_capabilities VALUES ('demo-manager','local_finance')");
$f=new Finance($db,'demo-finance');$accounts=$f->accounts();
if(!$accounts){$bank=$f->createAccount('Vereinskonto · Testdaten','offline','1250,00','2026-01-01');$cash=$f->createAccount('Barkasse · Testdaten','cash','200,00','2026-01-01');}
else {echo "Bestehende Finanzdaten bleiben erhalten; keine zusätzlichen Testbuchungen.\n";exit;}
$seed=[['contribution','Jahresbeiträge · Testdaten','Testmitglieder','720,00','2026-04-03','membership',$bank],['expense','Raummiete April · Testdaten','Mustervermietung','240,00','2026-04-08','rooms',$bank],['income','Frühlingskonzert · Testdaten','Testveranstaltung','480,00','2026-05-15','culture',$bank],['expense','Veranstaltungstechnik · Testdaten','Mustertechnik','175,50','2026-05-15','culture',$bank],['donation','Förderung Kulturprogramm · Testdaten','Synthetische Förderperson','350,00','2026-06-10','donations',$bank],['expense','Büromaterial · Testdaten','Musterbüro','38,90','2026-06-15','office',$cash],['income','Sommerlesung · Testdaten','Testveranstaltung','265,00','2026-07-12','culture',$bank],['expense','Raummiete Juli · Testdaten','Mustervermietung','240,00','2026-07-15','rooms',$bank],['donation','Sommerförderung · Testdaten','Synthetische Förderperson','180,00','2026-08-08','donations',$bank],['expense','Druck Programmheft · Testdaten','Musterdruck','95,00','2026-08-09','office',$bank],['contribution','Mitgliedsbeitrag September · Testdaten','Muster Mitglied','60,00','2026-09-01','membership',$bank]];
foreach($seed as $i=>[$kind,$description,$counterparty,$amount,$date,$category,$account]){$id=$f->createEntry(['kind'=>$kind,'description'=>$description,'counterparty'=>$counterparty,'amount'=>$amount,'document_date'=>$date,'due_date'=>$date,'category'=>$category],md5('kulturhus-fixture-'.$i));$f->recordPayment($id,1,$account,$date);}
$f->createEntry(['kind'=>'contribution','description'=>'Offener Jahresbeitrag · Testdaten','counterparty'=>'Muster Mitglied','amount'=>'120,00','document_date'=>'2026-09-01','due_date'=>'2026-09-15','category'=>'membership'],md5('kulturhus-open-contribution'));
$f->createEntry(['kind'=>'expense','description'=>'Offene Raummiete · Testdaten','counterparty'=>'Mustervermietung','amount'=>'240,00','document_date'=>'2026-09-01','due_date'=>'2026-09-20','category'=>'rooms'],md5('kulturhus-open-expense'));
$f->createGoods(['donor'=>'Synthetische Förderperson','description'=>'Lesepult · Testdaten','received_on'=>'2026-08-12','amount'=>'150,00','valuation'=>'Fiktiver Wert für den Oberflächentest; kein steuerlicher Nachweis.'],md5('kulturhus-goods'));
echo "Synthetische Finanzbeispiele gespeichert. Keine WISO-Daten übernommen.\n";
