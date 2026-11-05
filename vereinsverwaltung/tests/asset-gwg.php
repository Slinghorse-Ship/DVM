<?php declare(strict_types=1);
// Future-year closure only in this synthetic process, never a production clock override.
namespace Kulturhus\Verein { function date(string $format,?int $time=null):string{return \gmdate($format,$time??\strtotime('2027-01-15 UTC'));} }
namespace {
require __DIR__.'/balance-sources.php';
use Kulturhus\Verein\{AssetBooks,BalanceAssetSchedule,BalanceTaxMapping};
require_once __DIR__.'/../src/Finance/Balance/BalanceTaxMapping.php';
$input=['method'=>'gwg_immediate','basis'=>'119','acquired_on'=>'2026-03-01','sphere'=>'1','account'=>'06700','reason'=>'Synthetic individual invoice and independently usable asset',
    'gwg_net'=>'100','gwg_vat'=>'19','gwg_deductible'=>'0','asset_reviewed'=>'1','gwg_independent'=>'1','gwg_no_pool'=>'1'];
$plan=AssetBooks::plan($input);
check($plan['months']===1&&$plan['residual_cents']===0,'GWG has no invented useful life');
check(AssetBooks::schedule($plan,2026)['depreciation_cents']===11900,'gross GWG expense without VAT deduction');
check(AssetBooks::schedule($plan,2027)['depreciation_cents']===0&&AssetBooks::schedule($plan,2027)['closing_cents']===0,'GWG is not depreciated twice');
$deductible=AssetBooks::plan(array_replace($input,['basis'=>'100','gwg_deductible'=>'19']));
check(AssetBooks::schedule($deductible,2026)['depreciation_cents']===10000,'deductible VAT excluded from expense');
$maximum=AssetBooks::plan(array_replace($input,['basis'=>'952','gwg_net'=>'800','gwg_vat'=>'152']));
check($maximum['basis_cents']===95200,'800 euro threshold uses net even without deduction');
foreach(['gwg_net'=>'800.01','gwg_vat'=>'20','gwg_deductible'=>'20','gwg_independent'=>'','gwg_no_pool'=>'','asset_reviewed'=>'','reason'=>'','account'=>'06300','method'=>'pool','acquired_on'=>'2024-01-01','sphere'=>'9','basis'=>'120'] as $key=>$value)denied(fn()=>AssetBooks::plan(array_replace($input,[$key=>$value])),'GWG rejects '.$key);
$gwgInvoice=$make('119','06700','expense','2026-03-01');
$db->exec("INSERT INTO inventory_items(club_id,id,code,name,kind) VALUES('a','gwg','GWG-1','Synthetic low value equipment','reusable')");
$db->exec("INSERT INTO inventory_locations(club_id,id,name) VALUES('a','gwg-store','Synthetic store'); INSERT INTO inventory_stock(club_id,item_id,location_id,shelf) VALUES('a','gwg','gwg-store',2)");
denied(fn()=>$books->save('gwg',0,$input),'collective stock cannot be one GWG');
$db->exec("UPDATE inventory_stock SET shelf=1 WHERE club_id='a' AND item_id='gwg'");
$books->save('gwg',0,$input);
$db->prepare('INSERT INTO invoice_position_items VALUES(?,?,?,?,?,?,?,?)')->execute(['a',$gwgInvoice,1,'gwg',1,'Synthetic GWG','admin',gmdate('c')]);
$gwgOptions=['recognised_on'=>'2026-03-01','private_paid_on'=>''];
check(!$sources->preview(2026,'invoice',$gwgInvoice,$gwgOptions)['issues'],'single GWG reconciles to invoice');
$db->exec("INSERT INTO inventory_items(club_id,id,code,name,kind) VALUES('a','gwg2','GWG-2','Synthetic second item','reusable')");
$db->prepare('INSERT INTO invoice_position_items VALUES(?,?,?,?,?,?,?,?)')->execute(['a',$gwgInvoice,1,'gwg2',1,'Synthetic second item','admin',gmdate('c')]);
check((bool)$sources->preview(2026,'invoice',$gwgInvoice,$gwgOptions)['issues'],'multiple pieces cannot masquerade as one GWG');
$db->exec("DELETE FROM invoice_position_items WHERE club_id='a' AND entry_id='".$gwgInvoice."' AND item_id='gwg2'");
$badPlan=$plan;$badPlan['gwg']['net_cents']=11000;$badPlan['gwg']['vat_cents']=900;
$db->prepare("UPDATE asset_book_plans SET payload=? WHERE item_id='gwg'")->execute([json_encode($badPlan)]);
check((bool)$sources->preview(2026,'invoice',$gwgInvoice,$gwgOptions)['issues'],'VAT split must match original invoice, not just gross');
$db->prepare("UPDATE asset_book_plans SET payload=? WHERE item_id='gwg'")->execute([json_encode($plan)]);
$import('invoice',$gwgInvoice,$gwgOptions,2026);
$books->recordYear('gwg',1,2026);$import('depreciation','gwg:2026',[],2026);
$snapshot=(new BalanceAssetSchedule($db,'admin'))->build(2026);
check($snapshot['items']['gwg']['cost_closing_cents']===11900&&$snapshot['items']['gwg']['accumulated_closing_cents']===11900&&$snapshot['items']['gwg']['book_closing_cents']===0,'GWG gross cost retained after full expense');
check($ledger->report(2026)['calculation']['accounts']['src_gwg_depreciation']['skr_code']==='62600','GWG posts separate exact SKR expense account');
denied(fn()=>$import('depreciation','gwg:2026',[],2026),'GWG cannot be posted twice');
denied(fn()=>$books->save('gwg',1,$input),'recorded GWG election immutable');
check(count($books->get('gwg')['history'])===1,'GWG election retained in history');
echo "$n combined source/GWG checks passed; synthetic MySQL only\n";
}
