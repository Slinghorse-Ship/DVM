<?php declare(strict_types=1);
namespace Kulturhus\Verein;
use PDO;use DomainException;
/** Called only by authorized services inside their existing MySQL transaction. */
final class Numbering
{
    public static function format(array $rule,int $n,string $date):string
    {
        return $rule['prefix'].match($rule['date']){'year'=>substr($date,0,4).'-','month'=>str_replace('-','',substr($date,0,7)).'-',default=>''}.str_pad((string)$n,$rule['width'],'0',STR_PAD_LEFT).$rule['suffix'];
    }
    public static function allocate(PDO $db,string $club,string $kind,string $date,string $object,int $fallback=0):string
    {
        if(!$db->inTransaction()||!in_array($kind,['invoice','expense','member'],true))throw new DomainException('Nummernvergabe benötigt einen eigenen laufenden Fachvorgang.');
        try{$clubLock=$db->prepare('SELECT id FROM clubs WHERE id=? FOR SHARE NOWAIT');$clubLock->execute([$club]);if(!$clubLock->fetchColumn())throw new DomainException('Eigenen aktiven Verein auswählen.',403);}
        catch(\PDOException $failure){if(in_array((int)($failure->errorInfo[1]??0),[1205,3572],true))throw new DomainException('Nummernkreis wird gerade bearbeitet. Bitte erneut prüfen.',503,$failure);throw$failure;}
        $q=$db->prepare("SELECT payload FROM club_settings WHERE club_id=? AND section='numbers' FOR SHARE NOWAIT");$q->execute([$club]);$payload=$q->fetchColumn();$rules=$payload?json_decode($payload,true,512,JSON_THROW_ON_ERROR):SettingsSchema::defaults('numbers');
        $namespace=$kind==='member'?'member':'receipt';
        if(!$payload&&$fallback>0)$value=sprintf('KH-%05d',$fallback);
        else{
            $db->prepare('INSERT INTO number_counters(club_id,kind,next_number)VALUES(?,?,?)ON DUPLICATE KEY UPDATE next_number=next_number')->execute([$club,$kind,(int)$rules[$kind]['next']]);
            $q=$db->prepare('SELECT next_number FROM number_counters WHERE club_id=? AND kind=? FOR UPDATE NOWAIT');$q->execute([$club,$kind]);$n=max((int)($q->fetchColumn()?:1),(int)$rules[$kind]['next']);if($n>999999999)throw new DomainException('Nummernkreis ausgeschöpft.');
            $value=self::format($rules[$kind],$n,$date);
            $db->prepare('INSERT INTO number_counters VALUES(?,?,?) ON DUPLICATE KEY UPDATE next_number=VALUES(next_number)')->execute([$club,$kind,$n+1]);
        }
        $q=$db->prepare('SELECT 1 FROM assigned_numbers WHERE club_id=? AND namespace=? AND value=? FOR SHARE NOWAIT');$q->execute([$club,$namespace,$value]);$exists=$q->fetchColumn();
        if($namespace==='member'){$q=$db->prepare('SELECT 1 FROM memberships WHERE club_id=? AND member_number=? FOR SHARE NOWAIT');$q->execute([$club,$value]);$exists=$exists||$q->fetchColumn();}
        if($exists)throw new DomainException('Nummer '.$value.' ist bereits vergeben. Bitte den nächsten Wert im Nummernkreis erhöhen.');
        $db->prepare('INSERT INTO assigned_numbers VALUES(?,?,?,?)')->execute([$club,$namespace,$value,$object]);return $value;
    }
}
