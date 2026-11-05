<?php declare(strict_types=1);
namespace Kulturhus\Verein;

use DomainException;
use PDO;
use Throwable;

/** Six current owned summaries; listing never calculates or creates a charge. */
final class CatalogAssignments
{
    public function __construct(private PDO $db, private string $actorId) {}

    public function page(string $tab, array $input = []): array
    {
        $page = $input['pricing_page'] ?? '1';
        $q = $input['pricing_q'] ?? '';
        if (
            !in_array($tab, ['rates', 'services'], true)
            || (!is_string($page) && !is_int($page))
            || !preg_match('/\A[1-9][0-9]{0,6}\z/D', (string)$page)
            || (int)$page > 1000000
            || !is_string($q)
            || !mb_check_encoding($q, 'UTF-8')
            || mb_strlen($q, 'UTF-8') > 150
            || preg_match('/[\x00-\x1f\x7f]/', $q)
        ) {
            throw new DomainException('Tarifzuweisungsseite und Suche prüfen.', 422);
        }
        $pageInput = (string)$page;
        $own = !$this->db->inTransaction();
        try {
            if ($own) $this->db->beginTransaction();
            $actor = Permissions::actor($this->db, $this->actorId);
            if (!in_array($actor['finance_access'], ['read', 'write'], true) || $actor['members_access'] === 'none') {
                throw new DomainException('Finanz- und Mitgliederleserechte für Tarifzuweisungen erforderlich.', 403);
            }
            $club = $actor['club_id'];
            $args = [$club];
            $name = "JSON_UNQUOTE(JSON_EXTRACT(c.payload,'$.name'))";
            $from = ' FROM catalog_assignments a JOIN settings_catalog c ON c.club_id=a.club_id AND c.id=a.catalog_id JOIN contacts p ON p.club_id=a.club_id AND p.id=a.contact_id';
            $where = ' WHERE a.club_id=? AND p.archived_at IS NULL AND c.kind '
                . ($tab === 'services' ? "='service'" : "IN ('contribution','donation')");
            if ($q !== '') {
                $where .= ' AND LOCATE(LOWER(?),LOWER(CONCAT(p.name,CHAR(10),' . $name . '))) > 0';
                $args[] = $q;
            }
            $statement = $this->db->prepare('SELECT COUNT(*)' . $from . $where . ' FOR SHARE NOWAIT');
            $statement->execute($args);
            $total = (int)$statement->fetchColumn();
            $pages = max(1, (int)ceil($total / 6));
            $page = (int)$page;
            if ($page > $pages) throw new DomainException('Verfügbare Tarifzuweisungsseite auswählen.', 422);
            $statement = $this->db->prepare(
                'SELECT a.id,a.catalog_id,a.contact_id,a.starts_on,a.ends_on,a.version,p.name,'
                . $name . " AS catalog_name,JSON_UNQUOTE(JSON_EXTRACT(c.payload,'$.interval')) AS catalog_interval,c.active,c.kind"
                . $from . $where . ' ORDER BY p.name,a.id LIMIT 6 OFFSET ' . (($page - 1) * 6) . ' FOR SHARE NOWAIT'
            );
            $statement->execute($args);
            $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
            if ($own) $this->db->commit();
            return ['rows' => $rows, 'total' => $total, 'pages' => $pages, 'page' => $page, 'page_input' => $pageInput, 'q' => $q];
        } catch (Throwable $failure) {
            if ($own && $this->db->inTransaction()) $this->db->rollBack();
            if ($failure instanceof \PDOException && in_array((int)($failure->errorInfo[1] ?? 0), [1205, 3572], true)) {
                throw new DomainException('Tarifzuweisungen werden gerade bearbeitet. Bitte erneut prüfen.', 503, $failure);
            }
            throw $failure;
        }
    }
}
