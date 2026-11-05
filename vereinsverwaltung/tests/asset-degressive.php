<?php declare(strict_types=1);
namespace Kulturhus\Verein { function date(string $format,?int $time=null):string{return \gmdate($format,$time??\strtotime('2027-01-15 UTC'));} }
namespace {
require __DIR__.'/balance-sources.php';
use Kulturhus\Verein\{AssetBooks,BalanceAssetSchedule};
$input=['method'=>'degressive','basis'=>'1000','residual'=>'0','months'=>'60','acquired_on'=>'2025-07-01','sphere'=>'1','account'=>'06300',
    'reason'=>'Synthetic reviewed book and tax plan','degressive_rate'=>'30','degressive_movable'=>'1','degressive_same_values'=>'1','degressive_switch'=>'1','asset_reviewed'=>'1'];
$plan=AssetBooks::plan($input);
check(AssetBooks::schedule($plan,2025)['depreciation_cents']===15000,'first year has six twelfths of 30 percent');
check(AssetBooks::schedule($plan,2026)['depreciation_cents']===25500,'second year applies unchanged rate to remaining value');
check(AssetBooks::schedule($plan,2027)['depreciation_cents']===17850,'third year declining amount exact');
check(AssetBooks::schedule($plan,2028)['switched_in']===2028,'switch to linear only when beneficial');
check(AssetBooks::schedule($plan,2028)['depreciation_cents']===16660,'remaining value spread over remaining 30 months');
check(AssetBooks::schedule($plan,2030)['closing_cents']===0,'final six months complete depreciation without a rounding remainder');
check(AssetBooks::schedule($plan,2031)['depreciation_cents']===0,'fully depreciated item is not expensed twice');
check(AssetBooks::schedule($plan,2024)['depreciation_cents']===0,'no expense before acquisition');
$noSwitch=AssetBooks::plan(array_replace($input,['degressive_switch'=>'']));
check(AssetBooks::schedule($noSwitch,2028)['switched_in']===null&&AssetBooks::schedule($noSwitch,2028)['depreciation_cents']===12495,'no unrequested method change');
$slow=AssetBooks::plan(array_replace($input,['months'=>'240','degressive_rate'=>'15']));
check(AssetBooks::schedule($slow,2025)['depreciation_cents']===7500,'triple linear limit applies for long useful lives');
foreach(['acquired_on'=>'2025-06-30','account'=>'01350','residual'=>'1','degressive_rate'=>'30.01','degressive_movable'=>'','degressive_same_values'=>'','asset_reviewed'=>'','method'=>'unsupported'] as $key=>$value)
    denied(fn()=>AssetBooks::plan(array_replace($input,[$key=>$value])),'degressive rejects '.$key);
denied(fn()=>AssetBooks::plan(array_replace($input,['months'=>'240','degressive_rate'=>'15.01'])),'rate above triple linear rejected');
$bad=$plan;$bad['degressive']['rate_basis_points']=true;denied(fn()=>AssetBooks::schedule($bad,2026),'stored boolean is not a depreciation percentage');
$bad=$plan;$bad['method']='special';denied(fn()=>AssetBooks::schedule($bad,2026),'unknown method never silently treated as linear');
// Full synthetic MySQL source chain; established earlier years remain untouched.
$invoice=$make('1190','06300','expense','2026-07-01');
$db->exec("INSERT INTO inventory_items(club_id,id,code,name,kind) VALUES('a','deg','DEG-1','Synthetic degressive equipment','reusable')");
$input=array_replace($input,['basis'=>'1190','acquired_on'=>'2026-07-01']);
$books->save('deg',0,$input);
$db->prepare('INSERT INTO invoice_position_items VALUES(?,?,?,?,?,?,?,?)')->execute(['a',$invoice,1,'deg',1,'Synthetic equipment','admin',gmdate('c')]);
$import('invoice',$invoice,['recognised_on'=>'2026-07-01','private_paid_on'=>''],2026);
$books->recordYear('deg',1,2026);$import('depreciation','deg:2026',[],2026);
$schedule=(new BalanceAssetSchedule($db,'admin'))->build(2026);$item=$schedule['items']['deg'];
check($item['method']==='degressive'&&$item['degressive']['rate_basis_points']===3000,'retained asset schedule includes explicit method proof');
check($item['additions_cents']===119000&&$item['depreciation_cents']===17850&&$item['book_closing_cents']===101150,'source acquisition and half-year declining charge reconcile');
denied(fn()=>$import('depreciation','deg:2026',[],2026),'degressive charge cannot be posted twice');
denied(fn()=>$books->save('deg',1,$input),'confirmed plan cannot be replaced after annual record');
echo "$n combined source/degressive checks passed; synthetic MySQL only\n";
}
