<?php declare(strict_types=1);
namespace Kulturhus\Native\Cms;

use InvalidArgumentException;
use Kulturhus\Native\Shared\{Access, Db};
use PDO;

/** A copy is a new private page, never another publication or import job. */
final class PageCopies
{
    use ClubPublicationLock;

    public function __construct(private PDO $db, private Access $access) {}

    public function create(
        string $sourceId,
        int $expectedVersion,
        array $options,
    ): array {
        return (new Pages($this->db,$this->access))->copyFrom($sourceId,$expectedVersion,$options);
    }

    /** Trusted source preparation; this method never inserts or changes a page. */
    public function prepare(
        string $sourceId,
        int $expectedVersion,
        array $options,
    ): array {
        $this->access->require("website", "edit");
        $options = CopyInput::validate($sourceId, $expectedVersion, $options);
        return Db::atomic($this->db, function () use (
            $sourceId,
            $expectedVersion,
            $options,
        ): array {
            $this->access->require("website", "edit");
            $this->lockClub();
            $pages = new Pages($this->db, $this->access);
            $source = $pages->get($sourceId);
            if ($source["version"] !== $expectedVersion) {
                throw new Conflict(
                    "Die Quelle wurde inzwischen geändert. Vor dem Kopieren neu laden.",
                );
            }
            if ($source['trashed']) throw new Conflict('Quelle zuerst aus dem Papierkorb zurückholen.');
            $payload = $source;
            $draft=Db::run($this->db,'SELECT draft_json FROM khcms_pages WHERE id=? AND club_id=?',[$sourceId,$this->access->club()])->fetchColumn();
            if(!is_string($draft))throw new Conflict('Originalquelle ist nicht verfügbar.');
            $payload=json_decode($draft,true,512,JSON_THROW_ON_ERROR);
            if (($options["basis"] ?? "draft") === "published") {
                if (!$source["published_revision"]) {
                    throw new Conflict(
                        "Die Quelle hat keine veröffentlichte Fassung.",
                    );
                }
                $json = Db::run(
                    $this->db,
                    "SELECT payload_json FROM khcms_revisions WHERE page_id=? AND club_id=? AND revision=?",
                    [
                        $sourceId,
                        $this->access->club(),
                        $source["published_revision"],
                    ],
                )->fetchColumn();
                if (!is_string($json)) {
                    throw new \RuntimeException("Originalrevision fehlt.");
                }
                $payload = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
            }
            FieldPolicy::assertCopy($this->db,$this->access,$payload);
            // A changed model requires deliberate review of the source before copying it.
            $payload = (new DataModel($this->db, $this->access))->publication(
                $payload,
            );
            $payload["title"] = $options["title"];
            $payload["slug"] = $options["slug"];
            $payload["nav_visible"] = false;
            $payload["nav_label"] = "";
            $payload["nav_order"] = 0;
            $payload = (new DataModel($this->db, $this->access))->normalizePage(
                $payload,
                $payload,
                true,
            );
            $prefix = match ($payload["template"]) {
                "news" => "aktuelles",
                "gallery" => "galerie",
                default => null,
            };
            if (
                $prefix !== null &&
                !preg_match(
                    "~\A" . $prefix . "/[a-z0-9]+(?:-[a-z0-9]+)*\z~D",
                    $payload["slug"],
                )
            ) {
                throw new InvalidArgumentException(
                    "Neue Adresse unter " .
                        $prefix .
                        "/ mit einem eigenen Namen angeben.",
                );
            }
            if (
                $pages->search(
                    [
                        [
                            "field" => "slug",
                            "op" => "eq",
                            "value" => $payload["slug"],
                        ],
                    ],
                    1,
                )
            ) {
                throw new Conflict(
                    "Die neue Adresse ist bereits vergeben. Eine eigene Adresse wählen.",
                );
            }
            return $payload;
        });
    }
}
