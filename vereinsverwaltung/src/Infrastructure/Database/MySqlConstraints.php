<?php declare(strict_types=1);
namespace Kulturhus\Verein;
use PDO;
use RuntimeException;

/** Operator-only restoration of original data invariants. Never repairs or deletes rows. */
final class MySqlConstraints
{
    private static function checks(PDO $db):array
    {
        $checks=array_replace_recursive(require dirname(__DIR__, 2).'/../migrations/mysql/086_checks.php',require dirname(__DIR__, 2).'/../migrations/mysql/129_checks.php');
        if(Database::tableExists($db,'finance_reimbursement_actions')){
            $checks=array_replace_recursive($checks,require dirname(__DIR__, 2).'/../migrations/mysql/130_checks.php');
            if((int)$db->query('SELECT COALESCE(MAX(version),0) FROM schema_migrations')->fetchColumn()>=131)$checks=array_replace_recursive($checks,require dirname(__DIR__, 2).'/../migrations/mysql/131_checks.php');
        }
        elseif(Database::tableExists($db,'schema_migrations')&&(int)$db->query('SELECT COALESCE(MAX(version),0) FROM schema_migrations')->fetchColumn()>=130)throw new RuntimeException('Die bestehende Erstattungs-Originaltabelle fehlt. Originale aus der gesicherten Datenbank prüfen; keine leere Historie anlegen.');
        if(Database::tableExists($db,'tax_auto_requests'))$checks=array_replace_recursive($checks,require dirname(__DIR__, 2).'/../migrations/mysql/132_checks.php');
        elseif(Database::tableExists($db,'schema_migrations')&&(int)$db->query('SELECT COALESCE(MAX(version),0) FROM schema_migrations')->fetchColumn()>=132)throw new RuntimeException('Die bestehende Steuerbestätigungstabelle fehlt. Ursprünglichen Stand prüfen; keine leere Historie anlegen.');
        if(Database::tableExists($db,'tax_filing_commands'))$checks=array_replace_recursive($checks,require dirname(__DIR__, 2).'/../migrations/mysql/133_checks.php');
        elseif(Database::tableExists($db,'schema_migrations')&&(int)$db->query('SELECT COALESCE(MAX(version),0) FROM schema_migrations')->fetchColumn()>=133)throw new RuntimeException('Die bestehende Erklärungsbestätigungstabelle fehlt. Ursprünglichen Stand prüfen; keine leere Historie anlegen.');
        if(Database::tableExists($db,'balance_filing_commands'))$checks=array_replace_recursive($checks,require dirname(__DIR__, 2).'/../migrations/mysql/134_checks.php');
        elseif(Database::tableExists($db,'schema_migrations')&&(int)$db->query('SELECT COALESCE(MAX(version),0) FROM schema_migrations')->fetchColumn()>=134)throw new RuntimeException('Die bestehende E-Bilanz-Bestätigungstabelle fehlt. Ursprünglichen Stand prüfen; keine leere Historie anlegen.');
        return$checks;
    }
    public static function preflight(PDO $db):array
    {
        $issues=[];
        foreach(self::checks($db) as $table=>$rules)foreach($rules as $name=>$expression){
            $count=(int)$db->query('SELECT COUNT(*) FROM `'.$table.'` WHERE ('.$expression.') IS FALSE')->fetchColumn();
            if($count)$issues[]=['table'=>$table,'rule'=>$name,'count'=>$count];
        }
        foreach(require dirname(__DIR__, 2).'/../migrations/mysql/086_unique.php' as $name=>$rule)foreach($rule['preflight'] as $kind=>$sql){
            $count=(int)$db->query($sql)->fetchColumn();if($count)$issues[]=['table'=>$rule['table'],'rule'=>$name.':'.$kind,'count'=>$count];
        }
        return $issues;
    }
    public static function install(PDO $db):void
    {
        if(!Database::isMysql($db)||$db->inTransaction())throw new RuntimeException('Constraint migration needs an independent MySQL connection.');
        $issues=self::preflight($db);if($issues)throw new RuntimeException('Bestehende Daten zuerst prüfen; nichts wurde korrigiert: '.json_encode($issues,JSON_THROW_ON_ERROR));
        $existing=[];foreach($db->query("SELECT TABLE_NAME,CONSTRAINT_NAME,ENFORCED FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND CONSTRAINT_TYPE='CHECK'") as $row)$existing[$row['TABLE_NAME']][$row['CONSTRAINT_NAME']]=$row['ENFORCED'];
        foreach(self::checks($db) as $table=>$rules){
            $add=[];foreach($rules as $name=>$expression){
                if(isset($existing[$table][$name])){
                    if($existing[$table][$name]!=='YES')$add[]='ALTER CHECK `'.$name.'` ENFORCED';
                    continue;
                }
                $add[]='ADD CONSTRAINT `'.$name.'` CHECK ('.$expression.')';
            }
            if($add)$db->exec('ALTER TABLE `'.$table.'` '.implode(', ',$add));
        }
        foreach(require dirname(__DIR__, 2).'/../migrations/mysql/086_unique.php' as $rule){
            $q=$db->prepare('SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND INDEX_NAME=?');$q->execute([$rule['table'],$rule['index_name']]);
            if(!(int)$q->fetchColumn())$db->exec($rule['add_sql']);
        }
    }
}
