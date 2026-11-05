<?php declare(strict_types=1);
require __DIR__.'/balance-sources.php';
require_once __DIR__.'/../src/Finance/Balance/BalanceTaxMapping.php';
use Kulturhus\Verein\{BalanceTaxMapping, BalanceLedger};
$report=$ledger->report(2026);$profit=$report['calculation']['annual_result_cents'];
$originals=[];foreach([['invoice',$vatEntry],['invoice',$income]] as [$type,$id])$originals[$id]=$sources->binding($type,$id)['source_hash'];
check($report['accounts']['src_input_vat']['skr_code']==='14000'&&$report['accounts']['src_output_vat']['skr_code']==='38000','invoice-derived tax accounts carry explicit general SKR42 codes');
$m=BalanceTaxMapping::preview(2026,$report['calculation']['accounts']);
check($m['rows']['src_input_vat']['status']==='review'&&$m['rows']['src_output_vat']['status']==='review','actual invoice sources cannot silently bypass VAT closing');
$ledger->addAccount(2026,$report['version'],'vat-closing','Synthetic remaining tax liability','liabilities','38990');
$r=$ledger->report(2026);$version=$r['version'];
$lines=[['account'=>'src_output_vat','debit_cents'=>3800,'credit_cents'=>0,'sphere'=>''],
    ['account'=>'src_input_vat','debit_cents'=>0,'credit_cents'=>1900,'sphere'=>''],
    ['account'=>'vat-closing','debit_cents'=>0,'credit_cents'=>1900,'sphere'=>'']];
denied(fn()=>(new BalanceLedger($db,'member'))->post(2026,$version,keyId(),'2026-12-31','Synthetic tax closing','Synthetic closing evidence',$lines),'member cannot post tax closing');
denied(fn()=>(new BalanceLedger($db,'other'))->post(2026,$version,keyId(),'2026-12-31','Synthetic tax closing','Synthetic closing evidence',$lines),'other club cannot post tax closing');
$id=$ledger->post(2026,$version,keyId(),'2026-12-31','Synthetic tax closing','Synthetic closing evidence',$lines);
denied(fn()=>$ledger->post(2026,$version,keyId(),'2026-12-31','Stale tax closing','Synthetic closing evidence',$lines),'stale version cannot duplicate closing');
$r=$ledger->report(2026);$m=BalanceTaxMapping::preview(2026,$r['calculation']['accounts']);
check(isset($m['mapping']['src_input_vat'],$m['mapping']['src_output_vat'],$m['mapping']['vat-closing']),'actual cleared source accounts and final liability map automatically');
check($r['calculation']['accounts']['vat-closing']['amount_cents']===1900&&$r['calculation']['annual_result_cents']===$profit,'closing preserves profit and exactly the VAT liability');
check(!$sources->reviewYear(2026)['issues'],'source invoice and payment reconciliation survives tax closing');
foreach($originals as $id=>$hash)check($sources->binding('invoice',$id)['source_hash']===$hash,'tax closing never rewrites original invoice binding');
$rateEntry=$make('119','42000','income','2026-02-01');
$f->updatePositions('entry',$rateEntry,(int)$f->entry($rateEntry)['version'],[['description'=>'Synthetic ordinary sale','amount'=>'119','tax_rate'=>'19','sphere'=>'4','account'=>'44000','booking_key'=>'']],'Synthetic automatic revenue account');
check(!$sources->preview(2026,'invoice',$rateEntry,$vatOptions)['issues'],'19 percent revenue account agrees with 19 percent position');
check($f->positions('entry',$rateEntry)[0]['booking_key']==='','automatic revenue account never receives a fabricated additional BU key');
foreach([['44000',7],['44000',0],['43000',19],['41840',19]] as [$code,$rate]){
    // Corrupt only our isolated, unimported test row to exercise the backend guard.
    $db->prepare('UPDATE accounting_positions SET account_code=?,tax_rate=? WHERE club_id=? AND entry_id=?')->execute([$code,$rate,'a',$rateEntry]);
    $p=$sources->preview(2026,'invoice',$rateEntry,$vatOptions);
    check(str_contains(implode(' ',$p['issues']),'Umsatzsteuersatz'),'mismatched account/rate rejected before import');
    check($sources->binding('invoice',$rateEntry)===null,'invalid source does not create a binding');
}
$db->prepare('UPDATE accounting_positions SET account_code=?,tax_rate=?,booking_key=? WHERE club_id=? AND entry_id=?')->execute(['44000',19,'3','a',$rateEntry]);
check(str_contains(implode(' ',$sources->preview(2026,'invoice',$rateEntry,$vatOptions)['issues']),'Automatikkonto'),'automatic account with extra BU key rejected at source boundary');
$db->prepare('UPDATE accounting_positions SET booking_key=? WHERE club_id=? AND entry_id=?')->execute(['','a',$rateEntry]);
$import('invoice',$rateEntry,$vatOptions,2026);
$r=$ledger->report(2026);check($r['calculation']['accounts']['src_code_44000']['amount_cents']===10000&&$r['calculation']['accounts']['src_output_vat']['amount_cents']===1900,'automatic account imports net revenue and exact output tax without duplicate key');
echo "$n source and VAT closing checks passed; isolated synthetic MySQL only\n";
