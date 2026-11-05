<?php declare(strict_types=1);
namespace Kulturhus\Native\Cms;

use DomainException;
use InvalidArgumentException;
use PDO;
use Kulturhus\Native\Shared\Db;

/** Historic media records remain immutable; only the separate current state changes. */
final class MediaAvailability
{
    public static function installed(PDO $db): bool
    {
        return (bool)Db::run($db,"SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='khcms_media_removal_states' AND TABLE_TYPE='BASE TABLE'")->fetchColumn();
    }

    public static function activeSql(PDO $db, string $alias='m'): string
    {
        if(!preg_match('/\A[a-z][a-z0-9_]*\z/D',$alias))throw new InvalidArgumentException('Bildabfrage prüfen.');
        return self::installed($db)?"NOT EXISTS(SELECT 1 FROM khcms_media_removal_states removal WHERE removal.club_id=$alias.club_id AND removal.media_id=$alias.id AND removal.state<>'restored')":'1=1';
    }

    /** Current locking reads also protect writers using an earlier repeatable-read snapshot. */
    public static function assertSelectable(PDO $db, string $club, string $id): void
    {
        if(!preg_match('/\A[a-f0-9]{32}\z/D',$id))throw new InvalidArgumentException('Bildkennung prüfen.');
        if(!self::installed($db))return;
        $state=Db::run($db,'SELECT state FROM khcms_media_removal_states WHERE club_id=? AND media_id=?'.($db->inTransaction()?' FOR SHARE':''),[$club,$id])->fetchColumn();
        if($state!==false&&$state!=='restored')throw new DomainException(self::message($state));
    }

    public static function assertContentSelectable(PDO $db,string $club,array $content): void
    {
        if(!self::installed($db))return;
        $json=json_encode($content,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        $state=Db::run($db,"SELECT state FROM khcms_media_removal_states WHERE club_id=? AND state<>'restored' AND LOCATE(CAST(media_id AS BINARY),CAST(? AS BINARY))>0 LIMIT 1".($db->inTransaction()?' FOR SHARE':''),[$club,$json])->fetchColumn();
        if($state!==false)throw new DomainException(self::message($state));
    }
    private static function message(string $state): string
    {
        return match($state){
            'removed'=>'Bild zuerst aus dem entfernten Bestand zurückholen.',
            'purging'=>'Endgültiges Löschen noch nicht abgeschlossen. Aktuellen Stand öffnen.',
            'purged'=>'Bild endgültig gelöscht.',
            default=>'Gespeicherten Bildzustand prüfen.'
        };
    }
}
