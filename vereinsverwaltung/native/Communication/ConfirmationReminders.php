<?php declare(strict_types=1);
namespace Kulturhus\Native\Communication;

use DomainException;
use PDO;
use Kulturhus\Native\Shared\{Access,Authority,Db,SecretBox};
use Kulturhus\Native\Integration\ManagerAccess;
use Kulturhus\Native\Delivery\Outbox;

/** Explicit opt-in planning; one reminder per valid, actually delivered DOI request. */
final class ConfirmationReminders
{
    public function __construct(private PDO $db,private Access $access,private SecretBox $box) {}

    public function plan(): array
    {
        $this->access->require('newsletter','manage');
        $row=Db::run($this->db,'SELECT version,paused FROM native_newsletter_reminder_plans WHERE club_id=?',[$this->access->club()])->fetch();
        return ['version'=>$row?(int)$row['version']:0,'enabled'=>$row?!(bool)$row['paused']:false];
    }

    public function savePlan(bool $enabled,int $expectedVersion): array
    {
        if($expectedVersion<0)throw new DomainException('Gültigen Planungsstand angeben.');
        return Db::atomic($this->db,function()use($enabled,$expectedVersion){
            $actor=$this->access->require('newsletter','manage');$club=$actor['club_id'];
            $old=Db::run($this->db,'SELECT version FROM native_newsletter_reminder_plans WHERE club_id=? FOR UPDATE',[$club])->fetch();
            if(($old?(int)$old['version']:0)!==$expectedVersion)throw new DomainException('Erinnerungsplanung wurde inzwischen geändert. Bitte neu laden.');
            $authority=$this->access instanceof ManagerAccess?'manager':'native';
            Db::run($this->db,'INSERT INTO native_newsletter_reminder_plans(club_id,actor_id,authority,paused,version) VALUES(?,?,?,?,1) ON DUPLICATE KEY UPDATE actor_id=VALUES(actor_id),authority=VALUES(authority),paused=VALUES(paused),version=version+1',[$club,$actor['id'],$authority,(int)!$enabled]);
            // Pausing also stops reminders prepared earlier but not yet handed to a transport.
            if(!$enabled)Db::run($this->db,"UPDATE native_delivery_jobs SET status='cancelled',reason='reminder_plan_paused',completed_at=UTC_TIMESTAMP() WHERE club_id=? AND kind='reminder' AND status='pending'",[$club]);
            return $this->plan();
        });
    }

    public function queue(string $subscriber): string
    {
        return Db::atomic($this->db,function()use($subscriber){
            $this->access->require('newsletter','manage');$club=$this->access->club();
            $s=(new Subscribers($this->db,$this->access))->get($subscriber);
            // Match the subscription and token under the same lock order as public confirmation.
            $s=Db::run($this->db,'SELECT email,status FROM khn_newsletter_subscribers WHERE club_id=? AND id=? FOR UPDATE',[$club,$s['id']])->fetch();
            if(!$s||$s['status']!=='pending')throw new DomainException('Empfänger wartet nicht auf Bestätigung.');
            if(Db::run($this->db,"SELECT 1 FROM native_newsletter_renewals r JOIN native_delivery_jobs j ON j.id=r.job_id WHERE r.club_id=? AND r.subscriber_id=? AND j.status IN ('pending','processing','uncertain')",[$club,$subscriber])->fetchColumn())throw new DomainException('Ein erneuter Bestätigungsauftrag ist bereits eingeplant oder zu prüfen.');
            $token=Db::run($this->db,"SELECT token_hash FROM khn_newsletter_tokens WHERE club_id=? AND subscriber_id=? AND kind='confirm' AND consumed_at IS NULL AND expires_at>UTC_TIMESTAMP() AND created_at<=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 DAY) ORDER BY created_at DESC LIMIT 1 FOR UPDATE",[$club,$subscriber])->fetch();
            if(!$token)throw new DomainException('Erinnerung frühestens nach einem Tag und nur bei gültiger Anmeldung.');
            $job=Db::run($this->db,"SELECT id,ciphertext FROM native_delivery_jobs WHERE club_id=? AND kind='confirm' AND status='sent' AND dedupe=? FOR SHARE",[$club,hash('sha256','confirm:'.$token['token_hash'])])->fetch();
            if(!$job)throw new DomainException('Kein zugestellter Bestätigungsauftrag für die Erinnerung vorhanden.');
            $payload=$this->box->open('job:'.$club.':'.$job['id'],$job['ciphertext']);
            if(($payload['subscriber']??'')!==$subscriber||($payload['confirmation_hash']??'')!==$token['token_hash']||($payload['to']??'')!==$s['email'])
                throw new DomainException('Bestätigungsauftrag passt nicht mehr zur Anmeldung.');
            $payload['subject']='Erinnerung: '.$payload['subject'];
            return (new Outbox($this->db,$this->box))->enqueue($club,'reminder',$token['token_hash'],$payload,gmdate('Y-m-d H:i:s'),$this->access,'manage');
        });
    }

    /** Plans only private jobs. Sending remains the separately enabled Outbox worker. */
    public static function run(PDO $db,SecretBox $box,int $limit=100,?string $club=null): array
    {
        if($db->inTransaction()||$limit<1||$limit>200)throw new DomainException('Eigene Planerverbindung und gültiges Limit erforderlich.');
        $queued=[];
        foreach(Db::run($db,'SELECT club_id FROM native_newsletter_reminder_plans WHERE paused=0'.($club!==null?' AND club_id=?':'').' ORDER BY club_id',$club!==null?[$club]:[])->fetchAll() as $candidate){
            if(count($queued)>=$limit)break;
            try{
                $jobs=Db::atomic($db,function()use($db,$box,$candidate,$limit,$queued){
                    // Serializes workers and administrative changes for this club.
                    $plan=Db::run($db,'SELECT * FROM native_newsletter_reminder_plans WHERE club_id=? FOR UPDATE',[$candidate['club_id']])->fetch();
                    if(!$plan||$plan['paused'])return [];
                    $access=Authority::resolve($db,['created_by'=>$plan['actor_id'],'authority'=>$plan['authority'],'club_id'=>$plan['club_id']]);
                    $access->require('newsletter','manage');
                    if($access->club()!==$plan['club_id'])throw new DomainException('Vereinszuordnung geändert.');
                    $remaining=$limit-count($queued);
                    $subscribers=Db::run($db,"SELECT DISTINCT s.id FROM khn_newsletter_subscribers s JOIN khn_newsletter_tokens t ON t.club_id=s.club_id AND t.subscriber_id=s.id JOIN native_delivery_jobs j ON j.club_id=t.club_id AND j.kind='confirm' AND j.status='sent' AND j.dedupe=SHA2(CONCAT('confirm:',t.token_hash),256) WHERE s.club_id=? AND s.status='pending' AND t.kind='confirm' AND t.consumed_at IS NULL AND t.expires_at>UTC_TIMESTAMP() AND t.created_at<=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 DAY) AND NOT EXISTS(SELECT 1 FROM native_delivery_jobs r WHERE r.club_id=t.club_id AND r.dedupe=SHA2(CONCAT('reminder:',t.token_hash),256)) AND NOT EXISTS(SELECT 1 FROM native_newsletter_renewals m JOIN native_delivery_jobs d ON d.id=m.job_id WHERE m.club_id=s.club_id AND m.subscriber_id=s.id AND d.status IN ('pending','processing','uncertain')) ORDER BY s.id LIMIT ".$remaining,[$plan['club_id']])->fetchAll();
                    $service=new self($db,$access,$box);$ids=[];
                    foreach($subscribers as $subscriber)$ids[]=$service->queue($subscriber['id']);
                    return $ids;
                });
                array_push($queued,...$jobs);
            }catch(DomainException){/* Revoked authority or obsolete confirmation cannot create jobs. */}
        }
        return $queued;
    }
}
