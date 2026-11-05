<?php
declare(strict_types=1);

namespace Kulturhus\Native\Cms;

use DomainException;
use InvalidArgumentException;
use Kulturhus\Native\Shared\{Access, Db};
use PDO;

/** Old content paths reference original pages; redirects never point to another alias. */
final class PathHistory
{
    use ClubPublicationLock;

    public function __construct(private PDO $db, private Access $access) {}

    private static function slug(string $slug): void
    {
        if (
            strlen($slug) > 180 ||
            !preg_match(
                "~\A[a-z0-9]+(?:-[a-z0-9]+)*(?:/[a-z0-9]+(?:-[a-z0-9]+)*)*\z~D",
                $slug,
            ) ||
            preg_match(
                '~^(?:assets|site|media|feeds|vereinsverwaltung|login|logout|account|accounts|invite|reset|forgot|preview|media-preview|chat-file|chat-image|chat-status|model-fields|reference-options|page-photo-status|album-photo-status|newsletter|newsletter-confirm|newsletter-unsubscribe|publication-image|publication-video|social-image|social-video|search|suche|status|extensions|api|file|file-preview)(?:/|$)~D',
                $slug,
            )
        ) {
            throw new InvalidArgumentException(
                "Alten Inhaltspfad ohne Domain angeben; System- und Anmeldelinks sind geschützt.",
            );
        }
    }

    public function listing(string $pageId): array
    {
        $this->access->require("website", "read");
        if (
            !Db::run(
                $this->db,
                "SELECT 1 FROM khcms_pages WHERE id=? AND club_id=?",
                [$pageId, $this->access->club()],
            )->fetchColumn()
        ) {
            throw new DomainException("Seite nicht verfügbar.");
        }
        TemplatePolicy::assertCapability($this->db,$this->access,$pageId,'read');
        return Db::run(
            $this->db,
            "SELECT id,slug,page_version,created_at FROM khcms_path_history WHERE club_id=? AND page_id=? ORDER BY slug",
            [$this->access->club(), $pageId],
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Explicit old-link import and the publication lifecycle share this checked operation. */
    public function remember(
        string $pageId,
        string $slug,
        int $expectedVersion,
    ): void {
        self::slug($slug);
        $this->access->require("website", "publish");
        Db::atomic($this->db, function () use (
            $pageId,
            $slug,
            $expectedVersion,
        ): void {
            $actor = $this->access->require("website", "publish");
            $this->lockClub();
            $this->page($pageId, $expectedVersion);
            (new \Kulturhus\Native\Language\Settings($this->db,$this->access))->assertSourcePath($slug,$pageId);
            self::assertAvailable(
                $this->db,
                $this->access->club(),
                $pageId,
                $slug,
            );
            $old = Db::run(
                $this->db,
                "SELECT page_id FROM khcms_path_history WHERE club_id=? AND slug=? FOR UPDATE",
                [$this->access->club(), $slug],
            )->fetchColumn();
            if ($old !== false) {
                return;
            }
            Db::run(
                $this->db,
                "INSERT INTO khcms_path_history(id,club_id,slug,page_id,page_version,created_by,created_at) VALUES(?,?,?,?,?,?,UTC_TIMESTAMP(6))",
                [
                    bin2hex(random_bytes(16)),
                    $this->access->club(),
                    $slug,
                    $pageId,
                    $expectedVersion,
                    $actor["id"],
                ],
            );
        });
    }

    /** System routes keep their own dispatcher; only formerly public content paths are retained. */
    public function retainPublished(
        string $pageId,
        ?string $slug,
        int $expectedVersion,
    ): void {
        if ($slug === null) {
            return;
        }
        try {
            self::slug($slug);
        } catch (InvalidArgumentException) {
            return;
        }
        $this->remember($pageId, $slug, $expectedVersion);
    }

    public function forget(
        string $pageId,
        string $historyId,
        int $expectedVersion,
        bool $confirmed,
    ): void {
        if (!$confirmed || !preg_match('/^[a-f0-9]{32}$/D', $historyId)) {
            throw new DomainException(
                "Das Entfernen des alten Links ausdrücklich bestätigen.",
            );
        }
        Db::atomic($this->db, function () use (
            $pageId,
            $historyId,
            $expectedVersion,
        ): void {
            $this->access->require("website", "publish");
            $this->lockClub();
            $this->page($pageId, $expectedVersion);
            $changed = Db::run(
                $this->db,
                "DELETE FROM khcms_path_history WHERE id=? AND club_id=? AND page_id=?",
                [$historyId, $this->access->club(), $pageId],
            );
            if ($changed->rowCount() !== 1) {
                throw new Conflict(
                    "Dieser alte Link wurde inzwischen geändert.",
                );
            }
        });
    }

    private function page(string $id, int $version): void
    {
        $actual = Db::run(
            $this->db,
            "SELECT version FROM khcms_pages WHERE id=? AND club_id=? FOR UPDATE",
            [$id, $this->access->club()],
        )->fetchColumn();
        if ($actual === false) {
            throw new DomainException("Seite nicht verfügbar.");
        }
        TemplatePolicy::assertCapability($this->db,$this->access,$id,'publish');
        if ((int) $actual !== $version) {
            throw new Conflict("Seite inzwischen geändert. Bitte neu öffnen.");
        }
    }

    public static function assertAvailable(
        PDO $db,
        string $club,
        string $pageId,
        string $slug,
    ): void {
        PagePurge::assertPathAvailable($db, $club, $slug);
        if (
            Db::run(
                $db,
                "SELECT id FROM khcms_pages WHERE club_id=? AND id<>? AND (slug=? OR published_slug=?) LIMIT 1",
                [$club, $pageId, $slug, $slug],
            )->fetchColumn() ||
            Db::run(
                $db,
                "SELECT id FROM khcms_path_history WHERE club_id=? AND slug=? AND page_id<>? LIMIT 1",
                [$club, $slug, $pageId],
            )->fetchColumn() ||
            Db::run(
                $db,
                "SELECT page_id FROM khcms_events WHERE club_id=? AND slug=? AND page_id<>? LIMIT 1",
                [$club, $slug, $pageId],
            )->fetchColumn()
        ) {
            throw new Conflict(
                "Dieser URL-Pfad gehört bereits zu einer anderen Seite oder deren Linkhistorie.",
            );
        }
    }

    /** Uses only the latest published original; drafts, archives and foreign clubs never resolve. */
    public function resolve(string $slug): ?string
    {
        $this->access->require("website", "read");
        try {
            self::slug($slug);
        } catch (InvalidArgumentException) {
            return null;
        }
        $target = Db::run(
            $this->db,
            "SELECT p.published_slug FROM khcms_path_history h JOIN khcms_pages p ON p.id=h.page_id AND p.club_id=h.club_id JOIN khcms_revisions r ON r.page_id=p.id AND r.club_id=p.club_id AND r.revision=p.published_revision WHERE h.club_id=? AND h.slug=? AND " . NewsChannels::where('website'),
            [$this->access->club(), $slug],
        )->fetchColumn();
        if(!is_string($target)) {
            $id=Db::run($this->db,'SELECT page_id FROM khcms_path_history WHERE club_id=? AND slug=?',[$this->access->club(),$slug])->fetchColumn();
            $notice=$id?(new Events($this->db,$this->access->club(),$this->access))->publicId($id):null;
            if($notice&&$notice['cancellation_notice'])$target=$notice['slug'];
        }
        if (!is_string($target) || $target === $slug) {
            return null;
        }
        try {
            self::slug($target);
        } catch (InvalidArgumentException) {
            return null;
        }
        return $target;
    }
}
