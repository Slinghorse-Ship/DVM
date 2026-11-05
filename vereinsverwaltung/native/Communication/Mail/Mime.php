<?php declare(strict_types=1);
namespace Kulturhus\Native\Communication\Mail;

use DomainException;
use Kulturhus\Native\Communication\Content;

/** Bounded RFC 5322/MIME text extraction; original bytes are retained separately. */
final class Mime
{
    public const MAX_ORIGINAL_BYTES=41943040;
    public const MAX_BATCH_BYTES=41943040;
    private const MAX_SCANNED_BYTES=125829120;
    public static function prepareMemory(int $bytes):void
    {
        if($bytes<=8388608)return;$limit=trim((string)ini_get('memory_limit'));if($limit==='-1')return;
        if(preg_match('/\A([0-9]+)([KMG]?)\z/iD',$limit,$m)){$current=(int)$m[1]*(['K'=>1024,'M'=>1048576,'G'=>1073741824][strtoupper($m[2])]??1);if($current<268435456&&@ini_set('memory_limit','256M')===false)throw new DomainException('Diese E-Mail kann derzeit nicht vollständig verarbeitet werden.');}
    }
    public static function messageId(string $id):string
    {
        if(!preg_match('/\A<([A-Za-z0-9.!#$%&\x27*+\/=?^_`{|}~-]{1,180}@[A-Za-z0-9.-]{1,253})>\z/D',$id))throw new DomainException('Mailkennung prüfen.');
        return $id;
    }
    private static function split(string $raw):array
    {
        $parts=preg_split('/\r?\n\r?\n/',$raw,2);
        if(count($parts)!==2||strlen($parts[0])>65536)throw new DomainException('Mailheader nicht vollständig.');
        return $parts;
    }
    private static function headers(string $raw,bool $display=false):array
    {
        $raw=preg_replace('/\r?\n[ \t]+/',' ',$raw);$headers=[];
        foreach(preg_split('/\r?\n/',$raw)as$line){
            if(!preg_match('/\A([\x21-\x39\x3b-\x7e]+):[ \t]*(.*)\z/D',$line,$match)||str_contains($line,"\0")){if($display)continue;throw new DomainException('Mailheader prüfen.');}
            $key=strtolower($match[1]);$value=trim($match[2]);
            if(isset($headers[$key])&&in_array($key,['from','reply-to','to','message-id','in-reply-to','references','content-type','content-transfer-encoding','auto-submitted'],true)){
                if(!$display)throw new DomainException('Mehrdeutiger Mailheader.');
                if(in_array($key,['from','reply-to','to'],true))$value=$headers[$key].', '.$value;
                elseif(in_array($key,['references','in-reply-to'],true))$value=$headers[$key].' '.$value;
                elseif($headers[$key]!==$value)$value='';
            }
            $headers[$key]=$value;
        }
        return $headers;
    }
    private static function address(string $header):string
    {
        if(preg_match('/\A[^<>]*<([^<>]+)>\z/D',$header,$match))$header=$match[1];
        return Content::email($header);
    }
    private static function addresses(string $header):array
    {
        $parts=[];$part='';$quoted=false;$escaped=false;$angle=false;
        for($i=0;$i<strlen($header);$i++){
            $char=$header[$i];
            if($escaped){$part.=$char;$escaped=false;continue;}
            if($quoted&&$char==='\\'){$part.=$char;$escaped=true;continue;}
            if($char==='"')$quoted=!$quoted;
            if(!$quoted&&$char==='<'){if($angle)throw new DomainException('Mailadressaten prüfen.');$angle=true;}
            if(!$quoted&&$char==='>'){if(!$angle)throw new DomainException('Mailadressaten prüfen.');$angle=false;}
            if(!$quoted&&!$angle&&$char===','){$parts[]=self::address(trim($part));$part='';if(count($parts)>=20)throw new DomainException('Zu viele Mailadressaten.');continue;}
            $part.=$char;
        }
        if($quoted||$angle||$escaped)throw new DomainException('Mailadressaten nicht vollständig.');
        $parts[]=self::address(trim($part));return $parts;
    }
    private static function parameter(string $value,string $name):?string
    {
        return preg_match('/(?:^|;)\s*'.preg_quote($name,'/').'\s*=\s*(?:"([^"\r\n]*)"|([^;\s]+))/i',$value,$m)?(($m[1]??'')!==''?$m[1]:($m[2]??'')):null;
    }
    private static function text(array $headers,string $body,int $depth=0,bool $display=false):?array
    {
        if($depth>8)throw new DomainException('Mail zu verschachtelt.');
        if(str_starts_with(strtolower($headers['content-disposition']??''),'attachment'))return null;
        $type=strtolower(trim(explode(';',$headers['content-type']??'text/plain')[0]));
        if(str_starts_with($type,'multipart/')){
            $boundary=self::parameter($headers['content-type'],'boundary');
            if(!$boundary||strlen($boundary)>70||preg_match('/[\x00-\x1f\x7f]/',$boundary))throw new DomainException('MIME-Grenze prüfen.');
            if(!preg_match('/(?:\A|\r?\n)--'.preg_quote($boundary,'/').'--[ \t]*(?:\r?\n|\z)/',$body))throw new DomainException('MIME-Abschluss fehlt.');
            $parts=preg_split('/(?:\A|\r?\n)--'.preg_quote($boundary,'/').'(?:--)?[ \t]*(?:\r?\n|\z)/',$body);
            if(count($parts)>42)throw new DomainException('Zu viele Mailteile.');
            $fallback=null;
            foreach(array_slice($parts,1,-1)as$part){try{[$head,$bytes]=self::split($part);$text=self::text(self::headers($head,$display),$bytes,$depth+1,$display);}catch(DomainException $error){if(!$display)throw $error;continue;}if($text&&$text['type']==='text/plain')return $text;if($text)$fallback??=$text;}
            return $fallback;
        }
        if(!in_array($type,['text/plain','text/html'],true))return null;
        $encoding=strtolower($headers['content-transfer-encoding']??'7bit');
        $body=match($encoding){'base64'=>base64_decode(preg_replace('/\s+/','',$body),true),'quoted-printable'=>quoted_printable_decode($body),'7bit','8bit','binary'=>$body,default=>throw new DomainException('Mailkodierung prüfen.')};
        if(!is_string($body)||strlen($body)>262144)throw new DomainException('Mailtext zu groß oder beschädigt.');
        $charset=self::parameter($headers['content-type']??'','charset')??'UTF-8';
        try{if(!mb_check_encoding($body,$charset))throw new DomainException('Mailtext beschädigt.');$body=mb_convert_encoding($body,'UTF-8',$charset);}catch(\ValueError){throw new DomainException('Mailzeichensatz unbekannt.');}
        if($type==='text/html'){
            $body=preg_replace('~<(script|style|head)\b[^>]*>.*?</\1\s*>~is','',$body);
            $reply=self::htmlText(ReplyText::withoutHtmlQuotes($body));
            $body=self::htmlText($body);
        }
        if($display&&strlen($body)>20000)$body=mb_strcut($body,0,19997,'UTF-8').'…';
        return ['type'=>$type,'text'=>Content::originalText($body,20000,true)]+(isset($reply)?['reply_text'=>$reply]:[]);
    }
    private static function htmlText(string $body):string
    {
        $body=preg_replace('~<br\b[^>]*>|</(?:p|div|li|tr|h[1-6])\s*>~i',"\n",$body);
        return html_entity_decode(strip_tags($body),ENT_QUOTES|ENT_HTML5,'UTF-8');
    }
    /** Decode RFC 2047 words without feeding literal RFC 6532 UTF-8 to mb_decode_mimeheader. */
    private static function displayHeader(string $header):string
    {
        $word='=\?[^?\s]+\?[bq]\?[^?\r\n]*\?=';
        $decoded=preg_replace_callback('~'.$word.'(?:[ \t]+'.$word.')*~i',static fn(array $match):string=>mb_decode_mimeheader($match[0]),$header);
        return preg_replace('/[\x00-\x1f\x7f]/u',' ',mb_scrub($decoded??$header,'UTF-8'))??'';
    }
    /** General mail is retained even when its text or reply address cannot be interpreted. */
    public static function mailbox(string $raw):array
    {
        if($raw===''||strlen($raw)>self::MAX_ORIGINAL_BYTES)throw new DomainException('Originalmail muss zwischen einem Byte und 40 MB groß sein.');
        self::prepareMemory(strlen($raw));
        $out=['subject'=>'Nachricht ohne lesbaren Betreff','from'=>'Unbekannter Absender','reply_to'=>'','message_id'=>'','text'=>'','readable'=>false,'thread_references'=>[]];
        try{
            [$head,$body]=self::split($raw);$headers=self::headers($head,true);
            $out['thread_references']=self::threadReferences($raw);
            $subject=self::displayHeader($headers['subject']??'');
            $out['subject']=mb_substr($subject,0,320)?:'Ohne Betreff';
            $out['from']=mb_substr(self::displayHeader($headers['from']??'Unbekannter Absender'),0,500);
            try{
                $header=$headers['reply-to']??$headers['from']??'';$addresses=self::addresses($header);
                if(count($addresses)===1){$address=preg_match('/\A[^<>]*<([^<>]+)>\z/D',$header,$match)?$match[1]:$header;$out['reply_to']=trim($address);}
            }catch(DomainException){}
            try{$out['message_id']=self::messageId($headers['message-id']??'');}catch(DomainException){}
            $text=self::text($headers,$body,0,true);if($text!==null){$out['text']=$text['text'];$out['readable']=true;}
        }catch(\Throwable){/* Original bytes remain available as a protected attachment, never rendered as HTML. */}
        return $out;
    }
    /** Only explicit RFC reply headers join messages; subject or sender similarity never does. */
    public static function threadReferences(string $raw):array
    {
        if(strlen($raw)>self::MAX_ORIGINAL_BYTES)throw new DomainException('Originalmail zu groß.');
        [$head]=self::split($raw);$headers=self::headers($head,true);$references=[];
        foreach(['references','in-reply-to']as$key){
            preg_match_all('/<[^<>\s]+>/',$headers[$key]??'',$matches);
            if(count($matches[0])>30)continue;
            foreach($matches[0]as$id)try{$references[self::messageId($id)]=true;}catch(DomainException){}
        }
        return array_keys($references);
    }
    /** Read HTML and inline raster artwork from the authenticated original, not the text envelope. */
    public static function htmlMessage(string $raw):array
    {
        if($raw===''||strlen($raw)>self::MAX_ORIGINAL_BYTES)throw new DomainException('Originalmail muss zwischen einem Byte und 40 MB groß sein.');
        self::prepareMemory(strlen($raw));
        [$head,$body]=self::split($raw);$images=[];$parts=0;$imageBytes=0;$scanned=0;$plain=null;
        $walk=function(array$headers,string$body,int$depth=0)use(&$walk,&$images,&$parts,&$imageBytes,&$scanned,&$plain):?string{
            $scanned+=strlen($body);if($depth>8||++$parts>256||$scanned>self::MAX_SCANNED_BYTES)throw new DomainException('Mail zu verschachtelt.');
            $type=strtolower(trim(explode(';',$headers['content-type']??'text/plain')[0]));
            if(str_starts_with(strtolower($headers['content-disposition']??''),'attachment')&&!str_starts_with($type,'image/'))return null;
            if(str_starts_with($type,'multipart/')){
                $boundary=self::parameter($headers['content-type'],'boundary');if(!$boundary||strlen($boundary)>70||preg_match('/[\x00-\x1f\x7f]/',$boundary))throw new DomainException('MIME-Grenze prüfen.');
                if(!preg_match('/(?:\A|\r?\n)--'.preg_quote($boundary,'/').'--[ \t]*(?:\r?\n|\z)/',$body))throw new DomainException('MIME-Abschluss fehlt.');
                $chunks=preg_split('/(?:\A|\r?\n)--'.preg_quote($boundary,'/').'(?:--)?[ \t]*(?:\r?\n|\z)/',$body);if(count($chunks)>42)throw new DomainException('Zu viele Mailteile.');
                $html=null;foreach(array_slice($chunks,1,-1)as$chunk){try{[$head,$bytes]=self::split($chunk);$candidate=$walk(self::headers($head,true),$bytes,$depth+1);}catch(DomainException){continue;}if($candidate!==null)$html??=$candidate;}return $html;
            }
            if(!in_array($type,['text/plain','text/html','image/png','image/jpeg','image/gif','image/webp'],true))return null;
            $encoding=strtolower($headers['content-transfer-encoding']??'7bit');$decoded=match($encoding){'base64'=>base64_decode(preg_replace('/\s+/','',$body),true),'quoted-printable'=>quoted_printable_decode($body),'7bit','8bit','binary'=>$body,default=>throw new DomainException('Mailkodierung prüfen.')};if(!is_string($decoded))throw new DomainException('Mailtext beschädigt.');
            if(in_array($type,['text/plain','text/html'],true)){
                if(strlen($decoded)>262144)throw new DomainException('Mailtext zu groß.');$charset=self::parameter($headers['content-type']??'','charset')??'UTF-8';try{if(!mb_check_encoding($decoded,$charset))throw new DomainException('Mailtext beschädigt.');$decoded=mb_convert_encoding($decoded,'UTF-8',$charset);}catch(\ValueError){throw new DomainException('Mailzeichensatz unbekannt.');}
                if($type==='text/plain'){$plain??=$decoded;return null;}return $decoded;
            }
            $cid=trim($headers['content-id']??''," \t<>");if($cid===''||strlen($cid)>200||preg_match('/[\x00-\x20\x7f]/',$cid)||strlen($decoded)>2097152||$imageBytes+strlen($decoded)>4194304)return null;
            $image=@getimagesizefromstring($decoded);if(!$image||($image['mime']??'')!==$type||$image[0]*$image[1]>32000000)return null;
            $imageBytes+=strlen($decoded);if(!isset($images[$cid]))$images[$cid]='data:'.$type.';base64,'.base64_encode($decoded);return null;
        };
        $html=$walk(self::headers($head,true),$body);return ['html'=>$html,'images'=>$images,'text'=>$plain];
    }

    /** Files remain part of their byte-exact original; no extracted copy is stored. */
    public static function attachments(string $raw):array
    {
        if($raw===''||strlen($raw)>self::MAX_ORIGINAL_BYTES)throw new DomainException('Originalmail muss zwischen einem Byte und 40 MB groß sein.');
        self::prepareMemory(strlen($raw));
        [$head,$body]=self::split($raw);$files=[];$parts=0;$scanned=0;$markup=null;
        $walk=function(array $headers,string $body,int $depth=0)use(&$walk,&$files,&$parts,&$scanned,&$markup):void{
            $scanned+=strlen($body);if($depth>8||++$parts>256||$scanned>self::MAX_SCANNED_BYTES)throw new DomainException('Mail zu verschachtelt.');
            $type=strtolower(trim(explode(';',$headers['content-type']??'text/plain')[0]));$disposition=$headers['content-disposition']??'';
            if(str_starts_with($type,'multipart/')){
                $boundary=self::parameter($headers['content-type'],'boundary');if(!$boundary||strlen($boundary)>70||preg_match('/[\x00-\x1f\x7f]/',$boundary))throw new DomainException('MIME-Grenze prüfen.');
                if(!preg_match('/(?:\A|\r?\n)--'.preg_quote($boundary,'/').'--[ \t]*(?:\r?\n|\z)/',$body))throw new DomainException('MIME-Abschluss fehlt.');
                $chunks=preg_split('/(?:\A|\r?\n)--'.preg_quote($boundary,'/').'(?:--)?[ \t]*(?:\r?\n|\z)/',$body);if(count($chunks)>42)throw new DomainException('Zu viele Mailteile.');
                foreach(array_slice($chunks,1,-1)as$chunk){try{[$head,$bytes]=self::split($chunk);$walk(self::headers($head,true),$bytes,$depth+1);}catch(DomainException){continue;}}return;
            }
            $name=self::filename($disposition,'filename')??self::filename($headers['content-type']??'','name');
            $bodyPart=$name===null&&!str_starts_with(strtolower($disposition),'attachment')&&in_array($type,['text/plain','text/html'],true);
            if($bodyPart&&$type==='text/plain')return;
            if(count($files)>=64)throw new DomainException('Zu viele Mailanhänge.');
            $encoding=strtolower($headers['content-transfer-encoding']??'7bit');
            $bytes=match($encoding){'base64'=>base64_decode(preg_replace('/\s+/','',$body),true),'quoted-printable'=>quoted_printable_decode($body),'7bit','8bit','binary'=>$body,default=>throw new DomainException('Mailkodierung prüfen.')};
            if(!is_string($bytes)||strlen($bytes)>self::MAX_ORIGINAL_BYTES)throw new DomainException('Mailanhang beschädigt.');
            if($bodyPart){if(strlen($bytes)<=262144){$charset=self::parameter($headers['content-type']??'','charset')??'UTF-8';try{if(mb_check_encoding($bytes,$charset))$markup??=mb_convert_encoding($bytes,'UTF-8',$charset);}catch(\ValueError){}}return;}
            $index=count($files)+1;$extension=['application/pdf'=>'pdf','image/png'=>'png','image/jpeg'=>'jpg','image/gif'=>'gif','image/webp'=>'webp','text/plain'=>'txt','message/rfc822'=>'eml','application/zip'=>'zip'][$type]??'bin';
            $name=$name??'Anhang-'.$index.'.'.$extension;$name=mb_substr(trim(preg_replace('/[\x00-\x1f\x7f\/\\\\]/u','_',mb_scrub($name,'UTF-8'))),0,200);if($name===''||in_array($name,['.','..'],true))$name='Anhang-'.$index.'.'.$extension;
            $image=@getimagesizefromstring($bytes);$preview=$image&&in_array($image['mime']??'',['image/png','image/jpeg','image/gif','image/webp'],true)&&$image[0]*$image[1]<=32000000?$image['mime']:'';
            $cid=trim($headers['content-id']??''," \t<>");if(strlen($cid)>200||preg_match('/[\x00-\x20\x7f]/',$cid))$cid='';
            $files[]=['part'=>$index,'name'=>$name,'mime'=>$type,'size'=>strlen($bytes),'sha256'=>hash('sha256',$bytes),'preview'=>$preview,'cid'=>$cid,'bytes'=>$bytes];
        };
        $walk(self::headers($head,true),$body);$embedded=[];
        if($markup!==null){$dom=HtmlDocument::parse($markup);foreach($dom->getElementsByTagName('img')as$image){$src=$image->getAttribute('src');if(str_starts_with(strtolower($src),'cid:'))$embedded[rawurldecode(substr($src,4))]=true;}}
        foreach($files as&$file)$file['embedded']=$file['cid']!==''&&isset($embedded[$file['cid']]);unset($file);return $files;
    }

    private static function filename(string $header,string $parameter):?string
    {
        $extended=self::parameter($header,$parameter.'*');
        if($extended===null){
            for($i=0;$i<16;$i++){
                $piece=self::parameter($header,$parameter.'*'.$i.'*')??self::parameter($header,$parameter.'*'.$i);
                if($piece===null)break;$extended=($extended??'').$piece;
            }
        }
        if($extended!==null&&preg_match("/\A([^']*)'[^']*'(.*)\z/sD",$extended,$match)){
            $value=rawurldecode($match[2]);try{return mb_convert_encoding($value,'UTF-8',$match[1]?:'UTF-8');}catch(\ValueError){return mb_scrub($value,'UTF-8');}
        }
        $value=$extended??self::parameter($header,$parameter);return $value===null?null:self::displayHeader($value);
    }

    public static function parse(string $raw):array
    {
        if(strlen($raw)>2097152)throw new DomainException('Antwortmail überschreitet 2 MB.');
        [$head,$body]=self::split($raw);$headers=self::headers($head);
        $id=self::messageId($headers['message-id']??'');$references=[];$direct=[];
        foreach(['references','in-reply-to']as$key)if(isset($headers[$key])){
            preg_match_all('/<[^<>\s]+>/',$headers[$key],$matches);
            $rest=preg_replace('/<[^<>\s]+>/','',$headers[$key]);
            if(trim($rest)!==''||count($matches[0])>30)throw new DomainException('Antwortbezug prüfen.');
            foreach($matches[0]as$reference){$references[]=self::messageId($reference);if($key==='in-reply-to')$direct[]=self::messageId($reference);}
        }
        $text=self::text($headers,$body);
        if(!$text)throw new DomainException('Kein lesbarer Antworttext.');
        $auto=strtolower($headers['auto-submitted']??'no')!=='no'||isset($headers['x-autoreply'])||isset($headers['x-autorespond'])||in_array(strtolower($headers['precedence']??''),['bulk','list','junk'],true);
        $to=self::addresses($headers['to']??'');$from=self::addresses($headers['from']??'');
        if(count($from)!==1)throw new DomainException('Mail hat mehrere Absender.');
        return ['message_id'=>$id,'references'=>array_values(array_unique($references)),'in_reply_to'=>array_values(array_unique($direct)),'from'=>$from[0],'to'=>$to,'text'=>$text['text'],'automatic'=>$auto,'original'=>base64_encode($raw)]+(isset($text['reply_text'])?['reply_text'=>$text['reply_text']]:[]);
    }
}
