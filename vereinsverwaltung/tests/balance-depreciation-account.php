<?php declare(strict_types=1);
require __DIR__.'/../src/bootstrap.php';
use Kulturhus\Verein\BalanceSources;
$n=0;$check=static function(bool $v)use(&$n):void{if(!$v)throw new RuntimeException('Depreciation account check '.($n+1));$n++;};
foreach(['01350'=>'62000','06300'=>'62200','06500'=>'62200'] as $asset=>$expense){
    $plan=['account'=>(string)$asset,'method'=>'linear_monthly'];$p=BalanceSources::depreciationAccount($plan,null);
    $check($p['code']===$expense&&$p['id']==='src_depreciation_'.$expense&&$p['marker']===$expense);
    $check(BalanceSources::depreciationAccount($plan,['depreciation_skr_code'=>$expense])===$p);
    $check(BalanceSources::depreciationAccount($plan,['annual'=>[]])===['id'=>'src_depreciation','name'=>'Abschreibungen','code'=>'']);
    $check(BalanceSources::depreciationAccount($plan,[])===['id'=>'src_depreciation','name'=>'Abschreibungen','code'=>'']);
}
$check(BalanceSources::depreciationAccount(['account'=>'06300','method'=>'degressive'],null)['code']==='62200');
$check(BalanceSources::depreciationAccount(['account'=>'06700','method'=>'gwg_immediate'],null)===BalanceSources::depreciationAccount(['account'=>'06700','method'=>'gwg_immediate'],['annual'=>[]]));
$check(BalanceSources::depreciationAccount(['account'=>'unknown'],null)['code']==='');
echo "$n depreciation source account checks passed; old bindings unchanged\n";
