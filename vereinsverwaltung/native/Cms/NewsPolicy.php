<?php declare(strict_types=1);
namespace Kulturhus\Native\Cms;

use DomainException;
use Kulturhus\Native\Shared\{Access, Db};
use PDO;

/** Private club policy and immutable approvals; public payloads retain their exact shape. */
final class NewsPolicy
{
    use ClubPublicationLock;
    public function __construct(private readonly PDO $db, private readonly Access $access) {}

    public static function install(PDO $db): void
    {
        foreach ([
            'CREATE TABLE IF NOT EXISTS khcms_news_policy (club_id VARCHAR(64) PRIMARY KEY, version INT UNSIGNED NOT NULL, four_eyes TINYINT UNSIGNED NOT NULL, updated_by VARCHAR(64) NOT NULL, updated_at DATETIME(6) NOT NULL)',
            'CREATE TABLE IF NOT EXISTS khcms_news_policy_history (club_id VARCHAR(64) NOT NULL, version INT UNSIGNED NOT NULL, four_eyes TINYINT UNSIGNED NOT NULL, actor_id VARCHAR(64) NOT NULL, created_at DATETIME(6) NOT NULL, PRIMARY KEY(club_id,version))',
            'CREATE TABLE IF NOT EXISTS khcms_news_approvals (page_id CHAR(32) NOT NULL, revision INT UNSIGNED NOT NULL, club_id VARCHAR(64) NOT NULL, policy_version INT UNSIGNED NOT NULL, content_revision INT UNSIGNED NOT NULL, content_hash CHAR(64) NOT NULL, author_id VARCHAR(64) NOT NULL, reviewer_id VARCHAR(64) NOT NULL, created_at DATETIME(6) NOT NULL, PRIMARY KEY(page_id,revision), KEY cms_news_approval_club(club_id,page_id), CONSTRAINT cms_news_approval_original FOREIGN KEY(page_id,revision) REFERENCES khcms_revisions(page_id,revision) ON DELETE RESTRICT)',
        ] as $sql) $db->exec($sql . ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    }

    public function get(): array
    {
        $actor = $this->access->require('website', 'read');
        $row = Db::run($this->db, 'SELECT version,four_eyes,updated_by,updated_at FROM khcms_news_policy WHERE club_id=?'.($this->db->inTransaction()?' FOR SHARE':''), [$actor['club_id']])->fetch();
        return $row ? ['version'=>(int)$row['version'], 'enabled'=>(int)$row['four_eyes']===1, 'updated_by'=>$row['updated_by'], 'updated_at'=>$row['updated_at']] : ['version'=>0,'enabled'=>false,'updated_by'=>null,'updated_at'=>null];
    }

    public function save(mixed $enabled, int $version): array
    {
        $this->access->require('website', 'manage');
        if (!is_bool($enabled) || $version<0 || $version>=4294967295) throw new DomainException('Freigaberegel als Ja/Nein mit gültiger Version angeben.');
        return Db::atomic($this->db, function () use ($enabled,$version): array {
            $actor=$this->access->require('website','manage'); $this->lockClub(); $state=$this->get();
            if ($state['version']!==$version) throw new Conflict('Freigaberegel inzwischen geändert. Bitte Formular neu laden.');
            if ($state['enabled']===$enabled) return $state;
            Db::run($this->db,'INSERT INTO khcms_news_policy VALUES(?,?,?,?,UTC_TIMESTAMP(6)) ON DUPLICATE KEY UPDATE version=VALUES(version),four_eyes=VALUES(four_eyes),updated_by=VALUES(updated_by),updated_at=VALUES(updated_at)',[$actor['club_id'],$version+1,(int)$enabled,$actor['id']]);
            Db::run($this->db,'INSERT INTO khcms_news_policy_history VALUES(?,?,?,?,UTC_TIMESTAMP(6))',[$actor['club_id'],$version+1,(int)$enabled,$actor['id']]);
            return $this->get();
        });
    }

    public function history(): array
    {
        $actor=$this->access->require('website','manage');
        return Db::run($this->db,'SELECT version,four_eyes,actor_id,created_at FROM khcms_news_policy_history WHERE club_id=? ORDER BY version DESC LIMIT 20',[$actor['club_id']])->fetchAll();
    }

    /** Uses original content revisions, never updated_by (which changes on review/publish). */
    public function state(string $id, array $payload): array
    {
        $actor=$this->access->require('website','read'); $policy=$this->get();$lock=$this->db->inTransaction()?' FOR SHARE':'';
        $content=Db::run($this->db,"SELECT r.revision,r.actor_id FROM khcms_revisions r JOIN khcms_pages p ON p.id=r.page_id AND p.club_id=r.club_id WHERE r.page_id=? AND r.club_id=? AND r.action IN ('create','save','restore','unarchive','trash-restore') ORDER BY r.revision DESC LIMIT 1".$lock,[$id,$actor['club_id']])->fetch();
        if (!$content) throw new \RuntimeException('Seite nicht gefunden.');
        $approval=Db::run($this->db,"SELECT a.* FROM khcms_news_approvals a JOIN khcms_revisions r ON r.page_id=a.page_id AND r.revision=a.revision AND r.club_id=a.club_id AND r.action='news-approve' AND r.actor_id=a.reviewer_id WHERE a.page_id=? AND a.club_id=? ORDER BY a.revision DESC LIMIT 1".$lock,[$id,$actor['club_id']])->fetch();
        $valid=$approval && NewsReview::status($payload)==='approved' && (int)$approval['policy_version']===$policy['version'] && (int)$approval['content_revision']===(int)$content['revision'] && $approval['author_id']===$content['actor_id'] && $approval['reviewer_id']!==$content['actor_id'] && hash_equals($approval['content_hash'],self::fingerprint($payload));
        return $policy + ['author'=>$content['actor_id'],'content_revision'=>(int)$content['revision'],'valid'=>(bool)$valid,'self'=>$actor['id']===$content['actor_id'],'approval'=>$approval?:null];
    }

    /** Called only inside Pages' locked review transaction, after its immutable revision. */
    public function record(string $id, array $payload, int $revision): void
    {
        $actor=$this->access->require('website','publish'); $state=$this->state($id,$payload);
        if (!$state['enabled']) return;
        if ($state['self']) throw new DomainException('Eine andere Person mit Veröffentlichungsrecht muss diesen Beitrag freigeben.');
        Db::run($this->db,'INSERT INTO khcms_news_approvals VALUES(?,?,?,?,?,?,?,?,UTC_TIMESTAMP(6))',[$id,$revision,$actor['club_id'],$state['version'],$state['content_revision'],self::fingerprint($payload),$state['author'],$actor['id']]);
    }

    public function assertPublishable(string $id, array $payload): void
    {
        if (!NewsChannels::isNews($payload)) return;
        $state=$this->state($id,$payload);
        if ($state['enabled'] && !$state['valid']) throw new DomainException('Dieser Beitrag benötigt eine aktuelle Vier-Augen-Freigabe.');
    }

    private static function fingerprint(array $payload): string
    {
        foreach (['news_review','id','version','trashed','trashed_at','archived','archived_at','published_revision','published_version','published_slug','published_at','created_at','updated_at','has_unpublished_changes'] as $key) unset($payload[$key]);
        $sort=static function (mixed $value) use (&$sort): mixed {
            if (!is_array($value)) return $value;
            if (!array_is_list($value)) ksort($value,SORT_STRING);
            foreach ($value as &$item) $item=$sort($item);
            return $value;
        };
        return hash('sha256',json_encode($sort($payload),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
    }
}
