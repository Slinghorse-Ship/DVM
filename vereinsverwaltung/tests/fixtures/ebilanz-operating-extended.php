<?php declare(strict_types=1);
require_once __DIR__.'/../../src/bootstrap.php';
require_once __DIR__.'/../../src/Finance/Balance/BalanceTaxValues.php';
require_once __DIR__.'/../../src/Finance/Balance/BalanceWholeClubReport.php';
require_once __DIR__.'/../../src/Finance/Balance/BalanceClubTaxReport.php';
use Kulturhus\Verein\{BalanceCalculation as C,BalanceTaxValues as T,BalanceWholeClubReport as W};

/** Independent synthetic original journal. Never opens a database. */
function operatingExtendedFixture(int $year,int $index,string $mode='normal',bool $whole=false,string $review='operating-r14'):array
{
    if(!in_array($review,['operating-r14','operating-r15','operating-r16','working-capital-r18','financial-debt-r19','balance-positions-r20','financial-income-r21','operating-r22','vat-r23','operating-r24','capital-r25','working-capital-r29','financial-r30','operating-r32','balance-positions-r34','bank-costs-r36'],true))throw new InvalidArgumentException('Reviewed synthetic fixture required.');
    $spec=json_decode(file_get_contents(__DIR__.'/../../resources/accounting/reviews/'.$review.'.json'),true,64,JSON_THROW_ON_ERROR);
    $edition=array_values(array_filter($spec['years'],static fn($s)=>$s['year']===$year))[0]??null;
    $modes=$review==='vat-r23'?['normal','negative','cancelled','settled','settled-negative']:['normal','negative','cancelled'];
    if(!$edition||($index!==-1&&!isset($edition['groups'][$index]))||!in_array($mode,$modes,true))throw new InvalidArgumentException('Synthetic group required.');
    $groups=$index===-1?$edition['groups']:[$edition['groups'][$index]];$sources=[];
    foreach($groups as $group)foreach($group['sources'] as $source)$sources[]=$source+['group'=>$group['group'],'condition'=>$group['condition']??null,'contra'=>$group['contra']??false];
    $chart=['bank'=>['name'=>'Synthetic bank','group'=>'cash','skr_code'=>'18000'],
        'capital'=>['name'=>'Synthetic capital','group'=>'equity','skr_code'=>'25000']];
    $opening=[['account'=>'bank','debit_cents'=>10000000,'credit_cents'=>0,'sphere'=>''],['account'=>'capital','debit_cents'=>0,'credit_cents'=>10000000,'sphere'=>'']];$entries=[];
    if($review==='capital-r25'){
        // Issued/established capital exists before its genuine deductions.
        foreach(['capital-corp'=>'29000','capital-foundation'=>'26000'] as $key=>$code){
            $chart[$key]=['name'=>'Synthetic existing capital','group'=>'equity','skr_code'=>$code];
            $opening[]=['account'=>$key,'debit_cents'=>0,'credit_cents'=>1000000,'sphere'=>''];
            $opening[0]['debit_cents']+=1000000;
        }
    }
    foreach($sources as $i=>$row){
        $s=['group'=>$row['group']];
        $key='code-'.$row['code'];$chart[$key]=['name'=>'Absichtlicher Schreiibfeehler '.$row['code'],'group'=>$s['group'],'skr_code'=>$row['code']];
        $amount=1234+$i;$debit=$row['contra']||in_array($s['group'],['expense','receivables','inventory','other_assets','prepaid','cash','fixed_assets'],true);if(in_array($mode,['negative','settled-negative'],true)&&($mode==='negative'||$row['condition']==='vat_clearing_zero'))$debit=!$debit;
        $sphere=in_array($s['group'],['revenue','expense'],true)?($whole?(string)(($i%4)+1):'4'):'';
        $lines=[['account'=>$key,'debit_cents'=>$debit?$amount:0,'credit_cents'=>$debit?0:$amount,'sphere'=>$sphere],
            ['account'=>'bank','debit_cents'=>$debit?0:$amount,'credit_cents'=>$debit?$amount:0,'sphere'=>'']];
        $entries[]=['id'=>hash('md5','synthetic-'.$key),'date'=>$year.'-06-30','description'=>'Synthetic source','lines'=>$lines];
        if(in_array($mode,['settled','settled-negative'],true)&&$row['condition']==='vat_clearing_zero'){
            $final=$debit?'vat-receivable':'vat-payable';
            $chart[$final]=['name'=>'Synthetic VAT closing balance','group'=>$debit?'other_assets':'liabilities','skr_code'=>$debit?'14610':'38430'];
            $entries[]=['id'=>hash('md5','synthetic-closing-'.$key),'date'=>$year.'-12-31','description'=>'Synthetic documented VAT closing',
                'lines'=>[['account'=>$key,'debit_cents'=>$debit?0:$amount,'credit_cents'=>$debit?$amount:0,'sphere'=>''],
                          ['account'=>$final,'debit_cents'=>$debit?$amount:0,'credit_cents'=>$debit?0:$amount,'sphere'=>'']]];
        }
        if($mode==='cancelled'){
            foreach($lines as &$line)[$line['debit_cents'],$line['credit_cents']]=[$line['credit_cents'],$line['debit_cents']];unset($line);
            $entries[]=['id'=>hash('md5','synthetic-reversal-'.$key),'date'=>$year.'-12-31','description'=>'Synthetic reversal','lines'=>$lines];
        }
    }
    $chart=C::chart($chart);
    $r=['year'=>$year,'state'=>'closed','closed_sha256'=>hash('sha256',json_encode([$chart,$opening,$entries])),
        'previous_sha256'=>null,'result_account'=>'capital','issues'=>[],'accounts'=>$chart,'opening'=>$opening,'entries'=>$entries,
        'calculation'=>C::build($year,$chart,$opening,$entries),
        'asset_schedule'=>['year'=>$year,'items'=>[],'totals'=>array_fill_keys(\Kulturhus\Verein\BalanceClubTaxReport::FIELDS,0),'issues'=>[]]];
    if($whole)$r=W::fromReport($r);
    $reviewValues=['year'=>$year,'journal_sha256'=>$r['closed_sha256'],'complete'=>true,'opening_adjustments'=>[],'current_adjustments'=>[]];
    if($whole&&$review==='capital-r25'){
        // These fixtures deliberately deposit/withdraw synthetic capital. The
        // real workflow still requires a decision for every original line.
        $reviewValues['capital_movements']=[];
        foreach($entries as $entry)foreach($entry['lines'] as $ordinal=>$line)if(in_array($chart[$line['account']]['group'],['equity','reserves'],true))
            $reviewValues['capital_movements']['journal:'.$entry['id'].':'.$ordinal]=['kind'=>$line['credit_cents']>0?'contribution':'withdrawal','reason'=>'Declared synthetic capital movement'];
    }
    return T::build($r,$reviewValues);
}
if(realpath($_SERVER['SCRIPT_FILENAME']??'')===__FILE__){
    set_error_handler(static function($s,$m,$f,$l){throw new ErrorException($m,0,$s,$f,$l);});
    echo json_encode(operatingExtendedFixture((int)$argv[1],(int)$argv[2],$argv[3]??'normal',($argv[4]??'')==='whole',$argv[5]??'operating-r14'),JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
}
