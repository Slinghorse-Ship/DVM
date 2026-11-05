<?php declare(strict_types=1);
/** Actual application methods, synthetic recorded rows; no database connection. */
require dirname(__DIR__,2).'/src/bootstrap.php';
require_once dirname(__DIR__,2).'/src/Finance/Accounting/DatevExport.php';
require_once dirname(__DIR__,2).'/src/Finance/Tax/TaxAnnualReport.php';
require_once dirname(__DIR__,2).'/src/Finance/Tax/TaxReviewQueue.php';
use Kulturhus\Verein\{Accounting,Finance,TaxCatalog,DatevExport,OutgoingInvoices,TaxAnnualReport,TaxReviewQueue,FinancePreferences,BalanceSources};
set_error_handler(static function($s,$m,$f,$l){throw new ErrorException($m,0,$s,$f,$l);});
$n=0;
function check(bool $v,string $message):void{global $n;++$n;if(!$v)throw new RuntimeException($message);}
function denied(callable $fn,string $message):void{try{$fn();}catch(DomainException){check(true,$message);return;}throw new RuntimeException($message);}
final class RecordedPositionHost {
 use Accounting;
 public function taxAccounts(int $year=TaxCatalog::YEAR):array{return TaxCatalog::accounts($year);}
 public function inputTaxEligibility():string{return 'no';}
 public function inputTaxDefault():string{return 'none';}
 private static function field(array $v,string $key,int $max,bool $required=false):string{return (new ReflectionMethod(Finance::class,'field'))->invoke(null,$v,$key,$max,$required);}
 private static function date(string $v):void{(new ReflectionMethod(Finance::class,'date'))->invoke(null,$v);}
 private static function cents(string $v):int{return Finance::cents($v);}
 public function normalize(array $v,string $kind,string $date,array $previous=[]):array{return $this->normalizePositions($v,$kind,$date,$previous);}
}
$expected=['1','2','3','4','9'];foreach(range(1,4) as $s)foreach(range(1,9) as $part)$expected[]=(string)$s.(string)$part;
$actual=array_map('strval',array_keys(TaxCatalog::costCenters()));$sorted=$actual;sort($sorted);sort($expected);check($sorted===$expected&&count($actual)===41,'exact original KOST1 domain');
$host=new RecordedPositionHost;$journal=['rows'=>[],'signed_total_cents'=>0];$detail=['rows'=>[],'totals'=>['income_cents'=>0,'expense_cents'=>0,'result_cents'=>0]];
$csvRows=[];$normalizeCases=0;$outgoingCases=0;
foreach($actual as $code){
 $parent=strlen($code)===2?$code[0]:$code;check(TaxCatalog::sphereOf($code)===$parent,'exact parent '.$code);
 foreach([2025,2026] as $year){
  $input=[['description'=>'Synthetic position','amount'=>'12.34','sphere'=>$code,'account'=>'40000','tax_rate'=>'0','booking_key'=>'']];$before=serialize($input);
  $rows=$host->normalize($input,'income',$year.'-01-02');check($rows[0]['sphere']===$code&&$rows[0]['catalog_year']===$year,'stored raw center '.$code);check(serialize($input)===$before,'input unchanged');
  $old=$rows;$old[0]['account_name']='Historical original';$again=$host->normalize($input,'income',$year.'-01-02',$old);check($again[0]['account_name']==='Historical original'&&$again[0]['sphere']===$code,'exact historical snapshot');++$normalizeCases;
 }
 $invoice=['buyer_name'=>'Synthetic buyer','buyer_street'=>'Teststr. 1','buyer_postcode'=>'12345','buyer_city'=>'Teststadt','description'=>'Synthetic invoice','document_date'=>'2025-01-02','delivery_date'=>'2025-01-02','due_date'=>'2025-01-03','category'=>'general','vat_reason'=>'Synthetic exempt example','positions'=>[['description'=>'Synthetic position','quantity'=>'1','unit'=>'C62','unit_gross'=>'12.34','tax_rate'=>'0','sphere'=>$code,'account'=>'40000']]];
 $before=serialize($invoice);$out=OutgoingInvoices::normalize($invoice);check($out['positions'][0]['sphere']===$code&&$out['total_cents']===1234,'outgoing exact center');check(serialize($invoice)===$before,'outgoing input unchanged');++$outgoingCases;
 $pref=FinancePreferences::normalize(['category_spheres'=>['general'=>$code]]);check($pref['category_spheres']['general']===$code,'preferences exact center');
 $reasons=(new ReflectionMethod(TaxReviewQueue::class,'reasons'))->invoke(null,$code,'40000',2025,2025);check(!isset($reasons['unknown_sphere']),'valid subdivisions known');check(isset($reasons['technical_sphere'])===($code==='9'),'9 review retained');
 foreach([[100,'income',false],[-30,'income',true],[-80,'expense',false],[20,'expense',true]] as $i=>[$signed,$direction,$reversed]){
  $id='tx-'.$code.'-'.$i;$income=$direction==='income'?$signed:0;$expense=$direction==='expense'?-$signed:0;
  $detail['rows'][]=['transaction_id'=>$id,'income_cents'=>$income,'expense_cents'=>$expense,'signed_cents'=>$signed];
  $journal['rows'][]=['transaction_id'=>$id,'signed_cents'=>$signed,'sphere'=>$code,'entry_id'=>'synthetic','debit_account_number'=>'63000','debit_account_name'=>'Synthetic expense','credit_account_number'=>'40000','credit_account_name'=>'Synthetic revenue','catalog_year'=>2025];$journal['signed_total_cents']+=$signed;
  $detail['totals']['income_cents']+=$income;$detail['totals']['expense_cents']+=$expense;$detail['totals']['result_cents']+=$signed;
  $csvRows[]=['amount_cents'=>abs($signed),'signed_cents'=>$signed,'reversal_of'=>$reversed?'synthetic-original':null,'bank_code'=>'18000','counter_code'=>$direction==='income'?'40000':'63000','booking_key'=>'','payment_date'=>'2025-01-02','receipt_number'=>'SYN','export_text'=>'Synthetic test','transaction_id'=>$id,'sphere'=>$code];
 }
}
$before=serialize([$journal,$detail]);$report=TaxAnnualReport::fromRecordedPayments(2025,$journal,$detail);check(serialize([$journal,$detail])===$before,'recorded source immutable');check($report['reconciliation']['balanced'],'annual reconciliation');check($report['totals']===$detail['totals'],'whole club total exact');
foreach($report['spheres'] as $sphere){$mult=in_array($sphere['key'],['1','2','3','4'],true)?10:($sphere['key']==='9'?1:0);check($sphere['income_cents']===70*$mult&&$sphere['expense_cents']===60*$mult&&$sphere['result_cents']===10*$mult&&$sphere['row_count']===4*$mult,'parent direction and refund '.$sphere['key']);}
check(array_column($report['rows'],'sphere')===array_column($journal['rows'],'sphere'),'raw subdivision retained in report');check(array_column($report['issues'],'code')===['technical_sphere'],'only 9 requires sphere review');
$preview=['options'=>['advisor'=>'1001','client'=>'1','from'=>'2025-01-01','to'=>'2025-12-31'],'rows'=>$csvRows];$before=serialize($preview);$csv=(new ReflectionMethod(DatevExport::class,'csv'))->invoke(null,$preview);check(serialize($preview)===$before,'CSV source immutable');$lines=explode("\r\n",substr($csv,3));$columns=str_getcsv($lines[1],';','"','');check(count($columns)===125&&str_contains($columns[36],'KOST1'),'official KOST1 column');
foreach($csvRows as $i=>$source){$row=str_getcsv($lines[$i+2],';','"','');check(count($row)===125&&$row[36]===$source['sphere'],'CSV exact KOST1');$incoming=$source['signed_cents']>0;if($source['reversal_of'])$incoming=!$incoming;check($row[1]===($incoming?'S':'H')&&$row[117]===($source['reversal_of']?'1':''),'reversal retains cost center and direction');}
foreach(['','0','5','10','20','30','40','50','51','90','99','111','1a',' 11','11 '] as $bad){check(TaxCatalog::sphereOf($bad)==='','invalid domain '.$bad);if($bad===''||trim($bad)!==$bad)continue;$input=[['description'=>'Invalid','amount'=>'1','sphere'=>$bad,'account'=>'40000','tax_rate'=>'0']];denied(fn()=>$host->normalize($input,'income','2025-01-02'),'invalid recorded center');$invalid=$invoice;$invalid['positions'][0]['sphere']=$bad;denied(fn()=>OutgoingInvoices::normalize($invalid),'invalid outgoing center');}
// No inferred main sphere for legacy unknown values or missing assignment.
foreach(['','10','99'] as $bad){$j=$journal;$d=$detail;$j['rows'][0]['sphere']=$bad;$unknown=TaxAnnualReport::fromRecordedPayments(2025,$j,$d);check($unknown['rows'][0]['sphere']===$bad&&$unknown['rows'][0]['sphere_key']==='unassigned','unknown preserved and unresolved');}
// Exercise actual donation/member-contribution validation without accessing PDO.
$adapter=(new ReflectionClass(BalanceSources::class))->newInstanceWithoutConstructor();$method=new ReflectionMethod(BalanceSources::class,'untaxedRevenue');
foreach(['1','11','19','2','21','3','31','4','41','9','10','99'] as $code){$p=['year'=>2025,'snapshot'=>[],'accounts'=>[]];$source=['entry'=>['cancelled'=>false,'amount_cents'=>1234],'claim'=>null,'assets'=>[],'positions'=>[['gross_cents'=>1234,'tax_rate'=>0,'tax_cents'=>0,'booking_key'=>'','input_tax'=>'unknown','sphere'=>$code,'account_code'=>'40400','account_category'=>'revenue','catalog_year'=>2025]]];$before=serialize($source);$args=[&$p,$source,'40400'];if(in_array($code,['1','11','19'],true))check($method->invokeArgs($adapter,$args)==='src_code_40400','ideell donation accepted '.$code);else denied(fn()=>$method->invokeArgs($adapter,$args),'other sphere cannot become ideell');check(serialize($source)===$before,'donation original unchanged');}
// Same main sphere permits a retained subdivision on an issued asset sale.
$sale=(new ReflectionClass(\Kulturhus\Verein\BalancePeriodSale::class))->newInstanceWithoutConstructor();$position=new ReflectionMethod($sale,'position');
foreach(['1','11','19','2','21','29','3','31','39','4','41','49'] as $code){$source=['positions'=>[['sphere'=>$code,'tax_rate'=>0]],'assets'=>[],'claim'=>null,'issued_invoice'=>['payload'=>['positions'=>[['sphere'=>$code,'quantity'=>1000,'unit'=>'C62']]]]];$before=serialize($source);$position->invoke($sale,['sphere'=>TaxCatalog::sphereOf($code)],$source);check(serialize($source)===$before,'issued sale subdivision remains immutable');$wrong=$source;$wrong['positions'][0]['sphere']='9';denied(fn()=>$position->invoke($sale,['sphere'=>TaxCatalog::sphereOf($code)],$wrong),'sale unknown sphere rejected');$wrong=$source;$wrong['issued_invoice']['payload']['positions'][0]['sphere']='9';denied(fn()=>$position->invoke($sale,['sphere'=>TaxCatalog::sphereOf($code)],$wrong),'issued original unknown sphere rejected');}
echo json_encode(['passed'=>true,'database_connected'=>false,'checks'=>$n,'cost_centers'=>41,'normalized_positions'=>$normalizeCases,'outgoing_positions'=>$outgoingCases,'csv_rows'=>count($csvRows),'annual_rows'=>count($report['rows'])],JSON_THROW_ON_ERROR)."\n";
