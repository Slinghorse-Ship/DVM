<?php declare(strict_types=1);
namespace Kulturhus\Native\Cms;
use InvalidArgumentException;
use RuntimeException;
use Kulturhus\Native\Shared\{Access, Db};
use PDO;

/** Restore owned historical album metadata as a private page revision, preserving every image byte. */
final class AlbumVersions
{
    use ClubPublicationLock;
    public function __construct(private PDO $db, private Access $access) {}

    public function restore(
        string $id,
        int $revision,
        int $expectedVersion,
    ): array {
        $this->access->require("website", "edit");
        if (
            !preg_match("/\A[a-f0-9]{32}\z/D", $id) ||
            $revision < 1 ||
            $revision > 4294967295 ||
            $expectedVersion < 1 ||
            $expectedVersion > 4294967294
        ) {
            throw new InvalidArgumentException(
                "Albumkennung und gültige gespeicherte Versionen angeben.",
            );
        }
        return Db::atomic($this->db, function () use (
            $id,
            $revision,
            $expectedVersion,
        ): array {
            $actor = $this->access->require("website", "edit");
            $this->lockClub();
            $pages = new Pages($this->db, $this->access);
            $current = $pages->get($id);
            if ($current["version"] !== $expectedVersion) {
                throw new Conflict(
                    "Album wurde inzwischen geändert. Bitte neu laden.",
                );
            }
            self::album($current);
            $json = Db::run(
                $this->db,
                "SELECT payload_json FROM khcms_revisions WHERE page_id=? AND club_id=? AND revision=?",
                [$id, $actor["club_id"], $revision],
            )->fetchColumn();
            if (!is_string($json)) {
                throw new RuntimeException(
                    "Gespeicherte Albumversion nicht gefunden.",
                );
            }
            $original = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
            if (!is_array($original) || array_is_list($original)) {
                throw new RuntimeException(
                    "Gespeicherte Albumversion ist beschädigt.",
                );
            }
            self::album($original);
            $definition = (new DataModel($this->db, $this->access))->template($original['template']);
            if (($definition['renderer'] ?? null) !== 'gallery') {
                throw new InvalidArgumentException('Die aktuelle Vorlage unterstützt dieses Album nicht mehr.');
            }
            $restored = $pages->restore($id, $revision, $expectedVersion);
            // A changed current template must not silently turn the restored album into another content type.
            self::album($restored);
            return $restored;
        });
    }

    private static function album(array $page): void
    {
        if (
            ($page["template_definition"]["renderer"] ??
                ($page["template"] ?? "")) !==
                "gallery" ||
            ($page["slug"] ?? "") === "galerie"
        ) {
            throw new InvalidArgumentException(
                "Eine gespeicherte Albumfassung wählen.",
            );
        }
    }
}
