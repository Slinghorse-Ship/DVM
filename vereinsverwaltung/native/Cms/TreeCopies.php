<?php declare(strict_types=1);
namespace Kulturhus\Native\Cms;
use InvalidArgumentException;
use Kulturhus\Native\Shared\{Access, Db};
use PDO;

/** Two atomic passes retain normal validation/history and allow forward/self references. */
final class TreeCopies
{
    use ClubPublicationLock;
    public function __construct(private PDO $db, private Access $access) {}

    public function create(
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
            $sources = (new TreeCopySources($this->db, $this->access))->read(
                $sourceId,
                $expectedVersion,
                $options["basis"],
            );
            $model = new DataModel($this->db, $this->access);
            $pages = new Pages($this->db, $this->access);
            $copier = new PageCopies($this->db, $this->access);
            foreach ($sources as $key => $source) {
                $sources[$key]["payload"] = $model->publication(
                    $source["payload"],
                );
            }
            $rootPayload = $model->normalizePage(
                array_replace($sources[$sourceId]["payload"], [
                    "title" => $options["title"],
                    "slug" => $options["slug"],
                ]),
                $sources[$sourceId]['payload'],
                true,
            );
            if (strlen($rootPayload["slug"]) > 120) {
                throw new InvalidArgumentException(
                    "Wurzeladresse für eine Teilbaumkopie auf maximal 120 Zeichen kürzen.",
                );
            }
            $addresses = [$sourceId => $rootPayload["slug"]];
            foreach ($sources as $key => $source) {
                if ($key !== $sourceId) {
                    $addresses[$key] = TreeCopyAddresses::child(
                        $rootPayload["slug"],
                        $source,
                    );
                }
            }
            $copies = [];
            $map = [];
            foreach ($sources as $key => $source) {
                $copy = $copier->create($key, $source["version"], [
                    "title" =>
                        $key === $sourceId
                            ? $options["title"]
                            : $source["payload"]["title"],
                    "slug" => $addresses[$key],
                    "basis" => $options["basis"],
                ]);
                $copies[$key] = $copy;
                $map[$key] = $copy["id"];
            }
            foreach ($copies as $key => $copy) {
                $changed = false;
                $parent = $copy["parent_id"];
                if ($key !== $sourceId && isset($map[$parent ?? ""])) {
                    $copy["parent_id"] = $map[$parent];
                    $changed = true;
                }
                $remapped = FieldValues::remapPages($copy, $map);
                if ($remapped !== $copy) { $copy = $remapped; $changed = true; }
                if ($changed) {
                    $copies[$key] = $pages->save($copy, $copy["version"]);
                }
            }
            return [
                "root" => $copies[$sourceId],
                "pages" => array_values($copies),
                "mapping" => $map,
            ];
        });
    }
}
