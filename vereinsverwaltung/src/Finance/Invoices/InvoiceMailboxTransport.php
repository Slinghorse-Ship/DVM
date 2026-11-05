<?php declare(strict_types=1);
namespace Kulturhus\Verein;
use DomainException;
/** Native IMAP, read-only and bounded. No mailbox flags, deletes or SMTP messages during reads. */
final class InvoiceMailboxTransport
{
    public static function publicHost(string $host):void{
        if(!preg_match('/^(?:[a-z0-9-]+\.)+[a-z]{2,63}$/iD',$host))throw new DomainException('Mailserver prüfen.');
        $records=dns_get_record($host,DNS_A|DNS_AAAA);if(!$records)throw new DomainException('Mailserver nicht auflösbar.');
        $seen=0;foreach($records as $r){$ip=$r['ip']??$r['ipv6']??'';if($ip==='')continue;++$seen;$packed=inet_pton($ip);$mapped=is_string($packed)&&strlen($packed)===16&&substr($packed,0,12)===str_repeat("\0",10)."\xff\xff";
            if($mapped||!filter_var($ip,FILTER_VALIDATE_IP,FILTER_FLAG_NO_PRIV_RANGE|FILTER_FLAG_NO_RES_RANGE))throw new DomainException('Nur öffentliche Mailserver erlaubt.');}
        if(!$seen)throw new DomainException('Mailserver hat keine öffentliche Adresse.');
    }
    private static function text(string $text,int $limit=240):string{
        $parts=imap_mime_header_decode($text);$out='';foreach($parts?:[] as $p){$charset=$p->charset==='default'?'UTF-8':$p->charset;try{$out.=mb_convert_encoding($p->text,'UTF-8',$charset);}catch(\Throwable){$out.='?';}}
        return mb_substr(preg_replace('/[\x00-\x1f\x7f]/u',' ',mb_scrub($out?:$text,'UTF-8'))??'',0,$limit);
    }
    /** RFC 2231 extended filenames and numbered continuations, independent of IMAP extension. */
    public static function filename(array $parameters):string{
        $p=[];foreach($parameters as $item)$p[strtolower((string)$item->attribute)]=(string)$item->value;
        foreach(['filename','name'] as $base){
            $value='';$encoded=false;
            if(isset($p[$base.'*'])){$value=$p[$base.'*'];$encoded=true;}
            elseif(isset($p[$base.'*0*'])||isset($p[$base.'*0'])){for($i=0;$i<30;$i++){if(isset($p[$base.'*'.$i.'*'])){$value.=$p[$base.'*'.$i.'*'];$encoded=true;}elseif(isset($p[$base.'*'.$i]))$value.=$p[$base.'*'.$i];else break;}}
            elseif(isset($p[$base]))$value=$p[$base];else continue;
            if($encoded){$charset='UTF-8';if(preg_match("/^([^']*)'[^']*'(.*)$/s",$value,$m)){$charset=$m[1]?:'UTF-8';$value=$m[2];}$value=rawurldecode($value);try{$value=mb_convert_encoding($value,'UTF-8',$charset);}catch(\Throwable){throw new DomainException('Dateiname hat eine unbekannte Zeichenkodierung.');}}
            if(strlen($value)>1000)throw new DomainException('Dateiname zu lang.');return $value;
        }return '';
    }
    public static function parts(object $node,string $part='',int $depth=0):array{
        if($depth>8)throw new DomainException('Nachricht zu verschachtelt.');$out=[];
        if(isset($node->parts)){if(count($node->parts)>40)throw new DomainException('Zu viele Nachrichtenteile.');foreach($node->parts as $i=>$child)$out=array_merge($out,self::parts($child,$part===''?(string)($i+1):$part.'.'.($i+1),$depth+1));}
        else{$name=self::filename(array_merge($node->dparameters??[],$node->parameters??[]));if($name===''&&strtolower((string)($node->disposition??''))==='attachment')$name='anhang-'.($part?:'1').'.'.match(strtolower((string)($node->subtype??''))){'pdf'=>'pdf','xml'=>'xml','png'=>'png','jpeg'=>'jpg','heic'=>'heic','heif'=>'heif',default=>'bin'};if($name!=='')$out[]=['part'=>$part?:'1','name'=>$name,'encoding'=>(int)($node->encoding??0),'size'=>(int)($node->bytes??0)];}
        if(count($out)>10)throw new DomainException('Mehr als zehn Anhänge.');return $out;
    }
    public static function read(array $c,int $validity,int $last):array{
        if(!function_exists('imap_open'))throw new DomainException('IMAP-Erweiterung fehlt.');self::publicHost($c['imap_host']);
        foreach([IMAP_OPENTIMEOUT,IMAP_READTIMEOUT,IMAP_WRITETIMEOUT] as $timeout)imap_timeout($timeout,15);
        $mailbox='{'.$c['imap_host'].':993/imap/ssl/validate-cert/norsh}'.$c['folder'];$stream=@imap_open($mailbox,$c['imap_user'],$c['imap_password'],OP_READONLY,1);if(!$stream){imap_errors();imap_alerts();throw new DomainException('IMAP-Verbindung fehlgeschlagen.');}
        try{
            $status=imap_status($stream,$mailbox,SA_UIDVALIDITY);if(!$status||!$status->uidvalidity)throw new DomainException('IMAP-Status fehlt.');$current=(int)$status->uidvalidity;$after=$current===$validity?$last:0;
            // PHP's c-client search parser does not accept the IMAP UID criterion.
            // Request UIDs via SE_UID and apply the saved cursor to those results.
            // A failed search must not masquerade as a successfully empty mailbox.
            $since=(new \DateTimeImmutable($c['since']))->format('d-M-Y');imap_errors();
            $uids=imap_search($stream,'SINCE "'.$since.'"',SE_UID);$searchErrors=imap_errors();
            if($uids===false&&$searchErrors)throw new DomainException('Nachrichten konnten nicht gesucht werden.');
            $uids=$uids?:[];
            $uids=array_values(array_filter(array_map('intval',$uids),fn($uid)=>$uid>$after));sort($uids);$messages=[];$total=0;$deadline=time()+45;
            foreach(array_slice($uids,0,25) as $uid){if(time()>$deadline)break;$overview=imap_fetch_overview($stream,(string)$uid,FT_UID)[0]??null;if(!$overview)throw new DomainException('Nachricht nicht lesbar.');
                $mail=['uid'=>$uid,'subject'=>self::text($overview->subject??'',200),'sender'=>self::text($overview->from??'',254),'date'=>date('Y-m-d',(int)($overview->udate??time())),'attachments'=>[],'message_id'=>self::text($overview->message_id??'',500),'original_date'=>self::text($overview->date??'',200)];
                if(($overview->size??0)>15728640){$mail['error']=true;$messages[]=$mail;continue;}
                try{$structure=imap_fetchstructure($stream,$uid,FT_UID);if(!$structure)throw new \RuntimeException('Nachrichtenstruktur vorübergehend nicht lesbar.');
                    foreach(self::parts($structure) as $part){if(time()>$deadline)return ['uidvalidity'=>$current,'messages'=>$messages];if($part['size']>14680064)throw new DomainException('Anhang zu groß.');$raw=imap_fetchbody($stream,$uid,$part['part'],FT_UID|FT_PEEK);if(!is_string($raw))throw new \RuntimeException('Anhang vorübergehend nicht lesbar.');$bytes=match($part['encoding']){3=>base64_decode($raw,true),4=>quoted_printable_decode($raw),default=>$raw};if(!is_string($bytes)||strlen($bytes)>10485760)throw new DomainException('Anhang ungültig.');$total+=strlen($bytes);if($total>41943040)return ['uidvalidity'=>$current,'messages'=>$messages];$mail['attachments'][]=['name'=>self::text($part['name']),'bytes'=>$bytes];}
                }catch(DomainException){$mail['error']=true;$mail['attachments']=[];}
                if(empty($mail['error'])){if(time()>$deadline)return ['uidvalidity'=>$current,'messages'=>$messages];$header=imap_fetchheader($stream,$uid,FT_UID);$body=imap_body($stream,$uid,FT_UID|FT_PEEK);if(!is_string($header)||!is_string($body))throw new \RuntimeException('Originalnachricht nicht lesbar.');$original=$header.$body;if(strlen($original)>15728640){$mail['error']=true;$mail['attachments']=[];}else{$total+=strlen($original);if($total>41943040)return ['uidvalidity'=>$current,'messages'=>$messages];$mail['original']=$original;}}
                $messages[]=$mail;if($total>41943040)break;
            }return ['uidvalidity'=>$current,'messages'=>$messages];
        }finally{imap_close($stream);imap_errors();imap_alerts();}
    }
    public static function sender(array $c,string $publicUrl):\Closure{
        if(!MailPolicy::allowed($publicUrl))throw new DomainException('Mailversand in dieser Umgebung gesperrt.');self::publicHost($c['smtp_host']);
        if(!class_exists(\Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport::class))throw new DomainException('SMTP-Laufzeit nicht verfügbar.');
        $transport=new \Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport($c['smtp_host'],(int)$c['smtp_port'],$c['smtp_port']==='465');
        if($c['smtp_port']==='587'){if(!method_exists($transport,'setRequireTls'))throw new DomainException('SMTP-Laufzeit für verpflichtendes STARTTLS fehlt. Bitte TLS-Port 465 verwenden.');$transport->setAutoTls(true);$transport->setRequireTls(true);}
        $transport->setUsername($c['smtp_user']);$transport->setPassword($c['smtp_password']);$mailer=new \Symfony\Component\Mailer\Mailer($transport);
        return static function(string $email,string $subject,string $text,string $html,array $attachments=[])use($mailer,$c,$publicUrl):void{
            if(!MailPolicy::allowed($publicUrl))throw new DomainException('Mailversand gesperrt.');self::publicHost($c['smtp_host']);
            try{$message=(new \Symfony\Component\Mime\Email())->from(new \Symfony\Component\Mime\Address($c['email'],$c['smtp_name']??''))->to($email)->subject($subject)->text($text)->html($html);foreach($attachments as $file)$message->attach($file['bytes'],$file['name'],$file['mime']);$mailer->send($message);}catch(\Throwable){throw new DomainException('Rechnungsversand nicht bestätigt; vor Wiederholung Versandstatus prüfen.');}
        };
    }
}
