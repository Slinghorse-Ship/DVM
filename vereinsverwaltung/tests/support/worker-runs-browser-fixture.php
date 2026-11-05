<?php declare(strict_types=1);
require dirname(__DIR__, 2) . "/native/bootstrap.php";
use Kulturhus\Native\TestSupport;
use Kulturhus\Native\Integration\ManagerBridge;
use Kulturhus\Native\Integration\Jobs\WorkerRuns;
if (
    PHP_SAPI !== "cli" ||
    (TestSupport::config()["label"] ?? "") !== "integrated-manager"
) {
    throw new RuntimeException(
        "Eigene synthetische Manager-Fixture erforderlich.",
    );
}
putenv("KH_NATIVE_MANAGER=1");
putenv("VEREIN_AUTH=native");
$db = TestSupport::db();
$club = ManagerBridge::clubKey("manager-demo");
$id = WorkerRuns::begin($db, $club, []);
WorkerRuns::finish($db, $club, $id, ["drafts" => 1]);
$id = WorkerRuns::begin($db, $club, ["send" => true]);
WorkerRuns::finish($db, $club, $id, [
    "account_mails" => ["uncertain" => 1],
]);
$id = WorkerRuns::begin($db, $club, ["publish" => true]);
WorkerRuns::finish($db, $club, $id, [
    "publications" => ["published" => 1, "conflicted" => 1],
]);
echo "Synthetische Hintergrundläufe bereit; kein externer Dienst aufgerufen.\n";
