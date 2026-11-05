<?php declare(strict_types=1);
require __DIR__.'/../src/bootstrap.php';
require_once __DIR__.'/../src/Finance/Accounting/FinanceReports.php';
require __DIR__.'/../src/Web/View.php';

use Kulturhus\Verein\{Database,Finance,Settings};

$checks=0;
function check(bool $condition,string $label):void{global $checks;if(!$condition)throw new RuntimeException($label);++$checks;echo "PASS $label\n";}
function denied(callable $callback,string $label):void{try{$callback();}catch(DomainException){check(true,$label);return;}throw new RuntimeException($label);}
set_error_handler(static function(int $severity,string $message,string $file,int $line):never{throw new ErrorException($message,0,$severity,$file,$line);});
$db=\Kulturhus\Verein\Tests\MySqlFixture::connect(':memory:');Database::migrate($db);
$db->exec("INSERT INTO clubs VALUES('a','Synthetischer Verein'),('b','Synthetischer anderer Verein');INSERT INTO actors(id,club_id,role,is_superuser) VALUES('writer','a','treasurer',0),('reader','a','member',0),('member','a','member',0),('other','b','treasurer',0),('admin','a','member',1)");
$settings=new Settings($db,'admin');$readerRole=$settings->saveRole('',0,['name'=>'Synthetisches Finanzleserecht','finance_access'=>'read','members_access'=>'none']);$settings->assignRole('reader',$readerRole,0);
$finance=new Finance($db,'writer');$other=new Finance($db,'other');
$bank=$finance->createAccount('Synthetisches Berichtskonto','offline','0','2026-01-01');
$correctionBank=$finance->createAccount('Synthetisches Korrekturkonto','offline','0','2026-01-01');
$foreign=$other->createAccount('Synthetisches Fremdkonto','offline','0','2026-01-01');
$entry=static function(string $kind,string $amount,string $account)use($finance):string{
    $id=$finance->createEntry(['kind'=>$kind,'description'=>'Synthetischer Auswertungsbeleg','amount'=>$amount,'category'=>'general','document_date'=>'2026-01-10','due_date'=>'2026-01-10'],bin2hex(random_bytes(16)));
    $finance->recordPayment($id,1,$account,'2026-01-10');return $id;
};
$entry('income','30,00',$bank);$entry('expense','9,00',$bank);
$corrected=$entry('donation','5,00',$correctionBank);$finance->reverse($corrected,2,'2026-02-03','Synthetische Korrektur');
$_SESSION=['actor'=>'reader'];
function renderReport(PDO $db,array $params):string{
    $_GET=$params+['from'=>'2026-01-01','to'=>'2026-02-28'];
    ob_start();try{require __DIR__.'/../views/Finance/Accounting/finance-reports.php';return ob_get_clean();}catch(Throwable $error){ob_end_clean();throw $error;}
}
$table=renderReport($db,['account'=>$bank]);
check(str_contains($table,'Januar 2026')&&str_contains($table,'Februar 2026'),'full German month names and year');
check(str_contains($table,'+30,00 €')&&str_contains($table,'−9,00 €')&&str_contains($table,'+21,00 €'),'income expense and result carry their cash direction');
check(str_contains($table,'Konto: Synthetisches Berichtskonto')&&str_contains($table,'name="account" value="'.$bank.'"'),'account scope is visible and preserved on form submission');
check(str_contains($table,'account='.$bank.'&amp;download=finance-report-xlsx'),'export retains the displayed account scope');
check(str_contains($table,'Vereinsergebnis')&&!str_contains($table,'method="post"'),'finance reader receives a read-only report');
$comparison=renderReport($db,['account'=>$bank,'compare'=>'1']);
check(str_contains($comparison,'Einnahmen Vorjahr')&&str_contains($comparison,'keine prozentuale Veränderung berechenbar'),'zero prior-year base is not presented as a percentage');
$balanced=renderReport($db,['report_display'=>'chart','account'=>$correctionBank]);
check(!str_contains($balanced,'class="report-chart-empty"'),'zero total with movements in different months is not an empty chart');
check(str_contains($balanced,'Einnahmen: 5,00 €')&&str_contains($balanced,'Einnahmen: −5,00 €'),'positive and negative monthly movements remain visible');
check(!preg_match('/(?:NaN|Infinity|height="-)/',$balanced),'correction bars have finite nonnegative geometry');
$negative=renderReport($db,['report_display'=>'chart','account'=>$correctionBank,'from'=>'2026-02-01']);
check(str_contains($negative,'Korrekturen enthalten')&&str_contains($negative,'−5,00 €'),'negative breakdown shows signed values instead of misleading donut shares');
$empty=renderReport($db,['report_display'=>'chart','from'=>'2026-12-01','to'=>'2026-12-31']);
check(str_contains($empty,'class="report-chart-empty"'),'period with no current or compared movements has an empty hint');
$journal=renderReport($db,['report_type'=>'journal','account'=>$bank]);
check(str_contains($journal,'Gegenkonto (Haben)')&&str_contains($journal,'−9,00 €'),'journal renders debit credit and signed amounts');
$emptyJournal=renderReport($db,['report_type'=>'journal','from'=>'2026-12-01','to'=>'2026-12-31']);
check(str_contains($emptyJournal,'Keine Buchungen im gewählten Zeitraum'),'empty journal has a clear state');
$longChart=renderReport($db,['report_display'=>'chart','resolution'=>'week','from'=>'2016-01-01','to'=>'2025-12-31']);
preg_match('/class="report-chart" viewBox="0 0 ([0-9]+) 330" width="([0-9]+)"/',$longChart,$size);
check(count($size)===3&&(int)$size[1]>20000&&$size[1]===$size[2],'long weekly chart retains a full intrinsic drawing width for scrolling');
$rejected=renderReport($db,['tags'=>['unavailable']]);check(str_contains($rejected,'data-report-repair')&&str_contains($rejected,'unavailable')&&!str_contains($rejected,'download=finance-report-xlsx'),'unknown tags retain their original filter and cannot silently change displayed export scope');
$rejected=renderReport($db,['tags'=>['nested'=>[]]]);check(str_contains($rejected,'data-report-original-tags')&&str_contains($rejected,'nested')&&str_contains($rejected,'unsupported_original_shape')&&!str_contains($rejected,'download=finance-report-xlsx'),'malformed tags remain rejected and explicit removal is required');
$rejected=renderReport($db,['account'=>$foreign]);check(str_contains($rejected,'data-finance-report-errors')&&str_contains($rejected,'Konto nicht verfügbar.')&&!str_contains($rejected,'Synthetisches Fremdkonto')&&!str_contains($rejected,'download=finance-report-xlsx'),'foreign account retains only the rejected identifier and never exposes a report or private account name');
$_SESSION['actor']='member';denied(fn()=>renderReport($db,[]),'member without finance rights cannot render reports');
restore_error_handler();
echo "$checks finance report view checks passed\n";
