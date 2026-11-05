<?php declare(strict_types=1);
namespace Kulturhus\Verein;
use PDO;
use RuntimeException;

/** Native logical snapshot. Generated columns are reconstructed, never inserted as values. */
final class MySqlBackup
{
    private static function identifier(string $name):string{return '`'.str_replace('`','``',$name).'`';}
    public static function create(PDO $db,string $dsn,string $destination):void
    {
        if(file_exists($destination)||is_link($destination)||!is_dir(dirname($destination)))throw new RuntimeException('Sicherungsziel muss eine neue Datei in einem vorhandenen privaten Verzeichnis sein.');
        $temporary=$destination.'.partial-'.bin2hex(random_bytes(8));$file=null;
        try{
            $old=umask(0077);try{$file=fopen($temporary,'xb');}finally{umask($old);}if(!$file)throw new RuntimeException('Private Sicherungsdatei konnte nicht angelegt werden.');
            self::export($db,$dsn,static function(string $text)use($file):void{while($text!==''){$n=fwrite($file,$text);if($n===false||$n===0)throw new RuntimeException('Sicherungsdatei konnte nicht vollständig geschrieben werden.');$text=substr($text,$n);}});
            if(!fflush($file))throw new RuntimeException('Sicherungsdatei konnte nicht abgeschlossen werden.');fclose($file);$file=null;
            if(!rename($temporary,$destination))throw new RuntimeException('Sicherungsdatei konnte nicht bereitgestellt werden.');chmod($destination,0600);
        }finally{if(is_resource($file))fclose($file);if(is_file($temporary))unlink($temporary);}
    }

    /** Same snapshot formatter, streamed to a sink (for encryption without plaintext files). */
    public static function export(PDO $db,string $dsn,callable $write):void
    {
        if(!Database::isMysql($db)||$db->inTransaction())throw new RuntimeException('MySQL-Sicherung benötigt eine eigene Verbindung ohne laufenden Vorgang.');
        $parts=[];foreach(explode(';',substr($dsn,6)) as $part){$p=explode('=',$part,2);if(count($p)===2)$parts[strtolower(trim($p[0]))]=trim($p[1]);}
        $name=(string)$db->query('SELECT DATABASE()')->fetchColumn();
        if(!str_starts_with(strtolower($dsn),'mysql:')||$name===''||($parts['dbname']??'')!==$name)throw new RuntimeException('Sicherungsdatenbank stimmt nicht mit der Verbindung überein.');
        $lock='verein-schema-'.substr(hash('sha256',$name),0,48);$q=$db->prepare('SELECT GET_LOCK(?,10)');$q->execute([$lock]);
        if((int)$q->fetchColumn()!==1)throw new RuntimeException('Datenbankwartung läuft; Sicherung später erneut starten.');
        $transaction=false;
        $buffered=$db->getAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY);
        try{
            $tables=$db->query('SELECT TABLE_NAME,TABLE_TYPE,ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() ORDER BY TABLE_NAME')->fetchAll();
            foreach($tables as $table)if($table['TABLE_TYPE']!=='BASE TABLE'||$table['ENGINE']!=='InnoDB')throw new RuntimeException('Sicherung enthält eine nicht unterstützte Tabelle oder Ansicht.');
            if((int)$db->query('SELECT COUNT(*) FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA=DATABASE()')->fetchColumn()||(int)$db->query('SELECT COUNT(*) FROM information_schema.EVENTS WHERE EVENT_SCHEMA=DATABASE()')->fetchColumn())throw new RuntimeException('Routinen oder Datenbank-Ereignisse benötigen eine gesonderte Sicherung.');
            if((int)$db->query('SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE() AND REFERENCED_TABLE_SCHEMA<>DATABASE()')->fetchColumn())throw new RuntimeException('Datenbankübergreifende Beziehungen benötigen eine gemeinsame Sicherung.');
            $metadata=[];
            foreach($tables as $table){
                $id=self::identifier($table['TABLE_NAME']);$ddl=$db->query('SHOW CREATE TABLE '.$id)->fetch(PDO::FETCH_NUM)[1];
                $q=$db->prepare("SELECT COLUMN_NAME,DATA_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND GENERATION_EXPRESSION='' ORDER BY ORDINAL_POSITION");$q->execute([$table['TABLE_NAME']]);$fields=$q->fetchAll();
                $metadata[]=['id'=>$id,'ddl'=>$ddl,'columns'=>array_map([self::class,'identifier'],array_column($fields,'COLUMN_NAME')),'binary'=>array_map(static fn($f)=>in_array($f['DATA_TYPE'],['binary','varbinary','tinyblob','blob','mediumblob','longblob','bit'],true),$fields)];
            }
            $triggers=$db->query('SELECT TRIGGER_NAME,ACTION_TIMING,EVENT_MANIPULATION,EVENT_OBJECT_TABLE,ACTION_STATEMENT,SQL_MODE FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() ORDER BY EVENT_OBJECT_TABLE,ACTION_TIMING,EVENT_MANIPULATION,ACTION_ORDER')->fetchAll();
            // Read-only snapshot deliberately does not acquire the business writer lock.
            $db->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            $db->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT, READ ONLY');$transaction=true;
            $write("-- Native Vereinsmanager MySQL snapshot; restore into an empty database.\nSET NAMES utf8mb4;\nSET @KH_OLD_FK=@@FOREIGN_KEY_CHECKS, @KH_OLD_MODE=@@SQL_MODE, @KH_OLD_TZ=@@TIME_ZONE;\nSET FOREIGN_KEY_CHECKS=0;\nSET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';\nSET TIME_ZONE='+00:00';\n");
            foreach($metadata as $table)$write($table['ddl'].";\n");
            $db->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY,false);
            foreach($metadata as $table){
                $columnList=implode(',',$table['columns']);$q=$db->query('SELECT '.$columnList.' FROM '.$table['id']);
                try{while($row=$q->fetch(PDO::FETCH_NUM)){
                    $values=[];foreach($row as $i=>$v){$hex="X'".bin2hex((string)$v)."'";$values[]=$v===null?'NULL':($table['binary'][$i]?$hex:'CONVERT('.$hex.' USING utf8mb4)');}
                    $write('INSERT INTO '.$table['id'].' ('.$columnList.') VALUES ('.implode(',',$values).");\n");
                }}finally{$q->closeCursor();}
            }
            $db->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY,$buffered);
            $db->rollBack();$transaction=false;
            foreach($triggers as $trigger){
                $write('SET SQL_MODE='.$db->quote($trigger['SQL_MODE']).";\nDELIMITER ;;\nCREATE TRIGGER ".self::identifier($trigger['TRIGGER_NAME']).' '.$trigger['ACTION_TIMING'].' '.$trigger['EVENT_MANIPULATION'].' ON '.self::identifier($trigger['EVENT_OBJECT_TABLE']).' FOR EACH ROW '.$trigger['ACTION_STATEMENT'].";;\nDELIMITER ;\n");
            }
            $write("SET FOREIGN_KEY_CHECKS=@KH_OLD_FK;\nSET SQL_MODE=@KH_OLD_MODE;\nSET TIME_ZONE=@KH_OLD_TZ;\n");
        }finally{
            $db->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY,$buffered);
            if($transaction&&$db->inTransaction())$db->rollBack();
            $q=$db->prepare('SELECT RELEASE_LOCK(?)');$q->execute([$lock]);
        }
    }
}
