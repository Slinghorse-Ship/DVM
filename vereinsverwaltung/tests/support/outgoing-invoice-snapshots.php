<?php declare(strict_types=1);

/** Select exactly the whole rows which existed before the next deliberate test operations. */
function ownOutgoingInvoiceEarlierRows(PDO $db,array $tables):callable
{
    $primary=[];
    foreach($db->query("SELECT TABLE_NAME,COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE() AND CONSTRAINT_NAME='PRIMARY' ORDER BY TABLE_NAME,ORDINAL_POSITION")->fetchAll()as$row)$primary[$row['TABLE_NAME']][]=$row['COLUMN_NAME'];
    $keys=[];
    foreach($tables as$table){
        if(!preg_match('/\A[a-z0-9_]+\z/D',$table)||!($primary[$table]??[]))throw new RuntimeException('Owned invoice snapshot primary keys required.');
        foreach($primary[$table]as$column)if(!preg_match('/\A[a-z0-9_]+\z/D',$column))throw new RuntimeException('Owned invoice snapshot columns required.');
        $keys[$table]=$db->query('SELECT '.implode(',',$primary[$table]).' FROM '.$table)->fetchAll(PDO::FETCH_NUM);
    }
    return static function(string $table)use($keys,$primary):array{
        if(!$keys[$table])return [' WHERE 0=1',[]];$columns=$primary[$table];$args=[];foreach($keys[$table]as$row)array_push($args,...$row);
        return [' WHERE ('.implode(',',$columns).') IN ('.implode(',',array_fill(0,count($keys[$table]),'('.implode(',',array_fill(0,count($columns),'?')).')')).')',$args];
    };
}
