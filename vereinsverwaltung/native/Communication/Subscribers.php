<?php declare(strict_types=1);
namespace Kulturhus\Native\Communication;

use Closure;
use DomainException;
use Kulturhus\Native\Shared\Access;
use Kulturhus\Native\Shared\Db;
use PDO;

/** Operator-scoped consent repository. Public anonymous endpoints are deliberately not installed. */
final class Subscribers
{
    private const PUBLIC_FIELDS = 'id,email,name,status,purposes,consent_evidence,version,confirmed_at,unsubscribed_at,created_at,updated_at';

    public function __construct(private PDO $db, private Access $access) {}

    public function list(): array
    {
        $this->access->require('newsletter','manage');
        return array_map($this->decode(...), Db::run($this->db,'SELECT '.self::PUBLIC_FIELDS.' FROM khn_newsletter_subscribers WHERE club_id=? ORDER BY email LIMIT 1000',[$this->access->club()])->fetchAll(PDO::FETCH_ASSOC));
    }

    public function get(string $id): array
    {
        $this->access->require('newsletter','manage');
        return $this->read($id);
    }

    /** Editing a profile never supplies or expands consent. */
    public function updateProfile(string $id, int $expectedVersion, string $name, array $purposes): array
    {
        $this->access->require('newsletter','manage');
        $name = Content::text($name,200);
        $purposes = $this->purposes($purposes);
        return Db::atomic($this->db,function () use ($id,$expectedVersion,$name,$purposes): array {
            $this->access->require('newsletter','manage');
            $old = $this->read($id,true);
            if ($old['version'] !== $expectedVersion) throw new DomainException('Empfänger inzwischen geändert. Aktuellen Stand öffnen.');
            if (array_diff($purposes,$old['purposes'])) throw new DomainException('Neue Themen benötigen eine neue dokumentierte Einwilligung.');
            if ($old['name'] === $name && $old['purposes'] === $purposes) return $old;
            Db::run($this->db,'UPDATE khn_newsletter_subscribers SET name=?,purposes=?,version=version+1,updated_at=UTC_TIMESTAMP() WHERE club_id=? AND id=?',[$name,json_encode($purposes,JSON_THROW_ON_ERROR),$this->access->club(),$id]);
            $this->event($id,'profile_updated',json_encode(['name_before'=>$old['name'],'name_after'=>$name,'topics_before'=>$old['purposes'],'topics_after'=>$purposes],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
            return $this->read($id);
        });
    }

    /** One bounded atomic operation; every selected original must still have its shown version. */
    public function unsubscribeSelected(array $selection, string $reason, bool $confirmed): int
    {
        $this->access->require('newsletter','manage');
        $selection = SubscriberSelection::versions($selection);
        if (!$selection || !$confirmed) throw new DomainException('Empfänger auswählen und Abmeldung bestätigen.');
        $reason = Content::text($reason,500,true);
        return Db::atomic($this->db,function () use ($selection,$reason): int {
            $this->access->require('newsletter','manage');
            $rows = [];
            foreach ($selection as $id=>$version) {
                $row = $this->read($id,true);
                if ($row['version'] !== $version) throw new DomainException('Auswahl inzwischen geändert. Betroffene Empfänger neu auswählen.');
                $rows[] = $row;
            }
            $count = 0;
            foreach ($rows as $row) {
                if ($row['status'] === 'unsubscribed') continue;
                Db::run($this->db,"UPDATE khn_newsletter_subscribers SET status='unsubscribed',unsubscribed_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP(),version=version+1 WHERE club_id=? AND id=?",[$this->access->club(),$row['id']]);
                Db::run($this->db,"DELETE FROM khn_newsletter_tokens WHERE club_id=? AND subscriber_id=? AND kind='confirm'",[$this->access->club(),$row['id']]);
                $this->event($row['id'],'admin_unsubscribed',$reason);
                ++$count;
            }
            return $count;
        });
    }

    public function historyPage(string $id, int $page = 1): array
    {
        $this->get($id);
        if ($page<1 || $page>1000000) throw new DomainException('Verlaufsseite prüfen.');
        $club = $this->access->club();
        $total = (int)Db::run($this->db,'SELECT COUNT(*) FROM khn_newsletter_consent_events WHERE club_id=? AND subscriber_id=?',[$club,$id])->fetchColumn();
        $pages = max(1,(int)ceil($total/6)); $page = min($page,$pages);
        $rows = Db::run($this->db,'SELECT action,evidence,actor_id,created_at FROM khn_newsletter_consent_events WHERE club_id=? AND subscriber_id=? ORDER BY created_at DESC,id DESC LIMIT 6 OFFSET '.(($page-1)*6),[$club,$id])->fetchAll(PDO::FETCH_ASSOC);
        return ['rows'=>$rows,'total'=>$total,'page'=>$page,'pages'=>$pages];
    }

    private function decode(array $row): array
    {
        $row['purposes'] = json_decode($row['purposes'],true,16,JSON_THROW_ON_ERROR);
        $row['version'] = (int)$row['version'];
        return $row;
    }

    private function read(string $id, bool $lock = false): array
    {
        Content::id($id);
        $row = Db::run($this->db,'SELECT '.self::PUBLIC_FIELDS.' FROM khn_newsletter_subscribers WHERE club_id=? AND id=?'.($lock?' FOR UPDATE':''),[$this->access->club(),$id])->fetch(PDO::FETCH_ASSOC);
        return $row ? $this->decode($row) : throw new DomainException('Empfänger nicht verfügbar.');
    }

    private function purposes(array $purposes): array
    {
        if (!array_is_list($purposes) || !$purposes || count($purposes)>2) throw new DomainException('Einwilligungszwecke prüfen.');
        foreach ($purposes as $purpose) if (!is_string($purpose) || !in_array($purpose,['events','news'],true)) throw new DomainException('Einwilligungszwecke prüfen.');
        $purposes = array_values(array_unique($purposes));
        sort($purposes);
        return $purposes;
    }

    /** Token is supplied only to a caller-owned in-memory sink, never returned, logged or stored in clear text. */
    public function requestConsent(string $email, string $name, array $purposes, string $evidence, Closure $capture): array
    {
        $this->access->require('newsletter','manage');
        $result = $this->upsert($email,$name,$purposes,$evidence,false);
        $this->issue($result['id'],'confirm',$capture);
        return $result;
    }

    public function recordWrittenConsent(string $email, string $name, array $purposes, string $evidence): array
    {
        $this->access->require('newsletter','manage');
        if (strlen(trim($evidence)) < 10) throw new DomainException('Schriftliche Einwilligung benötigt einen konkreten Nachweisverweis.');
        return $this->upsert($email,$name,$purposes,$evidence,true);
    }

    private function upsert(string $email, string $name, array $purposes, string $evidence, bool $written): array
    {
        $email = Content::email($email);
        $name = Content::text($name,200);
        $purposes = $this->purposes($purposes);
        $evidence = Content::text($evidence,1000,true);
        return Db::atomic($this->db,function () use ($email,$name,$purposes,$evidence,$written): array {
            $this->access->require('newsletter','manage');
            $club = $this->access->club();
            $old = Db::run($this->db,'SELECT id,version FROM khn_newsletter_subscribers WHERE club_id=? AND email=? FOR UPDATE',[$club,$email])->fetch(PDO::FETCH_ASSOC);
            $id = $old ? $old['id'] : bin2hex(random_bytes(16));
            $now = gmdate('Y-m-d H:i:s');
            $status = $written ? 'confirmed' : 'pending';
            $confirmed = $written ? $now : null;
            $json = json_encode($purposes,JSON_THROW_ON_ERROR);
            if ($old) Db::run($this->db,'UPDATE khn_newsletter_subscribers SET name=?,status=?,purposes=?,consent_evidence=?,version=version+1,confirmed_at=?,unsubscribed_at=NULL,updated_at=? WHERE club_id=? AND id=?',[$name,$status,$json,$evidence,$confirmed,$now,$club,$id]);
            else Db::run($this->db,'INSERT INTO khn_newsletter_subscribers(club_id,id,email,name,status,purposes,consent_evidence,version,confirmed_at,created_at,updated_at) VALUES(?,?,?,?,?,?,?,1,?,?,?)',[$club,$id,$email,$name,$status,$json,$evidence,$confirmed,$now,$now]);
            Db::run($this->db,"DELETE FROM khn_newsletter_tokens WHERE club_id=? AND subscriber_id=? AND kind='confirm'",[$club,$id]);
            $this->event($id,$written?'written_consent':'requested',$evidence);
            return $this->read($id);
        });
    }

    private function event(string $id, string $action, string $evidence): void
    {
        Db::run($this->db,'INSERT INTO khn_newsletter_consent_events(id,club_id,subscriber_id,action,evidence,actor_id,created_at) VALUES(?,?,?,?,?,?,?)',[bin2hex(random_bytes(16)),$this->access->club(),$id,$action,$evidence,$this->access->user()['id'],gmdate('Y-m-d H:i:s')]);
    }

    public function issueUnsubscribe(string $id, Closure $capture): void
    {
        $this->access->require('newsletter','manage');
        $this->issue($id,'unsubscribe',$capture);
    }

    private function issue(string $id, string $kind, Closure $capture): void
    {
        $secret = bin2hex(random_bytes(32));
        Db::atomic($this->db,function () use ($id,$kind,$secret): void {
            $this->access->require('newsletter','manage');
            $subscriber = $this->read($id,true);
            if ($kind === 'confirm' && $subscriber['status'] !== 'pending') throw new DomainException('Empfänger wartet nicht auf Bestätigung.');
            if ($kind === 'confirm') Db::run($this->db,"DELETE FROM khn_newsletter_tokens WHERE club_id=? AND subscriber_id=? AND kind='confirm'",[$this->access->club(),$id]);
            Db::run($this->db,'INSERT INTO khn_newsletter_tokens(token_hash,club_id,subscriber_id,kind,expires_at,created_at) VALUES(?,?,?,?,?,?)',[hash('sha256',$secret),$this->access->club(),$id,$kind,$kind === 'confirm' ? gmdate('Y-m-d H:i:s',time()+172800) : null,gmdate('Y-m-d H:i:s')]);
        });
        $capture($secret);
    }

    public function confirm(#[\SensitiveParameter] string $token): bool { return $this->redeem($token,'confirm'); }
    public function unsubscribe(#[\SensitiveParameter] string $token): bool { return $this->redeem($token,'unsubscribe'); }

    private function redeem(#[\SensitiveParameter] string $token, string $kind): bool
    {
        // This local demonstration API is operator-scoped; an anonymous gateway needs
        // independent rate limits, origin/CSRF handling and privacy-preserving responses.
        $this->access->require('newsletter','manage');
        if (!preg_match('/^[a-f0-9]{64}$/D',$token)) return false;
        $hash = hash('sha256',$token);
        $record = Db::run($this->db,'SELECT subscriber_id FROM khn_newsletter_tokens WHERE token_hash=? AND club_id=? AND kind=?',[$hash,$this->access->club(),$kind])->fetch(PDO::FETCH_ASSOC);
        if (!$record) return false;
        return Db::atomic($this->db,function () use ($hash,$kind,$record): bool {
            $this->access->require('newsletter','manage');
            $row = $this->read($record['subscriber_id'],true);
            $token = Db::run($this->db,'SELECT expires_at,consumed_at FROM khn_newsletter_tokens WHERE token_hash=? AND club_id=? AND kind=? FOR UPDATE',[$hash,$this->access->club(),$kind])->fetch(PDO::FETCH_ASSOC);
            $now = gmdate('Y-m-d H:i:s');
            if (!$token || ($token['expires_at'] !== null && $token['expires_at'] <= $now)) return false;
            if ($kind === 'confirm' && ($token['consumed_at'] !== null || $row['status'] !== 'pending')) return false;
            if ($kind === 'unsubscribe' && $row['status'] === 'unsubscribed') return true;
            if ($kind === 'confirm') {
                Db::run($this->db,"UPDATE khn_newsletter_subscribers SET status='confirmed',confirmed_at=?,updated_at=?,version=version+1 WHERE club_id=? AND id=?",[$now,$now,$this->access->club(),$row['id']]);
            } else {
                Db::run($this->db,"UPDATE khn_newsletter_subscribers SET status='unsubscribed',unsubscribed_at=?,updated_at=?,version=version+1 WHERE club_id=? AND id=?",[$now,$now,$this->access->club(),$row['id']]);
                Db::run($this->db,"DELETE FROM khn_newsletter_tokens WHERE club_id=? AND subscriber_id=? AND kind='confirm'",[$this->access->club(),$row['id']]);
            }
            Db::run($this->db,'UPDATE khn_newsletter_tokens SET consumed_at=? WHERE token_hash=? AND club_id=?',[$now,$hash,$this->access->club()]);
            $this->event($row['id'],$kind === 'confirm'?'doi_confirmed':'unsubscribed',$kind === 'confirm'?'Einmalige Tokenbestätigung':'Abmeldung mit Empfängerschlüssel');
            return true;
        });
    }

    /** Only identifiers are exposed to the editorial publication queue. */
    public function eligible(string $audience = 'all'): array
    {
        $this->access->require('newsletter','publish');
        if (!in_array($audience,['all','events','news'],true)) throw new DomainException('Unbekannte Zielgruppe.');
        $rows = Db::run($this->db,"SELECT id,purposes FROM khn_newsletter_subscribers WHERE club_id=? AND status='confirmed' AND confirmed_at IS NOT NULL AND unsubscribed_at IS NULL ORDER BY id",[$this->access->club()])->fetchAll(PDO::FETCH_ASSOC);
        $eligible = [];
        foreach ($rows as $row) {
            $purposes = json_decode($row['purposes'],true,16,JSON_THROW_ON_ERROR);
            // A combined edition needs both purposes, not merely any consent.
            if (($audience === 'all' && !array_diff(['events','news'],$purposes)) || in_array($audience,$purposes,true)) $eligible[] = ['id'=>$row['id']];
        }
        return $eligible;
    }

    public function history(string $id): array
    {
        $this->get($id);
        return Db::run($this->db,'SELECT action,evidence,actor_id,created_at FROM khn_newsletter_consent_events WHERE club_id=? AND subscriber_id=? ORDER BY created_at,id',[$this->access->club(),$id])->fetchAll(PDO::FETCH_ASSOC);
    }
}
