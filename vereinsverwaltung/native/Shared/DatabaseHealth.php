<?php declare(strict_types=1);
namespace Kulturhus\Native\Shared;

/** Read-only structural checks reused by recovery and operator diagnostics. */
final class DatabaseHealth
{
    private static function id(string $name): string { return '`'.str_replace('`','``',$name).'`'; }
    public static function assertHealthy(\PDO $db): void
    {
        foreach ($db->query('SHOW TABLES')->fetchAll(\PDO::FETCH_COLUMN) as $table) {
            foreach ($db->query('CHECK TABLE '.self::id($table))->fetchAll() as $result) {
                if ($result['Msg_type']==='error' || ($result['Msg_type']==='status' && $result['Msg_text']!=='OK')) throw new \DomainException('Wiederhergestellte Tabelle ist nicht intakt.');
            }
        }
        $groups=[];
        $sql='SELECT TABLE_NAME,CONSTRAINT_NAME,COLUMN_NAME,REFERENCED_TABLE_NAME,REFERENCED_COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE() AND REFERENCED_TABLE_NAME IS NOT NULL ORDER BY TABLE_NAME,CONSTRAINT_NAME,ORDINAL_POSITION';
        foreach ($db->query($sql) as $row) $groups[$row['TABLE_NAME'].'.'.$row['CONSTRAINT_NAME']][]=$row;
        foreach ($groups as $columns) {
            $joins=[]; $nonnull=[];
            foreach ($columns as $row) {
                $joins[]='p.'.self::id($row['REFERENCED_COLUMN_NAME']).'=c.'.self::id($row['COLUMN_NAME']);
                $nonnull[]='c.'.self::id($row['COLUMN_NAME']).' IS NOT NULL';
            }
            $row=$columns[0];
            $sql='SELECT COUNT(*) FROM '.self::id($row['TABLE_NAME']).' c WHERE '.implode(' AND ',$nonnull).' AND NOT EXISTS (SELECT 1 FROM '.self::id($row['REFERENCED_TABLE_NAME']).' p WHERE '.implode(' AND ',$joins).')';
            if ((int)$db->query($sql)->fetchColumn()) throw new \DomainException('Wiederhergestellte Daten enthalten ungültige Beziehungen.');
        }
    }
}
