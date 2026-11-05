<?php declare(strict_types=1);
namespace Kulturhus\Verein\Communication;

use DomainException;
use Kulturhus\Verein\{MailPolicy,InvoiceMailboxTransport};
use Kulturhus\Native\Delivery\{MailMessage,DeliveryNotStarted};
use Kulturhus\Native\Communication\Mail\Mime;

/** Read-only IMAP and an explicit SMTP reply. No flags, deletes, imports or automatic responses. */
final class MailboxTransport
{
    public static function read(array $config,int $validity,int $last):array
    {
        if(!MailPolicy::allowed((string)getenv('VEREIN_PUBLIC_URL')))throw new DomainException('Postfachabruf ist in dieser Umgebung gesperrt.');
        if(!function_exists('imap_open'))throw new DomainException('Für den Postfachabruf fehlt die IMAP-Erweiterung.');
        InvoiceMailboxTransport::publicHost($config['imap_host']);
        foreach([IMAP_OPENTIMEOUT,IMAP_READTIMEOUT,IMAP_WRITETIMEOUT] as $timeout)imap_timeout($timeout,15);
        $mailbox='{'.$config['imap_host'].':993/imap/ssl/validate-cert/norsh}'.$config['folder'];
        $stream=@imap_open($mailbox,$config['imap_user'],$config['imap_password'],OP_READONLY,1);
        if(!$stream){imap_errors();imap_alerts();throw new DomainException('IMAP-Verbindung fehlgeschlagen.');}
        try{
            $status=imap_status($stream,$mailbox,SA_UIDVALIDITY);if(!$status||!$status->uidvalidity)throw new DomainException('IMAP-Status fehlt.');
            $current=(int)$status->uidvalidity;$after=$current===$validity?$last:0;
            $since=(new \DateTimeImmutable($config['since']))->format('d-M-Y');imap_errors();
            $uids=imap_search($stream,'SINCE "'.$since.'"',SE_UID);$errors=imap_errors();
            if($uids===false&&$errors)throw new DomainException('Nachrichten konnten nicht gesucht werden.');
            $uids=array_values(array_filter(array_map('intval',$uids?:[]),static fn(int $uid):bool=>$uid>$after));sort($uids);
            $messages=[];$bytes=0;$deadline=microtime(true)+45;
            foreach(array_slice($uids,0,25)as$uid){
                if(microtime(true)>$deadline)break;
                $overview=@imap_fetch_overview($stream,(string)$uid,FT_UID)[0]??null;
                if(!$overview){if(imap_msgno($stream,$uid)===0)continue;throw new DomainException('E-Mail konnte nicht abgerufen werden. Bitte später erneut versuchen.');}
                if(($overview->size??0)>Mime::MAX_ORIGINAL_BYTES)throw new DomainException('Diese E-Mail überschreitet die unterstützte Größe von 40 MB.');
                if($messages&&$bytes+($overview->size??0)>Mime::MAX_BATCH_BYTES)break;
                Mime::prepareMemory((int)($overview->size??0));
                $header=imap_fetchheader($stream,$uid,FT_UID);$body=imap_body($stream,$uid,FT_UID|FT_PEEK);
                if(!is_string($header)||!is_string($body))throw new DomainException('Originalnachricht vorübergehend nicht lesbar.');
                $raw=$header.$body;if(strlen($raw)>Mime::MAX_ORIGINAL_BYTES)throw new DomainException('Diese E-Mail überschreitet die unterstützte Größe von 40 MB.');$bytes+=strlen($raw);if($bytes>Mime::MAX_BATCH_BYTES)break;
                $messages[]=['uid'=>$uid,'original'=>$raw];
            }
            return ['uidvalidity'=>$current,'messages'=>$messages];
        }finally{imap_close($stream);imap_errors();imap_alerts();}
    }
    public static function compose(array $account,array $message):\Symfony\Component\Mime\Email
    {
        require_once dirname(__DIR__,3).'/vendor/autoload.php';
        $mail=MailMessage::compose($account,$message);
        // Shared bulk-mail validation normalizes addresses; this reply retains the selected original local part.
        $mail->to(new \Symfony\Component\Mime\Address($message['to']));
        $mail->getHeaders()->addIdHeader('Message-ID',substr(Mime::messageId($message['message_id']),1,-1));
        if($message['parent_id']!==''){
            $parent=substr(Mime::messageId($message['parent_id']),1,-1);
            $references=[];foreach($message['references']??[]as$id)$references[substr(Mime::messageId($id),1,-1)]=true;unset($references[$parent]);$references=array_slice(array_keys($references),-29);$references[]=$parent;
            $mail->getHeaders()->addIdHeader('In-Reply-To',$parent);$mail->getHeaders()->addIdHeader('References',$references);
        }
        if(isset($message['quote'])){
            $quote=$message['quote'];$heading='Von: '.$quote['from']."\nEmpfangen: ".$quote['received_at']."\nBetreff: ".$quote['subject'];$text=str_replace(["\r\n","\r"],"\n",$quote['text']);
            $mail->text($message['text']."\n\n----- Zitierte Nachricht -----\n".$heading."\n\n> ".str_replace("\n","\n> ",$text));
            $e=static fn(string $s):string=>htmlspecialchars($s,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');$mail->html(($message['html']??'<p>'.nl2br($e($message['text']),false).'</p>').'<hr><blockquote type="cite"><p>'.nl2br($e($heading),false).'</p><div style="white-space:pre-wrap">'.$e($text).'</div></blockquote>');
        }
        return $mail;
    }
    public static function ready(array $account):void
    {
        if(!MailPolicy::allowed((string)getenv('VEREIN_PUBLIC_URL')))throw new DeliveryNotStarted('mail_delivery_disabled');
        InvoiceMailboxTransport::publicHost($account['host']);
    }
    public static function send(array $account,array $message):string
    {
        self::ready($account);$mail=self::compose($account,$message);
        $transport=new \Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport($account['host'],$account['port'],$account['port']===465);
        $transport->setUsername($account['username']);$transport->setPassword($account['password']);$transport->getStream()->setTimeout(15);
        if($account['port']===587)$transport->setRequireTls(true);
        return MailMessage::acceptedMessageId($transport->send($mail));
    }
}
