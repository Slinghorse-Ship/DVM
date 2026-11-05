<?php declare(strict_types=1);
use Kulturhus\Native\Shared\Db;

/** Explicit columns include INVISIBLE/generated originals; stream one row at a time. */
function ownOriginalDatabaseHash(PDO $db,array $tables,?callable $filter=null,bool $selectedMetadataOnly=false):string
{
    $metadata=$db->query('SELECT TABLE_NAME,COLUMN_NAME,ORDINAL_POSITION,COLUMN_TYPE,IS_NULLABLE,COLUMN_DEFAULT,EXTRA,GENERATION_EXPRESSION,CHARACTER_SET_NAME,COLLATION_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() ORDER BY TABLE_NAME,ORDINAL_POSITION');
    $columns=$metadata->fetchAll(PDO::FETCH_ASSOC);$metadata->closeCursor();unset($metadata);
    $metadata=$db->query("SELECT TABLE_NAME,COLUMN_NAME,ORDINAL_POSITION FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE() AND CONSTRAINT_NAME='PRIMARY' ORDER BY TABLE_NAME,ORDINAL_POSITION");
    $primary=$metadata->fetchAll(PDO::FETCH_ASSOC);$metadata->closeCursor();unset($metadata);
    // Opt in only when the exact whole-schema addition/removal is asserted separately.
    // The default continues to protect every database table's schema exactly as before.
    if($selectedMetadataOnly){
        $selected=array_fill_keys($tables,true);
        $columns=array_values(array_filter($columns,static fn(array $row):bool=>isset($selected[$row['TABLE_NAME']])));
        $primary=array_values(array_filter($primary,static fn(array $row):bool=>isset($selected[$row['TABLE_NAME']])));
    }
    $fields=[];$keys=[];foreach($columns as$row)$fields[$row['TABLE_NAME']][]=$row['COLUMN_NAME'];foreach($primary as$row)$keys[$row['TABLE_NAME']][]=$row['COLUMN_NAME'];
    $quote=static fn(string$id):string=>'`'.str_replace('`','``',$id).'`';$hash=hash_init('sha256');hash_update($hash,serialize([$columns,$primary]));
    $buffered=$db->getAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY);$db->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY,false);
    try{
        foreach($tables as$table){
            if(!is_string($table)||!preg_match('/^[a-z][a-z0-9_]*$/D',$table)||empty($fields[$table])||empty($keys[$table]))throw new RuntimeException('Exact original table and primary key required.');
            [$where,$arguments]=$filter?$filter($table):['',[]];
            $rows=Db::run($db,'SELECT '.implode(',',array_map($quote,$fields[$table])).' FROM '.$quote($table).$where.' ORDER BY '.implode(',',array_map($quote,$keys[$table])),$arguments);hash_update($hash,$table."\0");
            try{while($row=$rows->fetch(PDO::FETCH_ASSOC))hash_update($hash,serialize($row));}finally{$rows->closeCursor();unset($rows);}
        }
        return hash_final($hash);
    }finally{$db->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY,$buffered);}
}
