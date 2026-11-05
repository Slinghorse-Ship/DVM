<?php declare(strict_types=1);
require __DIR__.'/balance-sources.php';
use Kulturhus\Verein\{BalanceInvoiceClassification as C,BalanceSourceHistory};
use Kulturhus\Verein\Tests\MySqlFixture;
$entry=$make('226','50000','expense','2026-02-01');
$f->updatePositions('entry',$entry,(int)$f->entry($entry)['version'],[
 ['description'=>'Synthetic materials','amount'=>'119','tax_rate'=>'19','sphere'=>'4','account'=>'50000','input_tax'=>'deductible','booking_key'=>'9'],
 ['description'=>'Synthetic resale goods','amount'=>'107','tax_rate'=>'7','sphere'=>'3','account'=>'50000','input_tax'=>'deductible','booking_key'=>'8']],'Synthetic invoice facts');
$raw=$f->positions('entry',$entry);$oldProposal=$sources->preview(2026,'invoice',$entry,$vatOptions);
$newOptions=$vatOptions+['invoice_classification'=>['version'=>1,'uses'=>[1=>'material',2=>'goods']]];
$state=MySqlFixture::snapshot($db);$p=$sources->preview(2026,'invoice',$entry,$newOptions);
check(!$p['issues'],'classified invoice proposal: '.implode(' ',$p['issues']));check(MySqlFixture::snapshot($db)===$state,'classification preview is read only');
$amounts=[];foreach($p['vouchers'][0]['lines'] as $line)$amounts[$line['account']]=($amounts[$line['account']]??0)+$line['debit_cents'];
check($amounts['src_code_51300']===10000&&$amounts['src_code_53000']===10000&&$amounts['src_input_vat']===2600,'two uses and rates narrow independently with VAT once');
check($p['snapshot']['positions']===$oldProposal['snapshot']['positions']&&$p['hash']!==$oldProposal['hash'],'new proof retains original positions and has distinct approval hash');
denied(fn()=>$ledger->importSource(2026,$ledger->report(2026)['version'],'invoice',$entry,$newOptions,$oldProposal['hash'],true),'new classification cannot reuse old approval');
$originalIds=$import('invoice',$entry,$vatOptions,2026);$originalBinding=$sources->binding('invoice',$entry);$journal=MySqlFixture::rows($db,'balance_entries');
check($originalBinding['source_hash']===$oldProposal['hash'],'legacy invoice import retains exact original hash');
check(C::view(2026,$oldProposal['snapshot'],[],null,true)['request']===null,'legacy UI does not silently revise imported invoice');
$correction=$sources->correctionPreview(2026,'invoice',$entry,$newOptions);check(!$correction['issues'],'explicit classification correction preview');
$ledger->correctSource(2026,$ledger->report(2026)['version'],'invoice',$entry,$newOptions,$correction['hash'],'Synthetic factual use correction',true);
$binding=$sources->binding('invoice',$entry);check($binding['options']===$newOptions&&count((new BalanceSourceHistory($db,'admin'))->checked($binding))===2,'corrected binding retains both revisions');
foreach($journal as $row){$now=$db->prepare('SELECT * FROM balance_entries WHERE club_id=? AND id=?');$now->execute([$row['club_id'],$row['id']]);check($now->fetch()===$row,'existing journal row unchanged');}
check($raw===$f->positions('entry',$entry)&&!$sources->reviewYear(2026)['issues'],'original invoice intact and sources reconcile after correction');
$tax['input_tax_eligibility']='no';$db->prepare("UPDATE club_settings SET payload=? WHERE club_id='a' AND section='tax'")->execute([json_encode($tax)]);
$gross=$make('119','51000','expense','2026-02-01');$opts=$vatOptions+['invoice_classification'=>['version'=>1,'uses'=>[]]];
$gp=$sources->preview(2026,'invoice',$gross,$opts);check(!$gp['issues']&&$gp['vouchers'][0]['lines'][0]['debit_cents']===11900&&!isset($gp['accounts']['src_input_vat'])&&isset($gp['accounts']['src_code_51290']),'non-deductible source maps automatically at full gross cost');
(new \Kulturhus\Verein\ExpenseReimbursements($db,'admin'))->createForEntry($gross,'mara','member',keyId());$opts['private_paid_on']='2026-02-01';
$gp=$sources->preview(2026,'invoice',$gross,$opts);check(!$gp['issues']&&$gp['snapshot']['claim']['contact_id']==='mara'&&$gp['accounts'][$gp['snapshot']['settlement_account']]['group']==='member_payables','private payer remains a member liability');
require_once __DIR__.'/../src/Web/View.php';$_SESSION=['actor'=>'admin','csrf'=>'synthetic'];$_SERVER['REQUEST_METHOD']='GET';$_POST=[];
$render=static function(PDO $db,array $query):string{$_GET=$query;ob_start();require __DIR__.'/../views/Finance/Balance/balance-ledger.php';return ob_get_clean();};
$html=$render($db,['balance_year'=>'2026','balance_source'=>'invoice:'.$gross,'private_paid_on'=>'2026-02-01']);
check(str_contains($html,'name="classify_purchases" value="1"')&&!str_contains($html,'name="purchase_uses['),'new known-use invoice UI needs no question');
$html=$render($db,['balance_year'=>'2026','balance_source'=>'invoice:'.$entry]);
check(str_contains($html,'Wofür wurde es gekauft?')&&str_contains($html,'value="goods" checked'),'saved mixed-use answers visible on invoice review');
check((bool)$sources->preview(2026,'invoice',$income,$opts)['issues'],'outgoing invoice rejects purchase classification');
echo "$n source purchase automation checks passed; isolated synthetic MySQL only\n";
