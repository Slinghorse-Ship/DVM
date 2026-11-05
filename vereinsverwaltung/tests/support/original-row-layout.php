<?php declare(strict_types=1);
use Kulturhus\Native\Shared\Db;

/** Frozen original columns for an explicitly and separately asserted schema transition. */
function ownOriginalRowLayout(PDO $db,array $tables):array
{
    $out=[];
    foreach($tables as$table){
        if(!is_string($table)||!preg_match('/^[a-z][a-z0-9_]*$/D',$table))throw new RuntimeException('Exact original table required.');
        $columns=Db::run($db,'SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? ORDER BY ORDINAL_POSITION',[$table])->fetchAll(PDO::FETCH_COLUMN);
        $primary=Db::run($db,"SELECT COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND CONSTRAINT_NAME='PRIMARY' ORDER BY ORDINAL_POSITION",[$table])->fetchAll(PDO::FETCH_COLUMN);
        if(!$columns||!$primary||array_diff($primary,$columns))throw new RuntimeException('Original complete columns and stable key required.');$out[$table]=['columns'=>$columns,'primary'=>$primary];
    }return $out;
}

/** Every earlier full row, including invisible/generated columns; no implicit column omission. */
function ownOriginalRowsHash(PDO $db,array $layout,?callable $filter=null):string
{
    $quote=static fn(string $name):string=>'`'.str_replace('`','``',$name).'`';$hash=hash_init('sha256');hash_update($hash,serialize($layout));
    $buffered=$db->getAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY);$db->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY,false);
    try{foreach($layout as$table=>$original){
        if(!is_string($table)||!preg_match('/^[a-z][a-z0-9_]*$/D',$table)||!$original['columns']||!$original['primary'])throw new RuntimeException('Explicit original row layout required.');
        [$where,$arguments]=$filter?$filter($table):['',[]];$rows=Db::run($db,'SELECT '.implode(',',array_map($quote,$original['columns'])).' FROM '.$quote($table).$where.' ORDER BY '.implode(',',array_map($quote,$original['primary'])),$arguments);hash_update($hash,$table."\0");
        try{while($row=$rows->fetch(PDO::FETCH_ASSOC))hash_update($hash,serialize($row));}finally{$rows->closeCursor();unset($rows);}
    }return hash_final($hash);}finally{$db->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY,$buffered);}
}
