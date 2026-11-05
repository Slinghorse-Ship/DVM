<?php declare(strict_types=1);
namespace Kulturhus\Verein {function date(string $format,?int $time=null):string{return \gmdate($format,$time??\strtotime('2027-01-15 UTC'));}}
namespace {
// This base uses a local transport fixture and synthetic-only MySQL credentials.
require __DIR__.'/balance-tax-filing.php';
use Kulturhus\Verein\{BalanceLedger as L,BalanceTaxFiling as F,TaxFilings as T};
use Kulturhus\Verein\Tests\MySqlFixture;
$baseChecks=$checks;
$workingCapitalReview=$workingCapitalReview??'working-capital-r18';
if(!in_array($workingCapitalReview,['working-capital-r18','financial-debt-r19'],true))throw new RuntimeException('Explicit synthetic debt review required.');
$spec=json_decode(file_get_contents(__DIR__.'/../resources/accounting/reviews/'.$workingCapitalReview.'.json'),true,64,JSON_THROW_ON_ERROR);
$debtReview=$workingCapitalReview==='financial-debt-r19';
function workingCapitalFact(string $xml,string $target,bool $whole):string{
    $dom=new DOMDocument();if(!$dom->loadXML($xml,LIBXML_NONET))throw new RuntimeException('Invalid synthetic XML');
    $xp=new DOMXPath($dom);$xp->registerNamespace('g','http://www.xbrl.de/taxonomies/de-gaap-ci-2025-04-01');
    if($whole){$nodes=$xp->query('//g:'.$target);}
    else{
        $xp->registerNamespace('i','http://www.xbrl.org/2003/instance');$xp->registerNamespace('d','http://xbrl.org/2006/xbrldi');
        $contexts=$xp->query('//i:context[.//d:explicitMember[text()="de-gaap-ci:'.$target.'"]]');
        $nodes=null;
        foreach($contexts as $context){$found=$xp->query('//g:DeterminationOfTaxableIncomeSpec.forProfitOrganisationsAndUtilities.balanceSheet.valueOfItem[@contextRef="'.$context->getAttribute('id').'"]');if($found->length){$nodes=$found;break;}}
    }
    if(!$nodes||$nodes->length!==1)throw new RuntimeException('Expected one exact synthetic fact: '.$target);
    return $nodes->item(0)->textContent;
}
foreach($spec['years'] as $edition)foreach([false,true] as $whole){
    $year=$edition['year'];$club='working18-'.$year.'-'.(int)$whole;$finance=$club.'-finance';$board=$club.'-board';$member=$club.'-member';
    $db->prepare('INSERT INTO clubs VALUES(?,?)')->execute([$club,'Synthetic working capital']);
    foreach([[$finance,'treasurer',0],[$board,'treasurer',1],[$member,'member',0]] as [$actor,$role,$super])$db->prepare('INSERT INTO actors(id,club_id,role,is_superuser) VALUES(?,?,?,?)')->execute([$actor,$club,$role,$super]);
    $db->prepare("INSERT INTO club_settings(club_id,section,payload,version,updated_at) VALUES(?,'tax',?,1,?)")->execute([$club,json_encode($settings),gmdate('c')]);
    $chart=L::defaultChart();$chart['cash']['skr_code']='18000';$chart['equity']['skr_code']='25000';$lines=[];$net=0;$selected=[];$extras=[];
    foreach($edition['groups'] as $g)foreach($g['sources'] as $source){
        $code=$source['code'];$key='code-'.$code;$chart[$key]=['name'=>'Synthetic '.$code,'group'=>$g['group'],'skr_code'=>$code];
        $debit=$g['group']==='liabilities'?-1234:1234;$net+=$debit;
        $lines[]=['account'=>$key,'debit_cents'=>max(0,$debit),'credit_cents'=>max(0,-$debit),'sphere'=>''];
        if($g['conditional'])$selected[$key]=$g['choices'][0];
        foreach($g['disclosures'] as $target)$extras[$target]=($extras[$target]??0)+1234;
    }
    $lines[]=['account'=>'cash','debit_cents'=>max(0,-$net),'credit_cents'=>max(0,$net),'sphere'=>''];
    $ledger=new L($db,$finance);$ledger->create($year);$ledger->setup($year,1,$chart,$pair('cash','equity',10000000),'equity','Synthetic opening',true);
    $ledger->post($year,2,bin2hex(random_bytes(16)),$year.'-06-30','Synthetic working capital','Synthetic original evidence',$lines);
    (new L($db,$board))->close($year,3,['complete'=>true,'valuation'=>true,'cutoff'=>true,'tax'=>true],'Synthetic full review');
    $before=MySqlFixture::rows($db,'balance_entries');$closed=MySqlFixture::rows($db,'balance_years');
    $service=new F($db,$finance);$tax=new T($db,$finance);$id=$service->create($year,$whole?'complete_club_ledger':'separate_business_ledger');
    $p=$profile;$p['business_name']='Synthetic working capital';$p['tax_privileged']=!$whole;
    $review=['complete'=>true,'opening_adjustments'=>[],'current_adjustments'=>[]];
    $service->save($id,1,$p,$selected,$review);check($tax->validate($id,2)['valid'],'mixed balance-only accounts generate together');
    $xml=$tax->get($id)['xml_data'];
    foreach($extras as $target=>$cents)check(workingCapitalFact($xml,$target,$whole)===number_format($cents/100,2,'.',''),'exact summed disclosure '.$target);
    check($tax->get($id)['form_data']['balance_preparation']['mapping']===$selected,'only factual exceptions persisted');
    denied(fn()=>(new F($db,$member))->approve($id,3,true),'member cannot approve');
    denied(fn()=>$service->approve($id,3,true),'treasurer cannot self-approve');
    (new F($db,$board))->approve($id,3,true);
    check(isset($tax->get($id)['form_data']['balance_preparation']['approval']),'board approval binds exact XML');
    $review['current_adjustments']=[['id'=>'correct-working-capital','reason'=>'Synthetic reviewed tax correction','lines'=>[
        ['account'=>$debtReview?'code-34210':'code-12210','debit_cents'=>$debtReview?0:100,'credit_cents'=>$debtReview?100:0,'sphere'=>''],
        ['account'=>'cash','debit_cents'=>$debtReview?100:0,'credit_cents'=>$debtReview?0:100,'sphere'=>'']]]];
    $service->save($id,4,$p,$selected,$review);
    check($tax->get($id)['xml_data']===null&&!isset($tax->get($id)['form_data']['balance_preparation']['approval']),'changed value invalidates XML and approval');
    check($tax->validate($id,5)['valid'],'corrected tax balances regenerate');
    $changed=$debtReview?['bs.eqLiab.liab.assocComp.payables','bs.eqLiab.liab.assocComp.upTo1year','bs.eqLiab.liab.assocComp.upTo1year.trade']:['bs.ass.currAss.receiv.trade.upTo1year'];
    foreach($extras as $target=>$cents)
        check(workingCapitalFact($tax->get($id)['xml_data'],$target,$whole)===number_format(($cents+(in_array($target,$changed,true)?100:0))/100,2,'.',''),'only affected disclosures follow corrected tax balance');
    check($before===MySqlFixture::rows($db,'balance_entries')&&$closed===MySqlFixture::rows($db,'balance_years'),'retained journal remains unchanged');
    check($tax->attempts($id)===[],'new working-capital cases not sent');
}
echo ($checks-$baseChecks)." new working-capital MySQL checks passed; $baseChecks base checks; transport fixture only\n";
}
