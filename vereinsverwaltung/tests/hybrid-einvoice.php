<?php declare(strict_types=1);
require __DIR__.'/../src/bootstrap.php';
require_once __DIR__.'/../src/Finance/Invoices/OutgoingEInvoice.php';
require_once __DIR__.'/../src/Finance/Invoices/CiiInvoice.php';
require_once __DIR__.'/../src/Finance/Invoices/HybridEInvoice.php';
require_once __DIR__.'/../src/Finance/Invoices/outgoing-hybrid-action.php';
use Kulturhus\Verein\{Database,Finance,OutgoingInvoices,OutgoingEInvoice,CiiInvoice,HybridEInvoice,SettingsSchema,Documents};

// Completely isolated database and synthetic parties. No dispatch/transport is called.
$db=\Kulturhus\Verein\Tests\MySqlFixture::connect(':memory:');Database::migrate($db);
$db->exec("INSERT INTO clubs VALUES('a','Synthetic'),('b','Other');INSERT INTO actors(id,club_id,role,is_superuser) VALUES('admin','a','member',1),('foreign','b','member',1),('member','a','member',0)");
$n=0;function check($condition,string $label):void{global $n;if(!$condition)throw new RuntimeException($label);++$n;echo "PASS $label\n";}function denied(callable $fn,string $label):void{try{$fn();}catch(DomainException){check(true,$label);return;}throw new RuntimeException($label);}
$key=fn()=>bin2hex(random_bytes(16));$finance=new Finance($db,'admin');$account=$finance->createAccount('Synthetic bank','offline','0','2026-01-01');
function e(string $s):string{return htmlspecialchars($s,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}function url(array $a):string{return '/?'.http_build_query($a);}function csrf():string{return '';}function hidden(string $key,string $value):string{return '<input type="hidden" name="'.e($key).'" value="'.e($value).'">';}
$view=function(string $entryId)use($db,$finance):string{$r=$finance->entry($entryId);$documents=new Documents($db,'admin');$_SESSION=['actor'=>'admin'];$outgoingReady=true;ob_start();try{require __DIR__.'/../views/Finance/Invoices/outgoing-hybrid-status.php';return ob_get_contents();}finally{ob_end_clean();}};
foreach(['club'=>['name'=>'Fiktiver Musterverein','street'=>'Musterweg 1','postcode'=>'12345','city'=>'Musterstadt','email'=>'seller@example.org','phone'=>'+49 123 456789','register'=>'VR 12345 Musterstadt'],'tax'=>['number'=>'123/456/78901'],'bank'=>['iban'=>'DE89370400440532013000','holder'=>'Fiktiver Musterverein','account_id'=>$account]] as $section=>$values)$db->prepare('INSERT INTO club_settings(club_id,section,version,payload,updated_at) VALUES(?,?,1,?,?)')->execute(['a',$section,json_encode(array_replace(SettingsSchema::defaults($section),$values)),gmdate('c')]);
$invoices=new OutgoingInvoices($db,'admin');$hybrid=new OutgoingEInvoice($db,'admin');
$data=['buyer_name'=>'Fiktive Kundengesellschaft','buyer_street'=>'Testweg 2','buyer_postcode'=>'23456','buyer_city'=>'Beispielstadt','buyer_email'=>'buyer@example.org','buyer_reference'=>'K-100','description'=>'Fiktive Vereinsleistung','note'=>'FIKTIV – Nur technische Formatprüfung','vat_reason'=>'','document_date'=>'2026-09-08','delivery_date'=>'2026-09-07','due_date'=>'2026-09-22','category'=>'general','contact_id'=>'','payment_account'=>$account,'positions'=>[['description'=>'Fiktive Raumnutzung & Technik','quantity'=>'3','unit'=>'DAY','unit_gross'=>'119','tax_rate'=>'19','sphere'=>'3','account'=>'']]];
require_once __DIR__.'/support/legacy-outgoing-invoice.php';
$issue=fn(array $values):array=>legacyOutgoingInvoice($db,'admin',$values);
[$id,$entry]=$issue($data);$source=$hybrid->hybridSource($entry,1);$confirmation=['domestic_country'=>'DE','source_hash'=>$source['source_hash']];$xml=CiiInvoice::build($source['invoice'],$confirmation,$source['original']['sha256']);$dom=new DOMDocument();$dom->loadXML($xml);$xp=new DOMXPath($dom);$value=fn($tag)=>$xp->evaluate('string(//*[local-name()="'.$tag.'"])');
check($value('BilledQuantity')==='3'&&$value('BasisQuantity')==='3'&&$value('ChargeAmount')==='300.00','three units preserve exact original net price basis');
check($value('GrandTotalAmount')==='357.00'&&$value('TaxTotalAmount')==='57.00'&&$value('DuePayableAmount')==='357.00','original net VAT gross and payable amounts agree');
check($value('PaymentReference')===$invoices->get($id)['number'],'original invoice number is bank transfer reference');
check(str_contains($xml,'&amp;')&&!str_contains($xml,'& Technik'),'descriptions safely XML escaped');
denied(fn()=>$hybrid->hybridSource($entry,2),'stale entry rejected');denied(fn()=>(new OutgoingEInvoice($db,'foreign'))->hybridSource($entry,1),'cross tenant rejected');denied(fn()=>(new OutgoingEInvoice($db,'member'))->hybridSource($entry,1),'member without finance write rejected');
$manual=$finance->createEntry(['kind'=>'income','description'=>'Legacy invoice','counterparty'=>'Example','category'=>'general','document_date'=>'2026-09-08','due_date'=>'2026-09-22','positions'=>[['description'=>'Legacy package','amount'=>'119','tax_rate'=>'19','sphere'=>'','account'=>'']]],$key());denied(fn()=>$hybrid->hybridSource($manual,1),'manual entry cannot claim arbitrary PDF as original');
$confirm=function(string $entryId)use($hybrid,$finance):array{return ['domestic_country'=>'DE','source_hash'=>$hybrid->hybridSource($entryId,(int)$finance->entry($entryId)['version'])['source_hash']];};$buildCii=fn(array $invoice)=>CiiInvoice::build($invoice,['domestic_country'=>'DE','source_hash'=>CiiInvoice::sourceHash($invoice,$source['original']['sha256'])],$source['original']['sha256']);
$bad=$invoices->get($id);$bad['data']['total_cents']++;denied(fn()=>$buildCii($bad),'changed original total rejected');$bad=$invoices->get($id);$bad['data']['issuer']['club']['register']='';denied(fn()=>$buildCii($bad),'missing original register is not invented');$bad=$invoices->get($id);$bad['kind']='credit';denied(fn()=>$buildCii($bad),'credit not mislabeled as invoice');
$plainReport='<?xml version="1.0"?><validation filename="invoice.pdf"><pdf>ValidationResult [flavour=3b, totalAssertions=20, assertions=[], isCompliant=true]<summary status="valid"/></pdf><xml><info><profile>urn:cen.eu:en16931:2017</profile><validator version="parser-test"/></info><summary status="valid"/></xml><summary status="valid"/></validation>';
// Parser tests are not evidence of real conformance; native checks follow below.
check(HybridEInvoice::parseReport($plainReport)['pdf_assertions']===20,'report parser checks explicit veraPDF result');
denied(fn()=>HybridEInvoice::parseReport(str_replace('isCompliant=true','isCompliant=false',$plainReport)),'overall valid cannot hide failed PDF/A');denied(fn()=>HybridEInvoice::parseReport(str_replace('flavour=3b','flavour=1b',$plainReport)),'wrong PDF/A flavour rejected');denied(fn()=>HybridEInvoice::parseReport(str_replace('invoice.pdf','different.pdf',$plainReport)),'different report filename rejected');denied(fn()=>HybridEInvoice::parseReport('<!DOCTYPE validation [<!ENTITY x SYSTEM "file:///etc/passwd">]>'.$plainReport),'external entities rejected');
$runtime=getenv('VEREIN_HYBRID_RUNTIME');putenv('VEREIN_HYBRID_RUNTIME');$before=(int)$db->query('SELECT COUNT(*) FROM documents')->fetchColumn();denied(fn()=>$hybrid->saveHybrid($entry,1,$confirmation),'missing runtime blocks export instead of faking success');check((int)$db->query('SELECT COUNT(*) FROM documents')->fetchColumn()===$before,'failed export stores no document');if($runtime!==false)putenv('VEREIN_HYBRID_RUNTIME='.$runtime);
denied(fn()=>saveOutgoingHybridInvoice($db,'admin',['entry_id'=>[$entry],'entry_version'=>'1']),'action rejects malformed entry ID');
denied(fn()=>saveOutgoingHybridInvoice($db,'admin',['entry_id'=>$entry,'entry_version'=>'0 OR 1']),'action rejects malformed version');
check($view($manual)==='','no hybrid button for manually entered invoice');
$foreignData=array_replace($data,['buyer_postcode'=>'1012 AB','buyer_city'=>'Amsterdam']);[$foreignId,$foreignEntry]=$issue($foreignData);
denied(fn()=>$hybrid->saveHybrid($foreignEntry,1),'Amsterdam original cannot silently receive DE without confirmation');
denied(fn()=>$hybrid->saveHybrid($foreignEntry,1,$confirmation),'German confirmation bound to another original cannot cover Amsterdam');
denied(fn()=>$hybrid->saveHybrid($foreignEntry,1,array_replace($confirm($foreignEntry),['domestic_country'=>'other'])),'explicit foreign-address choice blocks export');
$bad=$invoices->get($foreignId);$bad['data']['buyer_country']='NL';denied(fn()=>$buildCii($bad),'explicit foreign country cannot be overridden by DE confirmation');
denied(fn()=>saveOutgoingHybridInvoice($db,'admin',['entry_id'=>$entry,'entry_version'=>'1','domestic_country'=>['DE'],'source_hash'=>$source['source_hash']]),'malformed domestic confirmation rejected');

if(HybridEInvoice::configured()){
    check(str_contains($view($entry),'outgoing-hybrid-save'),'issued invoice offers controlled hybrid action');
    $viewDom=new DOMDocument();@$viewDom->loadHTML($view($entry));$viewXp=new DOMXPath($viewDom);check($viewXp->query('//select[@name="domestic_country"]/option[@selected]')->length===0&&$viewXp->evaluate('string(//select[@name="domestic_country"]/option[1]/@value)')==='','domestic country has no hidden/default confirmation');
    check(str_contains($view($foreignEntry),'Amsterdam')&&str_contains($view($entry),'Musterweg 1'),'original buyer and seller addresses visible before confirmation');
    $timeLimit=(int)ini_get('max_execution_time');$document=saveOutgoingHybridInvoice($db,'admin',['entry_id'=>$entry,'entry_version'=>'1']+$confirmation);$file=(new Documents($db,'admin'))->file($document,1);$snapshot=json_decode($file['snapshot'],true,512,JSON_THROW_ON_ERROR);
    check((int)ini_get('max_execution_time')===$timeLimit,'native hybrid creation preserves enclosing workflow time budget');
    check($snapshot['validation']['state']==='valid'&&$snapshot['validation']['pdfa']==='3b'&&$snapshot['validation']['pdf_assertions']>100,'actual Mustang and veraPDF accept issued PDF/A-3b plus CII');
    check($snapshot['domestic_confirmation']['source_hash']===$source['source_hash']&&$snapshot['domestic_confirmation']['actor']==='admin'&&$snapshot['domestic_confirmation']['evidence']==='explicit_user_confirmation','domestic decision and original hash preserved in audit snapshot');
    check($snapshot['validation']['source_sha256']===$source['original']['sha256']&&$snapshot['validation']['xml_sha256']===hash('sha256',$xml),'reports bind original PDF and embedded XML hashes');
    check($hybrid->saveHybrid($entry,1,$confirmation)===$document,'same export idempotent');
    check(str_contains($view($entry),'ZUGFeRD-PDF herunterladen')&&!str_contains($view($entry),'outgoing-hybrid-save'),'validated download replaces create action');
    $finance->recordPayment($entry,1,$account,'2026-09-09');$version=(int)$finance->entry($entry)['version'];
    check(CiiInvoice::build($hybrid->hybridSource($entry,$version)['invoice'],$confirmation,$source['original']['sha256'])===$xml&&$hybrid->saveHybrid($entry,$version,$confirmation)===$document,'later payment does not rewrite original payable amount or duplicate export');
    $invalid=str_replace('<ram:TaxTotalAmount currencyID="EUR">57.00','<ram:TaxTotalAmount currencyID="EUR">56.00',$xml);denied(fn()=>HybridEInvoice::build($source['original']['bytes'],$invalid),'real validator rejects contradictory VAT total');
    $mixed=$data;$mixed['positions'][]=['description'=>'Fiktive ermäßigte Leistung','quantity'=>'1.5','unit'=>'HUR','unit_gross'=>'107','tax_rate'=>'7','sphere'=>'3','account'=>''];[$mixedId,$mixedEntry]=$issue($mixed);check($hybrid->saveHybrid($mixedEntry,1,$confirm($mixedEntry))!=='','actual mixed 7 and 19 percent with fractional quantity validates');
    $exempt=$data;$exempt['vat_reason']='Steuerbefreiung nach § 19 UStG.';$exempt['positions'][0]['tax_rate']='0';[$exemptId,$exemptEntry]=$issue($exempt);check($hybrid->saveHybrid($exemptEntry,1,$confirm($exemptEntry))!=='','actual exempt invoice and explicit reason validate');
    $qa=getenv('VEREIN_HYBRID_QA_DIR');if($qa&&is_dir($qa)){file_put_contents($qa.'/invoice.pdf',$file['bytes']);file_put_contents($qa.'/original.pdf',$source['original']['bytes']);file_put_contents($qa.'/invoice.xml',$xml);file_put_contents($qa.'/report.xml',$snapshot['validation']['report']);}
}else echo "SKIP actual hybrid validators: VEREIN_HYBRID_RUNTIME unset\n";
check((int)$db->query('SELECT COUNT(*) FROM outgoing_invoice_deliveries')->fetchColumn()===0,'no outgoing email queued or sent');
echo "$n hybrid invoice checks passed\n";
