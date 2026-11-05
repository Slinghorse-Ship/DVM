<?php declare(strict_types=1);
require __DIR__.'/../src/bootstrap.php';
require_once __DIR__.'/../src/Finance/Balance/BalanceTaxFiling.php';
use Kulturhus\Verein\{BalanceTaxMapping as M,BalanceTaxFiling,TaxCatalog};
$n=0;function check(bool $v,string $message):void{global $n;if(!$v)throw new RuntimeException($message);++$n;}
$mappingReview=$mappingReview??'operating-r15';
if(!in_array($mappingReview,['operating-r15','operating-r16','working-capital-r18','financial-debt-r19'],true))throw new RuntimeException('Explicit source review required.');
$spec=json_decode(file_get_contents(__DIR__.'/../resources/accounting/reviews/'.$mappingReview.'.json'),true,64,JSON_THROW_ON_ERROR);
$targets=BalanceTaxFiling::targets();
$money=static fn(int $cents):string=>number_format($cents/100,2,',','.').' €';
function e(mixed $value):string{return htmlspecialchars((string)$value,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
foreach($spec['years'] as $edition){
    $year=$edition['year'];$catalog=TaxCatalog::accounts($year);
    foreach($edition['groups'] as $group)foreach($group['sources'] as $row){
        $code=$row['code'];check(isset($catalog[$code])&&!$catalog[$code]['blocked'],'only exact bookable source '.$code);
        foreach([1234,-1234,0] as $amount){
            $account=['name'=>'Schreiibfehler <b>nur Test</b>','skr_code'=>$code,'group'=>$group['group'],'amount_cents'=>$amount];$before=serialize($account);
            $preview=M::preview($year,['test'=>$account]);$default=$preview['rows']['test'];$choices=M::choices($year,$account,$targets);
            $actual=array_keys($choices);$resolved=$amount<0?($group['negative_target']??$group['target']):$group['target'];
            $expected=isset($group['negative_target'])?[$resolved]:$group['choices'];sort($actual);sort($expected);
            check($actual===$expected,'only reviewed alternatives '.$code);
            if($group['conditional'])check($preview['mapping']===[]&&$default['choice_required'],'no guessed tax or recipient type '.$code);
            else check($preview['mapping']===['test'=>$resolved]&&$preview['review_count']===0,'automatic precise source '.$code);
            check(serialize($account)===$before,'source amount and name unchanged '.$code);
            $fieldName='mapping[test]';$selected='';
            ob_start();require __DIR__.'/../views/Finance/Balance/balance-tax-account.php';$html=ob_get_clean();
            check(!str_contains($html,'<b>nur Test</b>')&&str_contains($html,'&lt;b&gt;'),'escaped source caption '.$code);
            check(str_contains($html,'<select')===$group['conditional'],'only ambiguous rows ask a question '.$code);
            foreach($choices as $label)check(trim($label)!==''&&!str_starts_with($label,'is.'),'readable choice '.$code);
        }
        $wrong=$account;$wrong['group']=$group['group']==='expense'?'revenue':'expense';
        check(M::preview($year,['test'=>$wrong])['mapping']===[],'wrong account group blocked '.$code);
    }
    foreach(['48330','66650','69700','62050','71030','76450'] as $code){
        $a=['name'=>'Unsupported case','skr_code'=>$code,'group'=>str_starts_with($code,'71')||str_starts_with($code,'4')?'revenue':'expense','amount_cents'=>1234];
        check(M::preview($year,['test'=>$a])['mapping']===[],'reserved or not reviewed case stays blocked '.$code);
    }
}
echo "$n $mappingReview mapping/view checks passed; no database or external actions\n";
