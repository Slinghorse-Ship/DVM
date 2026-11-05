<?php declare(strict_types=1);
/** Synthetic integration checks; memory-only database, no mail or network. */
require __DIR__.'/../src/bootstrap.php';
use Kulturhus\Verein\{Database,DonationForms,Finance,Settings,SettingsSchema,Documents,DocumentPreview};

$checks=0;
function check(bool $ok,string $label):void{global $checks;if(!$ok)throw new RuntimeException($label);++$checks;echo "PASS $label\n";}
function denied(callable $fn,string $label):void{try{$fn();}catch(DomainException){check(true,$label);return;}throw new RuntimeException('Not denied: '.$label);}
$key=static fn()=>bin2hex(random_bytes(16));
$db=\Kulturhus\Verein\Tests\MySqlFixture::connect(':memory:');Database::migrate($db);
$db->exec("INSERT INTO clubs VALUES('a','Synthetic A'),('b','Synthetic B'); INSERT INTO actors(id,club_id,role) VALUES('manager','a','members'),('writer','a','treasurer'),('reader','a','member'),('member','a','member'),('editor','a','editor'),('foreign-manager','b','members'),('foreign','b','treasurer')");
$admin=new Settings($db,'manager');
$role=$admin->saveRole('',0,['name'=>'Finance read only','description'=>'Synthetic test','members_access'=>'none','finance_access'=>'read','settings_access'=>false,'inventory_access'=>'none','loans_access'=>'none','documents_access'=>'none']);
$admin->assignRole('reader',$role,0);
$club=array_replace(SettingsSchema::defaults('club'),['name'=>'Musterverein A e. V.','street'=>'Musterstraße 1','address2'=>'Abteilung Kunst','postcode'=>'00000','city'=>'Musterort']);
$admin->save('club',$club,0);
(new Settings($db,'foreign-manager'))->save('club',array_replace($club,['name'=>'Musterverein B e. V.']),0);
$finance=new Finance($db,'writer');$foreignFinance=new Finance($db,'foreign');
$moneyData=['kind'=>'donation','description'=>'Synthetische Geldspende','counterparty'=>"Alex Muster\nTestweg 2\n00000 Testort",'category'=>'donations','amount'=>'125,50','document_date'=>'2026-08-01','due_date'=>'2026-08-01'];
$entry=$finance->createEntry($moneyData,$key());
$foreignEntry=$foreignFinance->createEntry(array_replace($moneyData,['counterparty'=>'Fremder Testspender']),$key());
$goodsData=['donor'=>'Robin Muster','designation'=>'Mustertisch','description'=>'Zwei Jahre alt, guter Zustand.','amount'=>'80,05','received_on'=>'2026-08-02','valuation'=>'Synthetischer Vergleichswert','origin'=>'private','evidence_present'=>true];
$goods=$finance->createGoods($goodsData,$key());$foreignGoods=$foreignFinance->createGoods($goodsData,$key());
$paidAccount=$finance->createAccount('Recorded donations','offline','0','2026-01-01');$finance->recordPayment($entry,1,$paidAccount,'2026-08-01');
$forms=new DonationForms($db,'writer');$readForms=new DonationForms($db,'reader');
$money=$forms->defaults('money',$entry);$inKind=$forms->defaults('goods',$goods);

foreach(['member','manager','editor','missing'] as $actor){
    $limited=new DonationForms($db,$actor);
    denied(fn()=>$limited->defaults('money',$entry),'finance defaults denied: '.$actor);
    denied(fn()=>$limited->build($money),'finance preview denied: '.$actor);
    denied(fn()=>$limited->save($money,$key()),'finance save denied: '.$actor);
}
check($money['issuer']==="Musterverein A e. V.\nMusterstraße 1\nAbteilung Kunst\n00000 Musterort",'issuer comes from own club settings without settings permission');
check($money['donor']===$moneyData['counterparty']&&$money['amount']==='125,50'&&$money['donated_on']==='2026-08-01','money defaults preserve source donor amount and date');
check($money['source_id']===$entry&&$money['source_version']==='2','money defaults bind source and version');
check($inKind['donor']==='Robin Muster'&&$inKind['amount']==='80,05'&&$inKind['goods_description']==="Mustertisch\nZwei Jahre alt, guter Zustand.",'goods defaults preserve description and value');
check($inKind['goods_origin']==='private'&&$inKind['goods_evidence']==='1','goods defaults preserve declared origin and evidence');
check(str_starts_with((new DonationForms($db,'foreign'))->defaults('money',$foreignEntry)['issuer'],'Musterverein B'),'foreign club uses its own issuer');
foreach([['money',$foreignEntry],['goods',$foreignGoods]] as [$kind,$source]){
    denied(fn()=>$forms->defaults($kind,$source),'cross-club defaults denied: '.$kind);
    denied(fn()=>$forms->build(array_replace($money,['kind'=>$kind,'source_id'=>$source])),'cross-club supplied source denied: '.$kind);
}
denied(fn()=>$forms->defaults('money',$goods),'wrong source kind cannot use goods id as money');
$income=$finance->createEntry(array_replace($moneyData,['kind'=>'income','category'=>'general']),$key());
denied(fn()=>$forms->defaults('money',$income),'ordinary income is not a donation source');
$cancelled=$finance->createEntry($moneyData,$key());$finance->cancel($cancelled,1);
denied(fn()=>$forms->defaults('money',$cancelled),'cancelled donation cannot be used');

$draft=$forms->build($money);
check($draft['kind']==='donation_form_draft'&&$draft['source_id']===$entry&&$draft['source_version']===2,'preview retains typed provenance');
check($draft['donation_form']['donated_on']==='01.08.2026'&&$draft['document_date']==='2026-08-01','German form date and canonical archive date agree');
check($draft['missing']===['Begünstigter Zweck','Finanzamt','Steuernummer','Bescheidart','Bescheiddatum','Aufwandsverzicht'],'missing tax data remains an explicit incomplete draft');
check($draft['donation_form']['notice_type']===''&&$draft['donation_form']['notice_date']===''&&$draft['donation_form']['signature_place_date']==='Musterort, '.date('d.m.Y'),'known place/current date filled while missing notice remains empty; no signature invented');
check($draft['original_sha256']===hash_file('sha256',__DIR__.'/../resources/donation-forms/money-original.pdf'),'original template checksum recorded');
check($readForms->build($money)===$draft,'finance read role may build the same preview');
$pdf=DocumentPreview::pdf($readForms->build($money));
check(str_starts_with($pdf,'%PDF-')&&strlen($pdf)>10000,'finance reader gets actual original-based PDF preview');
denied(fn()=>$readForms->save($money,$key()),'finance read role cannot archive a draft');
check((int)$db->query('SELECT COUNT(*) FROM documents')->fetchColumn()===0,'preview and denied saves produce no archive records');

foreach(['0','-1','1,001','1e3','1.234,56','1000000001','abc'] as $amount)denied(fn()=>$forms->build(array_replace($money,['amount'=>$amount])),'invalid amount rejected: '.$amount);
foreach(['2026-02-30','01.08.2026',date('Y-m-d',strtotime('+1 day')),''] as $date)denied(fn()=>$forms->build(array_replace($money,['donated_on'=>$date])),'invalid or future donated date rejected: '.$date);
denied(fn()=>$forms->build(array_replace($money,['notice_date'=>'2026-02-30'])),'invalid tax notice date rejected');
denied(fn()=>$forms->build(array_replace($money,['notice_type'=>'guessed'])),'unknown notice type rejected');
denied(fn()=>$forms->build(array_replace($money,['donor'=>['Alex']])),'non-text donor rejected');
denied(fn()=>$forms->build(array_replace($inKind,['goods_description'=>''])),'goods description is required');
foreach([
    '0,01'=>['0,01','X null Euro null - eins Cent X'],
    '10.05'=>['10,05','X eins - null Euro null - fünf Cent X'],
    '125,50'=>['125,50','X eins - zwei - fünf Euro fünf - null Cent X'],
    '1234567,89'=>['1234567,89','X eins - zwei - drei - vier - fünf - sechs - sieben Euro acht - neun Cent X'],
] as $amount=>[$canonical,$words]){
    $built=$forms->build(array_replace($money,['source_id'=>'','source_version'=>'0','amount'=>$amount,'amount_words'=>'Manipulated value']));
    check($built['donation_form']['amount']===$canonical&&$built['donation_form']['amount_words']===$words,'amount and generated words agree exactly: '.$amount);
}

$token=$key();$saved=$forms->save($money,$token);$docs=new Documents($db,'writer');$document=$docs->document($saved);$file=$docs->file($saved);
$snapshot=json_decode($file['snapshot'],true,512,JSON_THROW_ON_ERROR);
check($document['origin']==='generated'&&$document['kind']==='donation'&&$document['scopes']===['finance'],'saved original draft stays generated and finance-protected');
check(str_starts_with($file['bytes'],'%PDF-')&&$file['mime']==='application/pdf'&&hash('sha256',$file['bytes'])===$file['sha256'],'actual PDF bytes and checksum archived');
check($snapshot===$draft&&$file['created_by']==='writer','archive preserves complete snapshot and author');
check(count($document['links'])===1&&$document['links'][0]['source_type']==='entry'&&$document['links'][0]['source_id']===$entry,'money PDF assigned to its finance entry');
check(count($docs->history($saved))===1&&$docs->history($saved)[0]['action']==='generated','generation has an audit history entry');
check($forms->save($money,$token)===$saved&&(int)$db->query('SELECT COUNT(*) FROM document_files')->fetchColumn()===1,'retry token deduplicates archive and PDF revision');
denied(fn()=>$forms->save(array_replace($money,['donor'=>'Changed Testspender']),$token),'same retry token with changed data rejected');
check((new Documents($db,'reader'))->file($saved)['sha256']===$file['sha256'],'finance read role may read the protected archived draft');
denied(fn()=>(new Documents($db,'member'))->file($saved),'ordinary member cannot read donation draft');
denied(fn()=>(new Documents($db,'manager'))->file($saved),'settings manager without finance cannot read donation draft');
denied(fn()=>(new Documents($db,'foreign'))->file($saved),'foreign club cannot read donation draft');
$savedGoods=$forms->save($inKind,$key());$goodsDocument=$docs->document($savedGoods);$goodsFile=$docs->file($savedGoods);$goodsSnapshot=json_decode($goodsFile['snapshot'],true,512,JSON_THROW_ON_ERROR);
check(count($goodsDocument['links'])===1&&$goodsDocument['links'][0]['source_type']==='goods'&&$goodsDocument['links'][0]['source_id']===$goods,'goods PDF assigned to its goods donation');
check($goodsSnapshot['donation_form']['goods_evidence']===true&&$goodsSnapshot['original_sha256']===hash_file('sha256',__DIR__.'/../resources/donation-forms/goods-original.pdf'),'goods snapshot retains evidence and correct original hash');
$unlinked=array_replace($money,['source_id'=>'','source_version'=>'0']);$standalone=$forms->save($unlinked,$key());
check($docs->document($standalone)['links']===[]&&$docs->document($standalone)['scopes']===['finance'],'manual draft remains finance protected without invented source');
$longDonor=trim(str_repeat('Äi ',95));$longDraft=$forms->save(array_replace($unlinked,['donor'=>$longDonor]),$key());$longSnapshot=json_decode($docs->file($longDraft)['snapshot'],true,512,JSON_THROW_ON_ERROR);
check(strlen($docs->document($longDraft)['title'])<=300&&preg_match('//u',$docs->document($longDraft)['title'])===1,'long donor uses a bounded valid UTF-8 archive title');
check($longSnapshot['donation_form']['donor']===$longDonor,'short archive label never shortens donor in original PDF snapshot');

$finance->updatePositions('entry',$entry,2,[['description'=>'Changed synthetic description','amount'=>'125,50','tax_rate'=>'0']],'Synthetic version check');
denied(fn()=>$forms->build($money),'stale source version cannot produce a preview');
denied(fn()=>$forms->save($money,$key()),'stale source version cannot archive a PDF');
check($forms->defaults('money',$entry)['source_version']==='3','reopened defaults use current source version');
check($docs->file($saved)['snapshot']===$file['snapshot']&&$docs->file($saved)['sha256']===$file['sha256'],'later source changes cannot alter archived PDF or snapshot');
check((int)$db->query('SELECT COUNT(*) FROM documents')->fetchColumn()===4,'only intended drafts were archived');
check(\Kulturhus\Verein\Tests\MySqlFixture::integrity($db)==='ok'&&\Kulturhus\Verein\Tests\MySqlFixture::foreignKeyErrors($db)===[],'database integrity and foreign keys preserved');
echo "$checks donation form checks passed\n";
