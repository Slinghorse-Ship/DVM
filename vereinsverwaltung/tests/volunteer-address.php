<?php declare(strict_types=1);
require __DIR__.'/../src/bootstrap.php';require __DIR__.'/../src/Web/View.php';
use Kulturhus\Verein\{Database,Registry,Settings,SettingsSchema,VolunteerForm,DocumentPreview};
$checks=0;
function verifyAddress(bool $ok,string $label):void{global $checks;if(!$ok)throw new RuntimeException($label);$checks++;}
$cases=[
 ['Musterweg 12, 26349 Musterort','Musterweg 12','26349 Musterort'],
 ["Musterweg 12\n26349 Musterort",'Musterweg 12','26349 Musterort'],
 [" Musterweg 12\r\n\r\n26349 Musterort ",'Musterweg 12','26349 Musterort'],
 ['Musterweg 12 26349 Musterort','Musterweg 12','26349 Musterort'],
 ['Musterweg 12a; 26349 Musterort','Musterweg 12a','26349 Musterort'],
 ['Musterweg 12–14, 01234 Musterstadt OT Dorf','Musterweg 12–14','01234 Musterstadt OT Dorf'],
 ["c/o Beispiel\nMusterweg 12\n26349 Musterort\nDeutschland",'c/o Beispiel, Musterweg 12','26349 Musterort, Deutschland'],
 ["Musterweg\u{00A0}12,\u{00A0}26349\u{00A0}Musterort",'Musterweg 12','26349 Musterort'],
 ['Musterweg 12, D-26349 Musterort','Musterweg 12','D-26349 Musterort'],
 ['26349 Musterort','','26349 Musterort'],
 ['Musterweg 12','Musterweg 12',''],
 ['Musterweg 12, Hinterhaus','Musterweg 12, Hinterhaus',''],
 ['', '', ''],
];
foreach($cases as [$raw,$street,$city])verifyAddress(VolunteerForm::postalAddress($raw)===['street'=>$street,'city'=>$city],'Address case '.$raw);
$db=\Kulturhus\Verein\Tests\MySqlFixture::connect(':memory:');Database::migrate($db);
$db->exec("INSERT INTO clubs VALUES('address-test','Beispielverein');INSERT INTO actors(id,club_id,role,is_superuser) VALUES('address-admin','address-test','members',1)");
(new Settings($db,'address-admin'))->save('club',array_replace(SettingsSchema::defaults('club'),['name'=>'Beispielverein e. V.','street'=>'Vereinsweg 7','postcode'=>'26349','city'=>'Musterort']),0);
$contact=(new Registry($db,'address-admin'))->saveContact(null,0,'person','Alex Muster','alex@example.invalid','Musterweg 12, 26349 Musterort',['birthday'=>'1960-01-02']);
$service=new VolunteerForm($db,'address-admin');$input=$service->prefill($contact)['values'];$data=$service->build($contact,$input,false);
verifyAddress($data['volunteer_form']['values'][3]==='Musterweg 12'&&$data['volunteer_form']['values'][4]==='26349 Musterort','Correct personal PDF fields');
verifyAddress($data['volunteer_form']['values'][6]==='Vereinsweg 7'&&$data['volunteer_form']['values'][7]==='26349 Musterort','Correct club PDF fields');
$pdf=DocumentPreview::pdf($data);verifyAddress(str_starts_with($pdf,'%PDF-'),'Actual original PDF generated');if(getenv('VOLUNTEER_ADDRESS_PDF'))file_put_contents(getenv('VOLUNTEER_ADDRESS_PDF'),$pdf);
$oldHash=VolunteerForm::digest($data);$db->prepare('UPDATE contacts SET address=?,version=version+1 WHERE id=?')->execute(['Neuer Weg 8, 01234 Neuer Ort',$contact]);
try{$service->save($contact,$input,false,bin2hex(random_bytes(16)),$oldHash);throw new RuntimeException('Stale profile accepted');}catch(DomainException){verifyAddress(true,'Changed profile requires a new preview');}
verifyAddress((int)$db->query('SELECT count(*) FROM documents')->fetchColumn()===0,'Rejected stale preview writes no document');
$data=$service->build($contact,$input,false);
$_SESSION=['actor'=>'address-admin','csrf'=>'synthetic','volunteer_preview'=>['actor'=>'address-admin','contact'=>$contact,'expires'=>time()+1000,'hash'=>VolunteerForm::digest($data),'input'=>$input,'signed'=>false,'token'=>'synthetic']];
$_GET=['view'=>'portal','tab'=>'safety','volunteer'=>'1','person'=>$contact];$_POST=[];
ob_start();require __DIR__.'/../views/Settings/volunteer-form.php';$html=(string)ob_get_clean();
verifyAddress(str_contains($html,'value="Neuer Weg 8"')&&str_contains($html,'value="01234 Neuer Ort"'),'Reopening reads current profile instead of previous draft');
verifyAddress(!str_contains($html,'data-volunteer-preview'),'Old preview is not presented on a fresh form visit');
echo "PASS $checks volunteer address and state checks\n";
