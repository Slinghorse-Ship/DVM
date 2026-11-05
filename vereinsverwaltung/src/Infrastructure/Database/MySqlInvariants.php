<?php declare(strict_types=1);
namespace Kulturhus\Verein;
use PDO;

/** Explicit native MySQL guards. No translation of historical SQLite DDL. */
final class MySqlInvariants
{
    public static function install(PDO $db):void
    {
        if(Database::tableExists($db,'finance_reimbursement_actions'))ReimbursementActionSchema::guards($db);
        elseif(Database::tableExists($db,'schema_migrations')&&(int)$db->query('SELECT COALESCE(MAX(version),0) FROM schema_migrations')->fetchColumn()>=130)throw new \RuntimeException('Die bestehende Erstattungs-Originaltabelle fehlt. Originale aus der gesicherten Datenbank prüfen; keine leere Historie anlegen.');
        // Charset widening preserves stored text and permits four-byte characters in chat/mail.
        // DDL is operator-only under the migration lock; never executed by a page request.
        $tables=$db->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_TYPE='BASE TABLE' AND TABLE_COLLATION NOT LIKE 'utf8mb4%'")->fetchAll(PDO::FETCH_COLUMN);
        try{
            $db->exec('SET FOREIGN_KEY_CHECKS=0');
            foreach($tables as $table){if(!preg_match('/^[a-z0-9_]+$/D',$table))throw new \RuntimeException('Invalid table');$db->exec('ALTER TABLE `'.$table.'` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');}
        }finally{$db->exec('SET FOREIGN_KEY_CHECKS=1');}
        foreach($db->query("SELECT TABLE_NAME,COLUMN_NAME,IS_NULLABLE,COLUMN_DEFAULT FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND DATA_TYPE='int' AND (COLUMN_NAME='cents' OR RIGHT(COLUMN_NAME,6)='_cents')")->fetchAll() as $field){
            $definition='BIGINT'.($field['IS_NULLABLE']==='YES'?' NULL':' NOT NULL');if($field['COLUMN_DEFAULT']!==null)$definition.=' DEFAULT '.$db->quote($field['COLUMN_DEFAULT']);
            $db->exec('ALTER TABLE `'.$field['TABLE_NAME'].'` MODIFY `'.$field['COLUMN_NAME'].'` '.$definition);
        }
        $triggers=[
            'kh_document_sticky'=>"BEFORE UPDATE ON documents FOR EACH ROW BEGIN IF OLD.admission_only=1 AND NEW.admission_only<>1 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Application access cannot be removed'; END IF; IF EXISTS(SELECT 1 FROM document_folders f WHERE f.club_id=NEW.club_id AND f.id=NEW.folder_id AND f.admission_only=1) THEN SET NEW.admission_only=1; END IF; END",
            'kh_document_inherit'=>"BEFORE INSERT ON documents FOR EACH ROW BEGIN IF EXISTS(SELECT 1 FROM document_folders f WHERE f.club_id=NEW.club_id AND f.id=NEW.folder_id AND f.admission_only=1) THEN SET NEW.admission_only=1; END IF; END",
            'kh_application_link'=>"AFTER INSERT ON document_links FOR EACH ROW BEGIN IF NEW.source_type='application' THEN UPDATE documents SET admission_only=1 WHERE club_id=NEW.club_id AND id=NEW.document_id; END IF; END",
            'kh_application_link_update'=>"AFTER UPDATE ON document_links FOR EACH ROW BEGIN IF NEW.source_type='application' THEN UPDATE documents SET admission_only=1 WHERE club_id=NEW.club_id AND id=NEW.document_id; END IF; END",
            'kh_folder_sticky'=>"BEFORE UPDATE ON document_folders FOR EACH ROW BEGIN IF OLD.admission_only=1 AND NEW.admission_only<>1 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Application folder access cannot be removed'; END IF; END",
        ];
        $triggers=array_merge(require dirname(__DIR__, 2).'/../migrations/mysql/085_native_guards.php',$triggers);
        foreach(['inventory_purchase_units','free_table_rows'] as $table){
            if(!in_array('sequence',Database::columns($db,$table),true))$db->exec('ALTER TABLE `'.$table.'` ADD COLUMN sequence BIGINT UNSIGNED NOT NULL AUTO_INCREMENT UNIQUE');
        }
        foreach($triggers as $name=>$body){
            $q=$db->prepare('SELECT ACTION_STATEMENT FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() AND TRIGGER_NAME=?');$q->execute([$name]);
            if(!$q->fetchColumn())$db->exec('CREATE TRIGGER `'.$name.'` '.$body);
        }
        require_once dirname(__DIR__, 2).'/Documents/DocumentProtection.php';
        $db->beginTransaction();
        try{
            foreach($db->query("SELECT DISTINCT f.club_id,f.id FROM document_folders f LEFT JOIN document_sources s ON s.club_id=f.club_id AND s.folder_id=f.id AND s.source_type='application' WHERE f.admission_only=1 OR s.source_id IS NOT NULL OR (f.scope='members' AND f.name='Anträge')")->fetchAll() as $f)DocumentProtection::protectFolder($db,$f['club_id'],$f['id']);
            $db->exec("UPDATE documents d SET admission_only=1 WHERE admission_only=0 AND EXISTS(SELECT 1 FROM document_links l WHERE l.club_id=d.club_id AND l.document_id=d.id AND l.source_type='application')");
            $db->commit();
        }catch(\Throwable $e){$db->rollBack();throw $e;}
    }
}
