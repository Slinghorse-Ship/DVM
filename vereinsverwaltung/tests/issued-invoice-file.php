<?php declare(strict_types=1);
require __DIR__.'/../src/bootstrap.php';
use Kulturhus\Verein\{OutgoingInvoices,IssuedInvoiceFile,SettingsSchema,CiiInvoice,EInvoiceValidator,HybridEInvoice};
$n=0;function check(bool $v,string $label):void{global $n;if(!$v)throw new RuntimeException($label);++$n;echo "PASS $label\n";}
function denied(callable $f,string $label):void{try{$f();}catch(DomainException){check(true,$label);return;}throw new RuntimeException($label);}
$data=OutgoingInvoices::normalize(['buyer_name'=>'Fiktive Kundengesellschaft','buyer_street'=>'Testweg 2','buyer_postcode'=>'23456','buyer_city'=>'Beispielstadt','buyer_email'=>'buyer@example.org','buyer_reference'=>'K-100','seller_contact'=>'Fiktive Rechnungsstelle','domestic_confirmed'=>'1','description'=>'Raumnutzung','note'=>'MUSTER - keine echte Rechnung','vat_reason'=>'','document_date'=>'2026-09-01','delivery_date'=>'2026-09-01','due_date'=>'2026-09-15','category'=>'rooms','positions'=>[['description'=>'Raumnutzung','quantity'=>'3','unit'=>'DAY','unit_gross'=>'119','tax_rate'=>'19']]]);
$issuer=[];foreach(['club'=>['name'=>'Fiktiver Musterverein','street'=>'Musterweg 1','postcode'=>'12345','city'=>'Musterstadt','email'=>'seller@example.org','phone'=>'+49 123 456789','register'=>'VR 12345 Musterstadt'],'tax'=>['number'=>'123/456/78901'],'bank'=>['iban'=>'DE89370400440532013000','holder'=>'Fiktiver Musterverein'],'paper'=>['header_text'=>'Fiktiver Musterverein','footer_left'=>'MUSTER - keine echte Rechnung'],'texts_invoices'=>[]] as $section=>$values)$issuer[$section==='texts_invoices'?'texts':$section]=array_replace(SettingsSchema::defaults($section),$values);
$data['issuer']=$issuer;$invoice=['id'=>'synthetic-invoice','club_id'=>'synthetic','kind'=>'invoice','state'=>'issued','number'=>'TEST-2026-1','data'=>$data];
$renderer=(new ReflectionClass(OutgoingInvoices::class))->newInstanceWithoutConstructor();$render=new ReflectionMethod(OutgoingInvoices::class,'document');
$document=$render->invoke($renderer,$invoice,$invoice['number'],$issuer);
$xml=IssuedInvoiceFile::xml($invoice,'%PDF-test-only');check(str_contains($xml,CiiInvoice::XRECHNUNG),'explicit XRechnung 3.0 profile');
foreach(['buyer_reference','buyer_email','seller_contact','domestic_confirmed'] as $field){$bad=$invoice;unset($bad['data'][$field]);denied(fn()=>IssuedInvoiceFile::xml($bad,'%PDF-test-only'),'missing '.$field.' blocks issuance');}
$bad=$invoice;$bad['data']['issuer']['club']['phone']='';denied(fn()=>IssuedInvoiceFile::xml($bad,'%PDF-test-only'),'missing seller phone blocks issuance');
check(EInvoiceValidator::configured()&&HybridEInvoice::configured(),'both native validators configured (no skipped conformance)');
foreach(['invoice','credit','cancellation','serif-regular','serif-bold','serif-italic','serif-bold-italic'] as $variant){
 $kind=str_starts_with($variant,'serif-')?'invoice':$variant;$candidate=$invoice;$candidate['kind']=$kind;$paperIssuer=$issuer;
 if(str_starts_with($variant,'serif-')){$paperIssuer['paper']['header_font']='serif';$paperIssuer['paper']['header_bold']=str_contains($variant,'bold');$paperIssuer['paper']['header_italic']=str_contains($variant,'italic');$paperIssuer['paper']['header_text']='Musterverein – ÄÖÜ ß €';}
 if($kind!=='invoice'){$candidate['number']='TEST-'.$kind;$candidate['data']['original_number']=$invoice['number'];$candidate['data']['original_date']=$invoice['data']['document_date'];}
 $doc=$render->invoke($renderer,$candidate,$candidate['number'],$paperIssuer);
 if($kind!=='invoice'){$doc['text']['intro']='Zu Rechnung TEST-2026-1: MUSTER';$doc['text']['closing']=$kind==='credit'?'Die berechnete Leistung wird gutgeschrieben.':'Die Rechnung wird vollständig storniert. Es entsteht keine Zahlung.';}
 $built=IssuedInvoiceFile::build($candidate,$doc);$proof=$built['document']['einvoice'];
 check($proof['kosit']['state']==='valid','native KoSIT accepts '.$variant);check($proof['validation']['state']==='valid'&&$proof['validation']['pdfa']==='3b','native Mustang/veraPDF accept '.$variant);
 check(hash_equals($proof['validation']['sha256'],hash('sha256',$built['bytes']))&&hash_equals($proof['validation']['xml_sha256'],hash('sha256',$proof['xml'])),'exact PDF/XML hash binding '.$variant);
 $qa=getenv('VEREIN_HYBRID_QA_DIR');if($qa&&is_dir($qa)){file_put_contents($qa.'/'.$variant.'.pdf',$built['bytes']);file_put_contents($qa.'/'.$variant.'.xml',$proof['xml']);}
}
echo "$n issued invoice file checks passed\n";
