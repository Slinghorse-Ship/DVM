<?php declare(strict_types=1);
namespace Kulturhus\Verein\Tests;

/** Restore evidence, not runtime SQL rewriting. MySQL may spell inherited charsets explicitly. */
final class MySqlSchemaProof
{
    public static function table(\PDO $db,string $table):array
    {
        if(!preg_match('/^[a-zA-Z0-9_]+$/D',$table))throw new \RuntimeException('Invalid schema-proof table');
        $ddl=$db->query('SHOW CREATE TABLE `'.$table.'`')->fetch(\PDO::FETCH_NUM)[1];
        $q=$db->prepare('SELECT COLUMN_NAME,COLUMN_TYPE,IS_NULLABLE,COLUMN_DEFAULT,CHARACTER_SET_NAME,COLLATION_NAME,EXTRA,GENERATION_EXPRESSION,COLUMN_COMMENT,ORDINAL_POSITION FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? ORDER BY ORDINAL_POSITION');
        $q->execute([$table]);$columns=$q->fetchAll(\PDO::FETCH_ASSOC);
        return ['ddl'=>self::explicitCharsets($ddl,$columns),'columns'=>$columns];
    }
    public static function explicitCharsets(string $ddl,array $columns):string
    {
        foreach($columns as $column){
            $charset=$column['CHARACTER_SET_NAME'];$collation=$column['COLLATION_NAME'];
            if(!is_string($charset)||!is_string($collation)||!preg_match('/^[a-z0-9_]+$/D',$charset)||!preg_match('/^[a-z0-9_]+$/D',$collation))continue;
            $name=str_replace('`','``',$column['COLUMN_NAME']);
            // Only the omitted CHARACTER SET before an already explicit, verified COLLATE.
            // No whitespace, key, CHECK, default, type, or foreign-key differences are ignored.
            $pattern='/^(\s+`'.preg_quote($name,'/').'` (?:char\([0-9]+\)|varchar\([0-9]+\)|(?:tiny|medium|long)?text)) COLLATE '.preg_quote($collation,'/').'(?= )/m';
            $ddl=preg_replace_callback($pattern,static fn($m)=>$m[1].' CHARACTER SET '.$charset.' COLLATE '.$collation,$ddl)??throw new \RuntimeException('Schema comparison failed');
        }
        return $ddl;
    }
}
