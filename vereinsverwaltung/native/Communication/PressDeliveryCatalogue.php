<?php declare(strict_types=1);
namespace Kulturhus\Native\Communication;

use PDO;
use DomainException;
use Kulturhus\Native\Shared\{Access,Db,SecretBox};

/** Only six private recipient records, without loading the shared message or image bytes. */
final class PressDeliveryCatalogue
{
    public const STATES=['all'=>'Alle','pending'=>'Vorgemerkt','processing'=>'Wird versandt','sent'=>'Versandt','cancelled'=>'Gestoppt','uncertain'=>'Ergebnis offen'];

    public static function summary(PDO $db,Access $access,string $item,int $version): array
    {
        $actor=$access->require('press','read');Content::id($item);
        if($version<1)throw new DomainException('Pressefassung auswählen.');
        $result=[];foreach(Db::run($db,"SELECT j.status,COUNT(*) AS total FROM native_delivery_jobs j JOIN native_delivery_sources s ON s.job_id=j.id AND s.club_id=j.club_id WHERE j.club_id=? AND j.kind='press' AND s.module='press' AND s.item_id=? AND s.item_version=? GROUP BY j.status",[$actor['club_id'],$item,$version])->fetchAll()as$row)$result[$row['status']]=(int)$row['total'];
        return $result;
    }

    public static function restriction(PDO $db,Access $access,string $item,int $version): string
    {
        $actor=$access->require('press','read');Content::id($item);
        $states=Db::run($db,"SELECT j.status FROM native_delivery_jobs j JOIN native_delivery_sources s ON s.job_id=j.id AND s.club_id=j.club_id WHERE j.club_id=? AND j.kind='press' AND s.module='press' AND s.item_id=? AND (j.status IN ('processing','uncertain') OR (j.status='sent' AND s.item_version<>?)) GROUP BY j.status",[$actor['club_id'],$item,$version])->fetchAll(PDO::FETCH_COLUMN);
        foreach(['uncertain','processing','sent']as$state)if(in_array($state,$states,true))return $state;
        return '';
    }

    public static function page(PDO $db,Access $access,SecretBox $box,array $query=[]): array
    {
        $actor=$access->require('press','read');$club=$actor['club_id'];
        $state=$query['press_state']??'all';if(!is_string($state)||!isset(self::STATES[$state]))throw new DomainException('Versandfilter prüfen.');
        $page=SubscriberSelection::number($query['press_page']??1);$item=$query['press_item']??'';$focus=$query['press_job']??'';
        foreach([$item,$focus]as$id){if(!is_string($id))throw new DomainException('Presseauftrag auswählen.');if($id!=='')Content::id($id);}
        if($item!=='')(new Workspace($db,$access,'press'))->get($item);
        $join=' FROM native_delivery_jobs j JOIN native_delivery_sources s ON s.job_id=j.id AND s.club_id=j.club_id';
        $where="j.club_id=? AND j.kind='press' AND s.module='press'";$args=[$club];
        if($item!==''){$where.=' AND s.item_id=?';$args[]=$item;}
        $counts=[];foreach(Db::run($db,'SELECT j.status,COUNT(*) AS total'.$join.' WHERE '.$where.' GROUP BY j.status',$args)->fetchAll()as$row)$counts[$row['status']]=(int)$row['total'];
        if($state!=='all'){$where.=' AND j.status=?';$args[]=$state;}
        if($focus!==''){
            $selected=Db::run($db,'SELECT j.id,j.due_at'.$join.' WHERE '.$where.' AND j.id=?',[...$args,$focus])->fetch();
            if(!$selected)throw new DomainException('Presseauftrag in diesem Verlauf nicht verfügbar.');
            $ahead=(int)Db::run($db,'SELECT COUNT(*)'.$join.' WHERE '.$where.' AND (j.due_at>? OR (j.due_at=? AND j.id>?))',[...$args,$selected['due_at'],$selected['due_at'],$focus])->fetchColumn();$page=1+intdiv($ahead,6);
        }
        $total=(int)Db::run($db,'SELECT COUNT(*)'.$join.' WHERE '.$where,$args)->fetchColumn();$pages=max(1,(int)ceil($total/6));$page=min($page,$pages);
        $raw=Db::run($db,'SELECT j.id,j.status,j.attempts,j.due_at,j.started_at,j.completed_at,j.external_id,j.reason,j.ciphertext,s.item_id,s.item_version,r.payload AS original'.$join.' LEFT JOIN khn_press_revisions r ON r.club_id=s.club_id AND r.item_id=s.item_id AND r.version=s.item_version WHERE '.$where.' ORDER BY j.due_at DESC,j.id DESC LIMIT 6 OFFSET '.(($page-1)*6),$args)->fetchAll(PDO::FETCH_ASSOC);
        $rows=[];
        foreach($raw as$row){
            $payload=$box->open('job:'.$club.':'.$row['id'],$row['ciphertext']);
            $original=json_decode($row['original']??'{}',true,64,JSON_THROW_ON_ERROR);
            if(($payload['approval']['item']??null)!==$row['item_id']||($payload['approval']['version']??null)!==(int)$row['item_version'])throw new DomainException('Originalbezug des Presseauftrags prüfen.');
            $row['email']=Content::email($payload['to']??'');$row['name']=Content::text($payload['press_contact']['name']??'',200);$row['outlet']=Content::text($payload['press_contact']['outlet']??'',200);$row['title']=Content::text($original['title']??'',300,true);
            unset($row['ciphertext'],$row['original']);$rows[]=$row;
        }
        return ['rows'=>$rows,'total'=>$total,'pages'=>$pages,'page'=>$page,'state'=>$state,'item'=>$item,'focus'=>$focus,'counts'=>$counts];
    }
}
