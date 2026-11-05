<?php declare(strict_types=1);
namespace Kulturhus\Native\Cms;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Kulturhus\Native\Shared\{Access, Db};
use PDO;

/** Counted, club-scoped metadata pages; never loads every image or consent proof. */
final class MediaCatalogue
{
    public const STATES = ['all'=>'Alle Bilder', 'unknown'=>'Rechte ungeprüft', 'cleared'=>'Nutzung geklärt', 'withdrawn'=>'Nutzung gesperrt', 'expired'=>'Abgelaufen'];
    public const ARCHIVES = ['all'=>'Alle Bilder','active'=>'Aktuelle Bilder','archived'=>'Archivierte Bilder'];
    public function __construct(private PDO $db, private Access $access) {}
    public function listing(array $query = []): array
    {
        $q = $query['media_q'] ?? ''; $state = $query['media_state'] ?? 'all'; $page = $query['media_page'] ?? '1';
        $archive=$query['media_archive']??'all';if(!is_string($archive)||!isset(self::ARCHIVES[$archive]))throw new InvalidArgumentException('Bildarchivfilter prüfen.');
        if (!is_string($q) || !preg_match('//u', $q) || mb_strlen($q)>120 || preg_match('/[\x00-\x1f\x7f]/', $q) || !is_string($state) || !isset(self::STATES[$state]) || (!is_string($page) && !is_int($page)) || !preg_match('/\A[1-9][0-9]{0,6}\z/D', (string)$page) || (int)$page>1000000) throw new InvalidArgumentException('Bildsuche und Ergebnisseite prüfen.');
        $q = trim($q); $page = (int)$page;
        return Db::atomic($this->db, function () use ($q, $state, $page,$archive): array {
            $actor = $this->access->require('website', 'read'); $club = $actor['club_id'];
            $where = 'm.club_id=? AND '.MediaAvailability::activeSql($this->db); $params = [$club];
            if ($q !== '') { $like = '%'.strtr($q, ['!'=>'!!', '%'=>'!%', '_'=>'!_']).'%'; $where .= " AND (m.original_name LIKE ? ESCAPE '!' OR m.alt LIKE ? ESCAPE '!' OR d.payload_json LIKE ? ESCAPE '!')"; array_push($params, $like, $like, $like); }
            if (in_array($state, ['unknown','cleared','withdrawn'], true)) { $where .= " AND COALESCE(JSON_UNQUOTE(JSON_EXTRACT(d.payload_json,'$.rights_state')),'unknown')=?"; $params[] = $state; }
            if ($state === 'expired') { $where .= " AND JSON_UNQUOTE(JSON_EXTRACT(d.payload_json,'$.expires_on'))<>'' AND JSON_UNQUOTE(JSON_EXTRACT(d.payload_json,'$.expires_on'))<?"; $params[] = (new DateTimeImmutable('now', new DateTimeZone('Europe/Berlin')))->format('Y-m-d'); }
            if($archive!=='all'){$where.=' AND COALESCE(a.archived,0)=?';$params[]=(int)($archive==='archived');}
            $from = ' FROM khcms_media m LEFT JOIN khcms_media_metadata d ON d.club_id=m.club_id AND d.media_id=m.id LEFT JOIN khcms_media_archives a ON a.club_id=m.club_id AND a.media_id=m.id WHERE '.$where;
            $total = (int)Db::run($this->db, 'SELECT COUNT(*)'.$from, $params)->fetchColumn(); $pages = max(1, (int)ceil($total/12)); $page = min($page, $pages); $offset = ($page-1)*12;
            $rows = Db::run($this->db, 'SELECT m.id,m.original_name,m.mime,m.width,m.height,m.created_at,COALESCE(a.archived,0) AS archived'.$from." ORDER BY m.created_at DESC,m.id DESC LIMIT 12 OFFSET $offset", $params)->fetchAll();
            $metadata = new MediaMetadata($this->db, $this->access);
            foreach ($rows as &$row) $row['metadata'] = $metadata->get($row['id']);
            if ($this->access->require('website','read')['club_id'] !== $club) throw new \DomainException('Bildzugang inzwischen geändert.');
            return compact('rows', 'total', 'pages', 'page', 'q', 'state','archive');
        });
    }
}
