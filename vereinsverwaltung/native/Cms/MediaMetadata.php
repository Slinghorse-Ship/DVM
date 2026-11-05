<?php declare(strict_types=1);
namespace Kulturhus\Native\Cms;

use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use InvalidArgumentException;
use Kulturhus\Native\Shared\{Access, Db, SecretBox};
use PDO;

/** Current usage restrictions and immutable metadata revisions; image bytes never change. */
final class MediaMetadata
{
    use ClubPublicationLock;
    public const CHANNELS = ['website'=>'Website', 'newsletter'=>'Newsletter', 'social'=>'Social Media', 'press'=>'Presse'];
    public const RIGHTS = ['unknown'=>'Ungeprüft', 'cleared'=>'Nutzung geklärt', 'withdrawn'=>'Nutzung gesperrt'];
    public const CONSENT = ['unknown'=>'Ungeprüft', 'not_required'=>'Nicht erforderlich', 'recorded'=>'Liegt vor', 'withdrawn'=>'Widerrufen'];
    private const PUBLIC_FIELDS = ['alt', 'credit', 'source_name', 'source_url', 'license_name', 'license_url'];
    public function __construct(private PDO $db, private Access $access, private ?SecretBox $box = null) {}

    public static function install(PDO $db): void
    {
        foreach ([
            'CREATE TABLE IF NOT EXISTS khcms_media_metadata (club_id VARCHAR(64) NOT NULL, media_id CHAR(32) NOT NULL, version INT UNSIGNED NOT NULL, payload_json TEXT NOT NULL, consent_cipher MEDIUMTEXT NULL, updated_by VARCHAR(64) NOT NULL, updated_at DATETIME(6) NOT NULL, PRIMARY KEY(club_id,media_id), CONSTRAINT cms_metadata_asset FOREIGN KEY(media_id) REFERENCES khcms_media(id) ON DELETE RESTRICT)',
            'CREATE TABLE IF NOT EXISTS khcms_media_metadata_revisions (club_id VARCHAR(64) NOT NULL, media_id CHAR(32) NOT NULL, version INT UNSIGNED NOT NULL, payload_json TEXT NOT NULL, consent_cipher MEDIUMTEXT NULL, actor_id VARCHAR(64) NOT NULL, created_at DATETIME(6) NOT NULL, PRIMARY KEY(club_id,media_id,version), CONSTRAINT cms_metadata_history_asset FOREIGN KEY(media_id) REFERENCES khcms_media(id) ON DELETE RESTRICT)',
        ] as $sql) $db->exec($sql.' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    }

    public function get(string $id): array
    {
        $actor = $this->access->require('website', 'read');
        return self::current($this->db, $actor['club_id'], $id);
    }

    public function save(string $id, array $input, int $version, ?string $proof = null): array
    {
        $this->access->require('website', 'edit');
        if ($version < 0 || $version >= 4294967295) throw new InvalidArgumentException('Gültige Bildversion angeben.');
        $payload = self::normalize($input);
        if ($proof !== null) {
            $this->access->require('website', 'manage');
            if (!$this->box) throw new DomainException('Privater Nachweiszugang nicht verfügbar.');
            $proof = self::text($proof, 4000, false, true);
        }
        return Db::atomic($this->db, function () use ($id, $payload, $version, $proof): array {
            $actor = $this->access->require('website', 'edit');
            if ($proof !== null) $this->access->require('website', 'manage');
            $this->lockClub();
            $old = self::current($this->db, $actor['club_id'], $id);
            if ($old['version'] !== $version) throw new Conflict('Bildangaben inzwischen geändert. Bitte Formular neu laden.');
            $cipher = Db::run($this->db, 'SELECT consent_cipher FROM khcms_media_metadata WHERE club_id=? AND media_id=? FOR UPDATE', [$actor['club_id'], $id])->fetchColumn();
            $cipher = $cipher === false ? null : $cipher;
            if ($proof !== null) $cipher = $proof === '' ? null : $this->box->seal(self::context($actor['club_id'], $id), ['reference'=>$proof]);
            $json = json_encode($payload, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
            Db::run($this->db, 'INSERT INTO khcms_media_metadata VALUES(?,?,?,?,?,?,UTC_TIMESTAMP(6)) ON DUPLICATE KEY UPDATE version=VALUES(version),payload_json=VALUES(payload_json),consent_cipher=VALUES(consent_cipher),updated_by=VALUES(updated_by),updated_at=VALUES(updated_at)', [$actor['club_id'], $id, $version+1, $json, $cipher, $actor['id']]);
            Db::run($this->db, 'INSERT INTO khcms_media_metadata_revisions VALUES(?,?,?,?,?,?,UTC_TIMESTAMP(6))', [$actor['club_id'], $id, $version+1, $json, $cipher, $actor['id']]);
            Db::run($this->db, 'UPDATE khcms_state SET generation=generation+1 WHERE club_id=?', [$actor['club_id']]);
            return self::current($this->db, $actor['club_id'], $id);
        });
    }

    public function privateProof(string $id, ?int $revision = null): string
    {
        $actor = $this->access->require('website', 'manage');
        self::current($this->db, $actor['club_id'], $id);
        if ($revision !== null && $revision < 1) throw new InvalidArgumentException('Nachweisversion prüfen.');
        $row = Db::run($this->db, 'SELECT consent_cipher FROM '.($revision === null ? 'khcms_media_metadata' : 'khcms_media_metadata_revisions').' WHERE club_id=? AND media_id=?'.($revision === null ? '' : ' AND version=?'), $revision === null ? [$actor['club_id'], $id] : [$actor['club_id'], $id, $revision])->fetch();
        if ($revision !== null && !$row) throw new DomainException('Bildversion nicht verfügbar.');
        if (empty($row['consent_cipher'])) return '';
        if (!$this->box) throw new DomainException('Privater Nachweiszugang nicht verfügbar.');
        return $this->box->open(self::context($actor['club_id'], $id), $row['consent_cipher'])['reference'];
    }

    public function history(string $id, int $page = 1): array
    {
        $actor = $this->access->require('website', 'read');
        self::current($this->db, $actor['club_id'], $id);
        if ($page < 1 || $page > 1000000) throw new InvalidArgumentException('Bildverlaufsseite prüfen.');
        $total = (int) Db::run($this->db, 'SELECT COUNT(*) FROM khcms_media_metadata_revisions WHERE club_id=? AND media_id=?', [$actor['club_id'], $id])->fetchColumn();
        $pages = max(1, (int) ceil($total/10)); $page = min($page, $pages); $offset = ($page-1)*10;
        $rows = Db::run($this->db, "SELECT version,payload_json,actor_id,created_at FROM khcms_media_metadata_revisions WHERE club_id=? AND media_id=? ORDER BY version DESC LIMIT 10 OFFSET $offset", [$actor['club_id'], $id])->fetchAll();
        foreach ($rows as &$row) { $row['metadata'] = json_decode($row['payload_json'], true, 32, JSON_THROW_ON_ERROR); unset($row['payload_json']); $row['version'] = (int)$row['version']; }
        return compact('rows', 'total', 'pages', 'page');
    }

    /** No consent details, rights assertions or supplied POST snapshots enter public content. */
    public static function capture(PDO $db, string $club, array $page): array
    {
        $result = [];
        foreach (self::ids($page) as $id) {
            $current = self::current($db, $club, $id);
            $result[$id] = ['version'=>$current['version']] + array_intersect_key($current, array_flip(self::PUBLIC_FIELDS));
        }
        return $result;
    }

    public static function ids(array $page): array
    {
        $ids = [...($page['media_ids'] ?? []), ...ContentSections::mediaIds($page)];
        $ids=[...$ids,...FieldValues::references($page,'media')];
        return array_values(array_unique($ids));
    }

    public static function assertPublication(PDO $db, string $club, array $page, string $channel = 'website'): void
    {
        foreach (self::ids($page) as $id) {
            $current = self::current($db, $club, $id);
            if (!self::usable($current, $channel)) throw new DomainException(self::usageError($current));
            if (($page['media_metadata'][$id]['version'] ?? 0) !== $current['version']) throw new Conflict('Bildangaben geändert. Inhalt prüfen und erneut speichern.');
        }
    }

    /** Issued original pixels retain their saved captions; only present usage rights apply. */
    public static function assertRights(PDO $db, string $club, array $ids, string $channel): void
    {
        foreach ($ids as $id) if (!self::usable($current=self::current($db, $club, $id), $channel)) {
            throw new DomainException(self::usageError($current));
        }
    }

    private static function usageError(array $metadata):string
    {
        $policy=$metadata['usage_policy']??[];
        if(($policy['check_rights']??false)&&($metadata['rights_state']!=='cleared'||!in_array($metadata['consent_state'],['recorded','not_required'],true)))return 'Bildrechte und Einwilligung vor Verwendung klären.';
        if($policy['require_details']??false)foreach(['credit','source_name','license_name']as$key)if(trim($metadata[$key]??'')==='')return 'Credit, Quelle und Lizenz in den Bildangaben ergänzen.';
        return 'Bildnutzung gesperrt, abgelaufen oder für diesen Kanal nicht erlaubt.';
    }

    public static function usable(array $metadata,string $channel): bool
    { return \Kulturhus\Native\Shared\MediaDescription::usable($metadata,$channel); }

    /** Legacy originals retain their original upload text; newer ones use their saved snapshot. */
    public static function original(PDO $db, string $club, string $id, array $page = []): array
    {
        if (isset($page['media_metadata'][$id])) return array_intersect_key($page['media_metadata'][$id], array_flip(self::PUBLIC_FIELDS));
        $row = self::asset($db, $club, $id);
        return ['alt'=>$row['alt'], 'credit'=>'', 'source_name'=>'', 'source_url'=>'', 'license_name'=>'', 'license_url'=>''];
    }

    private static function current(PDO $db, string $club, string $id): array
    {
        $asset = self::asset($db, $club, $id);
        $row = Db::run($db, 'SELECT version,payload_json,consent_cipher IS NOT NULL AS has_proof FROM khcms_media_metadata WHERE club_id=? AND media_id=?'.($db->inTransaction() ? ' FOR SHARE' : ''), [$club, $id])->fetch();
        $defaults = ['alt'=>$asset['alt'], 'credit'=>'', 'source_name'=>'', 'source_url'=>'', 'license_name'=>'', 'license_url'=>'', 'rights_state'=>'unknown', 'consent_state'=>'unknown', 'expires_on'=>'', 'channels'=>array_keys(self::CHANNELS)];
        return ($row ? json_decode($row['payload_json'], true, 32, JSON_THROW_ON_ERROR) : $defaults) + ['version'=>$row ? (int)$row['version'] : 0, 'has_proof'=>$row && (bool)$row['has_proof'], 'usage_policy'=>MediaPolicy::current($db,$club)];
    }

    private static function asset(PDO $db, string $club, string $id): array
    {
        if (!preg_match('/\A[a-f0-9]{32}\z/D', $id)) throw new InvalidArgumentException('Bildkennung prüfen.');
        MediaAvailability::assertSelectable($db,$club,$id);
        $row = Db::run($db, 'SELECT alt FROM khcms_media WHERE club_id=? AND id=?'.($db->inTransaction() ? ' FOR SHARE' : ''), [$club, $id])->fetch();
        if (!$row) throw new DomainException('Bild nicht verfügbar.');
        return $row;
    }

    private static function normalize(array $input): array
    { return \Kulturhus\Native\Shared\MediaDescription::normalize($input); }
    private static function text(mixed $value,int $max,bool $required=false,bool $multiline=false): string
    { return \Kulturhus\Native\Shared\MediaDescription::text($value,$max,$required,$multiline); }
    private static function context(string $club, string $id): string { return 'media-consent:'.$club.':'.$id; }
}
