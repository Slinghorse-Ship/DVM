<?php declare(strict_types=1);
namespace Kulturhus\Verein\Finance\Invoices\Reminder;
use DomainException;
use Kulturhus\Native\Shared\SecretBox;

/** Only approved message bytes; transport passwords and private approval reasons never belong here. */
final class ReminderEnvelope
{
    private static function box(#[\SensitiveParameter]string $master):SecretBox
    {
        if(strlen($master)<32)throw new DomainException('Privater Erinnerungsschlüssel nicht verfügbar.',503);
        return new SecretBox(hash_hmac('sha256','dvm-invoice-reminder-envelope/1',$master,true));
    }
    public static function seal(#[\SensitiveParameter]string $master,string $club,string $id,array $payload):string
    {
        return self::box($master)->seal('invoice-reminder:'.$club.':'.$id,$payload);
    }
    public static function open(#[\SensitiveParameter]string $master,array $row):array
    {
        try{
            $payload=self::box($master)->open('invoice-reminder:'.$row['club_id'].':'.$row['id'],$row['sealed_message']);$mime=base64_decode($payload['mime_base64']??'',true);
            if(($payload['format']??null)!=='dvm-invoice-reminder-message/1'||($payload['invoice_id']??null)!==$row['invoice_id']||($payload['recipient']??null)!==$row['recipient']||($payload['financial_source_sha256']??null)!==$row['source_sha256']||!is_string($mime)||$mime===''||!hash_equals((string)($payload['mime_sha256']??''),hash('sha256',$mime))||!hash_equals($row['message_sha256'],hash('sha256',ReminderRequests::json($payload))))throw new DomainException('Ungültiges ursprüngliches Nachrichtenpaket.');
            foreach(['from','name','reply_to']as$field)if(!is_string($payload['sender'][$field]??null))throw new DomainException('Ursprünglichen Absender prüfen.');
            return array_replace($payload,['mime'=>$mime]);
        }catch(\Throwable $failure){throw new DomainException('Ursprüngliche Erinnerungs-E-Mail bitte prüfen lassen.',503,$failure);}
    }
}
