<?php declare(strict_types=1);
namespace Kulturhus\Verein\Finance\Invoices;

use DomainException;
use Kulturhus\Verein\{SettingsSchema,ApplicationMail};
use Kulturhus\Native\Delivery\MailMessage;

/** New explicitly formatted messages retain their rendered body; older plain bodies stay literal. */
final class OutgoingMailMessage
{
    public const FORMAT='dvm-outgoing-invoice-mail/1';
    public static function json(array $value):string{return json_encode($value,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRESERVE_ZERO_FRACTION);}
    public static function make(array $context,array $input):array
    {
        $normalized=OutgoingMailInput::normalize($input);$subject=SettingsSchema::interpolate($normalized['subject'],$context['values']);$message=SettingsSchema::interpolate($normalized['message'],$context['values']);$number=$context['invoice']['number'];
        if($context['invoice']['kind']==='invoice'&&$context['payment']['open_cents']>0)$footer='Verwendungszweck bei Überweisung (Rechnungsnummer): '.$number."\nOffener Betrag: ".$context['values']['ZAHLBETRAG'];
        else$footer='Dokumentnummer: '.$number.($context['invoice']['kind']==='invoice'?"\nKein offener Betrag.":'');
        $message=trim($message)."\n\n".$footer;$html=SettingsSchema::html($message);
        $payload=['format'=>self::FORMAT,'invoice'=>$context['invoice']['id'],'document_sha256'=>$context['file']['sha256'],'subject'=>$subject,'subject_input'=>$normalized['subject'],'message_input'=>$normalized['message'],'message'=>$message,'html'=>$html,'club_name'=>$context['clubName'],'payment_open_cents'=>$context['payment']===null?'':(string)$context['payment']['open_cents'],'payment_source_sha256'=>hash('sha256',self::json($context['payment']??[]))];
        $payload['sha256']=hash('sha256',self::json($payload));
        self::proof($context,$subject,$message,$html);
        return['subject'=>$subject,'body'=>self::json($payload),'message'=>$message,'html'=>$html,'normalized'=>$normalized,'payload'=>$payload];
    }
    public static function proof(array $context,string $subject,string $message,string $html):void
    {
        try{
            require_once dirname(__DIR__,4).'/vendor/autoload.php';
            MailMessage::compose(['from'=>'preview@example.invalid','name'=>'DVM Vorschau','reply_to'=>'preview@example.invalid'],['to'=>$context['invoice']['data']['buyer_email'],'subject'=>$subject,'text'=>$message,'html'=>$html,'attachments'=>[['name'=>$context['file']['name'],'mime'=>$context['file']['mime'],'bytes'=>$context['file']['bytes']]]])->toString();
        }catch(DomainException|\InvalidArgumentException|\Symfony\Component\Mime\Exception\RfcComplianceException$failure){throw new DomainException('Empfänger, ganzen Betreff und Nachricht nach Variablenersetzung sowie das Original-PDF prüfen.',422,$failure);}
        catch(\Throwable$failure){throw new DomainException('Die Rechnungs-Mailvorschau benötigt das vollständige installierte Mailpaket. Bitte erneut prüfen.',503,$failure);}
    }
    public static function open(array $row):array
    {
        if(!is_string($row['body']??null)||!is_string($row['subject']??null))throw new DomainException('Gespeicherten Rechnungs-Mailauftrag bitte prüfen lassen.',503);
        if(!str_starts_with(ltrim($row['body']),'{'))return['format'=>'legacy-plain','subject'=>$row['subject'],'subject_input'=>$row['subject'],'message_input'=>$row['body'],'message'=>$row['body'],'html'=>null,'club_name'=>'Mein Verein','payment_open_cents'=>''];
        try{$data=json_decode($row['body'],true,64,JSON_THROW_ON_ERROR);}catch(\Throwable$failure){throw new DomainException('Gespeicherten Rechnungs-Mailauftrag bitte prüfen lassen.',503,$failure);}
        $wanted=['format','invoice','document_sha256','subject','subject_input','message_input','message','html','club_name','payment_open_cents','payment_source_sha256','sha256'];$keys=is_array($data)?array_keys($data):[];sort($wanted);sort($keys);
        if($keys!==$wanted)throw new DomainException('Gespeicherten Rechnungs-Mailauftrag bitte prüfen lassen.',503);
        foreach($data as$value)if(!is_string($value)||strlen($value)>2000000)throw new DomainException('Gespeicherten Rechnungs-Mailauftrag bitte prüfen lassen.',503);
        if($data['payment_open_cents']!==''&&!preg_match('/\A(?:0|[1-9][0-9]{0,11})\z/D',$data['payment_open_cents']))throw new DomainException('Gespeicherten Rechnungs-Mailauftrag bitte prüfen lassen.',503);
        $hash=$data['sha256'];unset($data['sha256']);
        if($data['format']!==self::FORMAT||$data['invoice']!==$row['invoice_id']||$data['document_sha256']!==$row['document_sha256']||$data['subject']!==$row['subject']||!preg_match('/\A[a-f0-9]{64}\z/D',$hash)||!hash_equals($hash,hash('sha256',self::json($data))))throw new DomainException('Gespeicherten Rechnungs-Mailauftrag bitte prüfen lassen.',503);
        return$data+['sha256'=>$hash];
    }
    public static function html(array $message,string $url,string $subject):string
    {
        return ApplicationMail::html($message['message'],$url,$subject,'','','RECHNUNG',$message['club_name'],$message['html']);
    }
}
