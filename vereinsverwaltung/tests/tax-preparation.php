<?php declare(strict_types=1);
require __DIR__.'/../src/bootstrap.php';
use Kulturhus\Verein\{Database,Finance,Documents,TaxPreparation};
$checks=0;function check(bool $ok,string $label):void{global $checks;if(!$ok)throw new RuntimeException($label);$checks++;echo "PASS $label\n";}
function denied(callable $fn,string $label):void{try{$fn();}catch(DomainException){check(true,$label);return;}throw new RuntimeException($label);}
$db=\Kulturhus\Verein\Tests\MySqlFixture::connect(':memory:');Database::migrate($db);$db->exec("INSERT INTO clubs VALUES('a','Test A'),('b','Test B');INSERT INTO actors(id,club_id,role) VALUES('finance','a','treasurer'),('member','a','member'),('other','b','treasurer'),('manager','a','members')");
$finance=new Finance($db,'finance');$key=static fn()=>bin2hex(random_bytes(16));$account=$finance->createAccount('Bank','offline','10','2025-01-01');
$finance->createTransaction(['account'=>$account,'booked_on'=>'2025-05-01','direction'=>'income','amount'=>'100','counterparty'=>'=unsafe','description'=>'Recorded income'],$key());
$finance->createTransaction(['account'=>$account,'booked_on'=>'2025-06-01','direction'=>'expense','amount'=>'20','description'=>'Recorded expense'],$key());
$service=new TaxPreparation($db,'finance');$bundle=$service->build(2025,2025,'Real test activity');
check(!array_diff(['2025/zahlungsjournal.csv','2025/geldkonten.csv','2025/sphaeren.csv','2025/steuerkonten.csv','2025/jahresangaben.json','fragen-zur-erklaerung.json','uebersicht.html','datenstand.json','taetigkeitsbericht.txt','vereins-und-steuerdaten.json','LESEN.txt'],array_keys($bundle['files'])),'bundle contains complete working papers and exact readable snapshot');
check($bundle['snapshot']['reports'][2025]['journal']['signed_total_cents']===8000,'recorded movements retain exact signed result');
check(str_contains($bundle['files']['2025/geldkonten.csv'],'9000'),'closing cash includes opening balance');
check(str_contains($bundle['files']['2025/zahlungsjournal.csv'],"'=unsafe"),'CSV formulas neutralized');
check(str_contains($bundle['files']['2025/zahlungsjournal.csv'],';-2000;')&&!str_contains($bundle['files']['2025/zahlungsjournal.csv'],"'-2000"),'negative cent values remain numeric for spreadsheet follow-up');
check(str_contains($bundle['files']['LESEN.txt'],'keine ausgefüllte Steuererklärung'),'work package cannot be mistaken for submission');
check(str_contains($bundle['files']['LESEN.txt'],'keine vollständige Vermögensübersicht'),'cash statement not mislabeled as all assets');
$token=$key();$id=$service->save(2025,2025,'Activity',[],$token);$docs=new Documents($db,'finance');$file=$docs->file($id);
check(str_starts_with($file['bytes'],"PK\x03\x04")&&$docs->document($id)['scopes']===['finance'],'real zip archived with financial protection');
check($service->save(2025,2025,'Activity',[],$token)===$id,'repeated export returns existing archive');
denied(fn()=>$service->save(2025,2025,'Different activity',[],$token),'same request cannot replace report');
denied(fn()=>(new TaxPreparation($db,'member'))->build(2025,2025,''),'member denied financial export');
denied(fn()=>(new Documents($db,'member'))->file($id),'member denied archived financial export');
denied(fn()=>(new Documents($db,'other'))->file($id),'other club denied archive');
foreach([[2020,2025],[2026,2025],[1999,2000],[2025,2099]] as [$from,$to])denied(fn()=>$service->build($from,$to,''),'invalid report range rejected');
denied(fn()=>$service->build(2025,2025,'',[$id]),'generated archives cannot be recursively attached');
$foreign=(new TaxPreparation($db,'other'))->build(2025,2025,'');check($foreign['snapshot']['reports'][2025]['journal']['count']===0,'foreign report has no first club movements');
check(!(\Kulturhus\Verein\Tests\MySqlFixture::foreignKeyErrors($db)[0]??false),'tax export leaves foreign keys valid');
echo "$checks tax preparation checks passed\n";
