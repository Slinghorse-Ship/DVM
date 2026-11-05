<?php declare(strict_types=1);
namespace Kulturhus\Native\Cms;

use InvalidArgumentException;
use Kulturhus\Native\Shared\{Access, Db};
use PDO;

/** Change only a saved draft's placement; descendants and public originals keep their identity. */
final class PageHierarchy
{
    use ClubPublicationLock;
    public const LIMIT = 500;

    public function __construct(private PDO $db, private Access $access) {}

    public function place(string $id, int $version, ?string $parent, int $position): array
    {
        $this->access->require('website', 'edit');
        foreach ([$id, $parent] as $key) {
            if ($key !== null && !preg_match('/\A[a-f0-9]{32}\z/D', $key)) {
                throw new InvalidArgumentException('Gültige Seitenkennung angeben.');
            }
        }
        if ($version < 1 || $version > 4294967294 || $position < -100000 || $position > 100000) {
            throw new InvalidArgumentException('Versionsstand und Reihenfolge prüfen.');
        }
        return Db::atomic($this->db, function () use ($id, $version, $parent, $position): array {
            $this->access->require('website', 'edit');
            $this->lockClub();
            $pages = new Pages($this->db, $this->access);
            $source = $pages->get($id);
            if ($source['version'] !== $version) {
                throw new Conflict('Die Seite wurde inzwischen geändert. Vor dem Verschieben neu laden.');
            }
            if ($source['trashed']) throw new Conflict('Quelle zuerst aus dem Papierkorb zurückholen.');
            if ($source['archived']) {
                throw new Conflict('Archivierte Seite zuerst zurück in die Bearbeitung holen.');
            }
            if ($source['parent_id'] === $parent && $source['nav_order'] === $position) return $source;
            if ($source['parent_id'] !== $parent) $this->validatePlacement($id, $parent);
            // A hierarchy form cannot silently migrate a changed field/template definition.
            $payload = (new DataModel($this->db, $this->access))->publication($source);
            $payload['id'] = $id;
            $payload['parent_id'] = $parent;
            $payload['nav_order'] = $position;
            return $pages->save($payload, $version);
        });
    }

    private function validatePlacement(string $id, ?string $parent): void
    {
        $seen = [$id => true];
        $ancestors = 0;
        while ($parent !== null) {
            if (isset($seen[$parent]) || ++$ancestors >= 100) {
                throw new InvalidArgumentException('Verschieben würde einen Kreis oder mehr als 100 Ebenen erzeugen.');
            }
            $seen[$parent] = true;
            $row = Db::run($this->db,
                "SELECT NULLIF(JSON_UNQUOTE(JSON_EXTRACT(p.draft_json,'$.parent_id')),'null') AS parent_id,a.archived_at FROM khcms_pages p LEFT JOIN khcms_archives a ON a.page_id=p.id AND a.club_id=p.club_id WHERE p.id=? AND p.club_id=?",
                [$parent, $this->access->club()])->fetch();
            if (!$row || $row['archived_at'] !== null) {
                throw new Conflict('Die gewählte übergeordnete Seite ist nicht in der aktiven Bearbeitung verfügbar.');
            }
            $parent = $row['parent_id'];
        }
        // Read IDs only, never every descendant's content or revision history.
        $queue = [[$id, 0]];
        $visited = [$id => true];
        for ($index = 0; $index < count($queue); ++$index) {
            [$current, $depth] = $queue[$index];
            $capacity = self::LIMIT - count($queue);
            $children = Db::run($this->db,
                "SELECT id FROM khcms_pages WHERE club_id=? AND JSON_UNQUOTE(JSON_EXTRACT(draft_json,'$.parent_id'))=? ORDER BY id LIMIT " . ($capacity + 1),
                [$this->access->club(), $current])->fetchAll(PDO::FETCH_COLUMN);
            if (count($children) > $capacity) {
                throw new InvalidArgumentException('Verschieben ist auf 500 Seiten im Teilbaum begrenzt. Kleineren Teilbaum wählen.');
            }
            foreach ($children as $child) {
                if (isset($visited[$child]) || $ancestors + $depth + 2 > 100) {
                    throw new InvalidArgumentException('Der gesamte Teilbaum muss kreisfrei und auf 100 Ebenen begrenzt bleiben.');
                }
                $visited[$child] = true;
                $queue[] = [$child, $depth + 1];
            }
        }
    }
}
