<?php declare(strict_types=1);
require __DIR__.'/../src/bootstrap.php';require_once __DIR__.'/../src/Finance/Accounting/DatevExport.php';
use Kulturhus\Verein\{Database,Documents,Finance,InvoiceInbox,InvoicePositionReview,TaxCatalog,DatevExport};
$db=\Kulturhus\Verein\Tests\MySqlFixture::connect(':memory:');Database::migrate($db);$db->exec("INSERT INTO clubs VALUES('a','Synthetic A'),('b','Synthetic B');INSERT INTO actors(id,club_id,role,is_superuser) VALUES('admin','a','member',1),('foreign','b','member',1),('member','a','member',0);INSERT INTO contacts(id,club_id,kind,name) VALUES('firm','a','organisation','Synthetic supplier')");
$f=new Finance($db,'admin');$docs=new Documents($db,'admin');$inbox=new InvoiceInbox($db,'admin');$key=fn()=>bin2hex(random_bytes(16));$n=0;
function check(bool $ok,string $label):void{global $n;if(!$ok)throw new RuntimeException($label);$n++;echo "PASS $label\n";}function denied(callable $fn,string $label):void{try{$fn();}catch(DomainException){check(true,$label);return;}throw new RuntimeException($label);}
$upload=fn(string $date)=>$docs->upload('synthetic.xml','<Invoice xmlns="urn:synthetic:test"/>',['title'=>'Synthetic historic invoice','kind'=>'receipt','scope'=>'finance','document_date'=>$date,'links'=>[]],$key());
$bill=['description'=>'Synthetic historic purchase','counterparty'=>'Synthetic supplier','amount'=>'36,04','category'=>'inventory','supplier_contact'=>'firm','supplier_version'=>'1','use_invoice_positions'=>'1','positions_reviewed'=>'1','invoice_positions'=>[
 ['description'=>'Synthetic office material','quantity'=>'3','unit'=>'Stück','unit_price'=>'4,00','amount'=>'12,00','price_basis'=>'gross','tax_rate'=>'0','sphere'=>'','account'=>'','booking_key'=>'','input_tax'=>'none'],
 ['description'=>'Synthetic equipment','quantity'=>'2','unit'=>'Stück','unit_price'=>'12,02','amount'=>'24,04','price_basis'=>'gross','tax_rate'=>'0','sphere'=>'','account'=>'','booking_key'=>'','input_tax'=>'none'],
]];
$entries=[];
foreach(['2019-06-13','2022-04-04','2024-02-29','2025-07-22','2026-09-08'] as $date){
 $year=(int)substr($date,0,4);$data=$bill+['document_date'=>$date,'due_date'=>$date];
 if(TaxCatalog::hasYear($year)){foreach($data['invoice_positions'] as &$position){$position['sphere']='3';$position['account']='06300';}unset($position);}
 $doc=$upload($date);$original=$docs->file($doc);$request=$key();$entry=$inbox->create($doc,1,$data,$request,'new_item','SYN-'.$year);$entries[$year]=$entry;$saved=$f->entry($entry);$positions=$f->positions('entry',$entry);
 check($saved['document_date']===$date&&$saved['due_date']===$date,'original dates retained '.$year);
 check((int)$saved['amount_cents']===3604&&array_column($positions,'gross_cents')===[1200,2404],'exact amounts retained '.$year);
 check(array_column($positions,'catalog_year')===[TaxCatalog::hasYear($year)?$year:null,TaxCatalog::hasYear($year)?$year:null],'only actual available same-year reference '.$year);
 check($docs->file($doc)===$original&&$docs->document($doc)['document_date']===$date,'original bytes and year metadata retained '.$year);
 $details=InvoicePositionReview::details($db,'admin',$entry);check($details[0]['details']['quantity']==='3'&&$details[1]['details']['unit_price_cents']===1202,'invoice quantities and prices unchanged '.$year);
 check($inbox->create($doc,1,$data,$request,'new_item','SYN-'.$year)===$entry&&count($inbox->document($doc)['entry_links'])===1,'idempotent save with one original link '.$year);
 if(!TaxCatalog::hasYear($year)){$mapped=$data;$mapped['invoice_positions'][0]['sphere']='3';$mapped['invoice_positions'][0]['account']='06300';$mappedEntry=$inbox->create($upload($date),1,$mapped,$key(),'new_item','SYN-MAPPED-'.$year);$mappedPositions=$f->positions('entry',$mappedEntry);check($mappedPositions[0]['account_code']==='06300'&&(int)$mappedPositions[0]['catalog_year']===TaxCatalog::YEAR,'current SKR42 account can be assigned to historical invoice '.$year);}
}
check((int)$db->query('SELECT COUNT(*) FROM finance_entries')->fetchColumn()===8,'historical mapping tests add no duplicate financial records');
$date='2025-07-22';$bad=$bill+['document_date'=>$date,'due_date'=>$date];$bad['invoice_positions'][0]=array_replace($bad['invoice_positions'][0],['sphere'=>'3','account'=>'01430']);denied(fn()=>$inbox->create($upload($date),1,$bad,$key(),'new_item','SYN-BAD'),'2025 conflicting source account blocked');
$bad['invoice_positions'][0]=array_replace($bad['invoice_positions'][0],['account'=>'68150','booking_key'=>'9','tax_rate'=>'19','input_tax'=>'deductible']);
$catalog2025=TaxCatalog::supportedKeys(2025);$accounts2025=TaxCatalog::accounts(2025);
check(($catalog2025['9']['direction']??'')==='expense'&&($catalog2025['9']['rate']??'')==='19'&&($accounts2025['68150']['active']??false),'2025 uses its own verified BU table and account catalog');
foreach(['2022-02-29','2022-13-01','2022-1-01'] as $date)denied(fn()=>$inbox->create($upload('2022-01-01'),1,$bill+['document_date'=>$date,'due_date'=>'2022-12-31'],$key(),'new_item','SYN-BAD'),'invalid date rejected '.$date);
denied(fn()=>(new InvoiceInbox($db,'member'))->create($upload('2025-01-01'),1,$bill+['document_date'=>'2025-01-01','due_date'=>'2025-01-01'],$key(),'new_item','SYN-BAD'),'finance write required');
// A legacy 2026 snapshot stays evidence of the actual source used, even for an older invoice.
$legacy=$entries[2026];$db->prepare("UPDATE finance_entries SET document_date='2022-04-04' WHERE id=?")->execute([$legacy]);$old=$f->positions('entry',$legacy);$same=array_map(fn($p)=>['description'=>$p['description'].' reviewed','amount'=>number_format($p['gross_cents']/100,2,',',''),'tax_rate'=>(string)$p['tax_rate'],'sphere'=>$p['sphere'],'account'=>$p['account_code'],'booking_key'=>$p['booking_key'],'input_tax'=>$p['input_tax']],$old);
$f->updatePositions('entry',$legacy,1,$same,'Synthetic unchanged legacy snapshot review');check(array_column($f->positions('entry',$legacy),'catalog_year')===[2026,2026],'legacy source edition preserved without relabelling');
$same[0]['sphere']='4';$f->updatePositions('entry',$legacy,2,$same,'Synthetic new mapping');check($f->positions('entry',$legacy)[0]['sphere']==='4'&&(int)$f->positions('entry',$legacy)[0]['catalog_year']===TaxCatalog::YEAR,'new mapping into historical invoice uses current verified catalog');
$bank=$f->createAccount('Synthetic bank','offline','0','2025-01-01');$f->recordPayment($entries[2025],1,$bank,'2025-08-01');$datev=new DatevExport($db,'admin');$options=['from'=>'2025-01-01','to'=>'2025-12-31','advisor'=>'1001','client'=>'1','bank_codes'=>[$bank=>'18000']];$preview=$datev->preview($options);check(!$preview['errors']&&count($preview['rows'])===2,'verified 2025 ordinary no-input-tax export passes annual catalog');
$csv=$datev->download($options,$preview['hash']);check(str_contains($csv,'20250101')&&!str_contains($csv,'20260101'),'DATEV header uses actual export year');
$f->recordPayment($legacy,3,$bank,'2025-08-02');$mismatch=$datev->preview($options);check((bool)$mismatch['errors'],'2026 snapshot cannot silently enter 2025 export');
foreach([2019,2022,2024,2027] as $year)denied(fn()=>$datev->preview(array_replace($options,['from'=>$year.'-01-01','to'=>$year.'-12-31'])),'unverified export year rejected '.$year);
// Exercise the native migration restart with nonempty records and every current column.
$before=$db->query('SELECT * FROM accounting_positions ORDER BY id')->fetchAll();$beforeRevisions=$db->query('SELECT * FROM accounting_revisions ORDER BY id')->fetchAll();Database::migrate($db);
check($before===$db->query('SELECT * FROM accounting_positions ORDER BY id')->fetchAll(),'annual migration retains every rowid and column byte-for-byte');check($beforeRevisions===$db->query('SELECT * FROM accounting_revisions ORDER BY id')->fetchAll(),'annual migration leaves prior audit snapshots unchanged');
check(\Kulturhus\Verein\Tests\MySqlFixture::integrity($db)==='ok'&&!(\Kulturhus\Verein\Tests\MySqlFixture::foreignKeyErrors($db)[0]??false),'annual migration and invoice database integrity');
echo "$n historic invoice/accounting checks passed; synthetic only, no mail or live state\n";
