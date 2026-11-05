<?php declare(strict_types=1);
namespace Kulturhus\Native\Communication\Mail;

use PDO;
use DomainException;
use Kulturhus\Native\Shared\SecretBox;
use Kulturhus\Native\Delivery\{MailAccount,MailMessage};
use Kulturhus\Native\Integration\ManagerClub;
use Kulturhus\Verein\{MailPolicy,InvoiceMailboxTransport};
require_once dirname(__DIR__,3).'/src/autoload.php';

/** Existing SMTP routing and read-only IMAP transport; no new provider account. */
final class Transport
{
    public static function ready(PDO $db,SecretBox $box,string $club):void
    {
        if(!MailPolicy::allowed((string)getenv('APP_URL')))throw new DomainException('Mailversand ist in dieser Umgebung gesperrt.');
        $account=(new MailAccount($db,$box))->configuration($club,ManagerClub::id($db,$club)!==null?'invitations':'newsletter');
        InvoiceMailboxTransport::publicHost($account['host']);
    }
    public static function compose(array $account,array $config,array $message):\Symfony\Component\Mime\Email
    {
        $account['reply_to']=$config['reply_to'];
        $mail=MailMessage::compose($account,$message);
        $mail->getHeaders()->addIdHeader('Message-ID',substr(Mime::messageId($message['message_id']),1,-1));
        return $mail;
    }
    public static function send(PDO $db,SecretBox $box,string $club,array $config,array $message):string
    {
        self::ready($db,$box,$club);
        $account=(new MailAccount($db,$box))->configuration($club,ManagerClub::id($db,$club)!==null?'invitations':'newsletter');
        $mail=self::compose($account,$config,$message);
        $transport=new \Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport($account['host'],$account['port'],$account['port']===465);
        $transport->setUsername($account['username']);$transport->setPassword($account['password']);$transport->getStream()->setTimeout(15);
        if($account['port']===587)$transport->setRequireTls(true);
        return MailMessage::acceptedMessageId($transport->send($mail));
    }
    public static function read(array $config,int $validity,int $last):array
    {
        if(!MailPolicy::allowed((string)getenv('APP_URL')))throw new DomainException('Postfachabruf ist in dieser Umgebung gesperrt.');
        return InvoiceMailboxTransport::read($config,$validity,$last);
    }
}
