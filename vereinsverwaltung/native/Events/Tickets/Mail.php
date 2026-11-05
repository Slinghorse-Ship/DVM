<?php declare(strict_types=1);
namespace Kulturhus\Native\Events\Tickets;

use PDO;
use Kulturhus\Native\Shared\{Db,Identity,SecretBox};
use Kulturhus\Verein\{ApplicationMail,MailPolicy};
require_once dirname(__DIR__,3).'/src/autoload.php';

/** One confirmation per issued online order. SMTP uncertainty never creates a retry. */
final class Mail
{
    public static function status(PDO $db,string $club,string $order):?string
    {
        $state=Db::run($db,'SELECT status FROM native_ticket_mail WHERE club_id=? AND order_id=?',[$club,$order])->fetchColumn();
        return is_string($state)?$state:null;
    }

    public static function run(PDO $db,SecretBox $box,string $club,?callable $send,int $limit=20):array
    {
        if($db->inTransaction()||$limit<1||$limit>20)throw new \DomainException('Ticketversand benötigt einen eigenen Vorgang mit höchstens 20 Nachrichten.');
        $result=['sent'=>0,'blocked'=>0,'uncertain'=>0];
        if($send===null)return $result;
        $rows=Db::run($db,"SELECT order_id FROM native_ticket_mail WHERE club_id=? AND status IN ('queued','sending') ORDER BY created_at,order_id LIMIT ".$limit,[$club])->fetchAll(PDO::FETCH_COLUMN);
        foreach($rows as$id){
            // Same advisory lock as checkout/reconciliation, released by MySQL on process death.
            $lock='ticket:'.substr(hash('sha256',$club.':'.$id),0,56);
            if((int)Db::run($db,'SELECT GET_LOCK(?,0)',[$lock])->fetchColumn()!==1)continue;
            try{
                $message=Db::atomic($db,static function()use($db,$box,$club,$id,&$result):?array{
                    $order=Db::run($db,'SELECT * FROM native_ticket_orders WHERE club_id=? AND id=? FOR UPDATE',[$club,$id])->fetch();
                    $state=Db::run($db,'SELECT status FROM native_ticket_mail WHERE club_id=? AND order_id=? FOR UPDATE',[$club,$id])->fetchColumn();
                    if($state==='sending'){
                        self::mark($db,$club,$id,'uncertain');$result['uncertain']++;return null;
                    }
                    if($state!=='queued')return null;
                    $original=$order?$box->open('ticket-order:'.$club.':'.$id,$order['ciphertext']):[];
                    $identity=Identity::forClub($db,$club);$origin=$original['origin']??'';
                    if(!$order||$order['payment_provider']==='pos'||empty($original['issued'])||$order['status']!=='paid'||$identity['origin']!==$origin||!preg_match('~\Ahttps://[a-zA-Z0-9.-]+\z~D',$origin)||!filter_var($original['buyer_email']??'',FILTER_VALIDATE_EMAIL)){
                        self::mark($db,$club,$id,'blocked');$result['blocked']++;return null;
                    }
                    if(!MailPolicy::allowed($origin))return null;
                    $link=$origin.'/tickets/order?access='.$original['access_token'];
                    $name=$identity['name']?:'Mein Verein';$test=($original['account']['mode']??null)==='test';
                    $subject=($test?'Testbestellung':'Deine Tickets & Gutscheine').' · '.$name;
                    $text='Hallo '.$original['buyer_name'].",\n\ndeine Bestellung für ".$original['title']." ist bestätigt.\n";
                    foreach($original['items']??[]as$item)$text.=$item['quantity'].' × '.$item['label']."\n";
                    $text.='Gesamt: '.number_format((int)$order['amount_cents']/100,2,',','.')." €\n\n";
                    if($test)$text.="Testzahlung: Diese Codes berechtigen weder zum echten Einlass noch zur Bezahlung.\n\n";
                    $text.="Hier findest du deine QR-Codes und den aktuellen Bestellstand:\n".$link."\n\nBewahre diesen persönlichen Link auf und gib ihn nicht weiter. Der aktuelle Bestellstand entscheidet über Einlass und Guthaben.\n\nDein ".$name;
                    $message=['to'=>$original['buyer_email'],'subject'=>$subject,'text'=>$text,'html'=>ApplicationMail::html($text,$origin,$subject,$link,'Tickets & Gutscheine öffnen','DEINE BESTELLUNG',$name)];
                    // Commit intent before crossing the SMTP boundary, including process termination.
                    self::mark($db,$club,$id,'sending');return $message;
                });
                if($message===null)continue;
                try{
                    $send($message);
                    Db::run($db,"UPDATE native_ticket_mail SET status='sent',sent_at=UTC_TIMESTAMP(6),updated_at=UTC_TIMESTAMP(6) WHERE club_id=? AND order_id=? AND status='sending'",[$club,$id]);$result['sent']++;
                }catch(\Kulturhus\Native\Delivery\DeliveryNotStarted $e){self::mark($db,$club,$id,$e->retryable?'queued':'blocked');if($e->retryable)$result['held']=($result['held']??0)+1;else$result['blocked']++;
                }catch(\Throwable){
                    $completed=Db::run($db,"SELECT 1 FROM native_ticket_mail WHERE club_id=? AND order_id=? AND status='sent' AND sent_at IS NOT NULL",[$club,$id])->fetchColumn();
                    if($completed){$result['sent']++;continue;}
                    Db::run($db,"UPDATE native_ticket_mail SET status='uncertain',updated_at=UTC_TIMESTAMP(6) WHERE club_id=? AND order_id=? AND status='sending'",[$club,$id]);$result['uncertain']++;
                }
            }finally{Db::run($db,'SELECT RELEASE_LOCK(?)',[$lock]);}
        }
        return $result;
    }

    private static function mark(PDO $db,string $club,string $id,string $state):void
    {
        Db::run($db,'UPDATE native_ticket_mail SET status=?,updated_at=UTC_TIMESTAMP(6) WHERE club_id=? AND order_id=?',[$state,$club,$id]);
    }
}
