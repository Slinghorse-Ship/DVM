<?php declare(strict_types=1);
namespace Kulturhus\Verein { function date(string $format,?int $time=null):string{return \gmdate($format,$time??\strtotime('2028-01-15 UTC'));} }
namespace {
require __DIR__.'/balance-tax-filing.php';
use Kulturhus\Verein\{BalanceLedger,BalanceSources,BalanceTaxFiling,TaxFilings,AssetBooks,Documents,Finance,SettingsSchema};
use Kulturhus\Verein\Tests\MySqlFixture;
set_error_handler(static function($s,$m,$f,$l){throw new ErrorException($m,0,$s,$f,$l);});
$db->exec("INSERT INTO clubs VALUES('c','Synthetic separate tax assets'); INSERT INTO actors(id,club_id,role,is_superuser) VALUES('assetboard','c','treasurer',1),('assetmember','c','member',0),('assetfinance','c','treasurer',0)");
$db->prepare('INSERT INTO club_settings(club_id,section,version,payload,updated_at) VALUES(?,?,1,?,?)')->execute(['c','tax',json_encode(array_replace(SettingsSchema::defaults('tax'),['accounting_basis'=>'balance','input_tax_eligibility'=>'no','depreciation_enabled'=>true])),gmdate('c')]);
$ledger=new BalanceLedger($db,'assetboard');$sources=new BalanceSources($db,'assetboard');$books=new AssetBooks($db,'assetboard');$f=new Finance($db,'assetboard');
$scrapCase=defined('TAX_ASSET_SCRAP')?TAX_ASSET_SCRAP:null;$saleCase=defined('TAX_ASSET_SALE')?TAX_ASSET_SALE:null;
$software=defined('TAX_ASSET_SOFTWARE_END');$assetCode=$software?'01350':(defined('TAX_ASSET_ACCOUNT')?TAX_ASSET_ACCOUNT:'06300');$depCode=$software?'62000':'62200';
if($software&&!function_exists('euro')){function euro(int $v):string{return number_format($v/100,2,',','.').' €';}}
$withDamagedSoftwareProof=static function(string $doc,callable $attempt)use($db):void{
    // This fixture owns a separately allocated/preserved synthetic database only.
    // Simulate storage damage, then restore both bytes and native guard exactly.
    MySqlFixture::dsn($db);
    $blocked=false;
    try{$db->prepare("UPDATE document_files SET bytes=? WHERE club_id='c' AND document_id=? AND revision=1")->execute(['corrupt synthetic proof',$doc]);}
    catch(PDOException $e){if($e->getCode()!=='45000')throw $e;$blocked=true;}
    check($blocked,'native document guard rejects rewriting evidence');
    $definition=$db->query('SHOW CREATE TRIGGER document_files_no_update')->fetch()['SQL Original Statement'];
    $read=$db->prepare("SELECT bytes FROM document_files WHERE club_id='c' AND document_id=? AND revision=1");$read->execute([$doc]);$bytes=$read->fetchColumn();
    if(!is_string($bytes))throw new RuntimeException('Synthetic original missing');
    $db->exec('DROP TRIGGER document_files_no_update');
    try{
        $db->prepare("UPDATE document_files SET bytes=? WHERE club_id='c' AND document_id=? AND revision=1")->execute(['corrupt synthetic proof',$doc]);
        $attempt();
    }finally{
        try{$db->prepare("UPDATE document_files SET bytes=? WHERE club_id='c' AND document_id=? AND revision=1")->execute([$bytes,$doc]);}
        finally{$db->exec($definition);}
    }
    check($db->query('SHOW CREATE TRIGGER document_files_no_update')->fetch()['SQL Original Statement']===$definition,'native document immutability restored identically');
};
$mixed=defined('TAX_ASSET_MIXED');$factor=$mixed?2:1;
$disposalCase=$scrapCase||$saleCase;$zero=$scrapCase==='zero'||$saleCase==='zero';$acquired=$zero?'2025-01-02':'2025-12-02';
$ledger->create(2025);$r=$ledger->report(2025);$ledger->setup(2025,$r['version'],$r['accounts'],[],'equity','Synthetic opening',true);
$purchase=$f->createEntry(['kind'=>'expense','description'=>'Synthetic equipment purchase','amount'=>'120','document_date'=>$acquired,'due_date'=>'2025-12-31','category'=>'general',
    'positions'=>[['description'=>'Synthetic equipment','amount'=>'120','tax_rate'=>'0','sphere'=>'4','account'=>$assetCode,'input_tax'=>'none']]],bin2hex(random_bytes(16)));
$png=base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jRZkAAAAASUVORK5CYII=');
(new Documents($db,'assetboard'))->upload('synthetic.png',$png,['title'=>'Synthetic original','kind'=>'invoice','scope'=>'finance','links'=>['entry:'.$purchase]],bin2hex(random_bytes(16)));
$db->exec("INSERT INTO inventory_items(club_id,id,code,name,kind) VALUES('c','tax-item','SYN-TAX','Synthetic equipment','reusable')");
$books->save('tax-item',0,['basis'=>'120','residual'=>'0','months'=>'12','acquired_on'=>$acquired,'sphere'=>'4','account'=>$assetCode,'reason'=>'Synthetic commercial life']);
$db->prepare('INSERT INTO invoice_position_items VALUES(?,?,?,?,?,?,?,?)')->execute(['c',$purchase,1,'tax-item',1,'Synthetic equipment','assetboard',gmdate('c')]);
$import=static function(int $year,string $type,string $id,array $options=[])use($ledger,$sources):void{
    $p=$sources->preview($year,$type,$id,$options);if($p['issues'])throw new RuntimeException(implode(' ',$p['issues']));
    $ledger->importSource($year,$ledger->report($year)['version'],$type,$id,$options,$p['hash'],true);
};
$import(2025,'invoice',$purchase,['recognised_on'=>$acquired,'private_paid_on'=>'']);$books->recordYear('tax-item',1,2025);$import(2025,'depreciation','tax-item:2025');
if($mixed){
    $second=$f->createEntry(['kind'=>'expense','description'=>'Synthetic second equipment','amount'=>'120','document_date'=>$acquired,'due_date'=>'2025-12-31','category'=>'general',
        'positions'=>[['description'=>'Synthetic second equipment','amount'=>'120','tax_rate'=>'0','sphere'=>'4','account'=>'06300','input_tax'=>'none']]],bin2hex(random_bytes(16)));
    (new Documents($db,'assetboard'))->upload('synthetic-second.png',$png,['title'=>'Synthetic second original','kind'=>'invoice','scope'=>'finance','links'=>['entry:'.$second]],bin2hex(random_bytes(16)));
    $db->exec("INSERT INTO inventory_items(club_id,id,code,name,kind) VALUES('c','scrap-item','SYN-SCRAP','Synthetic second equipment','reusable')");
    $books->save('scrap-item',0,['basis'=>'120','residual'=>'0','months'=>'12','acquired_on'=>$acquired,'sphere'=>'4','account'=>'06300','reason'=>'Synthetic second life']);
    $db->prepare('INSERT INTO invoice_position_items VALUES(?,?,?,?,?,?,?,?)')->execute(['c',$second,1,'scrap-item',1,'Synthetic second equipment','assetboard',gmdate('c')]);
    $import(2025,'invoice',$second,['recognised_on'=>$acquired,'private_paid_on'=>'']);$books->recordYear('scrap-item',1,2025);$import(2025,'depreciation','scrap-item:2025');
}
$ledger->close(2025,$ledger->report(2025)['version'],['complete'=>true,'valuation'=>true,'cutoff'=>true,'tax'=>true],'Synthetic complete review');
$svc=new BalanceTaxFiling($db,'assetfinance');$bs=new BalanceTaxFiling($db,'assetboard');$tax=new TaxFilings($db,'assetfinance');
$report=$ledger->report(2025);$dep=array_key_first(array_filter($report['accounts'],fn($a)=>$a['skr_code']===$depCode));
$plan=['months'=>24,'depreciation_account'=>$dep,'reason'=>'Synthetic professionally reviewed tax life <script>not executable</script>','confirmed'=>true];
$id=$svc->create(2025);$unchanged=MySqlFixture::rows($db,'balance_years');$assetBefore=$books->get('tax-item');
$snapshot=MySqlFixture::snapshot($db);
denied(fn()=>(new BalanceTaxFiling($db,'assetmember'))->assetTaxPlan($id,1,'tax-item',$plan),'member cannot set tax asset plan');
denied(fn()=>(new BalanceTaxFiling($db,'other'))->assetTaxPlan($id,1,'tax-item',$plan),'other club cannot set tax asset plan');
denied(fn()=>$svc->assetTaxPlan($id,1,'missing',$plan),'unknown retained item rejected');
denied(fn()=>$svc->assetTaxPlan($id,1,'tax-item',array_replace($plan,['confirmed'=>false])),'professional confirmation required');
check(MySqlFixture::snapshot($db)===$snapshot,'rejected tax plans have no writes');
$svc->assetTaxPlan($id,1,'tax-item',$plan);$r=$tax->get($id);$b=$r['form_data']['balance_preparation'];
check(!$b['review']['complete']&&$b['profile']['tax_privileged']===null,'plan saves without unrelated profile fields');
check($b['review']['asset_tax_plans']['tax-item']===$plan,'reviewed tax plan retained');
denied(fn()=>$svc->assetTaxPlan($id,1,'tax-item',null),'stale plan deletion blocked');
$review=$b['review'];unset($review['year'],$review['journal_sha256']);$review['complete']=true;
if($mixed)$review['asset_tax_plans']['scrap-item']=$plan;
$profile['asset_values_unchanged']=true;$profile['asset_tax_values_reviewed']=true;
denied(fn()=>$svc->save($id,2,$profile,[],$review),'contradictory unchanged declaration rejected');
$profile['asset_values_unchanged']=false;$profile['asset_tax_values_reviewed']=false;
denied(fn()=>$svc->save($id,2,$profile,[],$review),'separate tax values require explicit overall review');
$profile['asset_tax_values_reviewed']=true;$settlement=$sources->binding('invoice',$purchase)['snapshot']['settlement_account'];
$mapping=['equity'=>'bs.eqLiab.equity.retainedEarnings',$settlement=>'bs.eqLiab.liab.trade.genOther'];
if($mixed)$mapping[$sources->binding('invoice',$second)['snapshot']['settlement_account']]='bs.eqLiab.liab.trade.genOther';
$svc->save($id,2,$profile,$mapping,$review);$v=$svc->assertCurrent($tax->get($id));
check($v['result_difference_cents']===$factor*($zero?6000:500)&&$v['asset_tax']['tax_schedule']['items']['tax-item']['book_closing_cents']===($zero?6000:11500),'persisted plan computes exact tax depreciation');
check($tax->validate($id,3)['valid'],'reviewed separate tax asset generates actual XML');$bs->approve($id,4,true);
$html=$render($id,'assetfinance');
check(str_contains($html,'name="profile[asset_tax_values_reviewed]"')&&!str_contains($html,'name="profile[asset_values_unchanged]"'),'UI asks only relevant separate-value confirmation');
$html=$render($id,'assetfinance',[],false,'Abweichende Abschreibung bearbeiten');
check(str_contains($html,'Steuerwerte berechnen')&&str_contains($html,'Steuerlich: 2 Jahre'),'UI exposes plan and computed values');
check(str_contains($html,'&lt;script&gt;not executable&lt;/script&gt;')&&!str_contains($html,'<script>not executable</script>'),'tax evidence escaped in UI');
check(str_contains($html,'name="tax_depreciation_account" value="'.e($dep).'"')&&!str_contains($html,'<select name="tax_depreciation_account"'),'unique depreciation account selected automatically');
$svc->assetTaxPlan($id,5,'tax-item',null);$r=$tax->get($id);
check($r['state']==='draft'&&$r['xml_data']===null&&!isset($r['form_data']['balance_preparation']['approval']),'removal invalidates XML validation and approval');
check(!$r['form_data']['balance_preparation']['review']['complete'],'removal requires fresh review');
$svc->assetTaxPlan($id,6,'tax-item',$plan);$svc->save($id,7,$profile,$mapping,$review);$tax->validate($id,8);$bs->approve($id,9,true);
check($tax->send($id,10,'dummy','123456','SEND-TEST',true,true)['state']==='sent_test','approved plan reaches offline send fixture only');
check(MySqlFixture::rows($db,'balance_years')===$unchanged&&$books->get('tax-item')===$assetBefore,'full tax workflow leaves original journal and asset plan unchanged');
$ledger->create(2026);$event=[];
if($scrapCase){
    $doc=(new Documents($db,'assetboard'))->upload('synthetic-scrap.png',$png,['title'=>'Synthetic complete scrapping proof','kind'=>'invoice','scope'=>'finance'],bin2hex(random_bytes(16)));
    $event=['disposal_kind'=>'scrap','disposal_date'=>$zero?'2026-03-15':'2026-06-15','disposal_document'=>$doc,'disposal_reason'=>'Synthetic complete destruction without proceeds','disposal_confirmed'=>'1'];
    if($software){
        $event=array_replace($event,['disposal_kind'=>'software_end','software_rights_ended'=>'1','disposal_reason'=>'Synthetic license contract ended: all rights expired, no compensation']);
        $before=MySqlFixture::snapshot($db);
        foreach(['','0',true,1,[]] as $invalid)denied(fn()=>$books->recordYear('tax-item',1,2026,array_replace($event,['software_rights_ended'=>$invalid])),'license rights require exact explicit confirmation');
        foreach(['disposal_kind'=>'scrap','disposal_confirmed'=>'0','disposal_document'=>str_repeat('0',32),'disposal_date'=>'2025-12-02'] as $field=>$invalid)
            denied(fn()=>$books->recordYear('tax-item',1,2026,array_replace($event,[$field=>$invalid])),'invalid software evidence rejected: '.$field);
        denied(fn()=>(new AssetBooks($db,'assetmember'))->recordYear('tax-item',1,2026,$event),'member cannot terminate a license');
        denied(fn()=>(new AssetBooks($db,'other'))->recordYear('tax-item',1,2026,$event),'other club cannot terminate a license');
        denied(fn()=>$books->recordYear('tax-item',2,2026,$event),'stale license plan blocked');
        check(MySqlFixture::snapshot($db)===$before,'rejected license endings leave all rows unchanged');
        $withDamagedSoftwareProof($doc,fn()=>denied(fn()=>$books->recordYear('tax-item',1,2026,$event),'damaged license original cannot be confirmed'));
        check(MySqlFixture::snapshot($db)===$before,'damaged original rollback preserves annual records and audit');
        $ui=(static function()use($db,$books,$event):string{
            $_SESSION=['actor'=>'assetboard','csrf'=>'synthetic'];$_POST=['action'=>'asset-book-year']+$event;
            $data=$books->get('tax-item')['data'];$year=2026;
            ob_start();echo '<section class="card fw-workflow" data-tax-guided><h1>Softwarelizenz abschließen</h1><form>';require __DIR__.'/../views/Finance/Assets/asset-disposal.php';echo '<button>Jahresstand 2026 feststellen</button></form></section>';
            $html=ob_get_clean();$_POST=[];return $html;
        })();
        check(str_contains($ui,'software_rights_ended')&&str_contains($ui,'Nur nicht mehr benutzt?'),'software UI requires rights and explains non-use');
        check(!str_contains($ui,'name="sale_entry"')&&!str_contains($ui,'value="scrap"'),'software UI does not offer tangible sale or scrapping');
        if(!$zero)file_put_contents(__DIR__.'/software-end-ui.html',$ui);
    }
}
if($saleCase){
    $bank=$f->createAccount('Synthetic sale settlement','offline','0','2026-01-01');
    foreach(['club'=>['name'=>'Synthetic Association','street'=>'Testweg 1','postcode'=>'12345','city'=>'Musterstadt','phone'=>'+49 123 456789','email'=>'seller@example.org','register'=>'VR 12345 Musterstadt'],
        'bank'=>['iban'=>'DE89370400440532013000','holder'=>'Synthetic Association','account_id'=>$bank]] as $section=>$fields)
        $db->prepare('INSERT INTO club_settings(club_id,section,version,payload,updated_at) VALUES(?,?,1,?,?)')->execute(['c',$section,json_encode(array_replace(SettingsSchema::defaults($section),$fields)),gmdate('c')]);
    $taxConfig=json_decode($db->query("SELECT payload FROM club_settings WHERE club_id='c' AND section='tax'")->fetchColumn(),true);
    $db->prepare("UPDATE club_settings SET payload=? WHERE club_id='c' AND section='tax'")->execute([json_encode(array_replace($taxConfig,['number'=>'123/456/78901']))]);
    $outgoing=new \Kulturhus\Verein\OutgoingInvoices($db,'assetboard');$soldOn=$zero?'2026-03-15':'2026-06-15';
    $invoice=$outgoing->save('',0,['domestic_confirmed'=>'1','seller_contact'=>'Fiktive Rechnungsstelle','buyer_name'=>'Synthetic Customer','buyer_street'=>'Testweg 2','buyer_postcode'=>'23456','buyer_city'=>'Musterstadt','buyer_email'=>'customer@example.invalid','buyer_reference'=>'SYN-1',
        'description'=>'Synthetic complete sale','note'=>'Synthetic only','vat_reason'=>'Kleinunternehmerregelung § 19 UStG','document_date'=>$soldOn,'delivery_date'=>$soldOn,'due_date'=>'2026-06-30',
        'category'=>'general','contact_id'=>'','payment_account'=>$bank,'positions'=>[['description'=>'Synthetic equipment','quantity'=>'1','unit'=>'C62','unit_gross'=>$zero?'30':'60','tax_rate'=>'0','sphere'=>'4','account'=>'41840']]],bin2hex(random_bytes(16)));
    $outgoing->review($invoice,1);$saleEntry=$outgoing->issue($invoice,2);$issued=$outgoing->get($invoice);
    $saleOriginal=(new Documents($db,'assetboard'))->file($issued['document_id'],1);
    $saleProof=json_decode($saleOriginal['snapshot'],true,512,JSON_THROW_ON_ERROR)['einvoice'];
    check($issued['data']['domestic_confirmed']===true&&$saleProof['format']==='PDF/A-3b + XRechnung CII'&&$saleProof['kosit']['state']==='valid'
        &&hash_equals($saleProof['xml_sha256'],hash('sha256',$outgoing->xml($invoice))),'sale uses the validated immutable PDF/XML original');
    $event=['disposal_kind'=>'sale','disposal_date'=>$soldOn,'disposal_reason'=>'Synthetic complete sale with reviewed different tax life','sale_entry'=>$saleEntry,'sale_confirmed'=>'1'];
}
$books->recordYear('tax-item',1,2026,$event);
if($software)denied(fn()=>$books->recordYear('tax-item',1,2026,$event),'complete software disposal cannot be duplicated');
if($saleCase){
    $import(2026,'invoice',$saleEntry,['recognised_on'=>$soldOn,'private_paid_on'=>'']);
    $receivable=$sources->binding('invoice',$saleEntry)['snapshot']['settlement_account'];
    $mapping[$receivable]='bs.ass.currAss.receiv.trade.other';
}
$proposal=$sources->preview(2026,'depreciation','tax-item:2026');
if($zero)check($proposal['vouchers']===[]&&isset($proposal['accounts'][$saleCase?'src_sale_book_68950':($software?'src_software_disposal':'src_asset_scrapping')]),'zero book disposal reserves tax expense account without fake voucher');
if($software){
    check(($proposal['snapshot']['disposal_accounts']??null)===['68960'=>'src_software_disposal'],'software rest value uses retained intangible account identity');
    check(!isset($proposal['accounts']['src_asset_scrapping'])&&$proposal['accounts']['src_software_disposal']['skr_code']==='68960','no tangible disposal account used for software');
    $withDamagedSoftwareProof($doc,fn()=>check((bool)$sources->preview(2026,'depreciation','tax-item:2026')['issues'],'damaged original also blocks journal import'));
    check($sources->preview(2026,'depreciation','tax-item:2026')['hash']===$proposal['hash'],'unchanged license evidence retains source hash');
}
if($saleCase)check(isset($proposal['accounts']['src_code_48490'],$proposal['accounts']['src_code_68890'],$proposal['accounts']['src_sale_book_48550'],$proposal['accounts']['src_sale_book_68950']),'both gain/loss account pairs reserved before closing');
$import(2026,'depreciation','tax-item:2026');
if($disposalCase)check($sources->preview(2026,'depreciation','tax-item:2026')['hash']===$proposal['hash'],'disposal reservation preserves imported source hash');
if($mixed){
    $doc=(new Documents($db,'assetboard'))->upload('synthetic-scrap.png',$png,['title'=>'Synthetic destruction proof','kind'=>'invoice','scope'=>'finance'],bin2hex(random_bytes(16)));
    $books->recordYear('scrap-item',1,2026,['disposal_kind'=>'scrap','disposal_date'=>$soldOn,'disposal_document'=>$doc,'disposal_reason'=>'Synthetic complete scrapping without proceeds','disposal_confirmed'=>'1']);
    $p=$sources->preview(2026,'depreciation','scrap-item:2026');$import(2026,'depreciation','scrap-item:2026');
    check($sources->preview(2026,'depreciation','scrap-item:2026')['hash']===$p['hash'],'mixed scrapping source stays identical after import');
    $frozen=(new \Kulturhus\Verein\BalanceAssetSchedule($db,'assetboard'))->build(2026);
    check($frozen['items']['tax-item']['disposal_accounts']['68950']==='src_sale_book_68950'&&$frozen['items']['scrap-item']['disposal_accounts']['68950']==='src_asset_scrapping','distinct same-SKR accounts retained from actual sources');
}
$ledger->close(2026,$ledger->report(2026)['version'],['complete'=>true,'valuation'=>true,'cutoff'=>true,'tax'=>true],'Synthetic next year review');
$next=$svc->create(2026);$initialProfile=$profile;$initialProfile['asset_values_unchanged']=true;$initialProfile['asset_tax_values_reviewed']=false;
$svc->save($next,1,$initialProfile,$mapping,['complete'=>false,'opening_adjustments'=>[],'current_adjustments'=>[]]);
$svc->carryForward($next,2,$id,true);$r=$tax->get($next);$b=$r['form_data']['balance_preparation'];
check($b['review']['asset_tax_plans']['tax-item']===$plan&&!$b['profile']['asset_values_unchanged']&&!$b['profile']['asset_tax_values_reviewed'],'carry retains plan and clears review');
denied(fn()=>$svc->assetTaxPlan($next,3,'tax-item',null),'carried plan cannot be deleted');
denied(fn()=>$svc->assetTaxPlan($next,3,'tax-item',array_replace($plan,['months'=>36])),'carried plan cannot be changed');
$review=$b['review'];unset($review['year'],$review['journal_sha256']);$review['complete']=true;
$erased=$review;$erased['asset_tax_plans']=[];denied(fn()=>$svc->save($next,3,$profile,$mapping,$erased),'generic review cannot erase carried plans');
$svc->save($next,3,$profile,$mapping,$review);$v=$svc->assertCurrent($tax->get($next));
check($v['result_difference_cents']===$factor*($disposalCase?($zero?-6000:-500):5000)&&$v['asset_tax']['generated_opening']===[],'following year uses carried opening once');
check($v['asset_tax']['tax_schedule']['items']['tax-item']['book_closing_cents']===($disposalCase?0:5500),'following year retained tax value exact');
check($tax->validate($next,4)['valid'],'carried separate tax values generate actual XML');
if($disposalCase){
    $item=$v['asset_tax']['tax_schedule']['items']['tax-item'];
    check($item['depreciation_cents']===($zero?1500:3000)&&$item['book_disposals_cents']===($zero?4500:8500),'tax scrapping exact separate depreciation and rest value');
    $html=$render($next,'assetfinance');check(str_contains($html,'Steuerlich ausgebuchter Restwert')&&str_contains($html,$zero?'45,00':'85,00'),'rendered actual filing shows tax rest-value writeoff');
    if($software){
        check(str_contains($html,'Lizenz beendet am'),'filing distinguishes license termination');
        check($v['tax']['accounts']['src_software_disposal']['amount_cents']===($zero?4500:8500),'tax rest value uses intangible account even if book value was zero');
    }
    if($mixed){
        check($v['tax']['accounts']['src_sale_book_68950']['amount_cents']===($zero?4500:8500)&&$v['tax']['accounts']['src_asset_scrapping']['amount_cents']===($zero?4500:8500),'both tax rest values posted only to their own same-SKR account');
        check(str_contains($html,'Verkauft am')&&str_contains($html,'Ausgemustert am'),'filing distinguishes both disposal kinds');
    }
    if($saleCase){
        $sale=$v['asset_tax']['items']['tax-item']['sale'];
        check($sale['book']['gain']===true&&$sale['tax']['gain']===false&&$sale['tax']['result_cents']===($zero?-1500:-2500),'carried sale switches book gain to tax loss exactly');
        check(str_contains($html,'Verkaufsergebnis: Buch')&&str_contains($html,$zero?'-15,00':'-25,00'),'filing shows separate sale results');
        check((new Documents($db,'assetboard'))->file($issued['document_id'],1)===$saleOriginal,'tax reclassification never rewrites issued sales PDF');
        check((int)$db->query('SELECT COUNT(*) FROM outgoing_invoice_deliveries')->fetchColumn()===0,'sale workflow never queues member mail');
    }
    $bs->approve($next,5,true);check($tax->send($next,6,'dummy','123456','SEND-TEST',true,true)['state']==='sent_test','scrapping filing reaches offline receipt fixture only');
    $prior=$ledger->report(2026);$priorReview=$tax->get($next)['form_data']['balance_preparation']['review'];
    $ledger->create(2027);$ledger->close(2027,$ledger->report(2027)['version'],['complete'=>true,'valuation'=>true,'cutoff'=>true,'tax'=>true],'Synthetic year after disposal');
    $after=$ledger->report(2027);$carry=\Kulturhus\Verein\BalanceTaxValues::carryForward($prior,$priorReview,$after);
    $checked=\Kulturhus\Verein\BalanceTaxValues::build($after,['year'=>2027,'journal_sha256'=>$after['closed_sha256'],'complete'=>true,
        'opening_adjustments'=>$carry['entry']?[$carry['entry']]:[],'current_adjustments'=>[],'asset_tax_plans'=>$carry['asset_tax_plans']]);
    check($checked['result_difference_cents']===0&&array_sum($checked['asset_tax']['tax_schedule']['totals'])===0,'following year does not resurrect disposed book or tax values');
    denied(fn()=>$books->recordYear('tax-item',1,2027),'no depreciation after complete disposal');
}
$beforeRestore=MySqlFixture::snapshot($db);$restored=MySqlFixture::restoreBackup($db);
check(MySqlFixture::snapshot($restored)===$beforeRestore,'MySQL restore preserves asset plans and exact reviewed drafts');
echo "$checks combined filing and separate tax asset checks passed; synthetic MySQL only\n";
}
