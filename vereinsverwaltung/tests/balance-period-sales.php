<?php declare(strict_types=1);
require __DIR__.'/balance-period-sources.php';
use Kulturhus\Verein\{BalancePeriodLedger,BalancePeriodSources,BalancePeriodSale,BalancePeriodDisposalEvidence,AssetBooks,AssetSale,Finance,Documents,SettingsSchema,OutgoingInvoices,Database};
use Kulturhus\Verein\Tests\MySqlFixture;
$base=$n;$n=0;
$db->exec("INSERT INTO clubs VALUES('sales','Synthetic Dated Sales');INSERT INTO actors(id,club_id,role,is_superuser) VALUES('sales-admin','sales','treasurer',1),('sales-reader','sales','member',0)");
$f=new Finance($db,'sales-admin');$bank=$f->createAccount('Synthetic sales bank','offline','0','2025-07-01');
foreach(['club'=>['name'=>'Synthetic Association','street'=>'Testweg 1','postcode'=>'12345','city'=>'Musterstadt','phone'=>'+49 123 456789','email'=>'seller@example.org','register'=>'VR 12345 Musterstadt'],
    'tax'=>['number'=>'123/456/78901','accounting_basis'=>'balance','input_tax_eligibility'=>'no','depreciation_enabled'=>true,'fiscal_start'=>'2025-07-01'],
    'bank'=>['iban'=>'DE89370400440532013000','holder'=>'Synthetic Association','account_id'=>$bank]] as $section=>$fields)
    $db->prepare('INSERT INTO club_settings(club_id,section,version,payload,updated_at) VALUES(?,?,1,?,?)')->execute(['sales',$section,json_encode(array_replace(SettingsSchema::defaults($section),$fields)),gmdate('c')]);
$db->exec("INSERT INTO settings_roles(club_id,id,name,description,members_access,finance_access,inventory_access,settings_access,version) VALUES('sales','reader','Synthetic reader','','none','read','read',0,1);INSERT INTO settings_role_assignments VALUES('sales-reader','sales','reader',1)");
$l=new BalancePeriodLedger($db,'sales-admin');$s=new BalancePeriodSources($db,'sales-admin');$books=new AssetBooks($db,'sales-admin');$docs=new Documents($db,'sales-admin');$out=new OutgoingInvoices($db,'sales-admin');$evidence=new BalancePeriodDisposalEvidence($db,'sales-admin');
$period=['start'=>'2025-07-01','end'=>'2026-06-30'];$pid=$l->create($period,'Synthetic asset sales period',true);$l->setup($pid,1,[],'Synthetic zero opening',true);
$png=base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jRZkAAAAASUVORK5CYII=');
$import=function(string $type,string $id,array $options=[],?string $target=null)use($l,$s,$pid):array{
    $target??=$pid;$r=$l->report($target,0,false);$p=$s->preview($r['period'],$type,$id,$options);if($p['issues'])throw new RuntimeException(implode(' ',$p['issues']));
    $l->importSource($target,$r['version'],$type,$id,$options,$p['hash'],true);return $l->report($target);
};
$make=function(string $item,bool $deg=false)use($db,$f,$books,$docs,$png,$import):array{
    $purchase=$f->createEntry(['kind'=>'expense','description'=>'Synthetic '.$item,'amount'=>'1200,01','document_date'=>'2025-09-18','due_date'=>'2025-09-18','category'=>'general',
        'positions'=>[['description'=>'Synthetic equipment','amount'=>'1200,01','tax_rate'=>'0','sphere'=>'4','account'=>'06300','input_tax'=>'none']]],keyId());
    $docs->upload('purchase.png',$png,['title'=>'Synthetic purchase','kind'=>'invoice','scope'=>'finance','links'=>['entry:'.$purchase]],keyId());
    $db->prepare("INSERT INTO inventory_items(club_id,id,code,name,kind) VALUES('sales',?,?,?,'reusable')")->execute([$item,'INV-'.$item,'Synthetic '.$item]);
    $plan=['basis'=>'1200,01','residual'=>'0','months'=>'60','acquired_on'=>'2025-09-18','sphere'=>'4','account'=>'06300','reason'=>'Synthetic original'];
    if($deg)$plan+=['method'=>'degressive','degressive_rate'=>'30','degressive_movable'=>'1','degressive_same_values'=>'1','asset_reviewed'=>'1','degressive_switch'=>'1'];
    $books->save($item,0,$plan);$db->prepare('INSERT INTO invoice_position_items VALUES(?,?,?,?,?,?,?,?)')->execute(['sales',$purchase,1,$item,1,'Synthetic equipment','sales-admin',gmdate('c')]);
    $import('invoice',$purchase,['recognised_on'=>'2025-09-18','private_paid_on'=>'']);return $books->get($item)['data'];
};
$createSale=function(string $gross,string $rate,string $date='2026-01-15')use($out,$bank):array{
    $d=['domestic_confirmed'=>'1','seller_contact'=>'Fiktive Rechnungsstelle','buyer_name'=>'Synthetic Customer','buyer_street'=>'Testweg 2','buyer_postcode'=>'23456','buyer_city'=>'Musterstadt','buyer_email'=>'customer@example.invalid','buyer_reference'=>'SYN-1',
        'description'=>'Synthetic sale','note'=>'Synthetic only','vat_reason'=>$rate==='0'?'Kleinunternehmerregelung § 19 UStG':'','document_date'=>$date,'delivery_date'=>'2026-01-15','due_date'=>$date,'category'=>'general','contact_id'=>'','payment_account'=>$bank,
        'positions'=>[['description'=>'Synthetic equipment','quantity'=>'1','unit'=>'C62','unit_gross'=>$gross,'tax_rate'=>$rate,'sphere'=>'4','account'=>$rate==='0'?'41840':'44000']]];
    $timeLimit=(int)ini_get('max_execution_time');$id=$out->save('',0,$d,keyId());$out->review($id,1);$entry=$out->issue($id,2);
    check((int)ini_get('max_execution_time')===$timeLimit,'native invoice validation preserves enclosing workflow time budget');return [$id,$entry];
};
$proofs=[];$plans=[];$sales=[];$originals=[];$payments=[];
foreach([['gain19','1428','19',false,'48450','48550',110001],['loss19','1190','19',false,'68850','68950',110001],
    ['gain0','1200','0',false,'48490','48550',110001],['loss0','1000','0',false,'68890','68950',110001],
    ['deg','1100','0',true,'48490','48550',105001]] as [$item,$gross,$rate,$deg,$saleCode,$bookCode,$book]){
    $plan=$plans[$item]=$make($item,$deg);[$invoiceId,$entry]=$sales[$item]=$createSale($gross,$rate,$item==='gain0'?'2026-07-01':'2026-01-15');$issued=$out->get($invoiceId);$originals[$item]=$docs->file($issued['document_id'],1);$positions=$f->positions('entry',$entry);
    $candidate=array_values(array_filter($s->candidates($period)['invoices'],static fn($row)=>$row['id']===$entry));
    check(count($candidate)===1&&$candidate[0]['performance_date']==='2026-01-15','selection uses performance date even across year boundary '.$item);
    $form=['disposal_kind'=>'sale','disposal_date'=>'2026-01-15','disposal_reason'=>'Synthetic full sale','sale_entry'=>$entry,'sale_confirmed'=>'1'];
    $before=MySqlFixture::snapshot($db);$options=$proofs[$item]=$evidence->fromForm($plan,$form);$p=$s->preview($period,'depreciation',$item.':'.$pid,$options);
    check(!$p['issues'],'dated sale preview '.$item.': '.implode(' ',$p['issues']));
    check($p['snapshot']['sale_values']['book_cents']===$book&&$p['snapshot']['sale_values']['sale_code']===$saleCode,'actual period book and gain/loss code '.$item);
    check($p['snapshot']['sale_values']['book_code']===$bookCode,'book disposal code '.$item);
    if($deg)check(AssetSale::amounts($plan,'2026-01-15',110000,0)['book_cents']!==$book,'degressive sale must not reuse calendar calculation');
    $choices=(new BalancePeriodSale($db,'sales-admin'))->choices($period,$plan,$item);check(in_array($entry,array_column($choices,'entry_id'),true),'matching issued sale selectable '.$item);
    check($before===MySqlFixture::snapshot($db),'sale previews do not mutate originals or database '.$item);
    denied(fn()=>$evidence->fromForm($plan,array_replace($form,['sale_confirmed'=>''])),'explicit sale review required '.$item);
    denied(fn()=>$evidence->fromForm($plan,array_replace($form,['disposal_date'=>'2026-01-16'])),'delivery date immutable '.$item);
    $bad=$options;$bad['disposal']['gross_cents']++;check((bool)$s->preview($period,'depreciation',$item.':'.$pid,$bad)['issues'],'forged gross rejected '.$item);
    $before=MySqlFixture::snapshot($db);$r=$l->report($pid);
    denied(fn()=>(new BalancePeriodLedger($db,'sales-reader'))->importSource($pid,$r['version'],'depreciation',$item.':'.$pid,$options,$p['hash'],true),'read-only cannot post '.$item);
    denied(fn()=>$l->importSource($pid,$r['version'],'depreciation',$item.':'.$pid,$options,str_repeat('0',64),true),'stale review rejected '.$item);
    check(MySqlFixture::snapshot($db)===$before,'rejected sale leaves all tables unchanged '.$item);
    $r=$import('depreciation',$item.':'.$pid,$options);$assetHash=$s->binding('depreciation',$item.':'.$pid)['proposal']['hash'];
    check($assetHash===$p['hash'],'ownership anchor does not change source hash '.$item);
    check((bool)$s->preview($period,'depreciation',$item.':'.$pid)['issues'],'cannot silently remove confirmed sale '.$item);
    check((bool)(new \Kulturhus\Verein\BalanceSources($db,'sales-admin'))->preview(2026,'invoice',$entry,['recognised_on'=>'2026-01-15','private_paid_on'=>''])['issues'],'sale cannot enter calendar journal '.$item);
    $profit=$r['calculation']['annual_result_cents'];$r=$import('invoice',$entry,['recognised_on'=>'2026-01-15','private_paid_on'=>'']);
    check($r['calculation']['annual_result_cents']===$profit+$p['snapshot']['sale_values']['net_cents'],'sale recognised once as net proceeds '.$item);
    check($r['accounts']['src_code_'.$saleCode]['group']===$p['snapshot']['sale_values']['group'],'invoice derived gain/loss account '.$item);
    check($f->positions('entry',$entry)===$positions&&$docs->file($issued['document_id'],1)===$originals[$item],'issued PDF and position classification unchanged '.$item);
    $tx=$f->createTransaction(['account'=>$bank,'booked_on'=>'2026-01-20','direction'=>'income','amount'=>$gross,'counterparty'=>'Synthetic customer','description'=>'Synthetic sale paid'],keyId());
    $f->allocate($tx,1,$entry,(int)$f->entry($entry)['version'],$gross,keyId());$profit=$r['calculation']['annual_result_cents'];$r=$import('transaction',$tx);$payments[$item]=$tx;
    $recv=$s->binding('invoice',$entry)['proposal']['snapshot']['settlement_account'];
    check($r['calculation']['annual_result_cents']===$profit&&$r['calculation']['accounts'][$recv]['closing_cents']===0,'payment clears receivable without double revenue '.$item);
    check(!$r['issues']&&!$r['asset_schedule']['issues'],'sale reconciles to books '.$item);
}
$plan=$make('duplicate');check((bool)$s->preview($period,'depreciation','duplicate:'.$pid,$proofs['gain19'])['issues'],'one invoice cannot dispose two assets');
foreach(['{broken','{"delivery_date":null}','{"delivery_date":[]}'] as $damaged){
    $db->beginTransaction();try{
        $db->prepare("UPDATE outgoing_invoices SET payload=? WHERE club_id='sales' AND id=?")->execute([$damaged,$sales['gain19'][0]]);
        check(count($s->candidates($period)['invoices'])>0,'damaged original metadata cannot crash source selection');
        check(!in_array($sales['gain19'][1],array_column((new BalancePeriodSale($db,'sales-admin'))->choices($period,$plan,'duplicate'),'entry_id'),true),'damaged original cannot be selected for sale');
    }finally{$db->rollBack();}
}
$r=$import('depreciation','duplicate:'.$pid);
check((bool)(new BalancePeriodSources($db,'other'))->preview($period,'depreciation','gain19:'.$pid,$proofs['gain19'])['issues'],'foreign tenant cannot inspect sale');
// Changed original evidence invalidates the disposal, invoice and dependent payment.
$db->beginTransaction();try{
    $db->prepare("UPDATE accounting_positions SET sphere='3' WHERE club_id='sales' AND entry_id=?")->execute([$sales['gain19'][1]]);
    foreach([['depreciation','gain19:'.$pid,$proofs['gain19']],['invoice',$sales['gain19'][1],['recognised_on'=>'2026-01-15','private_paid_on'=>'']],['transaction',$payments['gain19'],[]]] as [$type,$id,$opt])
        check((bool)$s->preview($period,$type,$id,$opt)['issues'],'changed source blocks '.$type);
}finally{$db->rollBack();}
foreach(['UPDATE balance_period_asset_sales SET entry_id=entry_id','DELETE FROM balance_period_asset_sales'] as $sql){
    try{$db->exec($sql." WHERE club_id='sales'");throw new RuntimeException('Ownership mutation accepted');}catch(PDOException $ex){check($ex->getCode()==='45000','database preserves immutable sale ownership');}
}
$read=new BalancePeriodLedger($db,'sales-reader');check(!$read->report($pid)['issues'],'read-only role verifies sold assets');
check($l->report($pid)['calculation']['annual_result_cents']===-70005,'all sales result equals net proceeds minus disposed cost and unsold depreciation');
$oldHistory=$l->history($pid);$links=MySqlFixture::rows($db,'balance_period_asset_sales');$revised=$proofs['gain19'];$revised['disposal']['reason']='Synthetic corrected review wording';
$revise=function(string $type,string $id,array $options)use($l,$s,$pid,$period):void{
    $r=$l->report($pid,0,false);$old=$s->binding($type,$id);$p=$s->preview($period,$type,$id,$options);
    $l->correctSource($pid,$r['version'],$type,$id,$options,$p['hash'],true,$old['proposal']['hash'],'Synthetic checked source correction');
};
$revise('depreciation','gain19:'.$pid,$revised);
check((bool)$l->report($pid)['source_issues'],'disposal correction invalidates dependent invoice and bank reviews');
$revise('invoice',$sales['gain19'][1],['recognised_on'=>'2026-01-15','private_paid_on'=>'']);$revise('transaction',$payments['gain19'],[]);
$r=$l->report($pid);check(!$r['issues']&&$r['calculation']['annual_result_cents']===-70005,'renewed dependent reviews restore identical profit without duplicate proceeds');
check(array_slice($l->history($pid),0,count($oldHistory))===$oldHistory&&MySqlFixture::rows($db,'balance_period_asset_sales')===$links,'correction retains original events and sale ownership');
$_SESSION=['actor'=>'sales-admin','csrf'=>'synthetic'];$_SERVER['REQUEST_METHOD']='GET';$_POST=[];$_GET=['periods'=>'1','period_id'=>$pid,'period_source'=>'depreciation:gain19:'.$pid];ob_start();require __DIR__.'/../views/Finance/Balance/balance-ledger.php';$html=ob_get_clean();
check(str_contains($html,'Ganzer Gegenstand verkauft')&&str_contains($html,'Ausgestellte Verkaufsrechnung')&&str_contains($html,'1.100,01')&&str_contains($html,'Gewinn aus dem Verkauf')&&str_contains($html,'Verkaufsrechnung prüfen'),'sale review UI exposes invoice, book value and next action');
$dom=new DOMDocument();$dom->loadHTML('<meta charset="utf-8">'.$html,LIBXML_NOERROR|LIBXML_NOWARNING);$xpath=new DOMXPath($dom);
check($xpath->query('//details[@data-period-advanced and not(@open)]//input[@name="account_id"]')->length===1,'technical account fields collapsed under advanced bookings');
check($xpath->query('//details[@data-period-advanced and not(@open)]//input[@name="lines[0][debit]"]')->length===1,'manual debit and credit excluded from normal entry flow');
check($xpath->query('//select[@name="period_source" and @data-select-search]')->length===1,'source selection uses existing accessible search control');
check(str_contains($html,'1. Zeitraum auswählen')&&str_contains($html,'2. Belege prüfen')&&str_contains($html,'3. Abschluss bestätigen'),'three plain-language steps displayed');
check($xpath->query('//*[@data-period-source-stale and @hidden]')->length===1&&$xpath->query('//*[@data-period-source-result]')->length===1,'preview has separate stale-input notice and protected result');
file_put_contents(__DIR__.'/balance-period-sales-ui.html',$html);
$r=$l->report($pid);$all=['complete'=>true,'valuation'=>true,'cutoff'=>true,'tax'=>true];$l->close($pid,$r['version'],$r['journal_sha256'],$all,'Synthetic complete closing');
$bundle=(new \Kulturhus\Verein\BalancePeriodExport($db,'sales-admin'))->build($pid);
check(isset($bundle['files']['anlagenverkaeufe.csv'])&&str_contains($bundle['files']['anlagenverkaeufe.csv'],'105001'),'working papers retain actual degressive book value and sale evidence');
$history=$l->history($pid);$next=$l->create(['start'=>'2026-07-01','end'=>'2026-12-31'],'Synthetic short next period',true);
foreach([...array_keys($plans),'duplicate'] as $item)$r=$import('depreciation',$item.':'.$next,[],$next);
check(!$r['issues']&&!$r['asset_schedule']['issues'],'next short period preserves sold assets at zero');
check($r['calculation']['annual_result_cents']===-12000,'next period depreciates only unsold asset');
check($l->history($pid)===$history,'next period cannot rewrite previous sale history');
check(!isset((new \Kulturhus\Verein\BalancePeriodExport($db,'sales-admin'))->build($next)['files']['anlagenverkaeufe.csv']),'historical sales not repeated in next period export');
Database::migrate($db);check((int)$db->query('SELECT MAX(version) FROM schema_migrations')->fetchColumn()===Database::SCHEMA_VERSION,'sale schema migration is repeatable');
check((int)$db->query("SELECT COUNT(*) FROM outgoing_invoice_deliveries WHERE club_id='sales'")->fetchColumn()===0,'no real or test mails queued');
check(!MySqlFixture::foreignKeyErrors($db),'tenant foreign keys valid');
echo "$n dated sale checks passed plus $base reused period checks; synthetic MySQL only\n";
