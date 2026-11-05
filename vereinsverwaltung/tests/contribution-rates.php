<?php declare(strict_types=1);
require __DIR__.'/../src/bootstrap.php';
use Kulturhus\Verein\{Database,Settings,ContributionRates,MembershipApplications,PublicMembershipForm,Registry,Finance,ContributionReminders};
$n=0;function ok(bool $v,string $s):void{global $n;if(!$v)throw new RuntimeException($s);++$n;echo "PASS $s\n";}
function denied(callable $f,string $s):void{try{$f();}catch(DomainException){ok(true,$s);return;}throw new RuntimeException($s);}
$db=\Kulturhus\Verein\Tests\MySqlFixture::connect(':memory:');Database::migrate($db);
$db->exec("INSERT INTO clubs VALUES('a','A'),('b','B');INSERT INTO actors(id,club_id,role) VALUES('manager','a','members'),('reader','a','member');INSERT INTO actor_capabilities VALUES('manager','local_finance')");
$db->exec("UPDATE actors SET is_superuser=1 WHERE id='manager'");
$settings=new Settings($db,'manager');$rates=ContributionRates::all($db,'a');
ok(count($settings->catalog('contribution'))===2&&$rates['single']['amount_cents']===4800&&$rates['family']['amount_cents']===7200,'existing rates available in catalog');
Database::migrate($db);ok(count($settings->catalog())===2,'migration is idempotent');
$intake=new MembershipApplications($db,'a','manager');$config=$intake->settings()['config'];$config['enabled']=true;$config['notice_text']='Jahresbeitrag (Einzelmitglied 48 €, Familie insgesamt 72 €).';$intake->saveSettings($config,0);
$row=array_column($settings->catalog(),null,'id')[$rates['single']['id']];$data=$row['data'];$data['amount']='60.00';
denied(fn()=>(new Settings($db,'reader'))->saveCatalog($row['id'],1,'contribution',$data),'ordinary member cannot change membership rates');
$settings->saveCatalog($row['id'],1,'contribution',$data);
ok(str_contains($intake->resolvedSettings()['config']['notice_text'],'Einzelmitglied 60,00 €'),'legacy consent notice resolves central amount');
ok($intake->settings()['version']===2,'rate changes invalidate old form consent version');
ok(ContributionRates::all($db,'b')['single']['amount_cents']===4800,'rate changes are club isolated');
$post=['csrf'=>'test','configuration_version'=>1,'request_key'=>bin2hex(random_bytes(16)),'first_name'=>'Test','last_name'=>'Member','email'=>'test@example.invalid','birthday'=>'1990-01-01','address'=>'Test 1','membership_type'=>'single','consent'=>'1'];
ok(!PublicMembershipForm::handle($intake,$post,'test',true)['success'],'stale public form rejected');
$post['configuration_version']=2;$state=PublicMembershipForm::handle($intake,$post,'test',true);ok($state['success'],'updated public form accepted');
ob_start();$membershipState=$state;$membershipState['success']=false;require __DIR__.'/../views/People/Members/application-fields.php';$html=ob_get_clean();ok(str_contains($html,'60,00 € jährlich')&&!str_contains($html,'48 € jährlich'),'public form renders central amount');
$confirmation=$db->query('SELECT mail_token FROM application_confirmations')->fetchColumn();$application=$intake->confirmEmail($confirmation);
$saved=json_decode($intake->application($application)['answers'],true);ok(str_contains(json_decode($intake->application($application)['consent_snapshot'],true)['text'],'Einzelmitglied 60,00 €'),'consent archives actual displayed amount');ok($saved['annual_fee_cents']===6000,'confirmed application snapshots current amount');
$snapshot=json_decode($db->query('SELECT snapshot FROM document_files')->fetchColumn(),true);ok(str_contains(json_encode($snapshot,JSON_UNESCAPED_UNICODE),'60,00 €'),'archived application PDF uses submitted amount');
$registry=new Registry($db,'manager');$contact=$registry->saveContact(null,0,'person','Payer','payer@example.invalid','');$registry->addMembership($contact,'','aktiv','2026-01-01');$finance=new Finance($db,'manager');
$entry=$finance->createAnnualContribution($contact,2026,'');ok($finance->entry($entry)['amount_cents']===6000,'new annual charge uses catalog amount');
$data['amount']='66.00';$settings->saveCatalog($row['id'],2,'contribution',$data);
ok($finance->createAnnualContribution($contact,2026,'')===$entry&&$finance->entry($entry)['amount_cents']===6000,'existing charge remains unchanged after rate edit');
ok(json_decode($intake->application($application)['answers'],true)['annual_fee_cents']===6000,'existing application remains unchanged');
ok($finance->entry($finance->createAnnualContribution($contact,2027,''))['amount_cents']===6600,'next new charge uses edited amount');
foreach([['active'=>false],['interval'=>'month'],['pricing'=>'age','tiers'=>[['from'=>0,'amount'=>'20']]],['tax_rate'=>'19'],['amount'=>'0']] as $bad)denied(fn()=>$settings->saveCatalog($row['id'],3,'contribution',array_replace($data,$bad)),'linked rate rejects incompatible configuration');
denied(fn()=>$settings->saveCatalog($row['id'],2,'contribution',$data),'concurrent stale rate edit rejected');
$familyRow=array_column($settings->catalog(),null,'id')[$rates['family']['id']];$familyData=$familyRow['data'];$familyData['amount']='90.00';$settings->saveCatalog($familyRow['id'],1,'contribution',$familyData);
$payer=$registry->saveContact(null,0,'person','Family payer','','');$child=$registry->saveContact(null,0,'person','Family child','','');foreach([$payer,$child] as $id)$registry->addMembership($id,'','aktiv','2026-01-01');
$registry->assignFamily($payer,'','new','Family');$family=$registry->family($payer);$registry->assignFamily($child,'',$family['id']);
$familyEntry=$finance->createAnnualContribution($payer,2026,$family['id']);ok($finance->entry($familyEntry)['amount_cents']===9000,'family amount comes from linked tariff');
ok($finance->createAnnualContribution($child,2026,$family['id'])===$familyEntry,'family members share one annual charge');
$preview=(new ContributionReminders($db,'manager'))->preview('2027-01-01');$candidates=array_column($preview['annual_candidates'],null,'contact_id');ok($candidates[$payer]['amount_cents']===9000&&!isset($candidates[$child]),'annual preview uses family rate once');
echo "$n contribution rate checks passed; no mail sent\n";

// Existing custom catalog entries survive a native MySQL migration restart.
$upgrade=\Kulturhus\Verein\Tests\MySqlFixture::connect();Database::migrate($upgrade);
$upgrade->exec("INSERT INTO clubs VALUES('old','Existing');INSERT INTO settings_catalog VALUES('old','custom','contribution','{}',1,1)");
Database::migrate($upgrade);Database::migrate($upgrade);
ok(ContributionRates::all($upgrade,'old')['family']['amount_cents']===7200,'existing club receives linked rates on upgrade');
ok($upgrade->query("SELECT payload FROM settings_catalog WHERE id='custom'")->fetchColumn()==='{}','migration preserves unrelated existing catalog entries');
echo "$n total contribution rate checks passed\n";
