<?php declare(strict_types=1);
require __DIR__.'/balance-period-sphere-areas.php';
require_once __DIR__.'/../src/Finance/Balance/BalanceTaxFiling.php';
require __DIR__.'/fixtures/balance-offline-runtime.php';
use Kulturhus\Verein\{BalancePeriodTaxReconciliations as SphereReviews,BalanceTaxFiling as SphereFiling,TaxFilings as SphereTax,SettingsSchema as SphereSettings};
$baseAreas=$n;$n=0;$taxReview=new SphereReviews($db,'sphere-admin');$filings=new SphereFiling($db,'sphere-admin');$taxService=new SphereTax($db,'sphere-admin');
putenv('VEREIN_MODE=synthetic');putenv('VEREIN_TAX_AUTO_TEST=synthetic-only');
$profile=['business_name'=>'','supplier'=>'Synthetischer Softwaretest','scope_confirmed'=>true,'asset_values_unchanged'=>false,'asset_tax_values_reviewed'=>true,'tax_privileged'=>true,
    'whole_business_name'=>'Synthetische Vereinsarbeit','whole_book_standard'=>'HGBM','company'=>['tax_number'=>'5192050001276','name'=>'Synthetischer Verein','street'=>'Testweg','house_number'=>'1','postal_code'=>'11111','city'=>'Teststadt','country'=>'Deutschland']];
$mapping=['cash'=>'bs.ass.currAss.cashEquiv.bank','expense'=>'is.netIncome.regular.operatingTC.otherCost.administration','equity'=>'bs.eqLiab.equity.retainedEarnings','revenue'=>'is.netIncome.regular.operatingTC.grossTradingProfit.totalOutput.netSales.grossSales.taxExemptUStG19_1','receivables'=>'bs.ass.currAss.receiv.trade.other'];
$journal=$l->history($pid);$ids=[];
foreach([$pid,$p2] as $index=>$periodId){
    foreach($l->report($periodId)['accounts'] as $key=>$account){
        // Explicit choice for this fixture's original unpaid supplier invoice.
        $choice=match($account['group']){'cash'=>'bs.ass.currAss.cashEquiv.bank','equity'=>'bs.eqLiab.equity.retainedEarnings','liabilities'=>'bs.eqLiab.liab.trade.genOther','member_payables'=>'bs.eqLiab.liab.other.other',default=>null};
        if($choice!==null)$mapping[$key]=$choice;
    }
    $review=$taxReview->draft($periodId);$taxReview->save($periodId,0,$review,'Synthetic dated sphere tax draft');
    denied(fn()=>$filings->createPeriod($periodId),'draft tax proof cannot create filing');
    $taxReview->confirm($periodId,1,$review['income_sha256'],true,'Synthetic original tax evidence reviewed');
    $state=$taxReview->get($periodId);check(isset($state['values']['sphere_reconciliation'])&&$state['current_approval'],'stored sphere proof reloads and verifies');
    $id=$filings->createPeriod($periodId);$ids[]=$id;$bad=$profile;$bad['asset_values_unchanged']=true;$bad['asset_tax_values_reviewed']=false;
    denied(fn()=>$filings->save($id,1,$bad,$mapping,['complete'=>true]),'cannot declare reviewed sphere values unchanged');
    $filings->save($id,1,$profile,$mapping,['complete'=>true]);
    $validated=$taxService->validate($id,2);check($validated['valid'],'real stored sphere XML: '.json_encode($validated));
    $record=$taxService->get($id);$xml=$record['xml_data'];check(str_contains($xml,'Einlagen und Entnahmen sind keine'),'SGEB explains capital separate from result');
    file_put_contents(__DIR__.'/balance-period-workflow-sphere-'.($index?'successor':'first').'.xml',$xml);
    $values=$filings->assertCurrent($record);check($values['whole']['book']===$values['whole']['tax'],'stored export contains original whole assets');
    $filings->approve($id,3,true);check($taxService->ready($id,4,'SEND-TEST',true,true)['id']===$id,'board approval and exact runtime binding checked');
    // Do not call send: native acceptance uses these exact synthetic XML bytes separately.
    check($taxService->attempts($id)===[],'no transport attempt during source acceptance');
}
check($l->history($pid)===$journal,'tax computation and XML leave original journal unchanged');
$renderSphereTax=static function(string $id)use($db):string{$_SESSION=['actor'=>'sphere-admin','csrf'=>'synthetic'];$_GET=['tax_year'=>'2026','filing'=>$id];$_POST=[];$_SERVER['REQUEST_METHOD']='GET';ob_start();try{require __DIR__.'/../views/Finance/Tax/tax-filings.php';return ob_get_contents();}finally{ob_end_clean();}};
$html=$renderSphereTax($ids[0]);check(str_contains($html,'Anlagen: Wechsel zwischen Vereinsbereichen')&&str_contains($html,'Steuerwert am Ende'),'final review exposes sphere values');
check(str_contains($html,'Anlagenverzeichnis und getrennte Buch- und Steuerwerte geprüft.'),'specific asset review shown');
$saved=$db->query("SELECT payload FROM club_settings WHERE club_id='sphere' AND section='tax'")->fetchColumn();
$change=$db->prepare("UPDATE club_settings SET payload=? WHERE club_id='sphere' AND section='tax'");$change->execute([json_encode(array_replace(SphereSettings::defaults('tax'),['accounting_basis'=>'euer']))]);
denied(fn()=>$filings->previewValues($taxService->get($ids[0])),'EÜR never promoted to balance');$change->execute([$saved]);
denied(fn()=>(new SphereFiling($db,'other'))->previewValues($taxService->get($ids[0])),'cross-club tax access rejected');
echo "$n stored sphere filing checks passed plus $baseAreas area checks; actual XML, simulated validation, no transport\n";
