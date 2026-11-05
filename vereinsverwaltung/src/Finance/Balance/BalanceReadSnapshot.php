<?php declare(strict_types=1);
namespace Kulturhus\Verein;
use PDO;
use DomainException;

/** Reuse verified read models only within one explicit, read-only snapshot.
 * Never wrap mutation callbacks; the complete context is discarded on return.
 */
final class BalanceReadSnapshot
{
    private static array $active=[];

    public static function run(PDO $db,callable $read):mixed
    {
        $id=spl_object_id($db);
        if(isset(self::$active[$id]))return $read();
        $own=!$db->inTransaction();
        if($own){
            if($db->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'){
                // Same native snapshot strategy as MySqlBackup: do not acquire the
                // application's database-wide WRITER lock merely to render a page.
                $db->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
                $db->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT, READ ONLY');
            }else $db->beginTransaction();
        }
        self::$active[$id]=['values'=>[],'pending'=>[]];
        try{$result=$read();if($own)$db->commit();return $result;}
        catch(\Throwable $e){if($own&&$db->inTransaction())$db->rollBack();throw $e;}
        finally{unset(self::$active[$id]);}
    }

    public static function remember(PDO $db,string $kind,array $identity,callable $read):mixed
    {
        $id=spl_object_id($db);if(!isset(self::$active[$id]))return $read();
        $key=$kind.':'.json_encode($identity,JSON_THROW_ON_ERROR);
        if(array_key_exists($key,self::$active[$id]['values']))return self::$active[$id]['values'][$key];
        if(isset(self::$active[$id]['pending'][$key]))throw new DomainException('Zirkulären Abschluss- oder Vortragsbezug prüfen.');
        self::$active[$id]['pending'][$key]=true;
        try{return self::$active[$id]['values'][$key]=$read();}
        finally{unset(self::$active[$id]['pending'][$key]);}
    }
}
