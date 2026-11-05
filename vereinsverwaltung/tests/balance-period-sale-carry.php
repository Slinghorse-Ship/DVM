<?php declare(strict_types=1);
require __DIR__.'/balance-period-sources.php';
use Kulturhus\Verein\{BalanceLedger,BalanceSources,BalancePeriodLedger,BalancePeriodSources,AssetBooks,Finance,Documents,SettingsSchema,OutgoingInvoices,BalancePeriodExport};
use Kulturhus\Verein\Tests\MySqlFixture;
$base=$n;$n=0;
$db->exec("INSERT INTO clubs VALUES('sale-carry','Synthetic Historical Sales');INSERT INTO actors(id,club_id,role,is_superuser) VALUES('sale-carry-admin','sale-carry','treasurer',1)");
$actor='sale-carry-admin';$f=new Finance($db,$actor);$bank=$f->createAccount('Synthetic bank','offline','0','2025-01-01');
foreach(['club'=>['name'=>'Synthetic Association','street'=>'Testweg 1','postcode'=>'12345','city'=>'Musterstadt','phone'=>'+49 123 456789','email'=>'seller@example.org','register'=>'VR 12345 Musterstadt'],
    'tax'=>['number'=>'123/456/78901','accounting_basis'=>'balance','input_tax_eligibility'=>'no','depreciation_enabled'=>true,'fiscal_start'=>'2025-01-01'],
    'bank'=>['iban'=>'DE89370400440532013000','holder'=>'Synthetic Association','account_id'=>$bank]] as $section=>$fields)
    $db->prepare('INSERT INTO club_settings(club_id,section,version,payload,updated_at) VALUES(?,?,1,?,?)')->execute(['sale-carry',$section,json_encode(array_replace(SettingsSchema::defaults($section),$fields)),gmdate('c')]);
$c=new BalanceLedger($db,$actor);$cs=new BalanceSources($db,$actor);$books=new AssetBooks($db,$actor);$docs=new Documents($db,$actor);$out=new OutgoingInvoices($db,$actor);
$c->create(2025);$c->setup(2025,1,$c->report(2025)['accounts'],[],'equity','Synthetic zero opening',true);
$import=function(string $type,string $id,array $options=[])use($c,$cs):void{
    $p=$cs->preview(2025,$type,$id,$options);if($p['issues'])throw new RuntimeException(implode(' ',$p['issues']));
    $c->importSource(2025,$c->report(2025)['version'],$type,$id,$options,$p['hash'],true);
};
$png=base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jRZkAAAAASUVORK5CYII=');$sales=[];
foreach([['gain','1428','19',false],['loss','1000','0',false],['deg','1100','0',true]] as [$item,$gross,$rate,$deg]){
    $purchase=$f->createEntry(['kind'=>'expense','description'=>'Synthetic purchase','amount'=>'1200,01','document_date'=>'2025-09-18','due_date'=>'2025-09-18','category'=>'general',
        'positions'=>[['description'=>'Synthetic equipment','amount'=>'1200,01','tax_rate'=>'0','sphere'=>'4','account'=>'06300','input_tax'=>'none']]],keyId());
    $docs->upload('purchase.png',$png,['title'=>'Synthetic purchase','kind'=>'invoice','scope'=>'finance','links'=>['entry:'.$purchase]],keyId());
    $db->prepare("INSERT INTO inventory_items(club_id,id,code,name,kind) VALUES('sale-carry',?,?,?,'reusable')")->execute([$item,'SC-'.$item,'Synthetic '.$item]);
    $plan=['basis'=>'1200,01','residual'=>'0','months'=>'60','acquired_on'=>'2025-09-18','sphere'=>'4','account'=>'06300','reason'=>'Synthetic original'];
    if($deg)$plan+=['method'=>'degressive','degressive_rate'=>'30','degressive_movable'=>'1','degressive_same_values'=>'1','asset_reviewed'=>'1','degressive_switch'=>'1'];
    $books->save($item,0,$plan);$db->prepare('INSERT INTO invoice_position_items VALUES(?,?,?,?,?,?,?,?)')->execute(['sale-carry',$purchase,1,$item,1,'Synthetic equipment',$actor,gmdate('c')]);
    $import('invoice',$purchase,['recognised_on'=>'2025-09-18','private_paid_on'=>'']);
    $data=['domestic_confirmed'=>'1','seller_contact'=>'Fiktive Rechnungsstelle','buyer_name'=>'Synthetic Customer','buyer_street'=>'Testweg 2','buyer_postcode'=>'23456','buyer_city'=>'Musterstadt','buyer_email'=>'customer@example.invalid','buyer_reference'=>'SYN-1',
        'description'=>'Synthetic historical sale','note'=>'Synthetic only','vat_reason'=>$rate==='0'?'Kleinunternehmerregelung § 19 UStG':'','document_date'=>'2025-12-15','delivery_date'=>'2025-12-15','due_date'=>'2026-01-15','category'=>'general','contact_id'=>'','payment_account'=>$bank,
        'positions'=>[['description'=>'Synthetic equipment','quantity'=>'1','unit'=>'C62','unit_gross'=>$gross,'tax_rate'=>$rate,'sphere'=>'4','account'=>$rate==='0'?'41840':'44000']]];
    $id=$out->save('',0,$data,keyId());$out->review($id,1);$entry=$out->issue($id,2);$sales[$item]=[$entry,$gross];
    $books->recordYear($item,1,2025,['disposal_kind'=>'sale','disposal_date'=>'2025-12-15','disposal_reason'=>'Synthetic historical sale','sale_entry'=>$entry,'sale_confirmed'=>'1']);
    $import('invoice',$entry,['recognised_on'=>'2025-12-15','private_paid_on'=>'']);$import('depreciation',$item.':2025');
}
$all=['complete'=>true,'valuation'=>true,'cutoff'=>true,'tax'=>true];$c->close(2025,$c->report(2025)['version'],$all,'Synthetic original closing');
$old=[];foreach(['balance_years','balance_entries','balance_sources','balance_source_history','asset_book_plans','asset_book_years','asset_sale_links'] as $table)$old[$table]=MySqlFixture::rows($db,$table);
$l=new BalancePeriodLedger($db,$actor);$s=new BalancePeriodSources($db,$actor);$period=['start'=>'2026-01-01','end'=>'2026-06-30'];$pid=$l->create($period,'Synthetic historical sale transition',true);
$carry=function(string $item,string $target)use($l,$s):array{
    $r=$l->report($target,0,false);$p=$s->preview($r['period'],'depreciation',$item.':'.$target);
    check(!$p['issues'],'historical sale carries without blockers '.$item.': '.implode(' ',$p['issues']));
    check($p['vouchers']===[]&&$p['snapshot']['schedule']['depreciation_cents']===0&&$p['snapshot']['schedule']['book_closing_cents']===0,'disposed item never returns or repeats depreciation '.$item);
    check($p['snapshot']['sale_origin']['year']===2025,'historical sale provenance remains explicit '.$item);
    $l->importSource($target,$r['version'],'depreciation',$item.':'.$target,[],$p['hash'],true);return $l->report($target);
};
$before=MySqlFixture::snapshot($db);$p=$s->preview($period,'depreciation','gain:'.$pid);check(!$p['issues']&&MySqlFixture::snapshot($db)===$before,'historical sale preview is read-only');
foreach($sales as $item=>[$entry,$gross]){
    $r=$carry($item,$pid);
    $tx=$f->createTransaction(['account'=>$bank,'booked_on'=>'2026-02-01','direction'=>'income','amount'=>$gross,'counterparty'=>'Synthetic buyer','description'=>'Synthetic historical invoice payment'],keyId());
    $f->allocate($tx,1,$entry,(int)$f->entry($entry)['version'],$gross,keyId());$p=$s->preview($period,'transaction',$tx);
    check(!$p['issues'],'historical sale receivable payable in new period '.$item.': '.implode(' ',$p['issues']));
    $l->importSource($pid,$l->report($pid,0,false)['version'],'transaction',$tx,[],$p['hash'],true);$r=$l->report($pid);
    $receivable=$cs->binding('invoice',$entry)['snapshot']['settlement_account'];
    check($r['calculation']['accounts'][$receivable]['closing_cents']===0&&$r['calculation']['annual_result_cents']===0,'later payment only settles the old sale '.$item);
}
check(!$r['issues'],'calendar sales and dated settlement reconcile');
$l->close($pid,$r['version'],$r['journal_sha256'],$all,'Synthetic historical sale close');$next=$l->create(['start'=>'2026-07-01','end'=>'2026-12-31'],'Synthetic next short period',true);
foreach(array_keys($sales) as $item)$r=$carry($item,$next);
check(!$r['issues']&&$r['calculation']['annual_result_cents']===0,'historical sale remains zero in second dated successor');
check(!isset((new BalancePeriodExport($db,$actor))->build($next)['files']['anlagenverkaeufe.csv']),'historical sales excluded from current-year sales CSV');
$db->beginTransaction();try{
    $db->prepare("UPDATE accounting_positions SET description='Changed historical sale' WHERE club_id='sale-carry' AND entry_id=?")->execute([$sales['gain'][0]]);
    check((bool)$s->preview($r['period'],'depreciation','gain:'.$next)['issues'],'changed historical sale original invalidates carried proof');
    check((bool)$l->report($next)['issues'],'changed historical sale prevents subsequent closing');
}finally{$db->rollBack();}
$l->close($next,$r['version'],$r['journal_sha256'],$all,'Synthetic second closing');
foreach($old as $table=>$rows)check(MySqlFixture::rows($db,$table)===$rows,'no rewrite of historical '.$table);
check(!MySqlFixture::foreignKeyErrors($db),'historical sale chain tenant references intact');
echo "$n historical sale carry checks passed plus $base reused checks; synthetic MySQL only\n";
