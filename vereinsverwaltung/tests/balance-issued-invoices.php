<?php declare(strict_types=1);
require __DIR__.'/../src/bootstrap.php';require_once __DIR__.'/mysql-bootstrap.php';
use Kulturhus\Verein\{BalanceLedger,BalanceSources,Database,Documents,Finance,OutgoingInvoices,SettingsSchema};
use Kulturhus\Verein\Tests\MySqlFixture;
set_error_handler(static function($s,$m,$f,$l){throw new ErrorException($m,0,$s,$f,$l);});
$n=0;function check(bool $ok,string $label):void{global $n;if(!$ok)throw new RuntimeException($label);++$n;echo "PASS $label\n";}
function denied(callable $fn,string $label):void{try{$fn();}catch(DomainException){check(true,$label);return;}throw new RuntimeException($label);}
function keyId():string{return bin2hex(random_bytes(16));}
function mutation(PDO $db,callable $change,callable $verify):void{$db->beginTransaction();try{$change();$verify();}finally{$db->rollBack();}}
putenv('VEREIN_BALANCE_WORKSPACE=1');
foreach([2025,2026] as $year){
 $db=MySqlFixture::connect();Database::migrate($db);
 $db->exec("INSERT INTO clubs VALUES('a','Synthetic issued invoice'),('b','Foreign');INSERT INTO actors(id,club_id,role,is_superuser) VALUES('admin','a','treasurer',1),('member','a','member',0),('foreign','b','treasurer',1)");
 $f=new Finance($db,'admin');$bank=$f->createAccount('Synthetic bank','offline','0',$year.'-01-01');
 foreach(['club'=>['name'=>'Synthetic Association','street'=>'Testweg 1','postcode'=>'12345','city'=>'Musterstadt','phone'=>'+49 123 456789','email'=>'seller@example.org','register'=>'VR 12345 Musterstadt'],
   'tax'=>['number'=>'123/456/78901','accounting_basis'=>'balance','input_tax_eligibility'=>'no'],
   'bank'=>['iban'=>'DE89370400440532013000','holder'=>'Synthetic Association','account_id'=>$bank]] as $section=>$fields)
   $db->prepare('INSERT INTO club_settings(club_id,section,version,payload,updated_at) VALUES(?,?,1,?,?)')->execute(['a',$section,json_encode(array_replace(SettingsSchema::defaults($section),$fields)),gmdate('c')]);
 $service=new OutgoingInvoices($db,'admin');$ledger=new BalanceLedger($db,'admin');$sources=new BalanceSources($db,'admin');$ledger->create($year);
 $report=$ledger->report($year);$ledger->setup($year,$report['version'],$report['accounts'],[],'equity','Synthetic opening without balances',true);
 $data=['domestic_confirmed'=>'1','seller_contact'=>'Fiktive Rechnungsstelle','buyer_name'=>'Synthetic Customer','buyer_street'=>'Testweg 2','buyer_postcode'=>'23456','buyer_city'=>'Musterstadt','buyer_email'=>'customer@example.invalid','buyer_reference'=>'SYN-1',
   'description'=>'Synthetic services','note'=>'Synthetic only','vat_reason'=>'Kleinunternehmerregelung § 19 UStG','document_date'=>$year.'-02-03','delivery_date'=>$year.'-02-01','due_date'=>$year.'-02-20',
   'category'=>'general','contact_id'=>'','payment_account'=>$bank,'positions'=>[
     ['description'=>'Synthetic standard','quantity'=>'1','unit'=>'C62','unit_gross'=>'119','tax_rate'=>'19','sphere'=>'4','account'=>'44000'],
     ['description'=>'Synthetic reduced','quantity'=>'1','unit'=>'C62','unit_gross'=>'107','tax_rate'=>'7','sphere'=>'4','account'=>'43000']]];
 $id=$service->save('',0,$data,keyId());$service->review($id,1);$entry=$service->issue($id,2);$issued=$service->get($id);$doc=$issued['document_id'];
 $options=['recognised_on'=>$data['delivery_date'],'private_paid_on'=>''];
 $preview=fn()=>$sources->preview($year,'invoice',$entry,$options);
 $candidate=array_values(array_filter($sources->candidates($year),static fn($c)=>$c['type']==='invoice'&&$c['id']===$entry))[0];
 check($candidate['date']===$data['delivery_date'],'source selector proposes the issued performance date');
 $p=$preview();check(!$p['issues'],'native issued PDF accepted: '.implode(' ',$p['issues']));
 check($p['snapshot']['issued_invoice']['id']===$id&&count($p['vouchers'])===1,'frozen issuance retained with single invoice posting');
 $original=(new Documents($db,'admin'))->file($doc,1);
 $before=MySqlFixture::snapshot($db);$preview();check($before===MySqlFixture::snapshot($db),'issued invoice preview has no side effects');
 check((bool)$sources->preview($year,'invoice',$entry,['recognised_on'=>$data['document_date'],'private_paid_on'=>''])['issues'],'invoice date cannot override known performance date');
 check((bool)(new BalanceSources($db,'foreign'))->preview($year,'invoice',$entry,$options)['issues'],'other tenant cannot read issued source');
 denied(fn()=>(new BalanceSources($db,'member'))->preview($year,'invoice',$entry,$options),'member cannot read issued source');
 $fails=function(string $label)use($preview){check((bool)$preview()['issues'],$label);};
 mutation($db,fn()=>$db->prepare("UPDATE outgoing_invoices SET state='reviewed' WHERE club_id='a' AND id=?")->execute([$id]),fn()=>$fails('draft/reviewed source is not an issued original'));
 mutation($db,fn()=>$db->prepare("UPDATE outgoing_invoices SET payload='{}' WHERE club_id='a' AND id=?")->execute([$id]),fn()=>$fails('changed issuance payload rejected'));
 mutation($db,fn()=>$db->prepare("DELETE FROM outgoing_invoice_history WHERE club_id='a' AND invoice_id=? AND action='issued'")->execute([$id]),fn()=>$fails('missing issuance history rejected'));
 foreach(['gross_cents=12000','tax_cents=1800','tax_rate=7',"description='Changed'"] as $change)
   mutation($db,fn()=>$db->prepare("UPDATE accounting_positions SET $change WHERE club_id='a' AND entry_id=? AND ordinal=1")->execute([$entry]),fn()=>$fails('issued monetary/description drift rejected'));
 foreach(['bytes','snapshot'] as $field){
   try{$db->prepare("UPDATE document_files SET $field=? WHERE club_id='a' AND document_id=?")->execute(['corrupt',$doc]);throw new RuntimeException('Immutable document unexpectedly changed');}
   catch(PDOException $e){check(str_contains($e->getMessage(),'Document versions are immutable'),'database prevents original '.$field.' mutation');}
 }
 mutation($db,fn()=>$db->prepare("UPDATE documents SET state='archived' WHERE club_id='a' AND id=?")->execute([$doc]),fn()=>$fails('archived original rejected'));
 mutation($db,fn()=>$db->prepare("DELETE FROM document_links WHERE club_id='a' AND source_type='entry' AND source_id=? AND document_id=?")->execute([$entry,$doc]),fn()=>$fails('missing original association rejected'));
 // A random generated PDF attached to an ordinary invoice must not unlock the adapter.
 $raw=$f->createEntry(['kind'=>'income','description'=>'Synthetic unrelated','amount'=>'119','document_date'=>$year.'-02-01','due_date'=>$year.'-02-20','category'=>'general','positions'=>[['description'=>'Synthetic unrelated','amount'=>'119','tax_rate'=>'19','sphere'=>'4','account'=>'44000']]],keyId());
 mutation($db,fn()=>$db->prepare("INSERT INTO document_links VALUES('a',?,'entry',?)")->execute([$doc,$raw]),function()use($sources,$year,$raw,$options){check((bool)$sources->preview($year,'invoice',$raw,$options)['issues'],'unrelated generated document is not an original');});
 $ledger->importSource($year,$ledger->report($year)['version'],'invoice',$entry,$options,$p['hash'],true);
 $r=$ledger->report($year);$receivable=$sources->binding('invoice',$entry)['snapshot']['settlement_account'];
 check($r['calculation']['accounts'][$receivable]['amount_cents']===22600&&$r['calculation']['annual_result_cents']===20000,'invoice creates exact net income and gross receivable');
 check($r['calculation']['accounts']['src_output_vat']['amount_cents']===2600,'both actual tax rates remain output VAT, never input tax');
 denied(fn()=>$ledger->importSource($year,$r['version'],'invoice',$entry,$options,$p['hash'],true),'issued invoice cannot be imported twice');
 $tx=$f->createTransaction(['account'=>$bank,'booked_on'=>$year.'-02-10','direction'=>'income','amount'=>'226','counterparty'=>'Synthetic Customer','description'=>'Synthetic payment'],keyId());
 $f->allocate($tx,1,$entry,(int)$f->entry($entry)['version'],'226',keyId());
 check($preview()['hash']===$p['hash'],'payment flags do not change issued source evidence');
 $payment=$sources->preview($year,'transaction',$tx);check(!$payment['issues'],'actual payment reconciles with generated original');
 $ledger->importSource($year,$ledger->report($year)['version'],'transaction',$tx,[],$payment['hash'],true);
 $r=$ledger->report($year);check($r['calculation']['accounts'][$receivable]['amount_cents']===0&&$r['calculation']['annual_result_cents']===20000,'payment clears receivable without duplicate revenue');
 check((new Documents($db,'admin'))->file($doc,1)===$original,'all imports preserve original PDF and metadata');
 // Credit handling remains explicit; never treat a generated credit as a supplier expense.
 $credit=$service->correct($id,3,'credit','10','Synthetic refund',$year.'-02-12',keyId());
 $fails('issued credit requires joint reconciliation before source finalisation');
 $ce=$service->get($credit)['entry_id'];check((bool)$sources->preview($year,'invoice',$ce,['recognised_on'=>$year.'-02-12','private_paid_on'=>''])['issues'],'credit cannot become an ordinary expense');
 check((int)$db->query('SELECT COUNT(*) FROM outgoing_invoice_deliveries')->fetchColumn()===0,'no mail is queued or sent by balance tests');
 check(!MySqlFixture::foreignKeyErrors($db),'issued invoice source references preserve tenant integrity');
}
echo "$n native outgoing invoice balance checks passed; synthetic MySQL only\n";
