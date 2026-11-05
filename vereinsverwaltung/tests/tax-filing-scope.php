<?php declare(strict_types=1);
require_once __DIR__.'/../src/Finance/Tax/TaxFilingScope.php';
use Kulturhus\Verein\TaxFilingScope;
$count=0;$check=static function(bool $ok,string $label)use(&$count):void{if(!$ok)throw new RuntimeException($label);$count++;};
$unknown=TaxFilingScope::build(2025,[]);$check(!$unknown['complete'],'unknown is not exemption');
$check(count($unknown['families'])===14,'all scoped procedure families represented');
$a=array_fill_keys(array_keys(TaxFilingScope::questions()),'no');$a['accounting']='euer';$a['vat']='small';$a['nonprofit']='yes';$a['notices_checked']='yes';
$r=TaxFilingScope::build(2025,$a);$check($r['families']['ust']['state']==='not_needed','small business with explicitly absent special circumstances');
$a['foreign_services']='yes';$r=TaxFilingScope::build(2025,$a);$check($r['families']['ust']['state']==='review','foreign services override simple exemption');
$a['employees']='yes';$a['profit_return']='yes';$r=TaxFilingScope::build(2025,$a);foreach(['lsta','lstb','elstam','euer'] as $key)$check($r['families'][$key]['state']==='needed',$key);
$a['accounting']='balance';$r=TaxFilingScope::build(2025,$a);$check($r['families']['ebilanz']['state']==='needed'&&$r['families']['euer']['state']==='not_needed','closing method exclusive');
$a['mini_jobs']='yes';$check((bool)TaxFilingScope::build(2025,$a)['issues'],'SV is not falsely marked implemented');
foreach([['vat'=>[]],['extra'=>'yes'],['employees'=>'false']] as $bad){try{TaxFilingScope::normalize($bad);throw new RuntimeException('bad input accepted');}catch(DomainException){$count++;}}
echo "$count filing scope checks passed\n";
