<?php declare(strict_types=1);
namespace Kulturhus\Verein\Finance\Invoices\Reminder;
use PDO;
use Kulturhus\Native\Delivery\DeliveryNotStarted;
use Kulturhus\Verein\MailChannelSettings;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mime\{Address,RawMessage};

/** Worker-only raw accepted MIME. Configuration and local policy fail before any SMTP transport. */
final class ReminderTransport
{
    public function __construct(private PDO $db,#[\SensitiveParameter]private string $master,private ?\Closure $transportFactory=null){}
    public function send(string $club,array $payload):string
    {
        if(getenv('KULTURHUS_DISABLE_MAIL')==='1'||!in_array(getenv('KULTURHUS_LOCAL_DEMO'),[false,'','0'],true)||getenv('VEREIN_MODE')==='synthetic'||in_array(getenv('APP_ENV'),['local','local-synthetic','development','test'],true))throw new DeliveryNotStarted('mail_delivery_disabled');
        try{$resolved=MailChannelSettings::resolve($this->db,$club,$this->master,'reminders');$config=$resolved['override'];if(!$config)throw new \DomainException('Fehlender Versandzugang.');}catch(\Throwable){throw new DeliveryNotStarted('mail_configuration_missing');}
        $identity=['from'=>$config['from'],'name'=>$config['name']??'','reply_to'=>$config['reply']?:$config['from']];
        try{
            require_once dirname(__DIR__,5).'/vendor/autoload.php';
            if($identity!==($payload['sender']??null)||!is_string($payload['mime']??null)||!hash_equals((string)($payload['mime_sha256']??''),hash('sha256',$payload['mime'])))throw new \DomainException('Ursprüngliche Nachricht oder Absender verändert.');
            $message=new RawMessage($payload['mime']);$envelope=new Envelope(new Address($identity['from']),[new Address($payload['recipient'])]);
        }catch(\Throwable){throw new DeliveryNotStarted('mail_message_invalid');}
        $transport=$this->transportFactory?($this->transportFactory)($config):new EsmtpTransport($config['host'],(int)$config['port'],(int)$config['port']===465);
        if(!$transport instanceof EsmtpTransport)throw new \RuntimeException('Ungültiger interner SMTP-Transport.');
        $transport->setUsername($config['user']);$transport->setPassword($config['password']);$transport->getStream()->setTimeout(15);if((int)$config['port']===587)$transport->setRequireTls(true);
        $sent=$transport->send($message,$envelope);if(!$sent)return '';
        // A positive DATA response need not contain a provider queue id. RawMessage has no synthesized header id.
        try{$reference=$sent->getMessageId();}catch(\Error){$reference='';}
        return $reference!==''?$reference:'smtp-accepted-sha256:'.$payload['mime_sha256'];
    }
}
