<?php declare(strict_types=1);
namespace Kulturhus\Native\Web\Website;

use Kulturhus\Native\Shared\{Access, Db};
use PDO;

/** Read one exact caller-owned upload; never retry it or select/publish a photo. */
final class PagePhotoStatus
{
    public function __construct(private PDO $db, private Access $access) {}

    public function query(array $query): array
    {
        $id = $query['upload_id'] ?? null;
        if (!is_string($id) || !preg_match('/\A[a-f0-9]{32}\z/D', $id)) throw new \InvalidArgumentException('Upload-Kennung prüfen.');
        return Db::atomic($this->db, function () use ($id): array {
            $actor = $this->access->require('website', 'edit');
            $this->access->require('website', 'read');
            $row = Db::run($this->db, 'SELECT id,alt,original_name FROM khcms_media WHERE club_id=? AND id=? AND created_by=?', [$actor['club_id'], $id, $actor['id']])->fetch(PDO::FETCH_ASSOC);
            if (!$row) return ['found' => false];
            return ['found' => true, 'image' => ['id' => $row['id'], 'alt' => $row['alt'], 'name' => $row['original_name'], 'url' => '/media-preview/'.$row['id'].'/thumb', 'edit_url' => '/?view=media&section=library&item='.$row['id']]];
        });
    }
}
