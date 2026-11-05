<?php declare(strict_types=1);
namespace Kulturhus\Verein { function date(string $format,?int $time=null):string{return \gmdate($format,$time??\strtotime('2028-01-15 UTC'));} }
namespace {
require __DIR__.'/balance-sources.php';
use Kulturhus\Verein\{AssetBooks,AssetDisposal,BalanceAssetSchedule,BalanceSourceHistory,BalanceLedger,BalanceSources,Documents,Finance,SettingsSchema};
$input=['basis'=>'1200','residual'=>'0','months'=>'60','acquired_on'=>'2026-01-01','sphere'=>'1','account'=>'06300','reason'=>'Synthetic evidenced equipment'];
$invoice=$make('1200','06300','expense','2026-01-01');
$db->exec("INSERT INTO inventory_items(club_id,id,code,name,kind) VALUES('a','scrap','SCRAP-1','Synthetic scrapped equipment','reusable')");
$books->save('scrap',0,$input);
$db->prepare('INSERT INTO invoice_position_items VALUES(?,?,?,?,?,?,?,?)')->execute(['a',$invoice,1,'scrap',1,'Synthetic equipment','admin',gmdate('c')]);
$import('invoice',$invoice,['recognised_on'=>'2026-01-01','private_paid_on'=>''],2026);
$docs=new Documents($db,'admin');$png=base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jRZkAAAAASUVORK5CYII=');
$doc=$docs->upload('scrap.png',$png,['title'=>'Synthetic signed scrapping record','kind'=>'invoice','scope'=>'finance'],keyId());
$event=['disposal_kind'=>'scrap','disposal_date'=>'2026-06-15','disposal_document'=>$doc,'disposal_reason'=>'Synthetic total destruction, no proceeds','disposal_confirmed'=>'1'];
$render=function(string $who,string $item,int $year,string $name)use($db):void{
    $out=getenv('VEREIN_DISPOSAL_UI_OUTPUT');if(!$out)return;
    if(!is_dir($out)||!str_starts_with(realpath($out),'/workspace/.local/'))throw new RuntimeException('Private synthetic UI output required');
    require_once __DIR__.'/../src/Web/View.php';
    if(!function_exists('euro')){function euro(int $cents):string{return number_format($cents/100,2,',','.').' €';}}
    $_SESSION=['actor'=>$who,'csrf'=>'synthetic-csrf'];$_SERVER['REQUEST_METHOD']='GET';$_POST=[];$_GET=['item'=>$item,'year'=>(string)$year];$finance=new Finance($db,$who);
    ob_start();require __DIR__.'/../views/Finance/Accounting/finance-book-values.php';$html=ob_get_clean();
    file_put_contents($out.'/'.$name.'.html','<!doctype html><html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Synthetic disposal</title></head><body><main style="margin:0;padding:16px">'.$html.'</main></body></html>');
};
foreach(['disposal_kind'=>'sale','disposal_date'=>'2025-12-31','disposal_document'=>str_repeat('0',32),'disposal_confirmed'=>'','disposal_reason'=>''] as $key=>$value)
    denied(fn()=>$books->recordYear('scrap',1,2026,array_replace($event,[$key=>$value])),'invalid disposal rejected: '.$key);
denied(fn()=>$books->recordYear('scrap',1,2026,array_replace($event,['disposal_reason'=>['unexpected']])),'structured POST value rejected without coercion');
denied(fn()=>(new AssetBooks($db,'other'))->recordYear('scrap',1,2026,$event),'other club cannot record asset disposal');
denied(fn()=>(new AssetBooks($db,'member'))->recordYear('scrap',1,2026,$event),'member cannot record asset disposal');
check(!$books->get('scrap')['years'],'invalid disposal never saves an annual record');
$books->recordYear('scrap',1,2026,$event);
$annual=$books->get('scrap')['years'][0];
check((int)$annual['depreciation_cents']===12000&&(int)$annual['closing_cents']===0,'six months depreciation and zero closing value');
$p=$sources->preview(2026,'depreciation','scrap:2026');
check(!$p['issues']&&$p['vouchers'][0]['date']==='2026-06-15','scrapping source uses actual disposal date');
check($p['vouchers'][0]['lines'][1]['account']==='src_asset_scrapping'&&$p['vouchers'][0]['lines'][1]['debit_cents']===108000,'residual loss separate from regular depreciation');
$import('depreciation','scrap:2026',[],2026);
check(count((new BalanceSourceHistory($db,'admin'))->checked($sources->binding('depreciation','scrap:2026')))===1,'disposal retains checked source history');
$s=(new BalanceAssetSchedule($db,'admin'))->build(2026)['items']['scrap'];
check($s['disposals_cents']===120000&&$s['accumulated_disposals_cents']===12000&&$s['book_disposals_cents']===108000,'gross acquisition and accumulated depreciation removed separately');
check($s['cost_closing_cents']===0&&$s['book_closing_cents']===0,'no phantom asset remains');
denied(fn()=>$books->recordYear('scrap',1,2027),'no future annual depreciation after disposal');
denied(fn()=>$import('depreciation','scrap:2026',[],2026),'no duplicate disposal import');
$docs->newVersion($doc,1,'changed.png',$png,'Synthetic changed evidence');
check((bool)$sources->preview(2026,'depreciation','scrap:2026')['issues'],'changed evidence revision invalidates source even if bytes identical');
// Fully depreciated GWG: immutable evidence, but no invented zero-euro money entry.
$invoice=$make('100','06700','expense','2026-01-01');
$db->exec("INSERT INTO inventory_items(club_id,id,code,name,kind) VALUES('a','scrap-zero','SCRAP-0','Synthetic zero-value equipment','reusable')");
// A linear one-year plan avoids the GWG election and is already exhausted by December.
$books->save('scrap-zero',0,array_replace($input,['basis'=>'100','months'=>'12']));
$db->prepare('UPDATE accounting_positions SET account_code=? WHERE club_id=? AND entry_id=?')->execute(['06300','a',$invoice]);
$db->prepare('INSERT INTO invoice_position_items VALUES(?,?,?,?,?,?,?,?)')->execute(['a',$invoice,1,'scrap-zero',1,'Synthetic equipment','admin',gmdate('c')]);
$import('invoice',$invoice,['recognised_on'=>'2026-01-01','private_paid_on'=>''],2026);
$books->recordYear('scrap-zero',1,2026);$import('depreciation','scrap-zero:2026',[],2026);
$books->recordYear('scrap-zero',1,2027,array_replace($event,['disposal_date'=>'2027-03-10']));
$p=$sources->preview(2027,'depreciation','scrap-zero:2027');
check(!$p['issues']&&$p['vouchers']===[]&&AssetDisposal::nonPosting($p),'zero-value source has no fake financial voucher');
$bad=$p;$bad['type']='invoice';check(!AssetDisposal::nonPosting($bad),'empty invoices never bypass posting requirement');
$bad=$p;$bad['snapshot']['annual']['opening_cents']=1;check(!AssetDisposal::nonPosting($bad),'tampered zero-value event rejected');
$proof=json_decode($annual['snapshot'],true,64,JSON_THROW_ON_ERROR)['disposal'];$plan=AssetBooks::plan($input);
check(AssetDisposal::schedule($plan,2027,$proof)['depreciation_cents']===0,'next-year calculation never revives disposed asset');
// Complete isolated second club: a zero-value removal must also survive finalisation and carry.
$db->exec("INSERT INTO clubs VALUES('z','Synthetic zero disposal');INSERT INTO actors(id,club_id,role,is_superuser) VALUES('zero-admin','z','treasurer',1);INSERT INTO inventory_items(club_id,id,code,name,kind) VALUES('z','zero','ZERO','Synthetic exhausted item','reusable')");
$ztax=SettingsSchema::defaults('tax');$ztax['accounting_basis']='balance';$ztax['input_tax_eligibility']='no';$ztax['depreciation_enabled']=true;
$db->prepare("INSERT INTO club_settings(club_id,section,payload,version,updated_at) VALUES('z','tax',?,1,?)")->execute([json_encode($ztax),gmdate('c')]);
$zl=new BalanceLedger($db,'zero-admin');$zs=new BalanceSources($db,'zero-admin');$zb=new AssetBooks($db,'zero-admin');$zd=new Documents($db,'zero-admin');$zf=new Finance($db,'zero-admin');
$zl->create(2025);$zl->setup(2025,1,$zl->report(2025)['accounts'],[],'equity','Synthetic empty initial business',true);
$zi=$zf->createEntry(['kind'=>'expense','description'=>'Synthetic asset invoice','amount'=>'120','document_date'=>'2025-01-01','due_date'=>'2025-01-01','category'=>'general',
    'positions'=>[['description'=>'Synthetic equipment','amount'=>'120','tax_rate'=>'0','sphere'=>'4','account'=>'06300','booking_key'=>'','input_tax'=>'none']]],keyId());
$zd->upload('invoice.png',$png,['title'=>'Synthetic purchase','kind'=>'invoice','scope'=>'finance','links'=>['entry:'.$zi]],keyId());
$zb->save('zero',0,array_replace($input,['basis'=>'120','months'=>'12','acquired_on'=>'2025-01-01','sphere'=>'4']));
$db->prepare('INSERT INTO invoice_position_items VALUES(?,?,?,?,?,?,?,?)')->execute(['z',$zi,1,'zero',1,'Synthetic equipment','zero-admin',gmdate('c')]);
$zimport=function($year,$type,$id,$options=[])use($zl,$zs){$p=$zs->preview($year,$type,$id,$options);check(!$p['issues'],'zero-asset source ready');return $zl->importSource($year,$zl->report($year)['version'],$type,$id,$options,$p['hash'],true);};
$zimport(2025,'invoice',$zi,['recognised_on'=>'2025-01-01','private_paid_on'=>'']);
$zdoc=$zd->upload('scrap.png',$png,['title'=>'Synthetic signed scrapping record','kind'=>'invoice','scope'=>'finance'],keyId());
$render('zero-admin','zero',2025,'disposal-open');
$zb->recordYear('zero',1,2025);$zimport(2025,'depreciation','zero:2025');
$checks=['complete'=>true,'valuation'=>true,'cutoff'=>true,'tax'=>true];$zl->close(2025,$zl->report(2025)['version'],$checks,'Synthetic first-year close');$zl->create(2026);
$zevent=array_replace($event,['disposal_date'=>'2026-03-10','disposal_document'=>$zdoc]);
$zb->recordYear('zero',1,2026,$zevent);
$beforeCount=(int)$db->query("SELECT COUNT(*) FROM balance_entries WHERE club_id='z' AND tax_year=2026")->fetchColumn();$beforeVersion=$zl->report(2026)['version'];
$ids=$zimport(2026,'depreciation','zero:2026');
check($ids===[]&&(int)$db->query("SELECT COUNT(*) FROM balance_entries WHERE club_id='z' AND tax_year=2026")->fetchColumn()===$beforeCount,'zero-value import creates no financial entries');
check($zl->report(2026)['version']===$beforeVersion+1,'zero-value import advances optimistic version');
check(count((new BalanceSourceHistory($db,'zero-admin'))->checked($zs->binding('depreciation','zero:2026')))===1,'zero-value source history verifies without monetary entries');
check(!$zs->reviewYear(2026)['issues'],'zero-value source reconciles before closing');
$zl->close(2026,$zl->report(2026)['version'],$checks,'Synthetic zero-value disposal close');
check($zl->report(2026)['asset_schedule']['totals']['disposals_cents']===12000,'closed year retains original cost removed for zero-value item');
$bundle=(new \Kulturhus\Verein\TaxPreparation($db,'zero-admin'))->build(2026,2026,'Synthetic activity');
$csv=$bundle['files']['2026/bilanz-anlagenverzeichnis.csv'];
check(str_contains($csv,'Anschaffungskosten Abgang Cent')&&str_contains($csv,'Abgangsnachweis SHA256'),'tax working-paper CSV includes separate disposal columns and evidence');
$exported=json_decode($bundle['files']['2026/bilanz-anlagenverzeichnis.json'],true,64,JSON_THROW_ON_ERROR);
check($exported['items']['zero']['disposals_cents']===12000&&$exported['items']['zero']['disposal']['document_id']===$zdoc,'export preserves fully depreciated cost release and original proof reference');
$zl->create(2027);$next=(new BalanceAssetSchedule($db,'zero-admin'))->build(2027);
check(!$next['issues']&&array_sum($next['totals'])===0,'following-year schedule stays zero and reconciles');
$render('zero-admin','zero',2027,'disposal-following');
denied(fn()=>$zimport(2026,'depreciation','zero:2026'),'closed zero-value event cannot be imported again');
echo "$n combined source/disposal checks passed; synthetic MySQL only\n";
}
