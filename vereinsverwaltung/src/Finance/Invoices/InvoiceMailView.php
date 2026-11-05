<?php declare(strict_types=1);
namespace Kulturhus\Verein;
use PDO;use DomainException;
require_once __DIR__.'/InvoiceMailWorkflow.php';
/** Stored RFC822 display only. Never renders active HTML or fetches resources. */
final class InvoiceMailView
{
 public function __construct(private PDO $db,private string $actor){}
 public function original(string $document,string $key,string $uid):string
 {
  $docs=new Documents($this->db,$this->actor);$doc=$docs->document($document);
  $q=$this->db->prepare('SELECT o.* FROM invoice_mail_original_links l JOIN invoice_mail_originals o USING(club_id,mailbox_key,message_uid) WHERE l.club_id=? AND l.document_id=? AND l.mailbox_key=? AND l.message_uid=?');$q->execute([$doc['club_id'],$document,$key,$uid]);$mail=$q->fetch(PDO::FETCH_ASSOC);if(!$mail)throw new DomainException('Originalmail nicht verfügbar.');
  $q=$this->db->prepare('SELECT document_id FROM invoice_mail_original_links WHERE club_id=? AND mailbox_key=? AND message_uid=?');$q->execute([$doc['club_id'],$key,$uid]);foreach($q->fetchAll(PDO::FETCH_COLUMN) as $related)$docs->document($related);
  if(!is_string($mail['bytes'])||!hash_equals($mail['sha256'],hash('sha256',$mail['bytes'])))throw new DomainException('Originalmail-Prüfsumme stimmt nicht.');
  return $mail['bytes'];
 }
 public function listing(string $document):array
 {
  $doc=(new Documents($this->db,$this->actor))->document($document);$q=$this->db->prepare('SELECT mailbox_key,message_uid FROM invoice_mail_original_links WHERE club_id=? AND document_id=? ORDER BY message_uid');$q->execute([$doc['club_id'],$document]);$rows=[];
  foreach($q->fetchAll(PDO::FETCH_ASSOC) as $ref){try{$bytes=$this->original($document,$ref['mailbox_key'],$ref['message_uid']);$rows[]=$ref+['available'=>true,'sha256'=>hash('sha256',$bytes),'workflow'=>(new InvoiceMailWorkflow($this->db,$this->actor))->summary($document,$ref['mailbox_key'],$ref['message_uid'])]+self::text($bytes);}catch(DomainException){$rows[]=['available'=>false];}}
  return $rows;
 }
 private static function split(string $raw):array
 {
  $parts=preg_split('/\r?\n\r?\n/',$raw,2);$header=substr($parts[0]??'',0,65536);$header=preg_replace('/\r?\n[ \t]+/',' ',$header);$headers=[];
  foreach(preg_split('/\r?\n/',$header) as $line){$pos=strpos($line,':');if($pos!==false)$headers[strtolower(trim(substr($line,0,$pos)))]=trim(substr($line,$pos+1));}return [$headers,$parts[1]??''];
 }
 private static function decode(string $text,string $charset='UTF-8'):string
 {
  try{return mb_convert_encoding($text,'UTF-8',$charset);}catch(\Throwable){return mb_convert_encoding($text,'UTF-8','UTF-8');}
 }
 public static function text(string $raw):array
 {
  $truncated=strlen($raw)>2097152;$raw=substr($raw,0,2097152);
  [$headers]=self::split($raw);$result=['truncated'=>$truncated];foreach(['from','to','subject','date'] as $key)$result[$key]=self::decode(mb_decode_mimeheader(substr($headers[$key]??'',0,2000)));
  $plain=[];$html=[];$count=0;
  $walk=function(string $part,int $depth)use(&$walk,&$plain,&$html,&$count){
   if(++$count>64||$depth>10)return;[$h,$body]=self::split($part);$type=strtolower($h['content-type']??'text/plain');
   if(str_starts_with(strtolower($h['content-disposition']??''),'attachment'))return;
   if(str_starts_with($type,'multipart/')&&preg_match('/boundary\s*=\s*(?:"([^"\r\n]{1,200})"|([^;\s]{1,200}))/i',$h['content-type']??'',$match)){
    $boundary=$match[1]?:($match[2]??'');$parts=preg_split('/(?:\A|\r?\n)--'.preg_quote($boundary,'/').'(?:--)?[ \t]*(?:\r?\n|\z)/',$body,66);foreach(array_slice($parts,1,64) as $child)$walk($child,$depth+1);return;
   }
   if(!str_starts_with($type,'text/plain')&&!str_starts_with($type,'text/html'))return;
   if(strlen($body)>1048576)return;$encoding=strtolower($h['content-transfer-encoding']??'');if($encoding==='base64'){$decoded=base64_decode($body,true);$body=$decoded===false?'':$decoded;}elseif($encoding==='quoted-printable')$body=quoted_printable_decode($body);
   preg_match('/charset\s*=\s*"?([^";\s]+)/i',$h['content-type']??'',$charset);$body=self::decode($body,$charset[1]??'UTF-8');
   if(str_starts_with($type,'text/plain'))$plain[]=$body;else{$body=preg_replace('/<(script|style)\b[^>]*>.*?<\/\1\s*>/is','',$body);$html[]=html_entity_decode(strip_tags(str_ireplace(['<br>','<br/>','<br />','</p>','</div>'],"\n",$body)),ENT_QUOTES|ENT_HTML5,'UTF-8');}
  };$walk($raw,0);
  $result['body']=mb_substr(trim(implode("\n\n",$plain?:$html)),0,100000);$result['html_converted']=!$plain&&!!$html;
  $result['body_missing']=$result['body']==='';
  if($result['body_missing'])$result['body']='Kein darstellbarer Nachrichtentext. Bitte die Originalmail herunterladen.';
  return $result;
 }
}
