<?php declare(strict_types=1);
namespace Kulturhus\Native\Installer;

/** Reader for the existing MySqlBackup formatter, including generated columns/triggers. */
final class SqlSnapshot
{
    private const STATEMENT_LIMIT=33554432;
    public static function import(\PDO $db, string $file, UpgradePermit|RestoreProgress|null $permit=null, ?string $target=null, ?\Closure $checkpoint=null): void
    {
        if ((PHP_SAPI!=='cli' && !$permit) || $db->getAttribute(\PDO::ATTR_DRIVER_NAME)!=='mysql' || $db->inTransaction()) throw new \DomainException('Eigene MySQL-Wartungsverbindung erforderlich.');
        if ($permit instanceof UpgradePermit) $permit->assertMaintenance($target??'',$db);
        if ($permit instanceof RestoreProgress) $permit->assertImport($db);
        $marker=$permit instanceof RestoreProgress?'native_restore_operation':'native_package_operation';
        $query='SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE()'.($permit?" AND TABLE_NAME<>".$db->quote($marker):'');
        if ((int)$db->query($query)->fetchColumn()) throw new \DomainException('Wiederherstellung benötigt eine leere eigene Datenbank.');
        $original=$db->query('SELECT @@FOREIGN_KEY_CHECKS,@@SQL_MODE,@@TIME_ZONE')->fetch(\PDO::FETCH_NUM);
        try {
            foreach (self::statements($file) as $sql) {
                self::execute($db,$sql);
                if ($checkpoint) $checkpoint('sql-statement');
            }
        } finally {
            $db->exec('SET FOREIGN_KEY_CHECKS='.(int)$original[0]);
            $db->exec('SET SQL_MODE='.$db->quote($original[1]));
            $db->exec('SET TIME_ZONE='.$db->quote($original[2]));
        }
    }

    /** The same fully parsed, bounded statements define the allowed partial table set. */
    public static function tables(string $file): array
    {
        $tables=[];
        foreach (self::statements($file) as $sql) if (preg_match('/\ACREATE TABLE `((?:[^`]|``)+)`\s*\(/D',trim($sql),$match)) {
            $name=str_replace('``','`',$match[1]);
            if (isset($tables[$name]) || in_array($name,['native_restore_operation','native_package_operation'],true)) throw new \DomainException('SQL-Snapshot enthält einen fremden Betriebsnachweis.');
            $tables[$name]=true;
        }
        if (!$tables) throw new \DomainException('SQL-Snapshot enthält keine ursprünglichen Tabellen.');
        $names=array_keys($tables);sort($names,SORT_STRING);return $names;
    }

    private static function statements(string $file): \Generator
    {
        $input=fopen($file,'rb');
        if (!$input) throw new \DomainException('Verifizierter SQL-Snapshot fehlt.');
        $delimiter=';'; $buffer=''; $quote=null; $escaped=false; $header=false;
        try {
            foreach (self::lines($input) as $line) {
                if (!$header) {
                    if ($line!=="-- Native Vereinsmanager MySQL snapshot; restore into an empty database.\n") throw new \DomainException('Unbekanntes SQL-Snapshotformat.');
                    $header=true; continue;
                }
                if ($quote===null && trim($buffer)==='' && preg_match('/\ADELIMITER (;;|;)\s*\z/D',$line,$match)) { $delimiter=$match[1]; continue; }
                // DDL comments may contain semicolons/newlines; INSERT values are hexadecimal.
                for ($i=0,$length=strlen($line),$start=0; $i<$length; ++$i) {
                    $char=$line[$i];
                    if ($quote!==null) {
                        if ($escaped) { $escaped=false; continue; }
                        if ($char==='\\') { $escaped=true; continue; }
                        if ($char===$quote) {
                            if (($line[$i+1]??'')===$quote) { ++$i; continue; }
                            $quote=null;
                        }
                        continue;
                    }
                    if (in_array($char,["'",'"','`'],true)) { $quote=$char; continue; }
                    if (substr($line,$i,strlen($delimiter))===$delimiter) {
                        $buffer.=substr($line,$start,$i-$start);
                        $statement=trim($buffer);self::allowed($statement);if ($statement!=='') yield $statement; $buffer='';
                        $i+=strlen($delimiter)-1; $start=$i+1;
                    }
                }
                $buffer.=substr($line,$start);
                if (strlen($buffer)>self::STATEMENT_LIMIT) throw new \DomainException('SQL-Anweisung überschreitet 32 MiB.');
            }
            if (!$header || $quote!==null || trim($buffer)!=='' || $delimiter!==';') throw new \DomainException('SQL-Snapshot unvollständig.');
        } finally {
            fclose($input);
        }
    }
    /** Keep quote/delimiter pairs intact across bounded stream reads. */
    private static function lines($input): \Generator
    {
        $line='';
        while (!feof($input)) {
            $part=fgets($input,65537);
            if ($part===false) {
                if (!feof($input)) throw new \DomainException('SQL-Snapshot nicht lesbar.');
                break;
            }
            if (strlen($line)+strlen($part)>self::STATEMENT_LIMIT) throw new \DomainException('SQL-Zeile überschreitet 32 MiB.');
            $line.=$part;
            if (str_ends_with($part,"\n")) { yield $line; $line=''; }
        }
        if ($line!=='') yield $line;
    }
    private static function execute(\PDO $db, string $sql): void
    {
        self::allowed($sql);if(trim($sql)!=='')$db->exec($sql);
    }
    private static function allowed(string $sql): void
    {
        if (strlen($sql)>self::STATEMENT_LIMIT) throw new \DomainException('SQL-Anweisung überschreitet 32 MiB.');
        $sql=trim($sql); if ($sql==='') return;
        if (!preg_match('/\A(?:SET\s|CREATE TABLE `|CREATE TRIGGER `|INSERT INTO `)/D',$sql)) throw new \DomainException('SQL-Anweisung gehört nicht zum nativen Snapshotformat.');
    }
}
