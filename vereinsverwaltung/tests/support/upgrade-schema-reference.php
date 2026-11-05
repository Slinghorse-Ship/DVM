<?php declare(strict_types=1);

/** Whole schema shape, independent of row-value fingerprints and version counters. */
function ownUpgradeSchemaShape(PDO $db):array
{
    $shape=[];
    foreach($db->query('SELECT TABLE_NAME,COLUMN_NAME,ORDINAL_POSITION,COLUMN_TYPE,IS_NULLABLE,COLUMN_DEFAULT,EXTRA,GENERATION_EXPRESSION,CHARACTER_SET_NAME,COLLATION_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() ORDER BY TABLE_NAME,ORDINAL_POSITION')->fetchAll(PDO::FETCH_ASSOC)as$row){$table=$row['TABLE_NAME'];$column=$row['COLUMN_NAME'];unset($row['TABLE_NAME'],$row['COLUMN_NAME']);$shape[$table]['columns'][$column]=$row;}
    foreach($db->query("SELECT TABLE_NAME,COLUMN_NAME,ORDINAL_POSITION FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE() AND CONSTRAINT_NAME='PRIMARY' ORDER BY TABLE_NAME,ORDINAL_POSITION")->fetchAll(PDO::FETCH_ASSOC)as$row)$shape[$row['TABLE_NAME']]['primary'][]=$row['COLUMN_NAME'];
    foreach($shape as&$table)$table['primary']??=[];unset($table);return $shape;
}

/** Fresh current schema as an independent target for added tables/columns only. */
function ownFreshUpgradeSchema():array
{
    $cfg=\Kulturhus\Native\TestSupport::config();
    if(getenv('KH_NATIVE_CI')!=='1'||($cfg['owner']??null)!=='isolated-native-ci'||($cfg['label']??null)!=='native-package-upgrade')throw new RuntimeException('Exact private package-upgrade fixture required.');
    $name='kh_native_'.bin2hex(random_bytes(12));$server=new PDO('mysql:host=127.0.0.1;port=13371;charset=utf8mb4',$cfg['username'],$cfg['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);$created=false;$db=null;
    try{
        $server->exec('CREATE DATABASE `'.$name.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_bin');$created=true;
        $db=new PDO('mysql:host=127.0.0.1;port=13371;dbname='.$name.';charset=utf8mb4',$cfg['username'],$cfg['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
        \Kulturhus\Native\Installer\SchemaRegistry::install($db);return ownUpgradeSchemaShape($db);
    }finally{$db=null;if($created)$server->exec('DROP DATABASE `'.$name.'`');}
}

/** Old definitions survive; additions match fresh definitions, one reviewed nullability change. */
function ownUpgradeSchemaDifferences(array $original,array $actual,array $reference):array
{
    $errors=[];
    foreach($reference as$table=>$definition)if(!isset($actual[$table]))$errors[]=$table.': missing current table';
    foreach($original as$table=>$definition){
        if(!isset($actual[$table])){$errors[]=$table.': original table removed';continue;}
        if($actual[$table]['primary']!==$definition['primary'])$errors[]=$table.': original primary key changed';
        foreach($definition['columns']as$column=>$before){
            $after=$actual[$table]['columns'][$column]??null;
            if($after===$before)continue;
            if($table==='settings_role_assignments'&&$column==='role_id'&&$before['IS_NULLABLE']==='NO'&&$after===($reference[$table]['columns'][$column]??null))continue;
            $errors[]=$table.'.'.$column.': original definition changed';
        }
        foreach($actual[$table]['columns']as$column=>$after){
            if(isset($definition['columns'][$column]))continue;
            $expected=$reference[$table]['columns'][$column]??null;
            // ALTER appends new fields; existing original positions cannot move.
            unset($after['ORDINAL_POSITION']);if(is_array($expected))unset($expected['ORDINAL_POSITION']);
            if($after!==$expected)$errors[]=$table.'.'.$column.': addition differs from current schema';
        }
        foreach($reference[$table]['columns']??[]as$column=>$ignored)if(!isset($actual[$table]['columns'][$column]))$errors[]=$table.'.'.$column.': missing current column';
    }
    foreach($actual as$table=>$definition)if(!isset($original[$table])&&$definition!==($reference[$table]??null))$errors[]=$table.': unexpected added table definition';
    return $errors;
}
