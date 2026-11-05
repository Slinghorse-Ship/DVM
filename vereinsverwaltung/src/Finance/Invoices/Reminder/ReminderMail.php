<?php declare(strict_types=1);
namespace Kulturhus\Verein\Finance\Invoices\Reminder;
use DomainException;
use PDO;
use Kulturhus\Native\Shared\Db;
use Kulturhus\Native\Delivery\MailMessage;
use Kulturhus\Verein\{MailChannelSettings,Permissions};
/** Configured sender evidence is separate from any attempted SMTP connection. */
final class ReminderMail
{
    public static function message(array $sender,array $context,array $made):\Symfony\Component\Mime\Email
    {
        require_once dirname(__DIR__,5).'/vendor/autoload.php';
        return MailMessage::compose($sender,['to'=>$context['invoice']['data']['buyer_email'],'subject'=>$made['subject'],'text'=>$made['message'],'html'=>$made['html'],'attachments'=>$made['attachments']]);
    }
    public static function identity(PDO $db,string $actor,#[\SensitiveParameter]string $key,array $context):array
    {
        $own=!$db->inTransaction();
        try{
            if($own)$db->exec('START TRANSACTION');$a=Permissions::finance($db,$actor);if($context['invoice']['club_id']!==$a['club_id'])throw new DomainException('Eigene ursprüngliche Rechnung auswählen.',404);
            $row=Db::run($db,'SELECT * FROM mail_channel_settings WHERE club_id=? FOR SHARE NOWAIT',[$a['club_id']])->fetch();$resolved=MailChannelSettings::resolve($db,$a['club_id'],$key,'reminders');
            $source=['purpose'=>'reminders','channel'=>$resolved['channel'],'version'=>(int)($row['version']??0),'config_sha256'=>$row?hash('sha256',$row['config']):null,'sealed_settings_sha256'=>$row?hash('sha256',$row['secrets']):null];
            $ready=['configured'=>false,'mime_ready'=>false,'connection_confirmed'=>false,'source'=>$source,'mime'=>null,'sender'=>null];
            if($config=$resolved['override']){
                $sender=['from'=>$config['from'],'name'=>$config['name']??'','reply_to'=>$config['reply']?:$config['from']];
                $ready=['configured'=>true,'mime_ready'=>false,'connection_confirmed'=>false,'source'=>$source,'mime'=>null,'sender'=>$sender];
            }
            if($own)$db->commit();return $ready;
        }catch(\Throwable $failure){if($own&&$db->inTransaction())$db->rollBack();throw $failure;}
    }
    public static function prepare(PDO $db,string $actor,#[\SensitiveParameter]string $key,array $context,array $made):array
    {
        $ready=self::identity($db,$actor,$key,$context);if(!$ready['configured'])return $ready;
        $mail=self::message($ready['sender'],$context,$made);
        return array_replace($ready,['mime_ready'=>true,'mime'=>$mail->toString(),'effective_subject'=>$mail->getSubject(),'effective_recipient'=>$mail->getTo()[0]->getAddress()]);
    }
}
