<?php declare(strict_types=1);
require __DIR__.'/../src/bootstrap.php';
require_once __DIR__.'/../src/Finance/Tax/TaxAnnualReport.php';
require_once __DIR__.'/../src/Finance/Tax/TaxReviewQueue.php';
use Kulturhus\Verein\{Database,Finance,Settings,TaxReviewQueue};
$checks=0;function check(bool $ok,string $message):void{global $checks;if(!$ok)throw new RuntimeException($message);++$checks;echo "PASS $message\n";}
function denied(callable $f,string $message):void{try{$f();}catch(DomainException){check(true,$message);return;}throw new RuntimeException($message);}
$db=\Kulturhus\Verein\Tests\MySqlFixture::connect(':memory:');Database::migrate($db);
$db->exec("INSERT INTO clubs VALUES('a','Fiktiver Verein'),('b','Fremder Verein');INSERT INTO actors(id,club_id,role,is_superuser) VALUES('admin','a','member',1),('finance','a','treasurer',0),('reader','a','member',0),('member','a','member',0),('foreign','b','treasurer',0)");
$settings=new Settings($db,'admin');$role=$settings->saveRole('',0,['name'=>'Finanzleser','finance_access'=>'read','members_access'=>'none','settings_access'=>false]);$settings->assignRole('reader',$role,0);
$f=new Finance($db,'finance');$key=fn()=>bin2hex(random_bytes(16));$bank=$f->createAccount('Fiktive Bank','offline','0','2022-01-01');
$create=function(string $date,string $title,array $positions=[])use($f,$key){$data=['kind'=>'expense','amount'=>'10.00','document_date'=>$date,'due_date'=>$date,'description'=>$title,'counterparty'=>'Fiktive Firma','category'=>'general'];if($positions)$data['positions']=$positions;return $f->createEntry($data,$key());};
$p=fn(string $sphere='3')=>['description'=>'Material','amount'=>'10.00','sphere'=>$sphere,'account'=>'68150','tax_rate'=>'19','input_tax'=>'none'];
$open=$create('2025-04-01','Unbezahlte Rechnung');$good=$create('2025-04-02','Korrekte unbezahlte Rechnung',[$p()]);$technical=$create('2025-04-03','Sammelposten',[$p('9')]);
$old=$create('2022-04-04','Alter Beleg später bezahlt');$f->recordPayment($old,(int)$f->entry($old)['version'],$bank,'2025-05-01');
$f->recordPayment($open,(int)$f->entry($open)['version'],$bank,'2025-05-02');
$tx=$f->createTransaction(['account'=>$bank,'booked_on'=>'2025-06-01','direction'=>'expense','amount'=>'3.00','description'=>'Nicht zugeordneter Umsatz'],$key());
$snapshot=static function()use($db):array{$rows=[];foreach($db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $table)$rows[$table]=\Kulturhus\Verein\Tests\MySqlFixture::rows($db,$table);return $rows;};
$queue=new TaxReviewQueue($db,'finance');$before=$snapshot();$db->exec('SET SESSION TRANSACTION READ ONLY');$result=$queue->build(2025);$rows=array_column($result['rows'],null,'id');
check(isset($rows['entry:'.$open])&&count($rows['entry:'.$open]['basis'])===2,'paid document deduplicates document and payment year');
check($rows['entry:'.$open]['cents']===1000,'deduplicated document amount not doubled');
check(!isset($rows['entry:'.$good]),'complete unpaid invoice with explicit no deduction and empty BU is not flagged');
check(in_array('technical_sphere',array_column($rows['entry:'.$technical]['reasons'],'code'),true),'unpaid sphere9 remains follow-up work');
check(isset($rows['entry:'.$old])&&count($rows['entry:'.$old]['basis'])===1,'old invoice appears in actual payment year with payment basis');
check(isset($rows['transaction:'.$tx])&&str_contains($rows['transaction:'.$tx]['url'],'tab=bank'),'unallocated transaction links to existing bank correction route');
check(isset(array_column($queue->build(2022)['rows'],null,'id')['entry:'.$old]),'old invoice document year remains separately selectable');
check($snapshot()===$before,'queue uses native read-only transactions and leaves every table unchanged');
check(!(new TaxReviewQueue($db,'reader'))->build(2025)['can_write'],'read-only finance actor can inspect without write claim');
denied(fn()=>(new TaxReviewQueue($db,'member'))->build(2025),'ordinary member denied');
check((new TaxReviewQueue($db,'foreign'))->build(2025)['rows']===[],'foreign club cannot see rows');
denied(fn()=>$queue->build(1999),'invalid year rejected');
$db->exec('SET SESSION TRANSACTION READ WRITE');
// A real correction, not a completion flag, removes the derived issue.
$f->updatePositions('entry',$technical,(int)$f->entry($technical)['version'],[$p()],'Fiktive Korrektur');
check(!isset(array_column($queue->build(2025)['rows'],null,'id')['entry:'.$technical]),'corrected account assignment disappears on next read');
// Corrupt legacy snapshot fixture only: current writes correctly forbid this basis.
// The isolated fixture deliberately simulates data predating the restored native CHECKs.
\Kulturhus\Verein\Tests\MySqlFixture::enforceChecks($db,false);
$db->exec("UPDATE accounting_positions SET catalog_year=2024 WHERE entry_id='$good'");
$changed=array_column($queue->build(2025)['rows'],null,'id');
check(in_array('unknown_catalog',array_column($changed['entry:'.$good]['reasons'],'code'),true),'unknown catalog basis is not silently relabelled');
$db->exec("UPDATE accounting_positions SET catalog_year=2025 WHERE entry_id='$good'");
\Kulturhus\Verein\Tests\MySqlFixture::enforceChecks($db,true);
check(!isset(array_column($queue->build(2025)['rows'],null,'id')['entry:'.$good]),'supported catalog basis does not inherit a stale issue');
function input(array $data,string $key,string $default=''):string{return is_string($data[$key]??null)?$data[$key]:$default;}
function e(mixed $v):string{return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}
function hidden(string $name,mixed $v):string{return '<input type="hidden" name="'.e($name).'" value="'.e($v).'">';}
function url(array $data):string{return '?'.http_build_query($data);}
$_SESSION=['actor'=>'reader'];$_GET=['year'=>'2025','q'=>''];
$db->exec("UPDATE finance_entries SET description='<script>synthetic</script>' WHERE id='$open'");
ob_start();require __DIR__.'/../views/Finance/Tax/tax-review-queue.php';$html=ob_get_clean();
check(str_contains($html,'&lt;script&gt;synthetic&lt;/script&gt;')&&!str_contains($html,'<script>synthetic'),'rendered descriptions are escaped');
check(str_contains($html,'Vorgang ansehen')&&!str_contains($html,'Vorgang öffnen und korrigieren'),'read-only rendering has no write promise');
check(str_contains($html,'name="q"')&&str_contains($html,'name="year"')&&!str_contains($html,'Erledigt markieren'),'one search and year filter without manual completion');
if(getenv('KULTURHUS_TAX_QUEUE_RENDER'))file_put_contents(getenv('KULTURHUS_TAX_QUEUE_RENDER'),$html);
check(\Kulturhus\Verein\Tests\MySqlFixture::integrity($db)==='ok'&&!\Kulturhus\Verein\Tests\MySqlFixture::foreignKeyErrors($db),'integrity and foreign keys intact');
echo "$checks checks passed\n";
