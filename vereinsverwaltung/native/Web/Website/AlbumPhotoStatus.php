<?php declare(strict_types=1);
namespace Kulturhus\Native\Web\Website;

use Kulturhus\Native\Shared\{Access, Db};
use PDO;

/** Read the exact caller-owned photo and its committed append revision, without replay. */
final class AlbumPhotoStatus
{
    public function __construct(private PDO $db, private Access $access) {}

    public function query(array $query): array
    {
        $album = $query['album_id'] ?? null;
        $version = $query['version'] ?? null;
        if (!is_string($album) || !preg_match('/\A[a-f0-9]{32}\z/D', $album)
            || (!is_string($version) && !is_int($version)) || !preg_match('/\A[1-9][0-9]*\z/D', (string) $version)
            || ($base = filter_var($version, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 4294967294]])) === false) {
            throw new \InvalidArgumentException('Albumkennung und gespeicherten Versionsstand prüfen.');
        }
        return Db::atomic($this->db, function () use ($query, $album, $base): array {
            $actor = $this->access->require('website', 'edit');
            $status = (new PagePhotoStatus($this->db, $this->access))->query($query);
            if (!$status['found']) return ['found' => false];
            // Read at most two bounded ID arrays, rather than the original text payloads.
            $row = Db::run($this->db, "SELECT p.version,a.archived_at,t.trashed_at,
                COALESCE(JSON_UNQUOTE(JSON_EXTRACT(r.payload_json,'$.template_definition.renderer')),JSON_UNQUOTE(JSON_EXTRACT(r.payload_json,'$.template'))) AS renderer,
                JSON_EXTRACT(b.payload_json,'$.media_ids') AS before_ids,
                JSON_EXTRACT(r.payload_json,'$.media_ids') AS after_ids
                FROM khcms_pages p
                JOIN khcms_revisions b ON b.page_id=p.id AND b.club_id=p.club_id AND b.revision=?
                JOIN khcms_revisions r ON r.page_id=p.id AND r.club_id=p.club_id AND r.revision=? AND r.actor_id=? AND r.action='save'
                LEFT JOIN khcms_archives a ON a.page_id=p.id AND a.club_id=p.club_id
                LEFT JOIN khcms_trash t ON t.page_id=p.id AND t.club_id=p.club_id
                WHERE p.club_id=? AND p.id=? FOR SHARE", [$base, $base + 1, $actor['id'], $actor['club_id'], $album])->fetch(PDO::FETCH_ASSOC);
            if (!$row || $row['renderer'] !== 'gallery') return ['found' => false];
            $before = json_decode($row['before_ids'] ?? 'null', true, 8, JSON_THROW_ON_ERROR);
            $after = json_decode($row['after_ids'] ?? 'null', true, 8, JSON_THROW_ON_ERROR);
            $id = $status['image']['id'];
            if (!is_array($before) || !array_is_list($before) || count($before) >= 100
                || in_array($id, $before, true) || $after !== [...$before, $id]) return ['found' => false];
            return $status + ['album' => ['id' => $album, 'version' => $base + 1,
                'current' => (int) $row['version'] === $base + 1 && $row['archived_at'] === null && $row['trashed_at'] === null]];
        });
    }
}
