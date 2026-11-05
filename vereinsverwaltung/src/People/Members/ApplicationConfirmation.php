<?php declare(strict_types=1);
namespace Kulturhus\Verein;
use PDO;
use DomainException;

/** Unconfirmed submissions are separate from board applications and PDF archives. */
final class ApplicationConfirmation
{
    public static function enqueue(PDO $db,string $club,array $input,string $key,int $version,bool $consent,bool $newsletter,?array $review=null):string
    {
        $old=DocumentCatalog::query($db,'SELECT id FROM application_confirmations WHERE club_id=? AND request_key=?',[$club,$key])->fetchColumn();if($old)return $old;
        $email=strtolower(trim($input['email']??''));if(!filter_var($email,FILTER_VALIDATE_EMAIL))throw new DomainException('Für die Bestätigung bitte eine gültige E-Mail-Adresse angeben.');
        if(PHP_SAPI!=='cli'&&ProductionRuntime::nativeManager()&&getenv('VEREIN_MODE')==='production'){
            $box=\Kulturhus\Native\Shared\SecretBox::fromFile((string)getenv('KH_NATIVE_PRIVATE_KEY_FILE'));
            if(!\Kulturhus\Native\Shared\PublicFormLimits::membership($db,\Kulturhus\Native\Integration\ManagerBridge::clubKey($club),$box,(string)($_SERVER['REMOTE_ADDR']??''),$email))throw new DomainException('Zu viele Bestätigungsanfragen. Bitte später erneut versuchen. Deine Angaben bleiben erhalten.');
        }
        $now=time();
        // Bound pending requests and confirmation mail per address and club.
        $recent=(int)DocumentCatalog::query($db,'SELECT COUNT(*) FROM application_confirmations WHERE club_id=? AND email=? AND created_at>?',[$club,$email,$now-3600])->fetchColumn();
        $total=(int)DocumentCatalog::query($db,'SELECT COUNT(*) FROM application_confirmations WHERE club_id=? AND created_at>?',[$club,$now-3600])->fetchColumn();
        if($recent>=6||$total>=100)throw new DomainException('Zu viele Anfragen. Bitte später erneut versuchen.');
        $id=bin2hex(random_bytes(16));$token=bin2hex(random_bytes(32));
        DocumentCatalog::query($db,'INSERT INTO application_confirmations(id,club_id,request_key,email,payload,configuration_version,consent,newsletter,token_hash,mail_token,created_at,expires_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)',[$id,$club,$key,$email,json_encode($review===null?$input:['format'=>'confirmation-review-v1','input'=>$input,'review'=>$review],JSON_THROW_ON_ERROR),$version,(int)$consent,(int)$newsletter,hash('sha256',$token),$token,$now,$now+172800]);
        return $id;
    }
    public static function confirm(PDO $db,string $club,string $token,MembershipApplications $intake):string
    {
        if(!preg_match('/^[a-f0-9]{64}$/D',$token))throw new DomainException('Bestätigungslink ungültig.');
        $r=DocumentCatalog::query($db,'SELECT * FROM application_confirmations WHERE club_id=? AND token_hash=?',[$club,hash('sha256',$token)])->fetch();
        if(!$r)throw new DomainException('Bestätigungslink ungültig.');
        if($r['application_id'])return $r['application_id'];
        if((int)$r['expires_at']<time())throw new DomainException('Der Bestätigungslink ist abgelaufen. Bitte einen neuen Antrag ausfüllen.');
        // submit is transactional and idempotent by request key, including concurrent confirmations.
        $payload=json_decode($r['payload'],true,64,JSON_THROW_ON_ERROR);if(($payload['format']??null)==='confirmation-review-v1'){$review=$payload['review'];$type=$review['answers']['membership_type'];if($intake->rates()[$type==='single'?'single':'family']['amount_cents']!==$review['answers']['annual_fee_cents'])throw new DomainException('Der Jahresbeitrag wurde geändert. Bitte den Antrag mit den aktuellen Bedingungen erneut prüfen.');$payload=$payload['input'];}
        $id=$intake->submit($payload,$r['request_key'],(int)$r['configuration_version'],(bool)$r['consent']);
        DocumentCatalog::query($db,"UPDATE application_confirmations SET confirmed_at=?,application_id=?,mail_token=NULL,newsletter_status=CASE WHEN newsletter=1 THEN 'pending' ELSE 'skipped' END WHERE id=? AND application_id IS NULL",[time(),$id,$r['id']]);
        return $id;
    }
    public static function dispatch(PDO $db,string $club,string $url,callable $send,?string $clubName=null):int
    {
        if(getenv('KULTURHUS_LOCAL_DEMO')==='1'||getenv('KULTURHUS_DISABLE_MAIL')==='1'||getenv('VEREIN_MODE')==='synthetic'||in_array(getenv('APP_ENV'),['local','local-synthetic'],true))return 0;
        $host=strtolower((string)parse_url($url,PHP_URL_HOST));if(parse_url($url,PHP_URL_SCHEME)!=='https'||$host===''||$host==='localhost'||str_ends_with($host,'.localhost')||filter_var($host,FILTER_VALIDATE_IP)||parse_url($url,PHP_URL_USER)!==null)return 0;
        $sent=0;
        foreach(DocumentCatalog::query($db,"SELECT * FROM application_confirmations WHERE club_id=? AND mail_status='pending' AND application_id IS NULL AND expires_at>? ORDER BY created_at LIMIT 20",[$club,time()])->fetchAll() as $r){
            if(DocumentCatalog::query($db,"UPDATE application_confirmations SET mail_status='sending' WHERE id=? AND mail_status='pending'",[$r['id']])->rowCount()!==1)continue;
            try{$send($r['email'],'Bitte Mitgliedsantrag bestätigen · '.($clubName??'Kulturhus'),'Bitte bestätige deinen Mitgliedsantrag innerhalb von 48 Stunden: '.rtrim($url,'/').'/?confirm_application='.rawurlencode($r['mail_token'])."\nErst nach deiner Bestätigung wird der Antrag dem Vorstand zur Prüfung vorgelegt. Wenn du keinen Antrag gestellt hast, ignoriere diese Nachricht.",((json_decode($r['payload'],true,64,JSON_THROW_ON_ERROR)['review']??null)));DocumentCatalog::query($db,"UPDATE application_confirmations SET mail_status='sent',mail_token=NULL WHERE id=?",[$r['id']]);$sent++;}
            catch(\Kulturhus\Native\Delivery\DeliveryNotStarted $e){$retry=$e->retryable&&(int)$r['expires_at']>time();DocumentCatalog::query($db,"UPDATE application_confirmations SET mail_status=?,mail_token=IF(?=1,mail_token,NULL) WHERE club_id=? AND id=? AND mail_status='sending'",[$retry?'pending':'blocked',(int)$retry,$club,$r['id']]);}
            catch(\Throwable){if(DocumentCatalog::query($db,"SELECT 1 FROM application_confirmations WHERE club_id=? AND id=? AND token_hash=? AND mail_status='sent' AND mail_token IS NULL",[$club,$r['id'],$r['token_hash']])->fetchColumn()){$sent++;continue;}DocumentCatalog::query($db,"UPDATE application_confirmations SET mail_status='uncertain',mail_token=NULL WHERE club_id=? AND id=? AND mail_status='sending'",[$club,$r['id']]);}
        }return $sent;
    }
}
