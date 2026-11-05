<?php declare(strict_types=1);
require __DIR__.'/../src/bootstrap.php';
require_once __DIR__.'/../src/Finance/Tax/TaxAnnualReport.php';

use Kulturhus\Verein\{Database,Finance,FinanceReports,Settings,TaxAnnualReport,TaxCatalog};

$checks=0;
function check(bool $ok,string $label):void{global $checks;if(!$ok)throw new RuntimeException($label);++$checks;echo "PASS $label\n";}
function denied(callable $read,string $label):void{try{$read();}catch(DomainException){check(true,$label);return;}throw new RuntimeException($label);}
$db=\Kulturhus\Verein\Tests\MySqlFixture::connect(':memory:');Database::migrate($db);
$db->exec("INSERT INTO clubs VALUES('a','Synthetischer Verein A'),('b','Synthetischer Verein B');INSERT INTO actors(id,club_id,role,is_superuser) VALUES('admin','a','member',1),('finance','a','treasurer',0),('reader','a','member',0),('member','a','member',0),('editor','a','editor',1),('foreign','b','treasurer',0),('inactive','a','treasurer',0),('disabled','a','treasurer',0)");
$settings=new Settings($db,'admin');
$role=$settings->saveRole('',0,['name'=>'Finanzleser','finance_access'=>'read','members_access'=>'none','settings_access'=>false]);
$settings->assignRole('reader',$role,0);
$db->exec("UPDATE actors SET active=0 WHERE id='inactive'");
$db->exec("INSERT INTO processwire_identities(pw_user_id,actor_id,enabled) VALUES(7762,'disabled',0)");
$f=new Finance($db,'finance');$g=new Finance($db,'foreign');$report=new TaxAnnualReport($db,'finance');$key=fn()=>bin2hex(random_bytes(16));
$bank=$f->createAccount('Synthetische Bank','offline','100.00','2022-01-01');
$other=$g->createAccount('Fremde Bank','offline','0','2022-01-01');
$p=fn(string $name,string $amount,string $sphere='1',string $account='40000',string $rate='0')=>['description'=>$name,'amount'=>$amount,'sphere'=>$sphere,'account'=>$account,'tax_rate'=>$rate];
$entry=function(string $kind,string $amount,string $date,string $description,array $positions=[])use($f,$key):string{
    $data=['kind'=>$kind,'amount'=>$amount,'document_date'=>$date,'due_date'=>$date,'description'=>$description,'counterparty'=>'Synthetischer Zahlungspartner','category'=>'general'];
    if($positions)$data['positions']=$positions;
    return $f->createEntry($data,$key());
};
$move=function(string $amount,string $date,string $direction='income')use($f,$bank,$key):string{return $f->createTransaction(['account'=>$bank,'booked_on'=>$date,'direction'=>$direction,'amount'=>$amount,'description'=>'Synthetische Zahlung'],$key());};
$allocate=function(string $transaction,string $entry,string $amount)use($f,$key):void{$f->allocate($transaction,(int)$f->transactionDetail($transaction)['version'],$entry,(int)$f->entry($entry)['version'],$amount,$key());};
$pay=function(string $entry,string $date)use($f,$bank):void{$f->recordPayment($entry,(int)$f->entry($entry)['version'],$bank,$date);};
$split=$entry('income','30.00','2025-12-01','Zahlung über Jahresgrenze',[$p('Erste Teilposition','10.01','1'),$p('Zweite Teilposition','19.99','2')]);
$allocate($move('20.00','2025-12-31'),$split,'20.00');
$allocate($move('10.00','2026-01-02'),$split,'10.00');
$tiny=$entry('income','0.02','2025-12-01','Centverteilung über Jahresgrenze',[$p('Erster Cent','0.01','1'),$p('Zweiter Cent','0.01','3')]);
$allocate($move('0.01','2025-12-31'),$tiny,'0.01');
$allocate($move('0.01','2026-01-02'),$tiny,'0.01');
$reversedIncome=$entry('income','5.00','2025-12-01','Korrigierte Einnahme',[$p('Korrigierte Einnahme','5.00','1')]);
$pay($reversedIncome,'2025-12-15');$f->reverse($reversedIncome,(int)$f->entry($reversedIncome)['version'],'2026-01-03','Synthetische Korrektur');
$reversedExpense=$entry('expense','7.00','2025-12-01','Korrigierte Ausgabe',[$p('Korrigierte Ausgabe','7.00','4','68150')]);
$pay($reversedExpense,'2025-12-15');$f->reverse($reversedExpense,(int)$f->entry($reversedExpense)['version'],'2026-01-03','Synthetische Korrektur');
$expense=$entry('expense','9.00','2026-01-01','Ausgabe mit Bruttobetrag',[$p('Material','9.00','3','68150','19')]);$pay($expense,'2026-01-04');
$technical=$entry('expense','4.00','2026-01-01','Technischer Sammelposten',[$p('Technischer Sammelposten','4.00','9','68150')]);$pay($technical,'2026-01-04');
$unclassified=$entry('expense','6.00','2026-01-01','Ungeklärte Zuordnung');$pay($unclassified,'2026-01-04');
$move('3.00','2026-01-05');
$shared=$entry('income','4.00','2026-01-01','Teil einer Sammelzahlung',[$p('Zugeordneter Anteil','4.00','1')]);$allocate($move('12.00','2026-01-05'),$shared,'4.00');
$f->saveTaxAccount('40401','Synthetisches eigenes Konto',true,0);
$custom=$entry('income','11.11','2026-01-01','Eigenes Konto',[$p('Eigenes Konto','11.11','2','40401')]);$pay($custom,'2026-01-06');
$f->saveTaxAccount('40401','Später umbenannt und deaktiviert',false,1);
// Historical invoices retain their actual date while recording the real verified assignment edition.
$historical=$entry('income','2.53','2022-04-01','Historische Zuordnung',[$p('Alter Beleg','2.53','2')]);
check($f->entry($historical)['document_date']==='2022-04-01'&&(int)$f->positions('entry',$historical)[0]['catalog_year']===TaxCatalog::YEAR,'historical assignment records actual current edition without inventing a 2022 catalogue');
check(TaxCatalog::assignmentUsesFallback(2022)&&!TaxCatalog::hasYear(2022),'historical fallback is explicit and not a verified original edition');
// Reports must preserve earlier stored evidence as well.
$old=$entry('income','2.53','2026-04-01','Alter Beleg später bezahlt',[$p('Alter Beleg','2.53','2')]);
$db->prepare("UPDATE finance_entries SET document_date='2022-04-01',due_date='2022-04-01' WHERE id=?")->execute([$old]);
$pay($old,'2026-01-07');
$open=$entry('expense','999.00','2026-01-01','Noch nicht bezahlte Rechnung',[$p('Unbezahltes Material','999.00','3','68150')]);
$f->createGoods(['donor'=>'Synthetischer Spender','description'=>'Synthetische Sachspende','designation'=>'Test','valuation'=>'Testgutachten','received_on'=>'2026-01-01','positions'=>[$p('Kein Geldfluss','100.00','1','11600')]],$key());
$g->createTransaction(['account'=>$other,'booked_on'=>'2026-01-10','direction'=>'income','amount'=>'9999.00','description'=>'Fremder Umsatz'],$key());
$snapshot=static function()use($db):array{$rows=[];foreach($db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $table)$rows[$table]=\Kulturhus\Verein\Tests\MySqlFixture::rows($db,$table);return $rows;};
$readOnlyBefore=$snapshot();
$db->exec('SET SESSION TRANSACTION READ ONLY');
$current=$report->build(2026);$previous=$report->build(2025);$empty=$report->build(2022);
check($current['totals']===['income_cents'=>3365,'expense_cents'=>1200,'result_cents'=>2165],'annual totals use exact paid cents, original correction direction and no opening balances');
check($previous['totals']===['income_cents'=>2501,'expense_cents'=>700,'result_cents'=>1801],'original-year figures are unchanged by next-year corrections');
check($current['basis']==='recorded_cash_movements'&&$current['period']===['from'=>'2026-01-01','to'=>'2026-12-31'],'report declares calendar period and recorded-payment basis');
check($empty['totals']===['income_cents'=>0,'expense_cents'=>0,'result_cents'=>0]&&!$empty['rows']&&!$empty['accounts']&&!$empty['issues'],'unpaid old document date does not manufacture past payments');
check(array_column($current['spheres'],'key')===['1','2','3','4','9','unassigned']&&count($empty['spheres'])===6,'all spheres including technical9 and unassigned remain explicit even when empty');
$spheres=array_column($current['spheres'],null,'key');
check($spheres['9']['expense_cents']===400&&$spheres['unassigned']['expense_cents']===600&&$spheres['unassigned']['income_cents']===1100,'technical9 does not absorb unclassified or unallocated payments');
check($spheres['4']['expense_cents']===-700&&$spheres['4']['income_cents']===0,'expense refund reduces expense instead of becoming income');
$incomeCorrection=array_values(array_filter($current['rows'],fn($r)=>$r['entry_id']===$reversedIncome))[0];
$expenseCorrection=array_values(array_filter($current['rows'],fn($r)=>$r['entry_id']===$reversedExpense))[0];
check($incomeCorrection['income_cents']===-500&&$incomeCorrection['expense_cents']===0&&$incomeCorrection['direction']==='income'&&$incomeCorrection['account_code']==='40000','income reversal keeps original sphere and expense-side snapshot account');
check($expenseCorrection['income_cents']===0&&$expenseCorrection['expense_cents']===-700&&$expenseCorrection['direction']==='expense'&&$expenseCorrection['account_code']==='68150','expense reversal keeps original sphere and income-side snapshot account');
$allRows=[...$previous['rows'],...$current['rows']];$byPosition=[];
foreach(array_filter($allRows,fn($r)=>$r['entry_id']===$split) as $row)$byPosition[$row['description']]=($byPosition[$row['description']]??0)+$row['income_cents'];
ksort($byPosition);
check($byPosition===['Erste Teilposition'=>1001,'Zweite Teilposition'=>1999],'partial payments across years reconcile each saved position exactly');
$tinyRows=array_values(array_filter($allRows,fn($r)=>$r['entry_id']===$tiny));
check(count($tinyRows)===2&&array_column($tinyRows,'description')===['Erster Cent','Zweiter Cent']&&array_column($tinyRows,'amount_cents')===[1,1],'one-cent payment slices across years do not double count or lose a position');
$customAccount=array_values(array_filter($current['accounts'],fn($r)=>$r['account_code']==='40401'))[0];
check($customAccount['account_name']==='Synthetisches eigenes Konto'&&$customAccount['catalog_year']===TaxCatalog::YEAR&&$customAccount['income_cents']===1111,'custom inactive account retains original saved name and real catalog year');
$oldRow=array_values(array_filter($current['rows'],fn($r)=>$r['entry_id']===$old))[0];
check((int)$oldRow['catalog_year']===2026&&$f->entry($old)['document_date']==='2022-04-01','payment year and saved reference catalog do not rewrite original invoice date');
$issueMap=array_column($current['issues'],null,'code');
check(isset($issueMap['unallocated_payment'],$issueMap['missing_sphere'],$issueMap['missing_account'],$issueMap['technical_sphere']),'missing assignments and technical sphere produce structured warnings');
check($issueMap['unallocated_payment']['count']===2&&$issueMap['missing_sphere']['count']===3&&$issueMap['technical_sphere']['count']===1,'warning counts follow journal positions including one unallocated remainder per payment');
check(!array_filter($current['issues'],fn($issue)=>str_contains($issue['code'],'booking')),'empty valid BU key is neither invented nor treated as a missing tax decision');
check(!in_array($open,array_column($current['rows'],'entry_id'),true),'unpaid invoice and noncash goods do not enter cash annual report');
foreach(['spheres','accounts'] as $kind){foreach(['income_cents','expense_cents','result_cents'] as $field)check(array_sum(array_column($current[$kind],$field))===$current['totals'][$field],$kind.' '.$field.' exactly reconciles');}
foreach([...$current['spheres'],...$current['accounts']] as $group){
    $picked=array_map(fn($i)=>$current['rows'][$i],$group['row_indexes']);
    check(count($picked)===$group['row_count']&&count(array_unique(array_column($picked,'transaction_id')))===$group['transaction_count']&&array_sum(array_column($picked,'result_cents'))===$group['result_cents'],'drilldown references and counts agree with group amounts');
}
foreach($current['issues'] as $issue)check($issue['count']===count($issue['row_indexes'])&&!array_diff($issue['row_indexes'],array_keys($current['rows'])),'warning points only to actual rows');
check($current['reconciliation']['balanced']&&$current['reconciliation']['journal_signed_total_cents']===$current['totals']['result_cents']&&$current['reconciliation']['transaction_totals']===$current['totals'],'reconciliation is explicit for transaction and journal totals');
check((new TaxAnnualReport($db,'reader'))->build(2026)===$current&&(new TaxAnnualReport($db,'admin'))->build(2026)===$current,'finance reader and central admin use identical tenant-limited report');
$foreign=(new TaxAnnualReport($db,'foreign'))->build(2026);
check($foreign['totals']['income_cents']===999900&&count($foreign['rows'])===1&&!array_intersect(array_column($foreign['rows'],'transaction_id'),array_column($current['rows'],'transaction_id')),'foreign actor can only report their own club');
check($snapshot()===$readOnlyBefore,'annual report uses native read-only transactions without changing any table or accounting snapshot');
foreach(['member','editor','missing','inactive','disabled'] as $actor)denied(fn()=>(new TaxAnnualReport($db,$actor))->build(2026),$actor.' cannot obtain annual financial data');
foreach([1999,(int)date('Y')+1,0,-1,PHP_INT_MAX] as $year)denied(fn()=>$report->build($year),'invalid or future annual period rejected');
check($report->build(2000)['totals']['result_cents']===0,'minimum supported year2000 accepted');
$db->beginTransaction();$report->build(2026);check($db->inTransaction(),'caller-owned read transaction remains open after report');
denied(fn()=>$report->build(1999),'invalid year inside caller transaction rejected');check($db->inTransaction(),'caller-owned transaction remains open after validation failure');$db->rollBack();
check(!$db->inTransaction(),'own report transactions always finish');
$db->exec('SET SESSION TRANSACTION READ WRITE');

// Defensive historical-data checks: unusual snapshots are disclosed, not relabelled or guessed.
// Only the isolated test fixture may simulate records predating native CHECK constraints.
\Kulturhus\Verein\Tests\MySqlFixture::enforceChecks($db,false);
$db->prepare("UPDATE accounting_positions SET sphere='5',catalog_year=NULL WHERE entry_id=?")->execute([$old]);
$legacy=$report->build(2026);$legacyIssues=array_column($legacy['issues'],null,'code');
check(isset($legacyIssues['unknown_sphere'],$legacyIssues['missing_catalog_year'])&&$legacy['totals']===$current['totals'],'unknown legacy sphere and missing saved catalog year warn without losing cents');
$legacyRow=array_values(array_filter($legacy['rows'],fn($r)=>$r['entry_id']===$old))[0];
check($legacyRow['sphere']==='5'&&$legacyRow['sphere_key']==='unassigned'&&$legacyRow['catalog_year']===null,'raw legacy sphere and missing year survive without invented correction');
$db->prepare("UPDATE accounting_positions SET sphere='2',catalog_year=2025 WHERE entry_id=?")->execute([$old]);
\Kulturhus\Verein\Tests\MySqlFixture::enforceChecks($db,true);
$olderCatalog=$report->build(2026);
$olderAccount=array_values(array_filter($olderCatalog['accounts'],fn($r)=>$r['account_code']==='40000'&&$r['catalog_year']===2025));
$existing2025=array_values(array_filter($current['accounts'],fn($r)=>$r['account_code']==='40000'&&$r['catalog_year']===2025));
$existing2025Income=array_sum(array_column($existing2025,'income_cents'));
check(count($olderAccount)>=1&&array_sum(array_column($olderAccount,'income_cents'))===$existing2025Income+253,'legacy reference joins its actual saved catalog year without losing existing 2025 payments');
$olderRow=array_values(array_filter($olderCatalog['rows'],fn($r)=>$r['entry_id']===$old))[0];
check($olderRow['catalog_year']===2025&&$olderRow['income_cents']===253&&$olderCatalog['totals']===$current['totals'],'changing fixture catalog grouping preserves legacy row cents and overall reconciliation');
$db->prepare('UPDATE accounting_positions SET catalog_year=2026 WHERE entry_id=?')->execute([$old]);
$db->exec("UPDATE actors SET active=0 WHERE id='finance'");
denied(fn()=>$report->build(2026),'reused report rechecks actor revocation');
check(\Kulturhus\Verein\Tests\MySqlFixture::integrity($db)==='ok'&&!(\Kulturhus\Verein\Tests\MySqlFixture::foreignKeyErrors($db)[0]??false),'synthetic fixture integrity maintained');

echo "$checks tax annual report checks passed\n";
