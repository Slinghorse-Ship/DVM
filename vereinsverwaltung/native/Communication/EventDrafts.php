<?php declare(strict_types=1);
namespace Kulturhus\Native\Communication;

use PDO;
use Kulturhus\Native\Shared\{Access, Db, Identity, RichText};
use Kulturhus\Native\Cms\{ClubPublicationLock, Events, EventDetails};
use Kulturhus\Native\Integration\ManagerEventSource;

/** Website and permitted manager calendar originals seed unapproved drafts from their exact versions. */
final class EventDrafts
{
    use ClubPublicationLock;
    public function __construct(private PDO $db, private Access $access,private string $module='social')
    {
        if(!in_array($module,['social','press'],true))throw new \DomainException('Veranstaltungsziel prüfen.');
    }
    public function installed(): bool
    {
        return (bool)Db::run($this->db,'SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?',['native_'.$this->module.'_event_origins'])->fetchColumn();
    }

    public function listing(array $query = [],?int $limit=null): array
    {
        $this->access->require($this->module, "read");
        $limit??=$this->module==='press'?6:20;if($limit<1||$limit>20)throw new \DomainException('Veranstaltungsseite prüfen.');
        $sources = $this->sources();
        $source = $query["source"] ?? array_key_first($sources);
        if (!is_string($source) || !isset($sources[$source])) {
            throw new \DomainException("Veranstaltungsquelle nicht verfügbar.");
        }
        if ($source === "manager") {
            return (new ManagerEventSource($this->db, $this->access))->listing(
                $query,$limit,
            );
        }
        return (new Events(
            $this->db,
            $this->access->club(),
            $this->access,
        ))->listing($query,null,$limit);
    }

    public function sources(): array
    {
        $this->access->require($this->module, "read");
        if($this->module==='press'&&!$this->installed())return[];
        $sources = [];
        try {
            $this->access->require("website", "read");
            $sources["website"] = "Veröffentlichtes Website-Programm";
        } catch (\DomainException) {
        }
        if ((new ManagerEventSource($this->db, $this->access))->available()) {
            $sources["manager"] = "Eigene Termine im Vereinskalender";
        }
        return $sources;
    }

    public function create(
        string $pageId,
        int $revision,
        string $source = "website",
        bool $confirmInternal = false,
        string $locale = 'de',
        string $format = 'post',
        string $label = '',
        array $design = [],
        string $animation = 'zoom',
    ): array {
        $this->access->require($this->module, "edit");
        if(!in_array($locale,['de','en'],true))throw new \DomainException('Entwurfssprache prüfen.');
        if (!in_array($format, ['post', 'story', 'reel'], true) || ($this->module !== 'social' && $format !== 'post')) throw new \DomainException('Veranstaltungsformat prüfen.');
        $label = Content::text($label, 100);
        $design = ImageDesign::options($design);
        $animation = ReelRenderer::animation($animation);
        if($revision<1)throw new \DomainException('Veranstaltungsfassung erneut auswählen.');
        if(!$this->installed())throw new \DomainException('Veranstaltungsentwürfe mit dem aktuellen Installationspaket einrichten.');
        if (!in_array($source, ["website", "manager"], true)) {
            throw new \DomainException("Veranstaltungsquelle nicht verfügbar.");
        }
        if ($source === "website") {
            $this->access->require("website", "read");
        } elseif (!$confirmInternal) {
            throw new \DomainException(
                $this->module==='press'?'Diesen Kalendertermin ausdrücklich für einen Presseentwurf auswählen.':"Bitte bestätigen, dass dieser interne Kalendertermin für einen Social-Beitrag bestimmt ist.",
            );
        }
        Content::id($pageId);
        return Db::atomic($this->db, function () use (
            $pageId,
            $revision,
            $source,
            $locale,
            $format,
            $label,
            $design,
            $animation,
        ) {
            $this->access->require($this->module, "edit");
            if ($source === "website") {
                $this->access->require("website", "read");
            }
            $this->lockClub();
            $club = $this->access->club();
            if ($source === "manager") {
                $p = (new ManagerEventSource($this->db, $this->access))->get(
                    $pageId,
                    $revision,
                );
            } else {
                $p = (new Events($this->db, $club, $this->access))->publicId($pageId,true);
            }
            if (
                !$p ||
                $p["id"] !== $pageId ||
                $p["published_version"] !== $revision
            ) {
                throw new \DomainException(
                    "Veranstaltungsfassung inzwischen geändert oder zurückgezogen. Bitte neu auswählen.",
                );
            }
            $zone = new \DateTimeZone($p["event_timezone"] ?? "Europe/Berlin");
            $start = EventDetails::start($p)->setTimezone($zone);
            $end = EventDetails::end($p)?->setTimezone($zone);
            $date = $start->format($locale==='en'?'Y-m-d H:i':'d.m.Y H:i') . ($locale==='en'?'':' Uhr');
            if ($end && $end > $start) {
                $date .=
                    ($locale==='en'?' to ':' bis ') .
                    $end->format(
                        $end->format("Y-m-d") === $start->format("Y-m-d")
                            ? "H:i"
                            : ($locale==='en'?'Y-m-d H:i':'d.m.Y H:i'),
                    ) .
                    ($locale==='en'?'':' Uhr');
            }
            $place = trim(
                ($p["event_location"] ?? "") .
                    " " .
                    ($p["event_address"] ?? ""),
            );
            $text = trim(
                ($p["excerpt"] ?? "") .
                    "\n\n" .
                    RichText::plain($p["body"] ?? ""),
            );
            $body =
                ($p["event_status"] === "cancelled" ? ($locale==='en'?'Cancelled: ':'Abgesagt: ') : "") .
                $date .
                "\n" .
                $place .
                "\n\n" .
                $text;
            $origin = Identity::forClub($this->db, $club)["origin"];
            $url =
                $source === "manager"
                    ? $p["source_url"]
                    : ($origin !== ""
                        ? $origin . "/" . ltrim($p["slug"], "/")
                        : "");
            $blocks = [];
            if (!empty($p["media_ids"])) {
                $blocks[] = [
                    "type" => "image",
                    "media_ref" => $p["media_ids"][0],
                    "alt" => $p["title"],
                ];
            }
            $draft = (new Workspace($this->db, $this->access, $this->module))->save([
                "title" => $p["title"],
                "summary" => ($label !== '' ? $label . ' · ' : '') . $date . ($place !== '' ? "\n".$place : ''),
                "body" => $body,
                "kind" => $this->module==='press'?'release':$format,
                "template" => $this->module==='press'?'press-release':'social-'.$format,
                "destination_url" => $url,
                "blocks" => $blocks,
                "story" => $format === 'story' ? [['title'=>$p['title'], 'text'=>($label !== '' ? $label . "\n" : '') . $date . "\n" . $place, 'media_ref'=>$p['media_ids'][0] ?? '']] : [],
                "image_design" => $design,
                "channels" => $this->module==='press'?[]:["facebook", "instagram"],
            ] + ($format === 'reel' ? ['video_design'=>['animation'=>$animation]] : []));
            Db::run(
                $this->db,
                "INSERT INTO native_{$this->module}_event_origins(club_id,item_id,page_id,revision,snapshot_json,created_at) VALUES(?,?,?,?,?,UTC_TIMESTAMP())",
                [
                    $club,
                    $draft["id"],
                    $pageId,
                    $revision,
                    json_encode(
                        $p,
                        JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE,
                    ),
                ],
            );
            return $draft;
        });
    }

    public function origin(string $item): ?array
    {
        (new Workspace($this->db, $this->access, $this->module))->get($item);
        if(!$this->installed())return null;
        $r = Db::run(
            $this->db,
            "SELECT page_id,revision,snapshot_json FROM native_{$this->module}_event_origins WHERE club_id=? AND item_id=?",
            [$this->access->club(), $item],
        )->fetch();
        if (!$r) {
            return null;
        }
        $snapshot = json_decode(
            $r["snapshot_json"],
            true,
            64,
            JSON_THROW_ON_ERROR,
        );
        return [
            "page_id" => $r["page_id"],
            "revision" => (int) $r["revision"],
            "source_kind" =>
                ($snapshot["_source_kind"] ?? "website") === "manager"
                    ? "manager"
                    : "website",
            "snapshot" => $snapshot,
        ];
    }
}
