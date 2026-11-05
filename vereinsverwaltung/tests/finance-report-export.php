<?php declare(strict_types=1);
require __DIR__.'/../src/Finance/Accounting/FinanceReportExport.php';
use Kulturhus\Verein\FinanceReportExport;
$n=0;function ok(bool $value,string $label):void{global $n;if(!$value)throw new RuntimeException($label);$n++;echo "PASS $label\n";}
function denied(callable $fn,string $label):void{try{$fn();}catch(DomainException){ok(true,$label);return;}throw new RuntimeException($label);}
function unzip(string $bytes):array{
    $path=tempnam(sys_get_temp_dir(),'khxlsx-test-');$zip=new ZipArchive();
    try{file_put_contents($path,$bytes);if($zip->open($path)!==true)throw new RuntimeException('Invalid XLSX ZIP');$files=[];for($i=0;$i<$zip->numFiles;$i++)$files[$zip->getNameIndex($i)]=$zip->getFromIndex($i);$zip->close();return $files;}finally{if(is_file($path))unlink($path);}
}
function xml(string $bytes):DOMXPath{$doc=new DOMDocument();if(!$doc->loadXML($bytes,LIBXML_NONET))throw new RuntimeException('Invalid OpenXML');$x=new DOMXPath($doc);$x->registerNamespace('s','http://schemas.openxmlformats.org/spreadsheetml/2006/main');return $x;}
function value(DOMXPath $x,string $ref):string{return $x->evaluate('string(//s:c[@r="'.$ref.'"]/s:v)');}
function textCell(DOMXPath $x,string $ref):string{return $x->evaluate('string(//s:c[@r="'.$ref.'"]/s:is/s:t)');}
$filters=['from'=>'2026-09-01','to'=>'2026-09-30','resolution'=>'month','compare'=>true,'account'=>'synthetic-account','tags'=>['Synthetische Auswahl']];
$income=['type'=>'income','filters'=>$filters,'rows'=>[
    ['label'=>'September 2026','from'=>'2026-09-01','to'=>'2026-09-30','income_cents'=>4800,'expense_cents'=>750,'result_cents'=>4050,'previous'=>['from'=>'2025-09-01','to'=>'2025-09-30','income_cents'=>3600,'expense_cents'=>800,'result_cents'=>2800]],
],'totals'=>['income_cents'=>4800,'expense_cents'=>750,'result_cents'=>4050],'previous_totals'=>['income_cents'=>3600,'expense_cents'=>800,'result_cents'=>2800],'previous_from'=>'2025-09-01','previous_to'=>'2025-09-30'];
$bytes=FinanceReportExport::xlsx($income);$files=unzip($bytes);$x=xml($files['xl/worksheets/sheet1.xml']);
ok(str_starts_with($bytes,'PK')&&count($files)===8,'export is a genuine eight-part OpenXML ZIP package');
foreach($files as $path=>$data){$doc=new DOMDocument();ok($doc->loadXML($data,LIBXML_NONET),'well-formed package part '.$path);}
ok(str_contains($files['[Content_Types].xml'],'spreadsheetml.sheet.main+xml')&&str_contains($files['xl/_rels/workbook.xml.rels'],'worksheets/sheet1.xml'),'content types and workbook relationships identify a real XLSX workbook');
ok(value($x,'D6')==='48.00'&&value($x,'E6')==='7.50'&&value($x,'F6')==='40.50','integer cents become numeric euro values without rounding or locale text');
ok($x->evaluate('string(//s:c[@r="D6"]/@t)')===''&&$x->evaluate('string(//s:c[@r="D6"]/@s)')==='2','amount cell is numeric with a currency style');
ok($x->evaluate('string(//s:c[@r="B6"]/@s)')==='3'&&is_numeric(value($x,'B6')),'dates use Excel serial numbers and a date style');
ok(value($x,'I6')==='36.00'&&value($x,'J6')==='8.00'&&value($x,'K6')==='28.00','previous-year columns preserve the comparative filtered amounts');
ok(textCell($x,'A7')==='Summe'&&value($x,'D7')==='48.00'&&value($x,'F7')==='40.50'&&value($x,'K7')==='28.00','summary row exactly matches the selected current and previous data');
ok(str_contains(textCell($x,'A3'),'01.09.2026 bis 30.09.2026')&&str_contains(textCell($x,'A3'),'Kontoauswahl aktiv')&&str_contains(textCell($x,'A3'),'Synthetische Auswahl'),'export records the actual period account restriction and tag selection');
ok($x->evaluate('string(//s:autoFilter/@ref)')==='A5:K6'&&$x->evaluate('string(//s:pane/@topLeftCell)')==='A6'&&$x->query('//s:cols/s:col')->length===11,'headers freeze, filter only detail rows and set column widths');
$without=$income;$without['filters']['compare']=false;$without['previous_totals']=null;$plain=xml(unzip(FinanceReportExport::xlsx($without))['xl/worksheets/sheet1.xml']);
ok($plain->evaluate('string(//s:autoFilter/@ref)')==='A5:F6'&&$plain->query('//s:c[@r="I6"]')->length===0,'comparison disabled removes previous-year columns');
$leap=$income;$leap['filters']['from']=$leap['filters']['to']='2024-02-29';$leap['rows'][0]['from']=$leap['rows'][0]['to']='2024-02-29';$leap['rows'][0]['previous']=['from'=>null,'to'=>null,'income_cents'=>0,'expense_cents'=>0,'result_cents'=>0];$leap['previous_totals']=['income_cents'=>0,'expense_cents'=>0,'result_cents'=>0];
$leapX=xml(unzip(FinanceReportExport::xlsx($leap))['xl/worksheets/sheet1.xml']);
ok(textCell($leapX,'G6')===''&&textCell($leapX,'H6')===''&&value($leapX,'I6')==='0.00','leap-day comparison supports absent prior-year date range and zero amounts');
$base=['payment_date'=>'2026-09-01','transaction_number'=>'SYN-001','receipt_number'=>'0048','amount_cents'=>4800,'signed_cents'=>4800,'debit_account_number'=>'0120','debit_account_name'=>'Synthetisches Konto','credit_account_number'=>'4000','credit_account_name'=>'Mitgliedsbeitrag','sphere'=>'Ideeller Bereich','booking_key'=>'','tax_rate'=>0,'description'=>'=HYPERLINK("https://example.invalid","Text")','counterparty'=>'+SUM(1,2)','info'=>'Text & <Daten>','reversal_of'=>null];
$expense=array_replace($base,['payment_date'=>'2026-09-10','transaction_number'=>'SYN-002','receipt_number'=>'TEST-2','amount_cents'=>750,'signed_cents'=>-750,'tax_rate'=>19,'description'=>'Synthetische Ausgabe']);
$journal=['type'=>'journal','filters'=>array_replace($filters,['compare'=>false]),'rows'=>[$base,$expense],'signed_total_cents'=>4050,'count'=>2];
$journalBytes=FinanceReportExport::xlsx($journal);$journalFiles=unzip($journalBytes);$jx=xml($journalFiles['xl/worksheets/sheet1.xml']);
ok(textCell($jx,'C6')==='0048'&&textCell($jx,'D6')==='0120','receipt and account identifiers preserve leading zeroes AS CHAR');
ok(textCell($jx,'K6')===$base['description']&&textCell($jx,'L6')===$base['counterparty']&&textCell($jx,'M6')==='Text & <Daten>','untrusted strings survive XML escaping without adding apostrophes or formulas');
ok($jx->query('//s:f')->length===0&&$jx->evaluate('string(//s:c[@r="K6"]/@t)')==='inlineStr','formula-looking content remains literal inline text');
$unsafe=$journal;$unsafe['rows'][0]['info']="Text\x00\xEF\xBF\xBE & gültig";$ux=xml(unzip(FinanceReportExport::xlsx($unsafe))['xl/worksheets/sheet1.xml']);
ok(textCell($ux,'M6')==='Text & gültig','XML-invalid control and noncharacters cannot corrupt the workbook');
ok(value($jx,'N6')==='48.00'&&value($jx,'O7')==='-7.50'&&value($jx,'O8')==='40.50','journal retains positive amounts signed directions and filtered net total');
ok(value($jx,'J7')==='0.19'&&$jx->evaluate('string(//s:c[@r="J7"]/@s)')==='6','tax rate is a numeric Excel percentage');
ok($jx->evaluate('string(//s:autoFilter/@ref)')==='A5:P7'&&!str_contains($journalFiles['xl/worksheets/sheet1.xml'],'SYN-003'),'journal exports only selected records and excludes its summary from filters');
$bad=$income;$bad['totals']['income_cents']=5000;$bad['totals']['result_cents']=4250;denied(fn()=>FinanceReportExport::xlsx($bad),'mismatched filtered income totals are rejected');
$bad=$income;$bad['rows'][0]['income_cents']=4800.5;denied(fn()=>FinanceReportExport::xlsx($bad),'fractional cents cannot silently round into exported values');
$bad=$income;$bad['rows'][0]['from']='2026-02-30';denied(fn()=>FinanceReportExport::xlsx($bad),'invalid dates cannot silently normalize in Excel');
$bad=$journal;$bad['signed_total_cents']=4800;denied(fn()=>FinanceReportExport::xlsx($bad),'mismatched journal total is rejected');
$bad=$journal;$bad['count']=3;denied(fn()=>FinanceReportExport::xlsx($bad),'journal count must match the actual exported selection');
$empty=$journal;$empty['rows']=[];$empty['count']=0;$empty['signed_total_cents']=0;$ex=xml(unzip(FinanceReportExport::xlsx($empty))['xl/worksheets/sheet1.xml']);
ok($ex->evaluate('string(//s:autoFilter/@ref)')==='A5:P5'&&value($ex,'O6')==='0.00','empty selections produce a valid workbook with a zero summary');
require_once __DIR__.'/../src/bootstrap.php';
require_once __DIR__.'/../src/Finance/Accounting/FinanceReports.php';
$db=\Kulturhus\Verein\Tests\MySqlFixture::connect();\Kulturhus\Verein\Database::migrate($db);
$db->exec("INSERT INTO clubs VALUES('export-test','Synthetic Export');INSERT INTO actors(id,club_id,role) VALUES('export-finance','export-test','treasurer')");
$finance=new \Kulturhus\Verein\Finance($db,'export-finance');$account=$finance->createAccount('Synthetic selected','offline','0','2024-01-01');$outside=$finance->createAccount('Synthetic excluded','offline','0','2024-01-01');
foreach([[$account,'2025-09-01','income','48','Included income'],[$account,'2025-09-02','expense','7.50','Included expense'],[$account,'2024-09-01','income','36','Prior year'],[$account,'2025-10-01','income','20','Excluded date'],[$outside,'2025-09-01','income','999','Excluded account']] as [$acc,$date,$direction,$amount,$label])$finance->createTransaction(['account'=>$acc,'booked_on'=>$date,'direction'=>$direction,'amount'=>$amount,'description'=>$label],bin2hex(random_bytes(16)));
$service=new \Kulturhus\Verein\FinanceReports($db,'export-finance');$realFilters=['from'=>'2025-09-01','to'=>'2025-09-30','resolution'=>'month','compare'=>true,'account'=>$account,'tags'=>[]];
$selectedIncome=$service->income($realFilters);$ix=xml(unzip(FinanceReportExport::xlsx($selectedIncome))['xl/worksheets/sheet1.xml']);
ok(value($ix,'D6')==='48.00'&&value($ix,'E6')==='7.50'&&value($ix,'F7')==='40.50'&&value($ix,'I7')==='36.00','actual FinanceReports account and period filters retain exact current and prior-year Excel totals');
$selectedJournal=$service->journal($realFilters);$selectedXml=unzip(FinanceReportExport::xlsx($selectedJournal))['xl/worksheets/sheet1.xml'];$sx=xml($selectedXml);
ok($selectedJournal['count']===2&&value($sx,'O8')==='40.50'&&!str_contains($selectedXml,'Excluded')&&!str_contains($selectedXml,'Prior year'),'actual filtered journal export excludes other accounts periods and comparison-only movements');
if(getenv('VEREIN_TEST_XLSX_OUTPUT'))file_put_contents(getenv('VEREIN_TEST_XLSX_OUTPUT'),$journalBytes);
echo "$n finance-report-export checks passed; synthetic report data only\n";
