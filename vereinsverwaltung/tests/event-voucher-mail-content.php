<?php declare(strict_types=1);
// Real renderers, synthetic recipients only. No bootstrap, DB connection or transport.
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
$root=getenv('VEREIN_MAIL_TEST_ROOT')?:dirname(__DIR__,2);
require_once $root.'/vereinsverwaltung/src/SourceFiles.php';
foreach(['EventVouchers','ApplicationMail','EventVoucherQr','EventVoucherPdf','DocumentPreview','SettingsSchema','EventVoucherMail'] as $class)
    require_once \Kulturhus\Verein\SourceFiles::file($class.'.php');
use Kulturhus\Verein\EventVoucherMail;
$n=0;
function checkMail(bool $condition,string $label):void{global $n;if(!$condition)throw new RuntimeException($label);++$n;}
$snapshot=['event_id'=>str_repeat('a',32),'title'=>'Testabend & Freunde','date'=>'2026-10-09T18:00:00+02:00','location'=>'Testort',
    'person'=>['name'=>'Mara Muster'],'text'=>"Hallo Mara Muster,\n\nPersönliche Testgutscheine.",
    'vouchers'=>[['code'=>'0123456789ABCDEF','label'=>'Getränk','unit'=>'piece','remaining'=>1]]];
$url='https://verein.example.invalid/vereinsverwaltung';$baseline=null;
foreach([null,['subject'=>'Danke {{VORNAME}} · {{VERANSTALTUNG}}','message'=>'Hallo {{VORNAME}}, vielen Dank für deine Hilfe!'],
    ['subject'=>'<b>{{NAME}}</b> & "Test"','message'=>'<script>Test</script> {{NAME}}']] as $template){
    $s=$snapshot;if($template!==null)$s['mail_template']=$template;$original=$s;
    $content=EventVoucherMail::content($s,$url);$e=htmlspecialchars($content['subject'],ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
    checkMail($s===$original,'Rendering never alters the approved snapshot');
    checkMail(str_contains($content['html'],'<h1 style="font: bold 32px Georgia,serif">'.$e.'</h1>'),'Heading equals resolved subject');
    checkMail(str_contains($content['html'],'<title>'.$e.'</title>'),'HTML title equals subject');
    checkMail(!str_contains($content['html'],'>Deine Gutscheine</h1>'),'No hard-coded heading');
    foreach(['Originalgröße','100 %','85 × 55'] as $obsolete)checkMail(!str_contains($content['html'].$content['text'],$obsolete),'No obsolete print instruction');
    checkMail(str_contains($content['html'],'Druck-PDF im Anhang')&&str_contains($content['text'],'Druck-PDF im Anhang'),'Short attachment note in both formats');
    checkMail(str_contains($content['html'],'?view=portal&amp;tab=documents#meine-gutscheine')&&str_contains($content['text'],'?view=portal&tab=documents#meine-gutscheine'),'Personal document link preserved');
    checkMail(!str_contains($content['html'],'<script>')&&!str_contains($content['html'],'<b>Mara Muster</b>'),'Template markup remains escaped');
    checkMail(str_contains($content['html'],'DVM – DeinVereinsManager')&&str_contains($content['html'],'Mein Verein'),'DVM and separate club identity retained');
    $pdf=array_values(array_filter($content['attachments'],static fn($f)=>$f['mime']==='application/pdf'));
    checkMail(count($pdf)===1&&str_starts_with($pdf[0]['bytes'],'%PDF-'),'Real PDF attachment retained');
    checkMail(count($content['attachments'])===2&&str_contains($content['html'],'cid:voucher-0123456789ABCDEF@kulturhus'),'Personal QR retained');
    if($baseline!==null)checkMail($content['attachments']===$baseline,'Subject and message edits do not alter PDF or QR');
    else $baseline=$content['attachments'];
    if($template!==null)checkMail(!str_contains($content['subject'],'{{')&&!str_contains($content['subject'],'Deine Gutscheine'),'Custom subject expanded');
    if($template!==null&&str_contains($template['message'],'{{VORNAME}}'))checkMail(str_contains($content['html'],'Hallo Mara,')&&str_starts_with($content['subject'],'Danke Mara ·'),'First name works in body and heading');
}
foreach(['Mara Muster'=>'Mara','Anne-Marie Muster'=>'Anne-Marie','  Jörg  Beispiel '=>'Jörg',"Mara\u{00a0}Muster"=>'Mara','Muster, Mara Sophie'=>'Mara Sophie','Mara'=>'Mara'] as $name=>$first)
    checkMail(EventVoucherMail::firstName($name)===$first,'First-name format');
$s=$snapshot;$s['mail_template']=['subject'=>'{{VORNAME}} / {{NAME}}','message'=>'Hallo {{VORNAME}},'];
checkMail(EventVoucherMail::message($s)===['subject'=>'Mara / Mara Muster','intro'=>'Hallo Mara,'],'Full-name token remains unchanged');
echo json_encode(['passed'=>true,'checks'=>$n,'synthetic_only'=>true,'database_access'=>false,'sent'=>0],JSON_THROW_ON_ERROR)."\n";
