<?php declare(strict_types=1);
namespace Kulturhus\Native\Delivery;

use Kulturhus\Native\Shared\Db;
use PDO;

/** Non-private original reference. Never contains mail, recipients or transport credentials. */
final class Source
{
    public static function record(
        PDO $db,
        string $club,
        string $job,
        string $kind,
        array $message,
    ): void {
        if (
            !in_array($kind, ["campaign", "test", "press", "meta", "stadtlandfunk"], true) ||
            ($kind==='test'?!isset($message['newsletter_original']):!isset($message['approval']))
        ) {
            return;
        }
        $approval = $kind==='test'?$message['newsletter_original']:$message["approval"];
        if (
            !is_string($approval["item"] ?? null) ||
            !preg_match('/^[a-f0-9]{32}$/D', $approval["item"]) ||
            !is_int($approval["version"] ?? null) ||
            $approval["version"] < 1
        ) {
            throw new \DomainException(
                "Ungültiger Originalverweis des Zustellauftrags.",
            );
        }
        $module = JobPurpose::module($kind);
        $table = \Kulturhus\Native\Communication\Schema::table(
            $module,
            "items",
        );
        $version = Db::run(
            $db,
            "SELECT version FROM $table WHERE club_id=? AND id=? FOR SHARE",
            [$club, $approval["item"]],
        )->fetchColumn();
        if ($version === false || (int) $version !== $approval["version"]) {
            throw new \DomainException(
                "Originalfassung des Zustellauftrags ist nicht aktuell.",
            );
        }
        Db::run(
            $db,
            "INSERT INTO native_delivery_sources(job_id,club_id,module,item_id,item_version) VALUES(?,?,?,?,?)",
            [$job, $club, $module, $approval["item"], $approval["version"]],
        );
    }
}
