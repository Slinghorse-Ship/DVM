<?php declare(strict_types=1);
/** Actual raster recognition and immutable manager document; no fake OCR response. */
require dirname(__DIR__).'/native/bootstrap.php';
require dirname(__DIR__).'/src/bootstrap.php';
use Kulturhus\Native\TestSupport;
use Kulturhus\Verein\{Database,Documents,InvoiceRecognition};

$runtime=getenv('VEREIN_OCR_RUNTIME');
if(!$runtime||!is_file($runtime))throw new RuntimeException('Private OCR runtime required');
$config=json_decode(file_get_contents($runtime),true,32,JSON_THROW_ON_ERROR);
if(($config['sandbox_backend']??'')!=='docker'||($config['require_sandbox']??false)!==true)throw new RuntimeException('Verified container sandbox required');
$db=TestSupport::db();Database::migrate($db);
$db->exec("INSERT INTO clubs VALUES('ocr-test','Synthetic OCR club');INSERT INTO actors(id,club_id,role,is_superuser) VALUES('ocr-admin','ocr-test','member',1),('ocr-reader','ocr-test','member',0)");
$n=0;function ocrCheck(bool $ok,string $label):void{global $n;if(!$ok)throw new RuntimeException($label);++$n;echo 'PASS '.$label."\n";}
$image=imagecreatetruecolor(1600,800);imagefill($image,0,0,imagecolorallocate($image,255,255,255));$black=imagecolorallocate($image,0,0,0);
$font=dirname(__DIR__).'/resources/fonts/liberation-serif/LiberationSerif-Regular.ttf';
foreach(['Synthetische Testrechnung','Rechnungsnummer: OCR-2026-001','Rechnungsdatum: 09.10.2026','Gesamtbetrag brutto: 123,45 EUR'] as $i=>$line)imagettftext($image,32,0,80,140+$i*110,$black,$font,$line);
ob_start();imagepng($image);$bytes=ob_get_clean();imagedestroy($image);
$documents=new Documents($db,'ocr-admin');
$id=$documents->upload('synthetic.png',$bytes,['title'=>'Synthetic invoice','kind'=>'receipt','scope'=>'finance','links'=>[]],bin2hex(random_bytes(16)));
$original=$documents->file($id);$recognition=new InvoiceRecognition($db,'ocr-admin');
putenv('KULTURHUS_LOCAL_DEMO=php');
$result=$recognition->run($id);
ocrCheck($result['state']==='ready'&&$result['method']==='ocr','manager document is recognized by real isolated Tesseract');
ocrCheck(($result['fields']['Rechnungsnummer']??'')==='OCR-2026-001','actual image invoice number recognized');
ocrCheck(($result['fields']['Rechnungsdatum']??'')==='2026-10-09'&&($result['fields']['Rechnungsbetrag brutto']??'')==='123.45','actual image date and gross amount recognized');
ocrCheck(hash('sha256',$documents->file($id)['bytes'])===hash('sha256',$original['bytes']),'stored original remains byte-identical');
ocrCheck((int)$db->query('SELECT COUNT(*) FROM finance_entries')->fetchColumn()===0,'OCR never creates a financial booking');
ocrCheck($recognition->result($id)['sha256']===hash('sha256',$bytes),'cached result is bound to original bytes');
try{(new InvoiceRecognition($db,'ocr-reader'))->result($id);throw new LogicException('reader accepted');}catch(DomainException){ocrCheck(true,'financial read permission protects recognized result');}

$temp=sys_get_temp_dir().'/kh-ocr-policy-'.bin2hex(random_bytes(8));mkdir($temp,0700);
try {
    // Reusing the container policy directly on the host must fail, even with a demo flag.
    $launcher=json_decode(file_get_contents(dirname($runtime).'/ocr-container.json'),true,16,JSON_THROW_ON_ERROR);
    $config['python']=$launcher['runtime'].'/ocr-5.5.3/run-python';
    file_put_contents($temp.'/runtime.json',json_encode($config,JSON_THROW_ON_ERROR));chmod($temp.'/runtime.json',0600);
    putenv('VEREIN_OCR_RUNTIME='.$temp.'/runtime.json');
    ocrCheck(InvoiceRecognition::extract($bytes,'image/png')['state']==='failed','container policy fails closed outside the actual container');
} finally {
    putenv('VEREIN_OCR_RUNTIME='.$runtime);
    foreach(glob($temp.'/*') as $file)unlink($file);rmdir($temp);
}
echo "$n OCR integration checks passed; actual raster, immutable original and synthetic MySQL.\n";
