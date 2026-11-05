<?php declare(strict_types=1);
namespace Kulturhus\Verein;
use PDO;

/** Durable once-per-application/address notification. Local environments never dispatch. */
final class ApplicationNotifications
{
    private static function recipients(PDO $db,string $club):array
    {
        $rows=DocumentCatalog::query($db,"SELECT c.email FROM admission_board b JOIN actors a ON a.id=b.actor_id AND a.club_id=b.club_id JOIN contacts c ON c.club_id=a.club_id AND c.id=a.contact_id WHERE b.club_id=? AND b.enabled=1 AND a.active=1 AND a.role!='editor'",[$club])->fetchAll();$emails=[];
        foreach($rows as $r){$email=strtolower(trim($r['email']));if(filter_var($email,FILTER_VALIDATE_EMAIL))$emails[$email]=true;}return array_keys($emails);
    }
    public static function enqueue(PDO $db,string $club,string $application):void
    {
        foreach(self::recipients($db,$club) as $email)DocumentCatalog::query($db,"INSERT IGNORE INTO application_notifications VALUES(?,?,?,'pending',?,NULL,NULL)",[$club,$application,$email,gmdate('c')]);
    }
    public static function dispatch(PDO $db,string $club,string $publicUrl,callable $send,?string $clubName=null):int
    {
        if(getenv('KULTURHUS_LOCAL_DEMO')==='1'||getenv('KULTURHUS_DISABLE_MAIL')==='1'||getenv('VEREIN_MODE')==='synthetic'||in_array(getenv('APP_ENV'),['local','local-synthetic'],true))return 0;
        $host=strtolower((string)parse_url($publicUrl,PHP_URL_HOST));if(parse_url($publicUrl,PHP_URL_SCHEME)!=='https'||$host===''||$host==='localhost'||str_ends_with($host,'.localhost')||filter_var($host,FILTER_VALIDATE_IP)||parse_url($publicUrl,PHP_URL_USER)!==null)return 0;
        $allowed=self::recipients($db,$club);$sent=0;
        $rows=DocumentCatalog::query($db,"SELECT * FROM application_notifications WHERE club_id=? AND status='pending' ORDER BY created_at LIMIT 20",[$club])->fetchAll();
        foreach($rows as $r){$key=[$club,$r['application_id'],$r['email']];$pending=DocumentCatalog::query($db,"SELECT 1 FROM membership_applications WHERE club_id=? AND id=? AND status='pending'",[$club,$r['application_id']])->fetchColumn();
            if(!$pending||!in_array($r['email'],$allowed,true)){DocumentCatalog::query($db,"UPDATE application_notifications SET status='skipped' WHERE club_id=? AND application_id=? AND email=? AND status='pending'",$key);continue;}
            $claimed=DocumentCatalog::query($db,"UPDATE application_notifications SET status='sending',attempted_at=? WHERE club_id=? AND application_id=? AND email=? AND status='pending'",array_merge([gmdate('c')],$key));if($claimed->rowCount()!==1)continue;
            try{$link=rtrim($publicUrl,'/').'/?view=portal&tab=application&application='.rawurlencode($r['application_id']);$send($r['email'],'Neuer Mitgliedsantrag · '.($clubName??'Kulturhus'),'Ein neuer Mitgliedsantrag liegt zur Prüfung vor. Bitte im Vereinsmanager anmelden: '.$link."\nDie Abstimmung wird nach Prüfung der Vorstandsbesetzung freigegeben.");DocumentCatalog::query($db,"UPDATE application_notifications SET status='sent',sent_at=? WHERE club_id=? AND application_id=? AND email=? AND status='sending'",array_merge([gmdate('c')],$key));++$sent;}
            catch(\Kulturhus\Native\Delivery\DeliveryNotStarted $e){
                DocumentCatalog::query($db,"UPDATE application_notifications SET status=? WHERE club_id=? AND application_id=? AND email=? AND status='sending'",array_merge([$e->retryable?'pending':'skipped'],$key));
            }
            catch(\Throwable){
                $actual=DocumentCatalog::query($db,'SELECT * FROM application_notifications WHERE club_id=? AND application_id=? AND email=?',$key)->fetch();
                if($actual&&$actual['status']==='sent'&&$actual['sent_at']!==null&&$actual['created_at']===$r['created_at']){++$sent;continue;}
                DocumentCatalog::query($db,"UPDATE application_notifications SET status='uncertain' WHERE club_id=? AND application_id=? AND email=? AND status='sending'",$key);
            }
        }return $sent;
    }
}
