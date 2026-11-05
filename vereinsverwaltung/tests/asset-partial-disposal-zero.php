<?php declare(strict_types=1);
require __DIR__.'/fixtures/asset-partial-review-setup.php';
require __DIR__.'/../src/Finance/Assets/AssetPartialDisposalRecords.php';
use Kulturhus\Verein\{AssetPartialDisposalReview,AssetPartialDisposalRecords,BalanceAssetSchedule,BalanceTaxValues};
use Kulturhus\Verein\Tests\MySqlFixture;
set_error_handler(static function($s,$m,$f,$l){throw new ErrorException($m,0,$s,$f,$l);});
$n=0;function check(bool $ok,string $label):void{global $n;if(!$ok)throw new RuntimeException($label);++$n;echo "PASS $label\n";}
extract(\Kulturhus\Verein\Tests\partialReviewSetup());
$import=function(int $year,string $type,string $id,array $options=[])use($ledger,$sources):void{
    $p=$sources->preview($year,$type,$id,$options);if($p['issues'])throw new RuntimeException(implode(' ',$p['issues']));
    $ledger->importSource($year,$ledger->report($year)['version'],$type,$id,$options,$p['hash'],true);
};
$checks=['complete'=>true,'valuation'=>true,'cutoff'=>true,'tax'=>true];
$import(2025,'invoice',$invoice,['recognised_on'=>'2025-01-15','private_paid_on'=>'']);
foreach([2025,2026] as $year){
    if($year!==2025)$ledger->create($year);
    $books->recordYear('partial',1,$year);$import($year,'depreciation','partial:'.$year);
    $ledger->close($year,$ledger->report($year)['version'],$checks,'Synthetic original depreciation');
}
$ledger->create(2027);$proof['date']='2027-06-15';
$review=(new AssetPartialDisposalReview($db,'writer'))->preview('partial',1,2027,$proof);
$records=new AssetPartialDisposalRecords($db,'writer');$r=$records->save('partial',1,2027,$proof,$review['hash'],0,bin2hex(random_bytes(16)),true);
$before=MySqlFixture::rows($db,'balance_entries');$result=$records->book($r['id'],$r['sha256'],true);
check($result['entry_ids']===[]&&MySqlFixture::rows($db,'balance_entries')===$before,'fully depreciated component creates no fictional journal amounts');
$b=$sources->binding('depreciation','partial:2027');check($b!==null&&$b['entry_ids']===[]&&$b['history_sha256']!==null,'zero-value event still has immutable source evidence');
$s=(new BalanceAssetSchedule($db,'admin'))->build(2027);check(!$s['issues'],'zero-value component reconciles');
$i=$s['items']['partial'];check([$i['disposals_cents'],$i['accumulated_disposals_cents'],$i['book_disposals_cents'],$i['cost_closing_cents'],$i['book_closing_cents']] === [4000,4000,0,8000,0],'gross cost and accumulated depreciation removed equally');
$ledger->close(2027,$ledger->report(2027)['version'],$checks,'Synthetic zero-value disposal close');
check($ledger->report(2027)['state']==='closed','zero-value disposal can close');
check(!MySqlFixture::foreignKeyErrors($db),'zero-value event retains tenant isolation');
echo "$n zero-value partial disposal checks passed; synthetic MySQL only\n";
