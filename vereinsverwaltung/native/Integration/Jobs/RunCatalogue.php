<?php declare(strict_types=1);
namespace Kulturhus\Native\Integration\Jobs;
use PDO;
use DomainException;
use Kulturhus\Native\Shared\{Access,Db};
use Kulturhus\Native\Integration\ManagerClub;

/** Read-only, counted pages of the current club's existing operational originals. */
final class RunCatalogue
{
    public const SIZE=6;
    public const STATES=['all'=>'Alle Läufe','review'=>'Prüfen','running'=>'Laufend','completed'=>'Abgeschlossen','failed'=>'Fehlgeschlagen'];
    public static function page(PDO $db,Access $access,array $query=[]):array
    {
        $access->require('website','manage');$club=$access->club();$state=$query['worker_state']??'all';$raw=$query['worker_page']??'1';
        if(!is_string($state)||!isset(self::STATES[$state])||(!is_int($raw)&&!is_string($raw))||!preg_match('/\A[1-9][0-9]{0,6}\z/D',(string)$raw)||(int)$raw>1000000)throw new DomainException('Status und Seite der Hintergrundläufe prüfen.');
        $empty=['rows'=>[],'total'=>0,'all_total'=>0,'page'=>1,'pages'=>1,'state'=>$state];if(ManagerClub::id($db,$club)===null)return $empty;
        $where='club_id=?';$args=[$club];
        $condition=match($state){'review'=>"(status IN ('needs_review','failed') OR (status='running' AND started_at<DATE_SUB(UTC_TIMESTAMP(),INTERVAL 15 MINUTE)))",'running'=>"status='running' AND started_at>=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 15 MINUTE)",'completed'=>"status='completed'",'failed'=>"status='failed'",default=>''};
        $all=(int)Db::run($db,'SELECT COUNT(*) FROM native_worker_runs WHERE club_id=?',[$club])->fetchColumn();if($condition!=='')$where.=' AND ('.$condition.')';
        $total=(int)Db::run($db,'SELECT COUNT(*) FROM native_worker_runs WHERE '.$where,$args)->fetchColumn();$pages=max(1,(int)ceil($total/self::SIZE));$page=min((int)$raw,$pages);$offset=($page-1)*self::SIZE;
        $rows=Db::run($db,'SELECT id,status,modes_json,summary_json,started_at,finished_at FROM native_worker_runs WHERE '.$where.' ORDER BY started_at DESC,id DESC LIMIT '.self::SIZE.' OFFSET '.$offset,$args)->fetchAll();
        return ['rows'=>$rows,'total'=>$total,'all_total'=>$all,'page'=>$page,'pages'=>$pages,'state'=>$state];
    }
}
