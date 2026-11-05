<?php declare(strict_types=1);
namespace Kulturhus\Native\Cms;

use InvalidArgumentException;
use Kulturhus\Native\Shared\{Access, Db};
use PDO;

/** Reversible retirement retains IDs, original bytes, paths, revisions and external bindings. */
final class PageTrash
{
    use ClubPublicationLock;
    public function __construct(private PDO $db, private Access $access) {}

    public static function activeSql(): string
    {
        return 'NOT EXISTS(SELECT 1 FROM khcms_trash t WHERE t.page_id=p.id AND t.club_id=p.club_id)';
    }

    public function move(string $id, int $version): array { return $this->change($id,$version,true); }
    public function restore(string $id, int $version): array { return $this->change($id,$version,false); }

    private function change(string $id, int $expected, bool $trash): array
    {
        $cap = $trash ? 'publish' : 'edit';
        $this->access->require('website',$cap);
        if (!preg_match('/\A[a-f0-9]{32}\z/D',$id) || $expected < 1 || $expected > 4294967294) throw new InvalidArgumentException('Seitenkennung und gespeicherten Versionsstand prüfen.');
        return Db::atomic($this->db,function() use($id,$expected,$trash,$cap):array {
            $actor = $this->access->require('website',$cap);
            $this->lockClub();
            $pages = new Pages($this->db,$this->access);
            $page = $pages->get($id);
            TemplatePolicy::assertCapability($this->db,$this->access,$id,$cap);
            if ($page['version'] !== $expected || $page['trashed'] === $trash) throw new Conflict('Seite oder Papierkorbstatus wurde inzwischen geändert. Aktuellen Stand neu laden.');
            $references = new PageReferences($this->db,$this->access);
            if ($trash) {
                if ($references->incoming($id)) throw new Conflict('Andere gespeicherte oder veröffentlichte Seiten verweisen auf diesen Inhalt. Unterseiten und Verknüpfungen zuerst bearbeiten.');
                (new PathHistory($this->db,$this->access))->retainPublished($id,$page['published_slug'],$expected);
                Db::run($this->db,'INSERT INTO khcms_trash(page_id,club_id,trashed_by,trashed_at) VALUES(?,?,?,UTC_TIMESTAMP(6))',[$id,$actor['club_id'],$actor['id']]);
            } else {
                $references->assertAvailable($id,$page);
                Db::run($this->db,'DELETE FROM khcms_trash WHERE page_id=? AND club_id=?',[$id,$actor['club_id']]);
            }
            $json = Db::run($this->db,'SELECT draft_json FROM khcms_pages WHERE id=? AND club_id=?',[$id,$actor['club_id']])->fetchColumn();
            if (!$trash) {
                $payload = json_decode($json,true,512,JSON_THROW_ON_ERROR);
                $reviewed = NewsReview::afterEdit($payload);
                if ($reviewed !== $payload) $json = json_encode($reviewed,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
            }
            Db::run($this->db,'INSERT INTO khcms_revisions(page_id,revision,club_id,payload_json,actor_id,action,created_at) VALUES(?,?,?,?,?,?,UTC_TIMESTAMP(6))',[$id,$expected+1,$actor['club_id'],$json,$actor['id'],$trash?'trash':'trash-restore']);
            Db::run($this->db,'UPDATE khcms_pages SET version=?,draft_json=?,published_revision=NULL,published_slug=NULL,published_at=NULL,updated_by=?,updated_at=UTC_TIMESTAMP(6) WHERE id=? AND club_id=?',[$expected+1,$json,$actor['id'],$id,$actor['club_id']]);
            Db::run($this->db,'DELETE FROM khcms_published_media WHERE page_id=? AND club_id=?',[$id,$actor['club_id']]);
            // Old plans keep their approved original but cannot revive the retired page.
            Db::run($this->db,"UPDATE khcms_publication_schedule SET status='cancelled',reason='page_trash',completed_at=UNIX_TIMESTAMP(),version=version+1 WHERE page_id=? AND club_id=? AND status='pending'",[$id,$actor['club_id']]);
            EventIndex::withdraw($this->db,$actor['club_id'],$id);
            EventPublications::removed($this->db,$actor['club_id'],$id,$trash);
            Db::run($this->db,'UPDATE khcms_state SET generation=generation+1 WHERE club_id=?',[$actor['club_id']]);
            return $pages->get($id);
        });
    }

    public function listing(int $page=1): array
    {
        $this->access->require('website','read');
        if ($page<1 || $page>999999) throw new InvalidArgumentException('Gültige Papierkorbseite angeben.');
        $club=$this->access->club();
        [$policy,$parameters]=TemplatePolicy::readFilter($this->db,$this->access);
        $total=(int)Db::run($this->db,'SELECT COUNT(*) FROM khcms_trash t JOIN khcms_pages p ON p.id=t.page_id AND p.club_id=t.club_id WHERE ('.$policy.')',$parameters)->fetchColumn();
        $last=max(1,(int)ceil($total/25));$page=min($page,$last);
        $rows=Db::run($this->db,"SELECT p.id,p.version,p.slug,JSON_UNQUOTE(JSON_EXTRACT(p.draft_json,'$.title')) AS title,t.trashed_at FROM khcms_pages p JOIN khcms_trash t ON t.page_id=p.id AND t.club_id=p.club_id WHERE (".$policy.") ORDER BY t.trashed_at DESC,p.id LIMIT 25 OFFSET ".(($page-1)*25),$parameters)->fetchAll(PDO::FETCH_ASSOC);
        return compact('rows','total','page','last');
    }
}
