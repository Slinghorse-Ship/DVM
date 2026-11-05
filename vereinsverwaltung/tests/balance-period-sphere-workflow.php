<?php declare(strict_types=1);
require __DIR__.'/balance-period-sources.php';
use Kulturhus\Verein\{BalancePeriodLedger,BalancePeriodSources,BalancePeriodSphereEvidence,BalancePeriodTaxValues,BalancePeriodTaxAssetValues,BalancePeriodAssetAllocation,AssetBooks,Finance,Documents,DocumentCatalog,SettingsSchema,BalancePeriodExport};
use Kulturhus\Verein\Tests\MySqlFixture;
$base=$n;$n=0;
$db->exec("INSERT INTO clubs VALUES('sphere','Synthetic Sphere Transfers');INSERT INTO actors(id,club_id,role,is_superuser) VALUES('sphere-admin','sphere','treasurer',1),('sphere-member','sphere','member',0)");
$tax=array_replace(SettingsSchema::defaults('tax'),['accounting_basis'=>'balance','depreciation_enabled'=>true,'input_tax_eligibility'=>'no','fiscal_start'=>'2025-07-01']);
$db->prepare("INSERT INTO club_settings(club_id,section,payload,version,updated_at) VALUES('sphere','tax',?,1,?)")->execute([json_encode($tax),gmdate('c')]);
$l=new BalancePeriodLedger($db,'sphere-admin');$s=new BalancePeriodSources($db,'sphere-admin');$f=new Finance($db,'sphere-admin');$books=new AssetBooks($db,'sphere-admin');$docs=new Documents($db,'sphere-admin');$evidence=new BalancePeriodSphereEvidence($db,'sphere-admin');
$period=['start'=>'2025-07-01','end'=>'2026-06-30'];$pid=$l->create($period,'Synthetic sphere changes',true);$l->setup($pid,1,[],'Synthetic zero opening',true);
$png=base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jRZkAAAAASUVORK5CYII=');
$entry=$f->createEntry(['kind'=>'expense','description'=>'Synthetic equipment','amount'=>'1200','document_date'=>'2025-08-01','due_date'=>'2025-08-01','category'=>'general',
    'positions'=>[['description'=>'Synthetic equipment','amount'=>'1200','tax_rate'=>'19','sphere'=>'4','account'=>'06300','booking_key'=>'','input_tax'=>'none']]],keyId());
$docs->upload('invoice.png',$png,['title'=>'Synthetic invoice','kind'=>'invoice','scope'=>'finance','links'=>['entry:'.$entry]],keyId());
$db->exec("INSERT INTO inventory_items(club_id,id,code,name,kind) VALUES('sphere','device','INV-S','Synthetic equipment','reusable'),('sphere','other-device','INV-X','Synthetic other equipment','reusable')");
foreach(['device','other-device'] as $item)DocumentCatalog::register($db,'sphere','item',$item);
$books->save('device',0,['basis'=>'1200','residual'=>'0','acquired_on'=>'2025-08-01','months'=>'60','sphere'=>'4','account'=>'06300','reason'=>'Synthetic book and initial tax values equal']);
$plan=$books->get('device')['data'];$db->prepare('INSERT INTO invoice_position_items VALUES(?,?,?,?,?,?,?,?)')->execute(['sphere',$entry,1,'device',1,'Synthetic equipment','sphere-admin',gmdate('c')]);
$proof=$docs->upload('evidence.png',$png,['title'=>'Synthetic tax advice <script>test</script>','kind'=>'receipt','scope'=>'finance','links'=>['item:device']],keyId());
$wrong=$docs->upload('other.png',$png,['title'=>'Synthetic other evidence','kind'=>'receipt','scope'=>'finance','links'=>['item:other-device']],keyId());
$import=function(string $type,string $id,array $opts=[],?string $target=null)use($l,$s,$pid):array{$target??=$pid;$r=$l->report($target);$p=$s->preview($r['period'],$type,$id,$opts);if($p['issues'])throw new RuntimeException(implode(' ',$p['issues']));$l->importSource($target,$r['version'],$type,$id,$opts,$p['hash'],true);return $l->report($target);};
$import('invoice',$entry,['recognised_on'=>'2025-08-01','private_paid_on'=>'']);$source='device:'.$pid;
$sphereForm=['sphere_preview'=>'1','sphere_index'=>'new','sphere_on'=>'2026-01-01','sphere_to'=>'1','sphere_case'=>'exempt_book','sphere_value'=>'1100','sphere_months'=>'0','sphere_residual'=>'0','sphere_document'=>$proof,
    'sphere_reason'=>'Synthetic confirmed charitable use','sphere_charitable'=>'1','sphere_funds'=>'1','sphere_vat'=>'1','sphere_tax'=>'1','sphere_history'=>'1'];
$opts=$evidence->fromForm($sphereForm);$return=array_replace($sphereForm,['sphere_on'=>'2026-03-01','sphere_to'=>'4','sphere_case'=>'taxable_return','sphere_value'=>'1060','sphere_charitable'=>'']);
$opts=$evidence->fromForm($return,$opts['sphere_changes']);$p=$s->preview($period,'depreciation',$source,$opts);
check(!$p['issues'],'sphere proposal: '.implode(' ',$p['issues']));
check($p['snapshot']['sphere_change']['book']['depreciation_by_sphere']===[1=>4000,2=>0,3=>0,4=>18000],'source separates two actual-use expense spheres');
check(count($p['vouchers'])===2&&array_sum(array_map(static fn($v)=>array_sum(array_column($v['lines'],'debit_cents')),$p['vouchers']))===22000,'balanced journal total is unchanged');
check($p['snapshot']['sphere_change']['tax']['closing_cents']===98000,'tax clock returns continuously across two-month exemption');
check(BalancePeriodSources::formOptions('depreciation',['asset_options'=>json_encode($opts)])===$opts,'reviewed options survive form encoding');
$before=MySqlFixture::snapshot($db);$s->preview($period,'depreciation',$source,$opts);check(MySqlFixture::snapshot($db)===$before,'sphere preview changes no rows');
$r=$l->report($pid);
denied(fn()=>$l->importSource($pid,$r['version'],'depreciation',$source,$opts,$p['hash'],false),'unconfirmed source not adopted');
denied(fn()=>$l->importSource($pid,$r['version'],'depreciation',$source,$opts,str_repeat('0',64),true),'stale preview not adopted');
denied(fn()=>(new BalancePeriodLedger($db,'sphere-member'))->importSource($pid,$r['version'],'depreciation',$source,$opts,$p['hash'],true),'ordinary member cannot post changes');
check((bool)(new BalancePeriodSources($db,'other'))->preview($period,'depreciation',$source,$opts)['issues'],'cross-tenant preview rejected');
check(MySqlFixture::snapshot($db)===$before,'denied writes remain atomic no-ops');
$conflict=function(string $club,array $preparation)use($db):void{
    $db->prepare("INSERT INTO tax_filings(club_id,id,family,data_version,tax_year,environment,form_data,scope_snapshot,created_by,updated_by,created_at,updated_at) VALUES(?,?,'balance','synthetic',2025,'test',?,'{}','sphere-admin','sphere-admin',?,?)")
        ->execute([$club,keyId(),json_encode(['balance_preparation'=>$preparation]),gmdate('c'),gmdate('c')]);
};
foreach([
    ['review'=>['asset_tax_plans'=>['device'=>['months'=>36]]]],
    ['carry_forward'=>['asset_tax_plans'=>['device'=>['months'=>36]]]],
    ['profile'=>['tax_privileged'=>false]],
] as $i=>$existing){$db->beginTransaction();try{$conflict('sphere',$existing);check((bool)$s->preview($period,'depreciation',$source,$opts)['issues'],'known incompatible original tax plan/profile cannot be overridden '.$i);}finally{$db->rollBack();}}
$db->beginTransaction();try{$conflict('b',['profile'=>['tax_privileged'=>false]]);check(!$s->preview($period,'depreciation',$source,$opts)['issues'],'another tenant tax profile does not affect source');}finally{$db->rollBack();}
$db->beginTransaction();try{
    $payload=['values'=>['review'=>['asset_tax_plans'=>['device'=>['months'=>36]]]]];
    $db->prepare("INSERT INTO balance_period_tax_reviews(club_id,period_id,revision,payload,sha256) VALUES('sphere',?,1,?,?)")->execute([$pid,json_encode($payload),str_repeat('f',64)]);
    check((bool)$s->preview($period,'depreciation',$source,$opts)['issues'],'separate stored tax-review plan cannot be ignored');
}finally{$db->rollBack();}
$bad=$opts;$bad['sphere_changes'][0]['document_id']=$wrong;check((bool)$s->preview($period,'depreciation',$source,$bad)['issues'],'identical bytes linked to different asset cannot be evidence');
$bad=$opts;$bad['sphere_changes'][0]['revision']=2;check((bool)$s->preview($period,'depreciation',$source,$bad)['issues'],'wrong original revision rejected');
$bad=$opts;$bad['sphere_changes'][0]['sha256']=str_repeat('f',64);check((bool)$s->preview($period,'depreciation',$source,$bad)['issues'],'changed evidence checksum rejected');
denied(fn()=>$evidence->fromForm(array_replace($sphereForm,['sphere_index'=>'99']),$opts['sphere_changes']),'invalid edit cannot append or overwrite another change');
$edited=$evidence->fromForm(array_replace($sphereForm,['sphere_index'=>'0','sphere_reason'=>'Synthetic corrected wording']),$opts['sphere_changes']);
check(count($edited['sphere_changes'])===2&&$edited['sphere_changes'][1]===$opts['sphere_changes'][1],'editing one event retains other current-period events');
$removed=$evidence->fromForm(['sphere_preview'=>'1','sphere_index'=>'1','sphere_action'=>'remove'],$opts['sphere_changes']);
check($removed['sphere_changes']===[$opts['sphere_changes'][0]],'removing draft event preserves other event for reviewed correction');
$noValue=array_replace($sphereForm,['sphere_case'=>'exempt_to_exempt','sphere_to'=>'3']);unset($noValue['sphere_value'],$noValue['sphere_months'],$noValue['sphere_residual']);
$noValue=$evidence->fromForm($noValue)['sphere_changes'][0];check($noValue['value_cents']===0&&$noValue['remaining_months']===0&&$noValue['residual_cents']===0,'irrelevant fields need no manual zero entry');
check((bool)$s->preview($period,'depreciation',$source,['sphere_changes'=>[['on'=>[]]]])['issues'],'malformed values produce review error, not PHP failure');
$r=$import('depreciation',$source);$old=$r['sources']['depreciation:'.$source]['proposal']['hash'];$entries=$r['entries'];
$p=$s->preview($period,'depreciation',$source,$opts);$l->correctSource($pid,$r['version'],'depreciation',$source,$opts,$p['hash'],true,$old,'Synthetic actual use evidenced');$r=$l->report($pid);
check(array_intersect_key($r['entries'],$entries)===$entries&&count($r['entries'])===count($entries)+3,'correction retains old booking, adds reversal and two correct postings');
check(!$r['issues']&&$r['calculation']['annual_result_cents']===-22000,'whole-club profit unchanged by sphere change');
check($books->get('device')['data']===$plan,'original inventory book plan untouched');
check(!$docs->canPurgeReceipt($proof),'recorded evidence is protected against permanent deletion');
denied(fn()=>$import('depreciation',$source,$opts),'same source cannot be posted twice');
$db->exec("INSERT INTO actors(id,club_id,role,is_superuser) VALUES('sphere-reader','sphere','member',0);INSERT INTO settings_roles(club_id,id,name,description,members_access,finance_access,inventory_access,settings_access,version) VALUES('sphere','read-sphere','Synthetic read','','none','read','read',0,1);INSERT INTO settings_role_assignments VALUES('sphere-reader','sphere','read-sphere',1)");
check(!(new BalancePeriodLedger($db,'sphere-reader'))->report($pid)['issues'],'read-only financial role can verify immutable proofs');
$_SESSION=['actor'=>'sphere-admin','csrf'=>'synthetic'];$_SERVER['REQUEST_METHOD']='GET';$_POST=[];
$render=static function(array $query)use($db):string{$_GET=$query;ob_start();try{require __DIR__.'/../views/Finance/Balance/balance-ledger.php';return ob_get_contents();}finally{ob_end_clean();}};
$query=['periods'=>'1','period_id'=>$pid,'period_source'=>'depreciation:'.$source];$html=$render($query);
check(str_contains($html,'Gegenstand künftig anders nutzen')&&str_contains($html,'Weiteren Wechsel erfassen')&&str_contains($html,'Buchwerte und Steuerwerte vergleichen'),'UI shows optional transfer and understandable value comparison');
check(!str_contains($html,'<script>test</script>')&&str_contains($html,'&lt;script&gt;test&lt;/script&gt;'),'evidence label is escaped');
$invalidHtml=$render($query+array_replace($sphereForm,['sphere_index'=>'0','sphere_vat'=>'']));
check(str_contains($invalidHtml,'Mittelbindung, Umsatzsteuer')&&!str_contains($invalidHtml,'value="balance-period-source-correct"'),'unchecked review blocks adoption');
check(!preg_match('/name="sphere_vat"[^>]*checked/',$invalidHtml),'unchecked review stays unchecked after validation failure');
$r=$l->report($pid);$all=['complete'=>true,'valuation'=>true,'cutoff'=>true,'tax'=>true];$l->close($pid,$r['version'],$r['journal_sha256'],$all,'Synthetic board checked transfer books');
$r=$l->report($pid);$bundle=(new BalancePeriodExport($db,'sphere-admin'))->build($pid);
check(isset($bundle['files']['anlagen-bereichswechsel.csv'],$bundle['files']['anlagen-bereichswerte.csv'])&&str_contains($bundle['files']['anlagen-bereichswechsel.csv'],$proof),'working papers include evidence-bound transfers and tax-value reconciliation');
check(!$bundle['snapshot']['tax_export_ready'],'workpaper is not an official tax filing');
denied(fn()=>BalancePeriodTaxValues::build($r,[]),'incomplete official capital/asset adapter stays blocked');
denied(fn()=>BalancePeriodTaxAssetValues::prepare($r,[]),'direct tax asset helper cannot discard transfer');
check((bool)BalancePeriodAssetAllocation::build($r,[],[],[])['issues'],'unassigned actual sphere change blocks area approval');
$history=$l->history($pid);$p2=$l->create(['start'=>'2026-07-01','end'=>'2026-12-31'],'Synthetic short following year',true);$r2=$import('depreciation','device:'.$p2,[],$p2);
$v=$r2['asset_schedule']['items']['device']['sphere_change'];
check(!$r2['issues']&&$v['changes']===[]&&count($v['history'])===2&&$v['tax']['opening_cents']===98000&&$v['tax']['closing_cents']===86000,'short successor retains both actual switches and correct tax/book carry');
check($v['tax']['contributions_cents']===0&&$v['tax']['withdrawals_cents']===0&&$v['book']['depreciation_by_sphere'][4]===12000,'successor does not replay movements or original allocation');
check($l->history($pid)===$history,'following period cannot rewrite closed predecessor');
$l->close($p2,$r2['version'],$r2['journal_sha256'],$all,'Synthetic next board review');
check(!(new BalancePeriodLedger($db,'sphere-reader'))->report($p2)['issues'],'reader verifies closed successor chain');
check(!MySqlFixture::foreignKeyErrors($db),'tenant/period references intact');
echo "$n sphere workflow checks passed plus $base reused sources; synthetic MySQL only\n";
