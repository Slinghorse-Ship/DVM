<?php declare(strict_types=1);
require dirname(__DIR__,2).'/vendor/autoload.php';
require dirname(__DIR__).'/native/bootstrap.php';
require dirname(__DIR__).'/src/autoload.php';
use Symfony\Component\Mime\{Email,RawMessage};
use Symfony\Component\Mime\Header\ParameterizedHeader;
use Symfony\Component\Mime\Exception\RfcComplianceException;

$checks=0;
$check=static function(bool $ok,string $label)use(&$checks):void{
    if(!$ok)throw new RuntimeException($label);$checks++;echo 'PASS '.$label.PHP_EOL;
};
$originalName='Originale Rechnung ä🙂.pdf';
$invalid=['','bad name',"bad\tname","bad\r\nX-Own-Injected: yes",'bad:name','bad;name','bad=name','bad"name','bad(name)','bad\\name','ümlaut',"bad\0name"];
foreach($invalid as $index=>$name)foreach(['construct','one','many']as$path){
    $header=new ParameterizedHeader('Content-Disposition','attachment',['filename'=>$originalName]);$denied=false;
    try{
        if($path==='construct')new ParameterizedHeader('Content-Disposition','attachment',[$name=>'own literal value']);
        elseif($path==='one')$header->setParameter($name,'own literal value');
        else $header->setParameters(['filename'=>'must not replace original',$name=>'own literal value']);
    }catch(RfcComplianceException){$denied=true;}
    $check($denied,'invalid MIME parameter refused at public '.$path.' entry '.$index);
    if($path!=='construct')$check($header->getParameters()===['filename'=>$originalName],'rejected parameter retains the whole earlier header '.$path.' '.$index);
}
$valid=new ParameterizedHeader('Content-Disposition','attachment',['filename'=>$originalName,'x-own_token'=>'literal value','name*'=>'UTF-8']);
$check($valid->getParameter('filename')===$originalName&&$valid->getParameter('x-own_token')==='literal value','valid literal parameter names and UTF-8 filename remain accepted');
$original="%PDF-own-original\0\r\n";
$mail=(new Email())->from('sender@example.invalid')->to('recipient@example.invalid')->subject('Unveränderter eigener Betreff ä🙂')->text('Unveränderter eigener Nachrichtentext')->attach($original,$originalName,'application/pdf');
$check($mail->getAttachments()[0]->getBody()===$original,'real MIME attachment retains complete original binary body');
$check($mail->getSubject()==='Unveränderter eigener Betreff ä🙂'&&$mail->getTextBody()==='Unveränderter eigener Nachrichtentext','valid original subject and message text remain literal');
$serialized=$mail->toString();
$check(str_contains($serialized,'recipient@example.invalid')&&str_contains($serialized,'application/pdf'),'valid actual multipart message serializes without sending');
$legacy=json_decode(file_get_contents(__DIR__.'/fixtures/mime-v7.4.18.json'),true,32,JSON_THROW_ON_ERROR);
$bytes=base64_decode($legacy['original_mime_base64'],true);
$check(is_string($bytes)&&hash('sha256',$bytes)===$legacy['original_sha256'],'trusted synthetic MIME original from the prior library matches its fixed digest');
$check((new RawMessage($bytes))->toString()===$bytes,'retained prior-version MIME original passes through the current raw sender byte for byte');
// Real incoming wire bytes: RFC 6532 literals and RFC 2047 encoded words must coexist.
$incoming=static fn(string $subject,string $from):string=>"From: ".$from." <sender@example.invalid>\r\nTo: recipient@example.invalid\r\nSubject: ".$subject."\r\nMessage-ID: <own-utf8-header@example.invalid>\r\nContent-Type: text/plain; charset=UTF-8\r\n\r\nReadable original body ä🙂";
$encoded=static fn(string $text):string=>'=?UTF-8?B?'.base64_encode($text).'?=';
$cases=[
 ['Grüße vom Büro 🎶','Änne Müller','Grüße vom Büro 🎶','Änne Müller'],
 [$encoded('Grüße vom Büro 🎶'),$encoded('Änne Müller'),'Grüße vom Büro 🎶','Änne Müller'],
 ['=?ISO-8859-1?Q?Gr=FC=DFe_aus_dem_B=FCro?=','=?UTF-8?Q?=C3=84nne_M=C3=BCller?=','Grüße aus dem Büro','Änne Müller'],
 [$encoded('Grüße ')."\r\n\t".$encoded('vom Büro'),'Änne Müller','Grüße vom Büro','Änne Müller'],
 ['Übersicht: '.$encoded('Grüße').' 🎶','Änne '.$encoded('Müller'),'Übersicht: Grüße 🎶','Änne Müller'],
 ['Meeting_2026','Plain sender','Meeting_2026','Plain sender'],
];
foreach($cases as$i=>[$subject,$from,$expectedSubject,$expectedFrom]){
 $raw=$incoming($subject,$from);$digest=hash('sha256',$raw);$envelope=\Kulturhus\Native\Communication\Mail\Mime::mailbox($raw);
 $check($envelope['subject']===$expectedSubject&&$envelope['from']===$expectedFrom.' <sender@example.invalid>','incoming literal encoded adjacent and mixed UTF-8 header case '.$i.' keeps its exact human subject and sender');
 $check($envelope['readable']&&$envelope['reply_to']==='sender@example.invalid'&&$envelope['text']==='Readable original body ä🙂'&&hash('sha256',$raw)===$digest,'header decoding retains body address routing and all original MIME bytes case '.$i);
}
foreach(["Bad \xff header",'=?unknown-own-charset?B?'.base64_encode("bad \xff bytes").'?=']as$i=>$bad){$envelope=\Kulturhus\Native\Communication\Mail\Mime::mailbox($incoming($bad,$bad));$check($envelope['readable']&&mb_check_encoding($envelope['subject'],'UTF-8')&&mb_check_encoding($envelope['from'],'UTF-8')&&$envelope['reply_to']==='sender@example.invalid','invalid header bytes retain safe display fallbacks without rejecting the readable body case '.$i);}
$envelope=\Kulturhus\Native\Communication\Mail\Mime::mailbox($incoming($encoded("Human\r\nX-Injected: no\0"),$encoded("Name\r\nInjected\0")));
$check(!preg_match('/[\x00-\x1f\x7f]/',$envelope['subject'].$envelope['from'])&&$envelope['reply_to']==='sender@example.invalid','decoded control bytes cannot introduce a display header or alter the actual reply address');
require __DIR__.'/support/mailbox-unicode-body.php';
echo json_encode(['suite'=>'native-mime-headers','checks'=>$checks,'version'=>Composer\InstalledVersions::getPrettyVersion('symfony/mime'),'malformed_parameter_paths'=>36,'retained_legacy_mime'=>'byte-exact','network_requests'=>0,'sent_messages'=>0,'live_transport'=>false],JSON_THROW_ON_ERROR).PHP_EOL;
