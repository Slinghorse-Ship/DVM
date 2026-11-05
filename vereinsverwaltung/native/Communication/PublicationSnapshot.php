<?php declare(strict_types=1);
namespace Kulturhus\Native\Communication;

use PDO;
use Kulturhus\Native\Shared\{Access, Authority, Db};

/** Shared validation for API publication and a manual app handoff. */
final class PublicationSnapshot
{
    public static function social(
        PDO $db,
        Access $access,
        string $itemId,
        string $mediaRoot,
    ): array {
        $access->require("social", "publish");
        Content::id($itemId);
        Db::run(
            $db,
            "SELECT id FROM khn_social_items WHERE club_id=? AND id=? FOR SHARE",
            [$access->club(), $itemId],
        );
        $item = (new Workspace($db, $access, "social"))->get($itemId);
        $approvedVersion = $item["approved_version"] ?? 0;
        if (
            !in_array($item["status"], ["approved", "scheduled"], true) ||
            empty($item["approved_by"]) ||
            $approvedVersion !==
                $item["version"] - ($item["status"] === "scheduled" ? 1 : 0)
        ) {
            throw new \DomainException(
                "Aktuelle Social-Fassung zuerst freigeben.",
            );
        }
        $authority = Authority::resolve($db, [
            "created_by" => $item["approved_by"],
            "authority" => $item["approved_authority"] ?? "native",
            "club_id" => $access->club(),
        ]);
        if (
            $authority->require("social", "publish")["club_id"] !==
            $access->club()
        ) {
            throw new \DomainException(
                "Vereinszuordnung der Freigabe geändert.",
            );
        }
        if($item['kind']==='reel')return ['item'=>$item,'images'=>[],'video'=>(new VideoApproval($db,$access,$mediaRoot))->check($item)];
        $store = new Images($db, $access, $mediaRoot);
        $carouselIdentity=$item['kind']==='carousel'?Db::run($db,'SELECT version FROM native_club_settings WHERE club_id=? FOR SHARE',[$access->club()])->fetchColumn():null;
        $images = [];
        foreach ((new ImageHistory($db,$access))->originals($itemId,$item['approved_image_ids']??[]) as $row) {
            if (
                !in_array($row["id"], $item["approved_image_ids"] ?? [], true)
            ) {
                continue;
            }
            if (
                !hash_equals(
                    $row["content_hash"],
                    Images::fingerprint($item),
                ) ||
                (int) $row["item_version"] !== $approvedVersion - 1 ||
                $row["kind"] !== $item["kind"] ||
                isset($images[$row["slide"]])
            ) {
                throw new \DomainException(
                    "Freigegebene Bildfassung passt nicht zum Inhalt.",
                );
            }
            ImageUsage::image($db, $access->club(), $row);
            $store->file($row["id"]);
            if($item['kind']==='carousel'){
                $meta=json_decode($row['metadata_json'],true,32,JSON_THROW_ON_ERROR);
                if(($meta['identity_version']??null)!==($carouselIdentity===false?0:(int)$carouselIdentity))throw new \DomainException('Vereinsdaten geändert. Karussell-Bilder erneut speichern und freigeben.');
                $store->jpeg($row['id']);
            }
            $images[(int) $row["slide"]] = $row;
        }
        $expected = $item['kind']==='carousel'?Carousel::count($item):($item["kind"] === "story" ? count($item["story"]) : 1);
        ksort($images);
        if (
            $expected < 1 ||
            count($images) !== $expected ||
            array_keys($images) !== range(0, $expected - 1)
        ) {
            throw new \DomainException(
                "Alle Bilder dieser Fassung zuerst erzeugen, prüfen und freigeben.",
            );
        }
        return ["item" => $item, "images" => array_values($images)];
    }
}
