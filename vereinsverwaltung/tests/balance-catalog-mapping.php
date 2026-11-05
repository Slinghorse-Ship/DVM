<?php declare(strict_types=1);
require __DIR__.'/fixtures/ebilanz-balance-accounts.php';
require_once __DIR__.'/../src/Finance/Balance/BalanceTaxMapping.php';
require_once __DIR__.'/../src/Finance/Balance/BalanceTaxFiling.php';
use Kulturhus\Verein\{BalanceTaxMapping as M,BalanceTaxFiling as F,TaxCatalog as K};
$n=0;function check(bool $ok,string $label):void{global $n;if(!$ok)throw new RuntimeException($label);++$n;}
$specs=json_decode(file_get_contents(__DIR__.'/fixtures/ebilanz-balance-accounts.json'),true,64,JSON_THROW_ON_ERROR);
foreach([2025,2026] as $year){
    $catalog=K::sourceData('skr42-'.$year.'.json');$choices=M::chartChoices($year);$covered=[];
    foreach($specs as $i=>$spec){
        if(isset($spec['source_range'])){
            $source=$spec['source_range'];$range=array_values(array_filter($catalog['ranges'],static fn($r)=>$r['code']===$source['start']&&$r['end']===$source['end']&&$r['name']===$source['name']));
            check(count($range)===1,'exact original range '.$year.' '.$i);
            $expected=array_keys(array_filter($choices,static fn($a)=>$a['code']>$source['start']&&$a['code']<=$source['end']));
            check(array_map('strval',$expected)===$spec['codes'],'all extra original range codes accounted for');
        }
        foreach([false,true] as $release){
            $v=balanceAccountFixture($year,$i,$release);$before=serialize($v);$p=M::preview($year,$v['tax']['accounts']);
            $conditional=($spec['requires_review']??false)===true;
            check($before===serialize($v)&&$p['review_count']===($conditional?count($spec['codes']):0),'mapping preserves ledger and requires only qualified choices');
            $wrong=[];
            foreach($spec['codes'] as $code){
                $key='code-'.$code;$covered[$code]=true;
                if($conditional){
                    check(!isset($p['mapping'][$key]),'conditional bank account is not guessed '.$code);
                    check(array_keys(M::choices($year,$v['tax']['accounts'][$key],F::targets()))===[$spec['target']],'only qualified bank child is offered '.$code);
                }else check(($p['mapping'][$key]??'')===$spec['target'],'exact expected target '.$year.' '.$code);
                check($choices[$code]['group']===$spec['group']&&$choices[$code]['review_required']===$conditional,'exact group and review requirement in chart');
                $off=$v['tax']['accounts'][$key];$off['group']='revenue';
                $wrong[$key]=$off;
                check(M::choices($year,$off,F::targets())===[],'wrong group has no allowed choices');
            }
            check(M::preview($year,$wrong)['review_count']===count($wrong),'all wrong-group accounts remain blocked');
        }
    }
    check(count($covered)===232,'all 232 added codes covered per year');
    foreach(['15000','15100','15250','01430','47340','99999'] as $code)check(!isset(M::preview($year,['x'=>['name'=>'Synthetic unresolved','group'=>'other_assets','skr_code'=>$code]])['mapping']['x']),'unsupported or ambiguous code never fabricated');
}
echo "$n balance-catalog checks passed; synthetic data, no database or transport\n";
