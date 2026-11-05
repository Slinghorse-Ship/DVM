<?php declare(strict_types=1);
require_once __DIR__.'/../../src/bootstrap.php';
require_once __DIR__.'/../../src/Finance/Balance/BalanceTaxValues.php';
use Kulturhus\Verein\{BalanceCalculation as C,BalanceTaxValues as T};

/** Synthetic journal only: source expectations are independent of the runtime registry. */
function balanceAccountFixture(int $year,int $index,bool $release=false):array
{
    $specs=json_decode(file_get_contents(__DIR__.'/ebilanz-balance-accounts.json'),true,64,JSON_THROW_ON_ERROR);
    if(!in_array($year,[2025,2026],true)||!isset($specs[$index]))throw new InvalidArgumentException('Synthetic account group required.');
    $spec=$specs[$index];$group=$spec['group'];
    $chart=['bank'=>['name'=>'Synthetic bank','group'=>'cash','skr_code'=>'18000'],
        'capital'=>['name'=>'Synthetic retained capital','group'=>'equity','skr_code'=>'25000']];
    $opening=[['account'=>'bank','debit_cents'=>10000000,'credit_cents'=>0,'sphere'=>''],['account'=>'capital','debit_cents'=>0,'credit_cents'=>10000000,'sphere'=>'']];$entries=[];
    foreach($spec['codes'] as $i=>$code){
        $key='code-'.$code;$chart[$key]=['name'=>'Synthetic '.$code,'group'=>$group,'skr_code'=>$code];$amount=1234+$i;
        $asset=in_array($group,['inventory','other_assets','receivables'],true);
        $lines=[['account'=>$key,'debit_cents'=>$asset?$amount:0,'credit_cents'=>$asset?0:$amount,'sphere'=>''],
            ['account'=>'bank','debit_cents'=>$asset?0:$amount,'credit_cents'=>$asset?$amount:0,'sphere'=>'']];
        $entries[]=['id'=>'synthetic-'.$code,'date'=>$year.'-06-30','lines'=>$lines];
        if($release){
            foreach($lines as &$line){$old=$line['debit_cents'];$line['debit_cents']=$line['credit_cents'];$line['credit_cents']=$old;}unset($line);
            $entries[]=['id'=>'release-'.$code,'date'=>$year.'-12-31','lines'=>$lines];
        }
    }
    $r=['year'=>$year,'state'=>'closed','closed_sha256'=>hash('sha256',json_encode([$chart,$opening,$entries])),'accounts'=>$chart,
        'opening'=>$opening,'entries'=>$entries,'calculation'=>C::build($year,$chart,$opening,$entries),'issues'=>[]];
    $review=['year'=>$year,'journal_sha256'=>$r['closed_sha256'],'complete'=>true,'opening_adjustments'=>[],'current_adjustments'=>[]];
    return T::build($r,$review);
}
if(realpath($_SERVER['SCRIPT_FILENAME']??'')===__FILE__){
    set_error_handler(static function($s,$m,$f,$l){throw new ErrorException($m,0,$s,$f,$l);});
    echo json_encode(balanceAccountFixture((int)($argv[1]??2025),(int)($argv[2]??0),($argv[3]??'')==='release'),JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
}
