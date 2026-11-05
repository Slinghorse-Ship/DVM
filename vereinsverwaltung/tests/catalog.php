<?php declare(strict_types=1);
require __DIR__.'/../src/bootstrap.php';use Kulturhus\Verein\Database;use Kulturhus\Verein\Finance;use Kulturhus\Verein\TaxCatalog;
require_once __DIR__.'/mysql-bootstrap.php';
$tests=0;function check(bool $ok,string $label):void{global $tests;++$tests;if(!$ok)throw new RuntimeException($label);echo "PASS $label\n";}
function denied(callable $fn,string $label):void{try{$fn();}catch(DomainException){check(true,$label);return;}throw new RuntimeException('Not denied: '.$label);}
$catalog=TaxCatalog::accounts();$source=TaxCatalog::sourceData('skr42-2026.json');$keys=TaxCatalog::bookingKeys();
check(count($catalog)===2727&&$source['source_entries']===1556&&!$source['unassigned_lines'],'complete pinned extraction and range expansion');
check(count($keys)===292&&count(array_unique(array_column($keys,'code')))===291,'all key rows retained including historical variant 49');
check(count(TaxCatalog::sourceData('datev-keys-2026.json')['functions_without_key'])===10,'functions without keys not invented as keys');
foreach(['40400','40450','40500','40550','40600','40650','40700','40750'] as $code)check(isset($catalog[$code])&&$catalog[$code]['active'],'donation account '.$code);
check($catalog['10390']['range_start']==='10000'&&$catalog['10390']['range_end']==='10399','range expansion retains source boundaries');
foreach(['01430','47340','47370','01600'] as $code)check(!$catalog[$code]['active'],'ambiguous or reserved account blocked '.$code);
check(count(TaxCatalog::supportedKeys())===8,'validated domestic subset present');
check(array_map('strval',array_keys(TaxCatalog::commonInvoiceAccounts()))===TaxCatalog::commonInvoiceCodes()&&count(TaxCatalog::commonInvoiceAccounts())<count($catalog),'invoice form exposes concise active club account subset');
$db=\Kulturhus\Verein\Tests\MySqlFixture::connect(':memory:');Database::migrate($db);$db->exec("INSERT INTO clubs VALUES('a','A');INSERT INTO actors(id,club_id,role) VALUES('f','a','treasurer')");$f=new Finance($db,'f');$key=fn()=>bin2hex(random_bytes(16));
foreach(['01430','47340','47370','01600','01601'] as $code)denied(fn()=>$f->saveTaxAccount($code,'Test',true,0),'source restriction cannot be bypassed '.$code);
denied(fn()=>$f->saveTaxAccount('40400','Overwrite',true,0,true),'create account cannot overwrite builtin');
$f->saveTaxAccount('44000','Test label',false,0);check($f->taxAccounts()['44000']['function']==='AM','override keeps account function metadata');
$data=['kind'=>'expense','description'=>'BU Test','counterparty'=>'Test','category'=>'general','document_date'=>'2026-09-01','due_date'=>'2026-09-01'];$pos=['description'=>'Bürobedarf','amount'=>'119','sphere'=>'4','account'=>'68150','tax_rate'=>'19','booking_key'=>'401'];
$id=$f->createEntry($data+['positions'=>[$pos]],$key());$p=$f->positions('entry',$id)[0];check($p['booking_key']==='401'&&$p['tax_cents']===1900,'key and domestic tax stored');check(json_decode($p['booking_key_snapshot'],true)['local_rate']===19,'key meaning stored as versioned snapshot');
$f->updatePositions('entry',$id,1,[array_replace($pos,['booking_key'=>'9'])],'Legacy alias checked');check($f->positions('entry',$id)[0]['booking_key']==='9'&&json_decode($f->positionHistory('entry',$id)[0]['previous_positions'],true)[0]['booking_key']==='401','key edits preserve prior snapshot');
foreach([['booking_key'=>'490'],['booking_key'=>'abc'],['booking_key'=>'3'],['tax_rate'=>'7'],['account'=>'','sphere'=>'']] as $bad)denied(fn()=>$f->createEntry($data+['positions'=>[array_replace($pos,$bad)]],$key()),'invalid or unsupported key combination');
denied(fn()=>$f->createEntry(array_replace($data,['kind'=>'donation'])+['positions'=>[array_replace($pos,['tax_rate'=>'0','account'=>'40400'])]],$key()),'donation never uses income tax key');
denied(fn()=>$f->createEntry(array_replace($data,['kind'=>'income'])+['positions'=>[array_replace($pos,['booking_key'=>'3','account'=>'43000'])]],$key()),'automatic account cannot use separate key');
$raw=['position_description'=>['T'],'position_amount'=>['119'],'position_sphere'=>['4'],'position_account'=>['68150'],'position_tax'=>['19'],'position_booking_key'=>['401']];check(Finance::positionsFromForm($raw)[0]['booking_key']==='401','key carried through form');denied(fn()=>Finance::positionsFromForm(array_replace($raw,['position_booking_key'=>[]])),'misaligned key columns rejected');
echo "$tests catalog checks passed\n";
