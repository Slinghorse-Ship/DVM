<?php declare(strict_types=1);
namespace Kulturhus\Verein\Settings;
use PDO;
use Kulturhus\Native\Shared\Db;
use Kulturhus\Verein\Numbering;
/** Bounded current counter metadata, never complete member or finance originals. */
final class NumberingContext
{
    public static function read(PDO $db,string $club,array $rules,string $date):array
    {
        if(!$db->inTransaction())throw new \DomainException('Nummernprüfung benötigt einen aktuellen Leseschritt.',503);
        $counters=Db::run($db,'SELECT kind,next_number FROM number_counters WHERE club_id=? ORDER BY kind FOR SHARE NOWAIT',[$club])->fetchAll();
        $counts=[];foreach(['receipt','member']as$namespace)$counts[$namespace]=(int)Db::run($db,'SELECT COUNT(*) FROM assigned_numbers WHERE club_id=? AND namespace=? FOR SHARE NOWAIT',[$club,$namespace])->fetchColumn();
        $members=(int)Db::run($db,'SELECT COUNT(*) FROM memberships WHERE club_id=? FOR SHARE NOWAIT',[$club])->fetchColumn();$examples=[];
        foreach($rules as$kind=>$rule){if($rule['next']>999999999){$examples[$kind]=['number'=>null,'reserved'=>false,'exhausted'=>true];continue;}$number=Numbering::format($rule,$rule['next'],$date);$namespace=$kind==='member'?'member':'receipt';$reserved=(bool)Db::run($db,'SELECT 1 FROM assigned_numbers WHERE club_id=? AND namespace=? AND value=? FOR SHARE NOWAIT',[$club,$namespace,$number])->fetchColumn();if($kind==='member')$reserved=$reserved||(bool)Db::run($db,'SELECT 1 FROM memberships WHERE club_id=? AND member_number=? FOR SHARE NOWAIT',[$club,$number])->fetchColumn();$examples[$kind]=['number'=>$number,'reserved'=>$reserved,'exhausted'=>false];}
        return['counters'=>$counters,'assigned_counts'=>$counts,'membership_count'=>$members,'date'=>$date,'examples'=>$examples];
    }
    public static function issues(array $review):array
    {
        $issues=[];
        if($review['input']['version']!==(string)$review['current']['version'])$issues[]=['code'=>409,'message'=>'Die ursprüngliche Nummernfassung ist nicht mehr aktuell.'];
        foreach($review['normalized']as$kind=>$rule){
            if($rule['next']<$review['current']['data'][$kind]['next'])$issues[]=['code'=>409,'message'=>'Der gewünschte nächste Wert liegt unter dem aktuellen Zähler.'];
            if($review['numbering_context']['examples'][$kind]['reserved'])$issues[]=['code'=>422,'message'=>'Eine angezeigte Beispielnummer ist bereits vergeben. Präfix, Suffix oder nächsten Wert prüfen.'];
        }
        $examples=$review['numbering_context']['examples'];
        if($examples['invoice']['number']===$examples['expense']['number'])$issues[]=['code'=>422,'message'=>'Rechnungs- und Ausgabenbeispiel sind gleich. Unterschiedliche Nummernregeln wählen.'];
        return$issues;
    }
}
