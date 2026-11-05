<?php declare(strict_types=1);
namespace Kulturhus\Verein;
use PDO;use RuntimeException;
/** Explicit maintenance only. No business request creates tables or repairs guards. */
final class ReimbursementActionSchema
{
    public static function install(PDO $db):void
    {
        if(!Database::isMysql($db)||$db->inTransaction())throw new RuntimeException('Reimbursement originals need an independent MySQL maintenance connection.');
        if(!Database::tableExists($db,'finance_reimbursement_actions')&&Database::tableExists($db,'schema_migrations')&&(int)$db->query('SELECT COALESCE(MAX(version),0) FROM schema_migrations')->fetchColumn()>=130)throw new RuntimeException('Die bestehende Erstattungs-Originaltabelle fehlt. Originale aus der gesicherten Datenbank prüfen; keine leere Historie anlegen.');
        $db->exec((string)file_get_contents(dirname(__DIR__,3).'/migrations/mysql/130_reimbursement_actions.sql'));
        self::guards($db);
        self::checks($db);
    }
    public static function checks(PDO $db):void
    {
        if(!Database::isMysql($db)||$db->inTransaction()||!Database::tableExists($db,'finance_reimbursement_actions'))throw new RuntimeException('Existing reimbursement originals and an independent maintenance connection required.');
        $rules=(require dirname(__DIR__,3).'/migrations/mysql/130_checks.php')['finance_reimbursement_actions'];
        if((int)$db->query('SELECT COALESCE(MAX(version),0) FROM schema_migrations')->fetchColumn()>=131)$rules=array_replace($rules,(require dirname(__DIR__,3).'/migrations/mysql/131_checks.php')['finance_reimbursement_actions']);
        $changes=[];
        foreach($rules as$name=>$expression){
            if((int)$db->query('SELECT COUNT(*) FROM finance_reimbursement_actions WHERE ('.$expression.') IS FALSE')->fetchColumn())throw new RuntimeException('Bestehende Erstattungsoriginale zuerst prüfen; nichts wurde korrigiert.');
            $q=$db->prepare("SELECT ENFORCED FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME='finance_reimbursement_actions' AND CONSTRAINT_NAME=? AND CONSTRAINT_TYPE='CHECK'");$q->execute([$name]);$enforced=$q->fetchColumn();
            if($enforced===false)$changes[]='ADD CONSTRAINT `'.$name.'` CHECK ('.$expression.')';elseif($enforced!=='YES')$changes[]='ALTER CHECK `'.$name.'` ENFORCED';
        }
        if($changes)$db->exec('ALTER TABLE finance_reimbursement_actions '.implode(', ',$changes));
    }
    public static function guards(PDO $db):void
    {
        if(!Database::isMysql($db)||$db->inTransaction()||!Database::tableExists($db,'finance_reimbursement_actions'))throw new RuntimeException('Existing reimbursement originals and an independent maintenance connection required.');
        $rules=[
            ['reimbursement_action_source_insert','INSERT',"BEGIN IF NOT EXISTS(SELECT 1 FROM finance_reimbursement_claims c WHERE CAST(c.club_id AS BINARY)=CAST(NEW.club_id AS BINARY) AND CAST(c.id AS BINARY)=CAST(NEW.claim_id AS BINARY) AND CAST(c.entry_id AS BINARY)=CAST(NEW.entry_id AS BINARY)) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Reimbursement action source does not match its original claim'; END IF; END"],
            ['reimbursement_action_immutable_update','UPDATE',"BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Reimbursement action originals are immutable'; END"],
            ['reimbursement_action_immutable_delete','DELETE',"BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Reimbursement action originals are immutable'; END"],
        ];
        foreach($rules as[$name,$operation,$body]){
            $q=$db->prepare('SELECT ACTION_TIMING,EVENT_MANIPULATION,EVENT_OBJECT_TABLE,ACTION_STATEMENT FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() AND TRIGGER_NAME=?');$q->execute([$name]);$existing=$q->fetch(PDO::FETCH_ASSOC);$expected=['ACTION_TIMING'=>'BEFORE','EVENT_MANIPULATION'=>$operation,'EVENT_OBJECT_TABLE'=>'finance_reimbursement_actions','ACTION_STATEMENT'=>$body];
            if($existing&&$existing!==$expected)throw new RuntimeException('Bestehenden Originalschutz der Erstattungsaufträge zuerst prüfen.');
            if(!$existing)$db->exec('CREATE TRIGGER `'.$name.'` BEFORE '.$operation.' ON finance_reimbursement_actions FOR EACH ROW '.$body);
        }
    }
}
