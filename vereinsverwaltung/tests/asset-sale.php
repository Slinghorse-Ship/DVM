<?php declare(strict_types=1);
namespace Kulturhus\Verein { function date(string $format,?int $time=null):string{return \gmdate($format,$time??\strtotime('2028-01-15 UTC'));} }
namespace {
require __DIR__.'/balance-issued-invoices.php';
use Kulturhus\Verein\{AssetBooks,AssetDisposal,AssetSale,BalanceAssetSchedule,BalanceSources,BalanceTaxMapping,Documents,OutgoingInvoices,SettingsSchema};
use Kulturhus\Verein\Tests\MySqlFixture;
$taxSettings=json_decode($db->query("SELECT payload FROM club_settings WHERE club_id='a' AND section='tax'")->fetchColumn(),true,64,JSON_THROW_ON_ERROR);
$db->prepare("UPDATE club_settings SET payload=? WHERE club_id='a' AND section='tax'")->execute([json_encode(array_replace($taxSettings,['accounting_basis'=>'balance','input_tax_eligibility'=>'no','depreciation_enabled'=>true]))]);
$books=new AssetBooks($db,'admin');$plan=['basis'=>'1200','residual'=>'0','months'=>'60','acquired_on'=>'2026-01-01','sphere'=>'4','account'=>'06300','reason'=>'Synthetic confirmed purchase'];
$import=function(string $type,string $id,array $options=[])use($ledger,$sources){$p=$sources->preview(2026,$type,$id,$options);if($p['issues'])throw new RuntimeException(implode(' ',$p['issues']));return $ledger->importSource(2026,$ledger->report(2026)['version'],$type,$id,$options,$p['hash'],true);};
$createAsset=function(string $item)use($db,$f,$books,$plan,$import){
 $purchase=$f->createEntry(['kind'=>'expense','description'=>'Synthetic purchase','amount'=>'1200','document_date'=>'2026-01-01','due_date'=>'2026-01-10','category'=>'general',
   'positions'=>[['description'=>'Synthetic equipment','amount'=>'1200','tax_rate'=>'0','sphere'=>'4','account'=>'06300','input_tax'=>'none']]],keyId());
 $png=base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jRZkAAAAASUVORK5CYII=');
 (new Documents($db,'admin'))->upload('synthetic.png',$png,['title'=>'Synthetic purchase','kind'=>'invoice','scope'=>'finance','links'=>['entry:'.$purchase]],keyId());
 $db->prepare("INSERT INTO inventory_items(club_id,id,code,name,kind) VALUES('a',?,?,?,'reusable')")->execute([$item,strtoupper($item),'Synthetic equipment '.$item]);
 $books->save($item,0,$plan);$db->prepare('INSERT INTO invoice_position_items VALUES(?,?,?,?,?,?,?,?)')->execute(['a',$purchase,1,$item,1,'Synthetic equipment','admin',gmdate('c')]);
 $import('invoice',$purchase,['recognised_on'=>'2026-01-01','private_paid_on'=>'']);
};
$createSale=function(string $gross,string $rate)use($service,$data){
 $d=array_replace($data,['description'=>'Synthetic asset sale','document_date'=>'2026-06-15','delivery_date'=>'2026-06-15','due_date'=>'2026-06-30',
   'positions'=>[['description'=>'Synthetic equipment','quantity'=>'1','unit'=>'C62','unit_gross'=>$gross,'tax_rate'=>$rate,'sphere'=>'4','account'=>$rate==='0'?'41840':'44000']]]);
 $id=$service->save('',0,$d,keyId());$service->review($id,1);return [$id,$service->issue($id,2)];
};
$events=[];
foreach([['gain19','1428','19','48450','48550',12000],['loss19','1190','19','68850','68950',-8000],['gain0','1200','0','48490','48550',12000],['loss0','1000','0','68890','68950',-8000]] as [$item,$gross,$rate,$saleCode,$bookCode,$gain]){
 $createAsset($item);[$invoiceId,$entry]=$createSale($gross,$rate);$issued=$service->get($invoiceId);$original=(new Documents($db,'admin'))->file($issued['document_id'],1);
 $event=['disposal_kind'=>'sale','disposal_date'=>'2026-06-15','disposal_reason'=>'Synthetic complete domestic sale','sale_entry'=>$entry,'sale_confirmed'=>'1'];
 $before=MySqlFixture::snapshot($db);
 denied(fn()=>$books->recordYear($item,1,2026,array_replace($event,['sale_confirmed'=>''])),'sale requires explicit confirmation');
 denied(fn()=>(new AssetBooks($db,'member'))->recordYear($item,1,2026,$event),'member cannot dispose asset');
 denied(fn()=>(new AssetBooks($db,'foreign'))->recordYear($item,1,2026,$event),'other tenant cannot dispose asset');
 denied(fn()=>$books->recordYear($item,1,2026,array_replace($event,['disposal_date'=>'2026-07-01'])),'transfer date must match issued delivery date');
 check($before===MySqlFixture::snapshot($db),'failed sale leaves invoice, plan, journal and history unchanged');
 $books->recordYear($item,1,2026,$event);$events[$item]=$event;
 $position=$f->positions('entry',$entry)[0];check($position['account_code']===$saleCode&&$position['booking_key']==='','sale account calculated from gain/loss without extra BU');
 check(count($f->positionHistory('entry',$entry))===1,'automatic reclassification retains a reason and previous position');
 check((new Documents($db,'admin'))->file($issued['document_id'],1)===$original,'sale leaves issued PDF immutable');
 $annual=$books->get($item)['years'][0];[$savedPlan,$proof]=AssetDisposal::unpack(json_decode($annual['snapshot'],true,64,JSON_THROW_ON_ERROR));
 $values=AssetSale::amounts($savedPlan,$proof['date'],$proof['gross_cents'],$proof['rate']);
 check((int)$annual['depreciation_cents']===12000&&$values['book_cents']===108000&&$values['result_cents']===$gain,'monthly depreciation, residual and gain/loss are exact');
 check((bool)$sources->preview(2026,'depreciation',$item.':2026')['issues'],'sale invoice must precede annual disposal import');
 $profit=$ledger->report(2026)['calculation']['annual_result_cents'];
 $import('invoice',$entry,['recognised_on'=>'2026-06-15','private_paid_on'=>'']);$import('depreciation',$item.':2026');
 check($ledger->report(2026)['calculation']['annual_result_cents']===$profit+$gain-12000,'only sale gain/loss and ordinary depreciation affect profit');
 $s=(new BalanceAssetSchedule($db,'admin'))->build(2026);check(!$s['issues'],'sale reconciles with acquisition and retained asset rollforward');
 $asset=$s['items'][$item];check($asset['cost_closing_cents']===0&&$asset['book_closing_cents']===0&&$asset['book_disposals_cents']===108000,'sale releases gross cost, accumulated depreciation and net carrying value');
 $mapping=BalanceTaxMapping::preview(2026,$ledger->report(2026)['calculation']['accounts'],$s);
 check($mapping['rows']['src_code_'.$saleCode]['status']==='assigned'&&$mapping['rows']['src_sale_book_'.$bookCode]['status']==='assigned','gain/loss accounts map only against matching asset evidence');
 denied(fn()=>$books->recordYear($item,1,2027),'sold asset cannot depreciate again');
 denied(fn()=>$import('depreciation',$item.':2026'),'same disposal cannot post twice');
 $tx=$f->createTransaction(['account'=>$bank,'booked_on'=>'2026-06-20','direction'=>'income','amount'=>$gross,'counterparty'=>'Synthetic Customer','description'=>'Synthetic sale payment'],keyId());
 $f->allocate($tx,1,$entry,(int)$f->entry($entry)['version'],$gross,keyId());$profit=$ledger->report(2026)['calculation']['annual_result_cents'];$import('transaction',$tx);
 check($ledger->report(2026)['calculation']['annual_result_cents']===$profit,'asset-sale payment never produces duplicate income');
 mutation($db,fn()=>$db->prepare("UPDATE accounting_positions SET sphere='3' WHERE club_id='a' AND entry_id=?")->execute([$entry]),function()use($sources,$entry,$item){
   check((bool)$sources->preview(2026,'invoice',$entry,['recognised_on'=>'2026-06-15','private_paid_on'=>''])['issues'],'changed sale allocation invalidates invoice source');
   check((bool)$sources->preview(2026,'depreciation',$item.':2026')['issues'],'changed sale allocation also invalidates asset disposal');
 });
}
$createAsset('duplicate');$before=MySqlFixture::snapshot($db);
denied(fn()=>$books->recordYear('duplicate',1,2026,$events['gain19']),'one issued sales invoice cannot dispose two assets');
check($before===MySqlFixture::snapshot($db),'duplicate sale leaves no partial reclassification');
$restore=MySqlFixture::restoreBackup($db);check(MySqlFixture::rows($db,'asset_sale_links')===MySqlFixture::rows($restore,'asset_sale_links'),'MySQL restore retains unique sale associations');
check(!MySqlFixture::foreignKeyErrors($db),'asset sale references remain within their tenant');
echo "$n issued invoice and asset-sale checks passed; isolated MySQL only\n";
}
