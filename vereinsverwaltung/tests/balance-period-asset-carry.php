<?php declare(strict_types=1);
require __DIR__.'/balance-period-sources.php';
require_once __DIR__.'/../src/Finance/Assets/AssetPartialDisposalRecords.php';
use Kulturhus\Verein\{BalanceLedger,BalanceSources,BalancePeriodLedger,BalancePeriodSources,AssetBooks,Finance,Documents,SettingsSchema,BalancePeriodExport};
use Kulturhus\Verein\Tests\MySqlFixture;
$base=$n;$n=0;
$carrySphere=defined('SYNTHETIC_BUSINESS_ASSET_CARRY')?'4':'1';
$db->exec("INSERT INTO clubs VALUES('carry','Synthetic Calendar Asset Carry');INSERT INTO actors(id,club_id,role,is_superuser) VALUES('carry-admin','carry','treasurer',1)");
$tax=array_replace(SettingsSchema::defaults('tax'),['accounting_basis'=>'balance','depreciation_enabled'=>true,'input_tax_eligibility'=>'no','fiscal_start'=>'2025-01-01']);
$db->prepare("INSERT INTO club_settings(club_id,section,payload,version,updated_at) VALUES('carry','tax',?,1,?)")->execute([json_encode($tax),gmdate('c')]);
$calendar=new BalanceLedger($db,'carry-admin');$cs=new BalanceSources($db,'carry-admin');$f=new Finance($db,'carry-admin');$books=new AssetBooks($db,'carry-admin');$docs=new Documents($db,'carry-admin');
$calendar->create(2025);$calendar->setup(2025,1,$calendar->report(2025)['accounts'],[],'equity','Synthetic zero opening',true);
$importCalendar=function(string $type,string $id,array $options=[])use($calendar,$cs):void{$p=$cs->preview(2025,$type,$id,$options);if($p['issues'])throw new RuntimeException(implode(' ',$p['issues']));$calendar->importSource(2025,$calendar->report(2025)['version'],$type,$id,$options,$p['hash'],true);};
$ids=[];$png=base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jRZkAAAAASUVORK5CYII=');
foreach(['linear','degressive','gwg','scrap','software','partial'] as $item){
    $amount=$item==='gwg'?'119':'1200,01';$code=$item==='gwg'?'06700':($item==='software'?'01350':'06300');
    $entry=$f->createEntry(['kind'=>'expense','description'=>'Synthetic '.$item,'amount'=>$amount,'document_date'=>'2025-09-18','due_date'=>'2025-09-18','category'=>'general',
        'positions'=>[['description'=>'Synthetic asset','amount'=>$amount,'tax_rate'=>'19','sphere'=>$carrySphere,'account'=>$code,'booking_key'=>'','input_tax'=>'none']]],keyId());
    $invoice=$docs->upload('invoice.png',$png,['title'=>'Synthetic calendar acquisition','kind'=>'invoice','scope'=>'finance','links'=>['entry:'.$entry]],keyId());
    $db->prepare("INSERT INTO inventory_items(club_id,id,code,name,kind) VALUES('carry',?,?,?,'reusable')")->execute([$item,'C-'.$item,'Synthetic '.$item]);
    \Kulturhus\Verein\DocumentCatalog::register($db,'carry','item',$item);
    $input=['basis'=>$amount,'residual'=>'0','acquired_on'=>'2025-09-18','months'=>'60','sphere'=>$carrySphere,'account'=>$code,'reason'=>'Synthetic confirmed original'];
    if($item==='degressive')$input+=['method'=>'degressive','degressive_rate'=>'30','degressive_movable'=>'1','degressive_same_values'=>'1','asset_reviewed'=>'1','degressive_switch'=>'1'];
    if($item==='gwg'){
        $input['months']='1';$input+=['method'=>'gwg_immediate','gwg_net'=>'100','gwg_vat'=>'19','gwg_deductible'=>'0','asset_reviewed'=>'1','gwg_independent'=>'1','gwg_no_pool'=>'1'];
        $db->exec("INSERT INTO inventory_locations(club_id,id,name) VALUES('carry','store','Synthetic store');INSERT INTO inventory_stock(club_id,item_id,location_id,shelf) VALUES('carry','gwg','store',1)");
    }
    $books->save($item,0,$input);$db->prepare('INSERT INTO invoice_position_items VALUES(?,?,?,?,?,?,?,?)')->execute(['carry',$entry,1,$item,1,'Synthetic '.$item,'carry-admin',gmdate('c')]);
    $importCalendar('invoice',$entry,['recognised_on'=>'2025-09-18','private_paid_on'=>'']);
    if(in_array($item,['scrap','software','partial'],true)){
        $proof=$docs->upload('disposal.png',$png,['title'=>'Synthetic calendar disposal','kind'=>'receipt','scope'=>'finance','links'=>['item:'.$item]],keyId());
        $input=['disposal_kind'=>$item==='software'?'software_end':'scrap','disposal_date'=>'2025-12-15','disposal_document'=>$proof,'disposal_reason'=>'Synthetic evidenced end','disposal_confirmed'=>'1','software_rights_ended'=>'1'];
        if($item==='partial'){
            $records=new \Kulturhus\Verein\AssetPartialDisposalRecords($db,'carry-admin');
            $review=$records->prepare($item,1,2025,$input+['component_cost'=>'300','component_residual'=>'0','component_document'=>$invoice,'partial_confirmed'=>'1']);
            $records->book($review['id'],$review['sha256'],true);
        }else{$books->recordYear($item,1,2025,$input);$importCalendar('depreciation',$item.':2025');}
    }else{$books->recordYear($item,1,2025);$importCalendar('depreciation',$item.':2025');}
    $ids[$item]=$entry;
}
$all=['complete'=>true,'valuation'=>true,'cutoff'=>true,'tax'=>true];$calendar->close(2025,$calendar->report(2025)['version'],$all,'Synthetic calendar review');$before=$calendar->report(2025);
$oldTables=[];foreach(['balance_years','balance_entries','balance_sources','balance_source_history','asset_book_years','asset_book_plans'] as $table)$oldTables[$table]=MySqlFixture::rows($db,$table);
$l=new BalancePeriodLedger($db,'carry-admin');$s=new BalancePeriodSources($db,'carry-admin');$period=['start'=>'2026-01-01','end'=>'2026-06-30'];$pid=$l->create($period,'Synthetic short transition',true);
$import=function(string $item,string $target)use($l,$s):array{$r=$l->report($target);$p=$s->preview($r['period'],'depreciation',$item.':'.$target);if($p['issues'])throw new RuntimeException(implode(' ',$p['issues']));$l->importSource($target,$r['version'],'depreciation',$item.':'.$target,[],$p['hash'],true);return $l->report($target);};
$r=$l->report($pid);check($r['previous']['kind']==='calendar'&&$r['previous']['sha256']===$before['closed_sha256'],'bridge anchored to exact signed calendar closing');
$state=MySqlFixture::snapshot($db);$p=$s->preview($period,'depreciation','linear:'.$pid);check(!$p['issues'],'linear calendar bridge: '.implode(' ',$p['issues']));check(MySqlFixture::snapshot($db)===$state,'calendar bridge preview writes nothing');
check($p['snapshot']['schedule']['book_opening_cents']===112001&&$p['snapshot']['schedule']['depreciation_cents']===12000&&$p['snapshot']['schedule']['book_closing_cents']===100001,'calendar asset enters short year without repeated acquisition or past depreciation');
foreach(array_keys($ids) as $item)$r=$import($item,$pid);
check($r['asset_schedule']['items']['gwg']['schedule']['depreciation_cents']===0,'fully depreciated calendar GWG is not expensed again');
check($r['asset_schedule']['items']['degressive']['schedule']['book_opening_cents']===108001&&$r['asset_schedule']['items']['degressive']['schedule']['depreciation_cents']===16200,'degressive calendar state carries actual remaining cost and period months');
check($r['asset_schedule']['items']['scrap']['schedule']['book_closing_cents']===0&&$r['asset_schedule']['items']['software']['schedule']['depreciation_cents']===0,'calendar full disposals do not reappear or create new depreciation');
check($r['asset_schedule']['items']['partial']['schedule']['book_opening_cents']===84001&&$r['asset_schedule']['items']['partial']['schedule']['book_closing_cents']===75001,'calendar partial disposal retains only the documented remaining component');
check(!$r['issues']&&!$r['asset_schedule']['issues']&&$r['calculation']['annual_result_cents']===-37200,'calendar-to-period assets reconcile by original fixed account');
$l->close($pid,$r['version'],$r['journal_sha256'],$all,'Synthetic short-period review');
$pid2=$l->create(['start'=>'2026-07-01','end'=>'2026-12-31'],'Synthetic next short period',true);foreach(array_keys($ids) as $item)$r=$import($item,$pid2);
check($r['asset_schedule']['items']['linear']['schedule']['book_opening_cents']===100001&&$r['asset_schedule']['items']['linear']['schedule']['book_closing_cents']===88001,'second period uses confirmed dated predecessor');
check($r['asset_schedule']['items']['degressive']['schedule']['book_opening_cents']===91801&&$r['asset_schedule']['items']['degressive']['schedule']['depreciation_cents']===13770,'degressive short-year chain never falls back to calendar recalculation');
check($r['asset_schedule']['items']['linear']['invoice_origin']===['kind'=>'calendar','year'=>2025],'calendar acquisition provenance remains explicit in successor');
$export=(new BalancePeriodExport($db,'carry-admin'))->build($pid2);check(str_contains($export['files']['anlagenverzeichnis.json'],'invoice_origin'),'export retains bridge provenance');
$l->close($pid2,$r['version'],$r['journal_sha256'],$all,'Synthetic final short closing');
foreach($oldTables as $table=>$rows)check(MySqlFixture::rows($db,$table)===$rows,'bridge never rewrites '.$table);
$db->beginTransaction();try{$db->prepare("UPDATE accounting_positions SET description='Synthetic changed original' WHERE club_id='carry' AND entry_id=?")->execute([$ids['linear']]);check((bool)$l->report($pid2)['issues'],'changed original invalidates both dated successors');}finally{$db->rollBack();}
$db->beginTransaction();try{$db->exec("UPDATE asset_book_years SET depreciation_cents=depreciation_cents+1 WHERE club_id='carry' AND item_id='linear'");check((bool)$s->preview($period,'depreciation','linear:'.$pid)['issues'],'changed historical annual amount cannot be bridged');}finally{$db->rollBack();}
check(!$l->report($pid2)['issues']&&!MySqlFixture::foreignKeyErrors($db),'restored synthetic evidence leaves valid closed chain');
echo "$n calendar asset carry checks passed plus $base reused source checks; synthetic MySQL only\n";
