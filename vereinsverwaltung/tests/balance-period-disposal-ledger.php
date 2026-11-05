<?php declare(strict_types=1);
require __DIR__.'/balance-period-sources.php';
use Kulturhus\Verein\{BalancePeriodLedger,BalancePeriodSources,BalancePeriodDisposalEvidence,AssetBooks,Finance,Documents,SettingsSchema,BalancePeriodExport};
use Kulturhus\Verein\Tests\MySqlFixture;
$base=$n;$n=0;
$db->exec("INSERT INTO clubs VALUES('disposals','Synthetic Dated Disposals');INSERT INTO actors(id,club_id,role,is_superuser) VALUES('disposal-admin','disposals','treasurer',1)");
$tax=array_replace(SettingsSchema::defaults('tax'),['accounting_basis'=>'balance','depreciation_enabled'=>true,'input_tax_eligibility'=>'no','fiscal_start'=>'2025-07-01']);
$db->prepare("INSERT INTO club_settings(club_id,section,payload,version,updated_at) VALUES('disposals','tax',?,1,?)")->execute([json_encode($tax),gmdate('c')]);
$l=new BalancePeriodLedger($db,'disposal-admin');$s=new BalancePeriodSources($db,'disposal-admin');$f=new Finance($db,'disposal-admin');$books=new AssetBooks($db,'disposal-admin');$docs=new Documents($db,'disposal-admin');$evidence=new BalancePeriodDisposalEvidence($db,'disposal-admin');
$period=['start'=>'2025-07-01','end'=>'2026-06-30'];$pid=$l->create($period,'Synthetic dated disposal',true);$l->setup($pid,1,[],'Synthetic zero opening',true);
$png=base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jRZkAAAAASUVORK5CYII=');
$make=function(string $item,string $code='06300',bool $deg=false)use($db,$f,$books,$docs,$png):array{
    $entry=$f->createEntry(['kind'=>'expense','description'=>'Synthetic '.$item,'amount'=>'1200,01','document_date'=>'2025-09-18','due_date'=>'2025-09-18','category'=>'general',
        'positions'=>[['description'=>'Synthetic asset','amount'=>'1200,01','tax_rate'=>'19','sphere'=>'1','account'=>$code,'booking_key'=>'','input_tax'=>'none']]],keyId());
    $invoice=$docs->upload('invoice.png',$png,['title'=>'Synthetic invoice '.$item,'kind'=>'invoice','scope'=>'finance','links'=>['entry:'.$entry]],keyId());
    $db->prepare("INSERT INTO inventory_items(club_id,id,code,name,kind) VALUES('disposals',?,?,?,'reusable')")->execute([$item,'INV-'.$item,'Synthetic '.$item]);
    \Kulturhus\Verein\DocumentCatalog::register($db,'disposals','item',$item);
    $input=['basis'=>'1200,01','residual'=>'0','acquired_on'=>'2025-09-18','months'=>'60','sphere'=>'1','account'=>$code,'reason'=>'Synthetic original'];
    if($deg)$input+=['method'=>'degressive','degressive_rate'=>'30','degressive_movable'=>'1','degressive_same_values'=>'1','asset_reviewed'=>'1','degressive_switch'=>'1'];
    $books->save($item,0,$input);$db->prepare('INSERT INTO invoice_position_items VALUES(?,?,?,?,?,?,?,?)')->execute(['disposals',$entry,1,$item,1,'Synthetic '.$item,'disposal-admin',gmdate('c')]);
    $proof=$docs->upload('disposal.png',$png,['title'=>'Synthetic disposal '.$item,'kind'=>'receipt','scope'=>'finance','links'=>['item:'.$item]],keyId());
    return ['entry'=>$entry,'invoice'=>$invoice,'proof'=>$proof,'plan'=>$books->get($item)['data']];
};
$import=function(string $type,string $id,array $options=[],?string $target=null)use($l,$s,$pid):array{$target??=$pid;$r=$l->report($target);$p=$s->preview($r['period'],$type,$id,$options);if($p['issues'])throw new RuntimeException(implode(' ',$p['issues']));$l->importSource($target,$r['version'],$type,$id,$options,$p['hash'],true);return $l->report($target);};
$fixtures=[];$opts=[];
foreach(['whole'=>'06300','part'=>'06300','software'=>'01350','deg'=>'06300'] as $item=>$code){
    $v=$fixtures[$item]=$make($item,$code,$item==='deg');$import('invoice',$v['entry'],['recognised_on'=>'2025-09-18','private_paid_on'=>'']);
    $form=['disposal_kind'=>$item==='part'?'partial_scrap':($item==='software'?'software_end':'scrap'),'disposal_date'=>'2026-01-15','disposal_reason'=>'Synthetic evidence','disposal_document'=>$v['proof'],
        'rights_ended'=>'1','part_cost'=>'300','part_residual'=>'0','part_document'=>$v['invoice'],'part_reason'=>'Synthetic documented cost','same_use_and_life'=>'1','part_confirmed'=>'1'];
    $opts[$item]=$evidence->fromForm($v['plan'],$form);$p=$s->preview($period,'depreciation',$item.':'.$pid,$opts[$item]);
    check(!$p['issues'],'dated disposal preview '.$item.': '.implode(' ',$p['issues']));
    check(BalancePeriodSources::formOptions('depreciation',['asset_options'=>json_encode($opts[$item])])===$opts[$item],'review form round trip '.$item);
    $choices=$evidence->choices($item,$v['entry']);
    check(array_column($choices['disposal_document'],'id')===[$v['proof']]&&count($choices['part_document'])===2,'evidence chooser contains only this asset and its acquisition '.$item);
}
$p=$s->preview($period,'depreciation','whole:'.$pid,$opts['whole']);$state=MySqlFixture::snapshot($db);$s->preview($period,'depreciation','whole:'.$pid,$opts['whole']);check(MySqlFixture::snapshot($db)===$state,'disposal preview writes nothing');
$r=$l->report($pid);denied(fn()=>$l->importSource($pid,$r['version'],'depreciation','whole:'.$pid,$opts['whole'],$p['hash'],false),'unconfirmed disposal rejected');
denied(fn()=>$l->importSource($pid,$r['version'],'depreciation','whole:'.$pid,$opts['whole'],str_repeat('f',64),true),'stale disposal hash rejected');
check(MySqlFixture::snapshot($db)===$state,'rejected disposal leaves all tables unchanged');
$bad=$opts['whole'];$bad['disposal']['document_id']=$fixtures['part']['proof'];check((bool)$s->preview($period,'depreciation','whole:'.$pid,$bad)['issues'],'wrong asset evidence rejected even with same bytes');
$bad=$opts['whole'];$bad['disposal']['date']='2025-06-30';check((bool)$s->preview($period,'depreciation','whole:'.$pid,$bad)['issues'],'outside-period disposal rejected');
$bad=$opts['software'];$bad['disposal']['rights_ended']=false;check((bool)$s->preview($period,'depreciation','software:'.$pid,$bad)['issues'],'unconfirmed software rights rejected');
check((bool)(new BalancePeriodSources($db,'other'))->preview($period,'depreciation','whole:'.$pid,$opts['whole'])['issues'],'foreign club cannot preview private evidence');
denied(fn()=>BalancePeriodSources::formOptions('depreciation',['asset_options'=>'broken']),'malformed form rejected');
denied(fn()=>BalancePeriodSources::formOptions('depreciation',['asset_options'=>'{"disposal":null}']),'null disposal form rejected');
$db->beginTransaction();try{
    $blocked=false;try{$db->prepare("UPDATE document_files SET bytes='tampered' WHERE club_id='disposals' AND document_id=?")->execute([$fixtures['whole']['proof']]);}catch(PDOException $e){$blocked=$e->getCode()==='45000';}
    check($blocked,'database rejects modification of immutable original bytes');
}finally{$db->rollBack();}
$bad=$opts['whole'];$bad['disposal']['sha256']=str_repeat('0',64);check((bool)$s->preview($period,'depreciation','whole:'.$pid,$bad)['issues'],'changed original checksum rejected');
// Ordinary depreciation can be corrected while open, preserving its original entries.
$r=$import('depreciation','whole:'.$pid);$old=$r['sources']['depreciation:whole:'.$pid]['proposal']['hash'];$originalEntries=$r['entries'];
$p=$s->preview($period,'depreciation','whole:'.$pid,$opts['whole']);$l->correctSource($pid,$r['version'],'depreciation','whole:'.$pid,$opts['whole'],$p['hash'],true,$old,'Synthetic disposal original reviewed');$r=$l->report($pid);
check(array_intersect_key($r['entries'],$originalEntries)===$originalEntries,'disposal correction retains every original booking');
check($r['asset_schedule']['items']['whole']['schedule']['book_closing_cents']===0&&$r['asset_schedule']['items']['whole']['schedule']['book_disposals_cents']===110001,'corrected asset derecognised at exact original cost');
foreach(['part','software','deg'] as $item)$r=$import('depreciation',$item.':'.$pid,$opts[$item]);
check(!$r['issues']&&!$r['asset_schedule']['issues'],'all asset movements reconcile against actual ledger');
$db->exec("INSERT INTO actors(id,club_id,role,is_superuser) VALUES('disposal-reader','disposals','member',0);INSERT INTO settings_roles(club_id,id,name,description,members_access,finance_access,inventory_access,settings_access,version) VALUES('disposals','asset-read','Synthetic finance reader','','none','read','read',0,1);INSERT INTO settings_role_assignments VALUES('disposal-reader','disposals','asset-read',1)");
$reader=new BalancePeriodLedger($db,'disposal-reader');check(!$reader->report($pid)['source_issues'],'read-only financial role can verify disposal without write permission');
denied(fn()=>$reader->importSource($pid,$r['version'],'depreciation','part:'.$pid,$opts['part'],str_repeat('0',64),true),'read-only role cannot book disposal');
check($r['asset_schedule']['items']['part']['schedule']['book_closing_cents']===75001,'partial retained component remains in assets');
check($r['calculation']['annual_result_cents']===-405003,'depreciation plus disposals never double expense original cost');
check($r['accounts']['src_period_disposal_68960']['skr_code']==='68960','software disposal uses own verified code');
check(!$docs->canPurgeReceipt($fixtures['whole']['proof']),'used disposal original cannot be permanently deleted');
$r=$l->report($pid);denied(fn()=>$import('depreciation','whole:'.$pid,$opts['whole']),'duplicate disposal rejected');
$_SESSION=['actor'=>'disposal-admin','csrf'=>'synthetic'];$_SERVER['REQUEST_METHOD']='GET';$_POST=[];$_GET=['periods'=>'1','period_id'=>$pid,'period_source'=>'depreciation:part:'.$pid];ob_start();require __DIR__.'/../views/Finance/Balance/balance-ledger.php';$html=ob_get_clean();
check(str_contains($html,'Restbuchwert des Abgangs')&&str_contains($html,'275,00')&&str_contains($html,'750,01'),'UI displays partial disposal and remaining book value');
check(str_contains($html,'Abgang prüfen')&&str_contains($html,'Teilkosten')&&str_contains($html,'Software-Lizenz endgültig beendet'),'UI exposes evidence-led movement options');
check(str_contains($html,'data-period-disposal')&&str_contains($html,'data-disposal-for="software_end"')&&str_contains($html,'data-disposal-for="partial_scrap"'),'UI separates software and component fields without changing server validation');
file_put_contents(__DIR__.'/balance-period-disposal-ui.html',$html);
$r=$l->report($pid);$all=['complete'=>true,'valuation'=>true,'cutoff'=>true,'tax'=>true];$l->close($pid,$r['version'],$r['journal_sha256'],$all,'Synthetic board review');
$bundle=(new BalancePeriodExport($db,'disposal-admin'))->build($pid);check(str_contains($bundle['files']['anlagenverzeichnis.csv'],'Abgänge Restbuchwert Cent')&&str_contains($bundle['files']['anlagenverzeichnis.csv'],'110001'),'asset export retains cost, accumulated and book disposal separately');
$history=$l->history($pid);$p2=$l->create(['start'=>'2026-07-01','end'=>'2026-12-31'],'Synthetic short following year',true);
foreach(array_keys($fixtures) as $item)$r=$import('depreciation',$item.':'.$p2,[],$p2);
check(!$r['issues']&&!$r['asset_schedule']['issues']&&$r['calculation']['annual_result_cents']===-9000,'next short year expenses only remaining partial component');
check($r['asset_schedule']['items']['whole']['schedule']['cost_closing_cents']===0&&$r['asset_schedule']['items']['deg']['schedule']['depreciation_cents']===0,'fully disposed assets cannot return or depreciate');
check((bool)$s->preview($r['period'],'depreciation','part:'.$p2,$opts['part'])['issues'],'old partial event cannot be entered as a new disposal');
$l->close($p2,$r['version'],$r['journal_sha256'],$all,'Synthetic following board review');check($l->history($pid)===$history,'following closing never rewrites historical proof');
check(!$reader->report($p2)['issues'],'read-only role can read closed disposal chain');
check(!MySqlFixture::foreignKeyErrors($db),'all references remain tenant-bound');
echo "$n dated disposal ledger checks passed plus $base reused period source checks; synthetic MySQL only\n";
