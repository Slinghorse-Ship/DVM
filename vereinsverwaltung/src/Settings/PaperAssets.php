<?php declare(strict_types=1);
namespace Kulturhus\Verein\Settings;
use DomainException;
use PDO;
use Kulturhus\Native\Shared\Db;

/** Purpose comes from real current and historical references, never an invented backfill. */
final class PaperAssets
{
    public static function signature(PDO $db,string $club,string $id):bool
    {
        if(!preg_match('/\A[a-f0-9]{32}\z/D',$id))throw new DomainException('Gültiges Briefpapierbild auswählen.',422);
        foreach(['club_settings','settings_revisions']as$table){
            $sql="SELECT 1 FROM ".$table." WHERE club_id=? AND section='paper' AND JSON_UNQUOTE(JSON_EXTRACT(IF(JSON_VALID(payload),payload,'{}'),'$.signature'))=?".($db->inTransaction()?' FOR SHARE NOWAIT':'');
            if(Db::run($db,$sql,[$club,$id])->fetchColumn())return true;
        }
        return false;
    }
    public static function load(PDO $db,string $club,string $id,bool $signature=false):array
    {
        try{
        if(!preg_match('/\A[a-f0-9]{32}\z/D',$id))throw new DomainException('Gültiges Briefpapierbild auswählen.',422);
        $row=Db::run($db,'SELECT mime,bytes,sha256 FROM settings_assets WHERE club_id=? AND id=?'.($db->inTransaction()?' FOR SHARE NOWAIT':''),[$club,$id])->fetch();
        if(!$row)throw new DomainException('Bild nicht verfügbar.',404);
        if(!$signature&&self::signature($db,$club,$id))throw new DomainException('Eine private Vorstands-Unterschrift darf nicht als Vereinslogo verwendet werden.',403);
        if(!is_string($row['bytes'])||!hash_equals($row['sha256'],hash('sha256',$row['bytes'])))throw new DomainException('Briefpapierbild stimmt nicht mit seiner ursprünglichen Prüfsumme überein.',503);
        return $row;
        }catch(\PDOException$failure){
            if(in_array((int)($failure->errorInfo[1]??0),[1205,3572],true))throw new DomainException('Briefpapier wird gerade bearbeitet. Bitte erneut prüfen.',503,$failure);
            throw$failure;
        }
    }
}
