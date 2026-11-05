<?php declare(strict_types=1);
require_once __DIR__.'/../../src/bootstrap.php';
require_once __DIR__.'/../../src/Finance/Balance/BalanceTaxValues.php';
require_once __DIR__.'/../../src/Finance/Balance/BalanceWholeClubReport.php';
require_once __DIR__.'/../../src/Finance/Balance/BalanceClubTaxReport.php';
require_once __DIR__.'/../../src/Finance/Balance/BalancePeriodFilingValues.php';
use Kulturhus\Verein\{BalanceCalculation as C,BalanceTaxValues as T,BalanceWholeClubReport as W,BalancePeriodFilingValues as P,BalancePeriodAssetSchedule};

function relatedAllowanceFixture(int $year,int $index,string $mode,bool $whole,bool $dated=false,string $bad=''):array
{
    $spec=json_decode(file_get_contents(__DIR__.'/../../resources/accounting/reviews/related-allowances-r39.json'),true,64,JSON_THROW_ON_ERROR);
    $edition=array_values(array_filter($spec['years'],static fn($s)=>$s['year']===$year))[0];
    if(!in_array($mode,['normal','opening','cancelled','inactive'],true)||($index!==-1&&!isset($edition['groups'][$index])))throw new InvalidArgumentException('Exact synthetic source required.');
    $groups=$index===-1?$edition['groups']:[$edition['groups'][$index]];
    $chart=['bank'=>['name'=>'Synthetic bank','group'=>'cash','skr_code'=>'18000'],'capital'=>['name'=>'Synthetic capital','group'=>'equity','skr_code'=>'25000'],
        'loss'=>['name'=>'Synthetic valuation','group'=>'expense','skr_code'=>'62800']];
    $opening=[['account'=>'bank','debit_cents'=>10000000,'credit_cents'=>0,'sphere'=>''],['account'=>'capital','debit_cents'=>0,'credit_cents'=>10000000,'sphere'=>'']];$entries=[];
    foreach($groups as $gi=>$group){
        $lines=[];$sphere=$whole?(string)(($gi%4)+1):'4';$sum=0;
        foreach($group['sources'] as $i=>$source){
            $code=$source['code'];$key='code-'.$code;$chart[$key]=['name'=>'Synthetic Schreiibfeehler '.$code,'group'=>$group['group'],'skr_code'=>$code];
            if($group['contra_receivable_maturity']!==null){
                $gross='gross-'.$code;$grossCode=match($code){'12760'=>'12710','12770'=>'12720','12960'=>'12910','12970'=>'12920',default=>throw new DomainException('Exact debtor family missing.')};
                if($bad==='wrong-maturity')$grossCode=match($grossCode){'12710'=>'12720','12720'=>'12710','12910'=>'12920','12920'=>'12910'};
                if($bad==='wrong-family')$grossCode=match($grossCode){'12710'=>'12910','12720'=>'12920','12910'=>'12710','12920'=>'12720'};
                $chart[$gross]=['name'=>'Original synthetic debtor pool','group'=>'receivables','skr_code'=>$grossCode];
                $opening[]=['account'=>$gross,'debit_cents'=>10000,'credit_cents'=>0,'sphere'=>''];$opening[1]['credit_cents']+=10000;
                $amount=$bad==='too-high'?10001:1234;
                if($mode==='opening'){
                    $opening[]=['account'=>$key,'debit_cents'=>0,'credit_cents'=>2000,'sphere'=>''];$opening[1]['credit_cents']-=2000;
                    $lines=[['account'=>$key,'debit_cents'=>766,'credit_cents'=>0,'sphere'=>''],['account'=>'loss','debit_cents'=>0,'credit_cents'=>766,'sphere'=>$sphere]];
                }else{
                    $positive=$bad==='positive';$lines=[['account'=>$key,'debit_cents'=>$positive?$amount:0,'credit_cents'=>$positive?0:$amount,'sphere'=>''],
                        ['account'=>'loss','debit_cents'=>$positive?0:$amount,'credit_cents'=>$positive?$amount:0,'sphere'=>$sphere]];
                }
            }else{
                $last=$i===count($group['sources'])-1;$amount=$last?$sum:1234+$i;if(!$last)$sum+=$amount;
                $debit=(!$last)!==($mode==='opening');
                $lines[]=['account'=>$key,'debit_cents'=>$debit?$amount:0,'credit_cents'=>$debit?0:$amount,'sphere'=>$bad==='cross-sphere'&&$last?'2':$sphere];
            }
        }
        if($bad==='unpaired'&&$group['calculation_only_pair']!==null){array_pop($lines);$lines[]=['account'=>'bank','debit_cents'=>0,'credit_cents'=>$sum,'sphere'=>''];}
        $entry=['id'=>hash('md5','synthetic-r39-'.$index.'-'.$gi),'date'=>$year.'-06-30','description'=>'Original synthetic closing','lines'=>$lines];if($mode!=='inactive')$entries[]=$entry;
        if($mode==='cancelled'){$reverse=$entry;$reverse['id']=hash('md5','r39-reversal-'.$entry['id']);$reverse['date']=$year.'-12-31';foreach($reverse['lines'] as &$l)[$l['debit_cents'],$l['credit_cents']]=[$l['credit_cents'],$l['debit_cents']];unset($l);$entries[]=$reverse;}
    }
    $chart=C::chart($chart);$period=['start'=>$year.'-01-01','end'=>$year.'-12-31'];
    $calculation=C::build($year,$chart,$opening,$entries,$dated?$period:null);
    if($calculation['issues'])throw new DomainException(implode(' ',$calculation['issues']));
    $r=['year'=>$year,'state'=>'closed','closed_sha256'=>hash('sha256',json_encode([$chart,$opening,$entries,$dated])),
        'previous_sha256'=>null,'result_account'=>'capital','issues'=>[],'accounts'=>$chart,'opening'=>$opening,'entries'=>$entries,'calculation'=>$calculation,
        'asset_schedule'=>['year'=>$year,'items'=>[],'totals'=>array_fill_keys(\Kulturhus\Verein\BalanceClubTaxReport::FIELDS,0),'issues'=>[]]];
    if($dated){$r['period']=$period;$r['asset_schedule']=['period'=>$period,'items'=>[],'issues'=>[]];}
    $review=['year'=>$year,'journal_sha256'=>$r['closed_sha256'],'complete'=>true,'opening_adjustments'=>[],'current_adjustments'=>[]];
    if($bad==='tax-unpaired'){
        $key='code-'.$groups[0]['sources'][0]['code'];$review['current_adjustments']=[['id'=>'unpaired','reason'=>'Synthetic rejection',
            'lines'=>[['account'=>$key,'debit_cents'=>1,'credit_cents'=>0,'sphere'=>'4'],['account'=>'bank','debit_cents'=>0,'credit_cents'=>1,'sphere'=>'']]]];
    }
    if($dated){$review['period']=$period;$review['asset_tax_plans']=[];if($whole){$review['capital_movements']=[];return P::whole($r,$review);}return P::build($r,$review);}
    if($whole)$r=W::fromReport($r);
    return T::build($r,$review);
}
if(realpath($_SERVER['SCRIPT_FILENAME']??'')===__FILE__){
    set_error_handler(static function($s,$m,$f,$l){throw new ErrorException($m,0,$s,$f,$l);});
    echo json_encode(relatedAllowanceFixture((int)$argv[1],(int)$argv[2],$argv[3],($argv[4]??'')==='whole',($argv[5]??'')==='dated',$argv[6]??''),JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
}
