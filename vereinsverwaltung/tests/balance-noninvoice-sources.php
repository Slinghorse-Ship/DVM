<?php declare(strict_types=1);
require __DIR__.'/../src/bootstrap.php';require_once __DIR__.'/mysql-bootstrap.php';
use Kulturhus\Verein\{BalanceLedger,BalanceSources,BalanceSourceHistory,Database,Finance,Settings,SettingsSchema,Registry,ContributionRates};
use Kulturhus\Verein\Tests\MySqlFixture;
$n=0;
function check(bool $ok,string $label):void{global $n;if(!$ok)throw new RuntimeException($label);++$n;echo "PASS $label\n";}
function denied(callable $f,string $label):void{try{$f();}catch(DomainException){check(true,$label);return;}throw new RuntimeException($label);}
function keyId():string{return bin2hex(random_bytes(16));}
$db=MySqlFixture::connect();Database::migrate($db);
$db->exec("INSERT INTO clubs VALUES('a','Synthetic balance'),('b','Other synthetic club');INSERT INTO actors(id,club_id,role,is_superuser) VALUES('admin','a','treasurer',1),('other','b','treasurer',1),('reader','a','member',0);INSERT INTO finance_accounts VALUES('bank','a','Synthetic bank','offline',0,'2025-01-01')");
$tax=SettingsSchema::defaults('tax');$tax['accounting_basis']='balance';$tax['input_tax_eligibility']='no';
$db->prepare("INSERT INTO club_settings(club_id,section,payload,version,updated_at) VALUES('a','tax',?,1,?)")->execute([json_encode($tax),gmdate('c')]);
putenv('VEREIN_BALANCE_WORKSPACE=1');$ledger=new BalanceLedger($db,'admin');$sources=new BalanceSources($db,'admin');$f=new Finance($db,'admin');
$settings=new Settings($db,'admin');$registry=new Registry($db,'admin');$ledger->create(2025);
$ledger->setup(2025,1,$ledger->report(2025)['accounts'],[],'equity','Synthetic zero opening',true);
$bank=array_key_first($sources->bankAccounts());
$import=function(string $type,string $id,array $options=[])use($sources,$ledger):array{
    $p=$sources->preview(2025,$type,$id,$options);if($p['issues'])throw new DomainException(implode(' ',$p['issues']));
    return $ledger->importSource(2025,$ledger->report(2025)['version'],$type,$id,$options,$p['hash'],true);
};
$payment=function(string $entry,string $amount,string $date='2025-02-01')use($f):string{
    $tx=$f->createTransaction(['account'=>'bank','booked_on'=>$date,'direction'=>'income','amount'=>$amount,'counterparty'=>'Synthetic payer','description'=>'Synthetic receipt'],keyId());
    $f->allocate($tx,1,$entry,(int)$f->entry($entry)['version'],$amount,keyId());return $tx;
};
$makeDonation=function(string $amount='100',string $code='')use($f):string{
    return $f->createEntry(['kind'=>'donation','description'=>'Synthetic cash gift','amount'=>$amount,'category'=>'donations','document_date'=>'2025-01-01','due_date'=>'2025-01-01',
        'positions'=>[['description'=>'Synthetic donation','amount'=>$amount,'tax_rate'=>'0','sphere'=>'1','account'=>$code,'booking_key'=>'']]],keyId());
};
$member=$registry->saveContact(null,0,'person','Synthetic Member','member@example.invalid','');$registry->addMembership($member,'','aktiv','2025-01-01');
$entry=$f->createAnnualContribution($member,2025,'','2025-01-02');$opts=['recognised_on'=>'2025-01-02','private_paid_on'=>''];
$p=$sources->preview(2025,'invoice',$entry,$opts);
check(!$p['issues'],'annual assessment accepted without a supplier PDF: '.implode(' ',$p['issues']));
check(isset($p['accounts']['src_code_40000'])&&$p['vouchers'][0]['lines'][1]['sphere']==='1','genuine annual contribution derives exact yearly 40000 and sphere 1');
check(!isset($p['accounts']['src_output_vat'])&&!isset($p['accounts']['src_input_vat']),'no invented VAT for annual contribution');
check($p['snapshot']['assessment']['year']===2025&&$p['snapshot']['assessment']['contribution_rate']['amount_cents']===4800,'historical assessment evidence retained');
$before=MySqlFixture::snapshot($db);$sources->preview(2025,'invoice',$entry,$opts);check($before===MySqlFixture::snapshot($db),'assessment preview never writes');
denied(fn()=>$import('invoice',$entry,['recognised_on'=>'2025-01-02','private_paid_on'=>'2025-01-03']),'contribution is not an expense reimbursement');
check((bool)$sources->preview(2026,'invoice',$entry,['recognised_on'=>'2026-01-02','private_paid_on'=>''])['issues'],'assessment cannot be recognised in arbitrary second year');
$import('invoice',$entry,$opts);$receivable=$sources->binding('invoice',$entry)['snapshot']['settlement_account'];
check($ledger->report(2025)['calculation']['annual_result_cents']===4800&&$ledger->report(2025)['calculation']['accounts'][$receivable]['amount_cents']===4800,'unpaid assessed contribution is a receivable, not bank cash');
denied(fn()=>$import('invoice',$entry,$opts),'assessment cannot post twice');
$rates=ContributionRates::all($db,'a');$row=array_column($settings->catalog(),null,'id')[$rates['single']['id']];$data=$row['data'];$data['amount']='60.00';$settings->saveCatalog($row['id'],$row['version'],'contribution',$data);
check($sources->preview(2025,'invoice',$entry,$opts)['hash']===$p['hash'],'changing current tariff does not rewrite old assessed revenue');
$tx=$payment($entry,'20');$import('transaction',$tx);$r=$ledger->report(2025)['calculation'];
check($r['annual_result_cents']===4800&&$r['accounts'][$receivable]['amount_cents']===2800&&$r['accounts'][$bank]['amount_cents']===2000,'partial contribution clears only amount received, no duplicate revenue');
$tx2=$payment($entry,'28','2025-02-02');$import('transaction',$tx2);check($ledger->report(2025)['calculation']['accounts'][$receivable]['amount_cents']===0,'second partial contribution clears assessment exactly');
$notes=$f->entry($entry)['notes'];$changed=json_decode($notes,true);$changed['payer']='missing';
$db->prepare('UPDATE finance_entries SET notes=? WHERE club_id=? AND id=?')->execute([json_encode($changed),'a',$entry]);
check((bool)$sources->reviewYear(2025)['issues'],'assessment corruption blocks year close');
$db->prepare('UPDATE finance_entries SET notes=? WHERE club_id=? AND id=?')->execute([$notes,'a',$entry]);
check(!$sources->reviewYear(2025)['issues'],'restored original assessment reconciles');
$foreign=(new BalanceSources($db,'other'))->preview(2025,'invoice',$entry,$opts);check((bool)$foreign['issues']&&!$foreign['snapshot'],'other club cannot read assessment');
denied(fn()=>(new BalanceSources($db,'reader'))->candidates(2025),'member role cannot inspect finance source list');

$donation=$makeDonation();
check(!in_array($donation,array_column($sources->candidates(2025),'id'),true),'unpaid donation promise is not a receivable candidate');
denied(fn()=>$import('invoice',$donation,$opts),'cash donation cannot be booked as an unpaid invoice');
check($ledger->report(2025)['calculation']['annual_result_cents']===4800,'unpaid donation promise does not create revenue');
$giftTx=$payment($donation,'30');$p=$sources->preview(2025,'transaction',$giftTx);
check((bool)$p['issues']&&isset($p['snapshot']['donations'][$donation]),'donation purpose requires factual review and remains visible in preview');
$giftOptions=['donation_use'=>'unrestricted'];
denied(fn()=>$import('transaction',$giftTx,['donation_use'=>'needs_review']),'restricted or unclear donation is not automatically expensed/released');
denied(fn()=>$import('transaction',$giftTx,['donation_use'=>'anything']),'unknown donation options rejected');
denied(fn()=>$import('transaction',$giftTx,['donation_use'=>'unrestricted','account'=>'equity']),'forged account overrides rejected');
$before=MySqlFixture::snapshot($db);$p=$sources->preview(2025,'transaction',$giftTx,$giftOptions);check(!$p['issues']&&MySqlFixture::snapshot($db)===$before,'cash donation preview is complete and read-only');
check(isset($p['accounts']['src_code_40400'])&&!array_filter($p['accounts'],fn($a)=>$a['group']==='receivables'),'received gift derives 40400 without creating donation debtor');
$import('transaction',$giftTx,$giftOptions);$r=$ledger->report(2025)['calculation'];
check($r['annual_result_cents']===7800&&$r['accounts'][$bank]['amount_cents']===7800,'only received donation portion recognised');
denied(fn()=>$import('transaction',$giftTx,$giftOptions),'same donation payment cannot post twice');
$giftTx2=$payment($donation,'70','2025-02-02');$import('transaction',$giftTx2,$giftOptions);
check($ledger->report(2025)['calculation']['annual_result_cents']===14800,'remaining donation receipt recognised once on actual receipt');
check(!$sources->reviewYear(2025)['issues'],'contributions and fully received free cash donations reconcile');
check($sources->binding('transaction',$giftTx)['snapshot']['donations'][$donation]['entry']['amount_cents']===10000,'original donation evidence retained with payment');
check(count((new BalanceSourceHistory($db,'admin'))->checked($sources->binding('transaction',$giftTx)))===1,'donation source history intact');
denied(fn()=>$import('transaction',$tx,['donation_use'=>'unrestricted']),'ordinary payment does not accept donation-only answers');
$foreign=(new BalanceSources($db,'other'))->preview(2025,'transaction',$giftTx,$giftOptions);check((bool)$foreign['issues']&&!$foreign['snapshot'],'other club cannot read donation transaction');
denied(fn()=>BalanceSources::formOptions('transaction',['donation_use'=>['unrestricted']]),'array injection in factual answer rejected');
check(BalanceSources::formOptions('transaction',['donation_use'=>''])===[],'empty donation field preserves ordinary source format');
check(BalanceSources::formOptions('invoice',['recognised_on'=>'2025-01-02'])===$opts,'shared form parsing preserves invoice defaults');

// One changed donation has two independently recorded partial bank receipts.
$originalEntries=MySqlFixture::rows($db,'balance_entries');
$db->prepare('UPDATE finance_entries SET description=? WHERE club_id=? AND id=?')->execute(['Corrected synthetic gift','a',$donation]);
check((bool)$sources->reviewYear(2025)['issues'],'changed donation evidence prevents an unchecked annual close');
$cp=$sources->correctionPreview(2025,'transaction',$giftTx,$giftOptions);
check(!$cp['issues']&&count($cp['items'])===2,'donation correction includes both partial receipts');
$beforeCorrection=MySqlFixture::snapshot($db);$sources->correctionPreview(2025,'transaction',$giftTx,$giftOptions);
check(MySqlFixture::snapshot($db)===$beforeCorrection,'dependent correction preview does not mutate source or journal');
$failEntry=$sources->binding('transaction',$giftTx2)['entry_ids'][0];
$db->exec("CREATE TRIGGER synthetic_fail_gift BEFORE INSERT ON balance_entries FOR EACH ROW BEGIN IF NEW.reversal_of=".$db->quote($failEntry)." THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Synthetic second donation failure'; END IF; END");
try{
    try{$ledger->correctSource(2025,$ledger->report(2025)['version'],'transaction',$giftTx,$giftOptions,$cp['hash'],'Synthetic correction',true);throw new RuntimeException('Expected injected failure');}
    catch(PDOException $ex){check(str_contains($ex->getMessage(),'Synthetic second donation failure'),'failure during second partial receipt correction injected');}
}finally{$db->exec('DROP TRIGGER synthetic_fail_gift');}
check(MySqlFixture::snapshot($db)===$beforeCorrection,'all partial receipt reversals and histories roll back together');
$ledger->correctSource(2025,$ledger->report(2025)['version'],'transaction',$giftTx,$giftOptions,$cp['hash'],'Synthetic description corrected',true);
check($ledger->report(2025)['calculation']['annual_result_cents']===14800,'non-economic donation correction does not change profit');
check(!$sources->reviewYear(2025)['issues'],'all dependent donations reconciled after correction');
foreach([$giftTx,$giftTx2] as $id)check(count((new BalanceSourceHistory($db,'admin'))->checked($sources->binding('transaction',$id)))===2,'each partial receipt keeps original and corrected history');
$afterEntries=array_column(MySqlFixture::rows($db,'balance_entries'),null,'id');
check(!array_filter($originalEntries,fn($e)=>$afterEntries[$e['id']]!==$e),'original journal rows never overwritten by correction');

// Automatic inference must not overwrite deliberately different or taxable assignments.
$savedPos=$db->query("SELECT * FROM accounting_positions WHERE club_id='a' AND entry_id=".$db->quote($donation))->fetch();
$db->prepare("UPDATE accounting_positions SET sphere='4' WHERE club_id=? AND entry_id=?")->execute(['a',$donation]);
check((bool)$sources->preview(2025,'transaction',$giftTx,$giftOptions)['issues'],'commercial sphere cannot silently become an ideal-purpose gift');
$db->prepare('UPDATE accounting_positions SET sphere=? WHERE club_id=? AND entry_id=?')->execute([$savedPos['sphere'],'a',$donation]);
$db->prepare('UPDATE accounting_positions SET tax_rate=19,tax_cents=1597 WHERE club_id=? AND entry_id=?')->execute(['a',$donation]);
check((bool)$sources->preview(2025,'transaction',$giftTx,$giftOptions)['issues'],'tax-bearing payment cannot silently become a tax-free donation');
$db->prepare('UPDATE accounting_positions SET tax_rate=?,tax_cents=? WHERE club_id=? AND entry_id=?')->execute([$savedPos['tax_rate'],$savedPos['tax_cents'],'a',$donation]);
check(!$sources->reviewYear(2025)['issues'],'original source assignments remain valid after negative tests');

// Family assessments have one economic claim, not one per covered person.
$payer=$registry->saveContact(null,0,'person','Synthetic Family','','');$child=$registry->saveContact(null,0,'person','Synthetic Child','','');
foreach([$payer,$child] as $id)$registry->addMembership($id,'','aktiv','2025-01-01');
$registry->assignFamily($payer,'','new','Synthetic family');$family=$registry->family($payer);$registry->assignFamily($child,'',$family['id']);
$familyEntry=$f->createAnnualContribution($payer,2025,$family['id'],'2025-01-02');
check($f->createAnnualContribution($child,2025,$family['id'],'2025-01-02')===$familyEntry,'family shares one annual assessment');
$familyP=$sources->preview(2025,'invoice',$familyEntry,$opts);check(!$familyP['issues']&&count($familyP['snapshot']['covered_members'])===2,'family assessment proof covers both members');
$import('invoice',$familyEntry,$opts);
check($ledger->report(2025)['calculation']['annual_result_cents']===22000,'one family fee only, not a fee per child');
$future=$f->createAnnualContribution($member,2026,'','2025-12-20');
check(!in_array($future,array_column($sources->candidates(2025),'id'),true)&&in_array($future,array_column($sources->candidates(2026),'id'),true),'pre-issued annual fee belongs only to assessed year');

// Separate goods and waiver workflows may never disappear from the completeness check.
$goodsId=keyId();$db->prepare('INSERT INTO goods_donations(id,club_id,donor,description,received_on,value_cents,valuation,request_key) VALUES(?,?,?,?,?,?,?,?)')->execute([$goodsId,'a','Synthetic donor','Synthetic goods','2025-02-01',1000,'Synthetic valuation',keyId()]);
check((bool)array_filter($sources->reviewYear(2025)['issues'],fn($i)=>str_contains($i,'Sachspenden')),'non-cash donations cannot be silently omitted from year review');
$db->prepare('DELETE FROM goods_donations WHERE club_id=? AND id=?')->execute(['a',$goodsId]);

// Render the real source view: the factual question must precede a possible posting button.
require __DIR__.'/../src/Web/View.php';$_SESSION=['actor'=>'admin','csrf'=>'synthetic-csrf'];$_SERVER['REQUEST_METHOD']='GET';$_POST=[];
$unpaid=$makeDonation('40');$pendingTx=$payment($unpaid,'40');
foreach(['','needs_review','unrestricted'] as $answer){
    $_GET=['balance_year'=>'2025','balance_source'=>'transaction:'.$pendingTx,'donation_use'=>$answer];
    ob_start();require __DIR__.'/../views/Finance/Balance/balance-ledger.php';$html=ob_get_clean();
    check(str_contains($html,'Darf der Verein alle zugeordneten Geldspenden'),'donation question visible before a failed POST: '.$answer);
    check(str_contains($html,'>In Bilanz übernehmen</button>')===($answer==='unrestricted'),'posting action only available for reviewed unrestricted donation: '.$answer);
}
$import('transaction',$pendingTx,$giftOptions);
check(!$sources->reviewYear(2025)['issues'],'all synthetic source cases reconciled');
$closing=$ledger->close(2025,$ledger->report(2025)['version'],['complete'=>true,'valuation'=>true,'cutoff'=>true,'tax'=>true],'Synthetic source review');
check($ledger->report(2025)['state']==='closed'&&!$ledger->report(2025)['ready_for_tax_export'],'closed internal journal is not falsely labelled E-Bilanz-ready');
denied(fn()=>$import('transaction',$pendingTx,$giftOptions),'closed year cannot receive another source import');
$ledger->create(2026);$next=$ledger->report(2026);
check($next['calculation']['annual_result_cents']===0,'prior-year contribution and donation income not repeated in new year');
check($next['calculation']['accounts'][$sources->binding('invoice',$familyEntry)['snapshot']['settlement_account']]['amount_cents']===7200,'unpaid family contribution carried to following year');
echo "$n non-invoice source checks passed; synthetic MySQL only, no mail/bank/tax transmission\n";
