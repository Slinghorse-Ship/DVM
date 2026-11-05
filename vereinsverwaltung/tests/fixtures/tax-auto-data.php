<?php declare(strict_types=1);
// Entirely synthetic; no names, identifiers or balances from a real association.
use Kulturhus\Verein\{TaxFilingScope,TaxDeclarationGuide,TaxYearNotes};
$answers=array_fill_keys(array_keys(TaxFilingScope::questions()),'no');
$answers=array_replace($answers,['nonprofit'=>'yes','kst_due'=>'yes','notices_checked'=>'yes','accounting'=>'euer','vat'=>'small']);
$guide=[];foreach(TaxDeclarationGuide::questions() as $k=>$q)$guide[$k]=['answer'=>'no','note'=>''];
$guide['purpose_fulfilled']=['answer'=>'yes','note'=>'Synthetischer Kulturworkshop im Testjahr.'];
$notes=[2025=>['record_completeness'=>'complete','receivables'=>'0','liabilities'=>'0','fixed_assets_book_value'=>'0','other_assets'=>'0','reserves_status'=>'none','funds_use_status'=>'entered','funds_use_note'=>'Synthetischer Workshop, Restmittel im Folgejahr verwendet.','corrections_status'=>'none']];
$details=['tax_number'=>'9198011310010','seat'=>'Teststadt','management'=>'Teststadt','statute_date'=>'2020-01-01','statute_filed'=>'yes','dues_date'=>'2020-01-02','dues_filed'=>'yes','purpose'=>'52','fully_exempt'=>'yes','domestic'=>'yes','source_reviewed'=>'yes','rounding'=>'yes'];
$club=['name'=>'Synthetischer Testverein e.V.','registered'=>true,'street'=>'Testweg 1','postcode'=>'80000','city'=>'Teststadt'];
$tax=['accounting_basis'=>'euer','input_tax_eligibility'=>'no','purpose'=>'Kunst und Kultur','membership_deductible'=>'no','number'=>'9198011310010'];
$totals=['income_cents'=>100049,'expense_cents'=>20000,'result_cents'=>80049];$spheres=[];
foreach(['1','2','3','4','9'] as $key)$spheres[]=['key'=>$key,'income_cents'=>$key==='1'?100049:0,'expense_cents'=>$key==='1'?20000:0,'result_cents'=>$key==='1'?80049:0];
$snapshot=['from'=>2025,'to'=>2025,'accounting_basis'=>'euer','club_data'=>$club,'tax'=>['data'=>$tax],'reports'=>[2025=>['annual'=>['totals'=>$totals,'spheres'=>$spheres,'issues'=>[]]]],'year_notes'=>TaxYearNotes::normalize($notes,2025,2025),'declaration'=>$guide];
return ['answers'=>$answers,'guide'=>$guide,'notes'=>$notes,'details'=>$details,'club'=>$club,'tax'=>$tax,'snapshot'=>$snapshot,
 'input'=>['from_year'=>'2025','to_year'=>'2025','activity_report'=>'Synthetischer Testbericht.','tax_documents'=>[],'tax_year_notes'=>$notes,'tax_guide'=>$guide,'auto_answers'=>$details]];
