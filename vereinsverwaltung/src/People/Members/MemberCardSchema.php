<?php declare(strict_types=1);
namespace Kulturhus\Verein;
use PDO;
use RuntimeException;

/** Explicit maintenance only. Normal card reads and actions never execute DDL. */
final class MemberCardSchema
{
    public static function install(PDO $db):void
    {
        if($db->inTransaction()||$db->getAttribute(PDO::ATTR_DRIVER_NAME)!=='mysql')throw new RuntimeException('Mitgliederausweise benötigen eine eigene MySQL-Wartungsverbindung.');
        $db->exec((string)file_get_contents(dirname(__DIR__,3).'/migrations/mysql/135_member_cards.sql'));
        foreach(['member_cards'=>'member_card_original','member_card_commands'=>'member_card_command']as$table=>$prefix)foreach(['UPDATE','DELETE']as$operation){
            $name=$prefix.'_immutable_'.strtolower($operation);$body="BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Membership card originals are immutable'; END";
            $q=$db->prepare('SELECT ACTION_TIMING,EVENT_MANIPULATION,EVENT_OBJECT_TABLE,ACTION_STATEMENT FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() AND TRIGGER_NAME=?');$q->execute([$name]);$prior=$q->fetch(PDO::FETCH_ASSOC);
            if($prior&&$prior!==['ACTION_TIMING'=>'BEFORE','EVENT_MANIPULATION'=>$operation,'EVENT_OBJECT_TABLE'=>$table,'ACTION_STATEMENT'=>$body])throw new RuntimeException('Originalschutz der Mitgliederausweise zuerst prüfen.');
            if(!$prior)$db->exec('CREATE TRIGGER `'.$name.'` BEFORE '.$operation.' ON `'.$table.'` FOR EACH ROW '.$body);
        }
    }
}
