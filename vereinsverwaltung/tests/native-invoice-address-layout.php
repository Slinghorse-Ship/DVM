<?php declare(strict_types=1);
ini_set('zend.exception_ignore_args','1');
require dirname(__DIR__).'/native/bootstrap.php';
require dirname(__DIR__).'/src/bootstrap.php';
require __DIR__.'/support/original-database-hash.php';
use Kulturhus\Native\{TestSupport,Installer\SchemaRegistry};
use Kulturhus\Verein\{Finance,Documents,SettingsSchema,OutgoingInvoices,HybridEInvoice};
$db=TestSupport::db();SchemaRegistry::install($db);$n=0;
$check=static function(bool $ok,string $label)use(&$n):void{if(!$ok)throw new RuntimeException($label);++$n;echo 'PASS '.$label."\n";};
$key=static fn()=>bin2hex(random_bytes(16));
$db->exec("INSERT INTO clubs VALUES('a','Own invoice address'),('b','Foreign');INSERT INTO actors(id,club_id,role,is_superuser)VALUES('admin','a','member',1),('foreign','b','member',1)");
$finance=new Finance($db,'admin');$account=$finance->createAccount('Own offline account','offline','0','2026-01-01');
foreach(['club'=>['name'=>'Verein __Original__','street'=>'Musterweg 1','postcode'=>'12345','city'=>'Musterstadt','email'=>'seller@example.org','phone'=>'+49 123 456789','register'=>'VR 12345 Musterstadt'],'tax'=>['number'=>'123/456/78901'],'bank'=>['iban'=>'DE89370400440532013000','holder'=>'Fiktiver Musterverein','account_id'=>$account]]as$section=>$values){
    $db->prepare('INSERT INTO club_settings(club_id,section,version,payload,updated_at) VALUES(?,?,1,?,?)')->execute(['a',$section,json_encode(array_replace(SettingsSchema::defaults($section),$values)),gmdate('c')]);
}
$data=['seller_contact'=>'Fiktive Rechnungsstelle','domestic_confirmed'=>true,'invoice_format'=>'pdf','buyer_name'=>'Firma * Original * & <Name>','buyer_street'=>'Testweg 2','buyer_postcode'=>'23456','buyer_city'=>'Beispielstadt','buyer_email'=>'buyer@example.org','buyer_reference'=>'K-100','description'=>'Fiktive Vereinsleistung','note'=>'FIKTIV – Nur technische Formatprüfung','vat_reason'=>'','document_date'=>'2026-09-08','delivery_date'=>'2026-09-07','due_date'=>'2026-09-22','category'=>'general','contact_id'=>'','payment_account'=>$account,'positions'=>[['description'=>'Leistung **Original** & <Position>','quantity'=>'3','unit'=>'DAY','unit_gross'=>'119','tax_rate'=>'19','sphere'=>'3','account'=>'']]];
$invoices=new OutgoingInvoices($db,'admin');$documents=new Documents($db,'admin');$tables=$db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);$originals=[];$pdfs=[];
if(!HybridEInvoice::configured())throw new RuntimeException('Actual local Mustang and veraPDF are required.');
foreach(['short','long-hybrid','maximum']as$case){
    $input=$data;
    if($case==='long-hybrid'){$input['buyer_name']=trim(str_repeat('Name ',38));$input['invoice_format']='hybrid-xrechnung';}
    if($case==='maximum'){$input['buyer_name']=str_repeat('Ä',200);$input['buyer_street']=str_repeat('S',200);$input['buyer_postcode']=str_repeat('1',20);$input['buyer_city']=str_repeat('O',150);}
    $id=$invoices->save('',0,$input,$key());$before=ownOriginalDatabaseHash($db,$tables);$preview=$invoices->currentPreview($id);
    $check(ownOriginalDatabaseHash($db,$tables)===$before&&!$db->inTransaction(),$case.' actual complete draft PDF read preserves every whole row and schema');
    $check($preview['document']['recipient']===$input['buyer_name']."\n".$input['buyer_street']."\n".$input['buyer_postcode'].' '.$input['buyer_city'],$case.' full normalized recipient reaches actual document without abbreviation');
    $invoices->review($id,1);$check($invoices->get($id)['state']==='reviewed',$case.' actual canonical review accepts valid complete recipient');
    $entry=$invoices->issue($id,2);$invoice=$invoices->get($id);$file=$documents->file($invoice['document_id'],1);
    $check($invoice['state']==='issued'&&$invoice['entry_id']===$entry&&str_starts_with($file['bytes'],'%PDF-'),$case.' canonical issuance stores real linked original');
    $snapshot=json_decode($file['snapshot'],true,512,JSON_THROW_ON_ERROR);
    if($case==='long-hybrid')$check(($snapshot['einvoice']['kosit']['state']??'')==='valid'&&($snapshot['einvoice']['validation']['state']??'')==='valid'&&($snapshot['einvoice']['validation']['pdfa']??'')==='3b',$case.' real KoSIT Mustang and veraPDF validate issued PDF/A-3b original');
    $pdfs[]=['label'=>$case,'pdf'=>base64_encode($file['bytes']),'recipient'=>$snapshot['recipient'],'position'=>$input['positions'][0]['description'],'sender'=>'Verein __Original__'];
    $originals[$id]=['invoice'=>$invoice,'file'=>$file,'entry'=>$finance->entry($entry)];
    $before=ownOriginalDatabaseHash($db,$tables);$again=$invoices->currentPreview($id);
    $check($again['bytes']===$file['bytes']&&$again['document']===$snapshot&&ownOriginalDatabaseHash($db,$tables)===$before,$case.' issued preview reads exact stored original without regenerating or changing any whole row');
    foreach($originals as$oldId=>$old){
        $check($invoices->get($oldId)===$old['invoice']&&$documents->file($old['invoice']['document_id'],1)===$old['file']&&$finance->entry($old['invoice']['entry_id'])===$old['entry'],$case.' preserves every earlier whole invoice financial source and PDF original');
    }
}
$before=ownOriginalDatabaseHash($db,$tables);$oldId=array_key_first($originals);$denied=false;
try{(new OutgoingInvoices($db,'foreign'))->currentPreview($oldId);}catch(DomainException $e){$denied=$e->getCode()===404;}
$check($denied&&ownOriginalDatabaseHash($db,$tables)===$before,'foreign original read is refused without changing any whole row');
$db->beginTransaction();$db->exec("UPDATE clubs SET name='Own earlier caller work' WHERE id='a'");$before=ownOriginalDatabaseHash($db,$tables);$invoices->currentPreview($oldId);
$check($db->inTransaction()&&ownOriginalDatabaseHash($db,$tables)===$before,'actual original read preserves caller transaction and complete earlier work');$db->rollBack();
$check((int)$db->query('SELECT COUNT(*) FROM finance_transactions')->fetchColumn()===0&&(int)$db->query('SELECT COUNT(*) FROM outgoing_invoice_deliveries')->fetchColumn()===0,'complete original creation causes no payment or mail delivery');
$input=tempnam(sys_get_temp_dir(),'kh-address-');if($input===false)throw new RuntimeException('Own PDF fixture required.');
try{
    file_put_contents($input,json_encode($pdfs,JSON_THROW_ON_ERROR));chmod($input,0600);$python=getenv('VEREIN_PYTHON');if(!$python||!is_file($python))throw new RuntimeException('Actual private PDF runtime required.');
    $process=proc_open([$python,__DIR__.'/invoice-address-layout.py',$input],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
    if(!is_resource($process))throw new RuntimeException('PDF text verifier could not start.');fclose($pipes[0]);$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$exit=proc_close($process);echo $out;
    if($exit!==0)throw new RuntimeException('Actual PDF text and layout verification failed: '.$err);
    $check(true,'actual stored originals preserve complete literal business text and readable layout');
}finally{unlink($input);}
echo json_encode(['suite'=>'native-invoice-address-layout','checks'=>$n,'external_actions'=>0],JSON_THROW_ON_ERROR)."\n";
