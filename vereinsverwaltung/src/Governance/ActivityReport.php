<?php declare(strict_types=1);
namespace Kulturhus\Verein;
use PDO;use DomainException;
final class ActivityReport
{
    public function __construct(private PDO $db,private string $actor,private ?WebsiteCalendar $website=null){}
    public function build(int $from,int $to,string $kind='tax',bool $includeMinutes=true):array
    {
        $a=Permissions::actor($this->db,$this->actor);if(!(new ActivityReportReview($this->db,$this->actor,$this->website,$kind))->canRead())throw new DomainException('Keine Berechtigung für diesen Tätigkeitsbericht.');
        if($from<2000||$to>$from+2||$to<$from||$to>(int)date('Y'))throw new DomainException('Berichtszeitraum prüfen.');
        $tz=new \DateTimeZone('Europe/Berlin');$start=(new \DateTimeImmutable($from.'-01-01',$tz))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');$end=(new \DateTimeImmutable(($to+1).'-01-01',$tz))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
        $rows=DocumentCatalog::query($this->db,"SELECT id,title,starts_at,ends_at,location,activity_kind,report_summary,version,source_reference FROM appointments WHERE club_id=? AND starts_at>=? AND starts_at<? AND activity_state='completed' ORDER BY starts_at,id LIMIT 5001",[$a['club_id'],$start,$end])->fetchAll();if(count($rows)>5000)throw new DomainException('Zu viele Tätigkeiten im Zeitraum.');
        $types=['event'=>'Veranstaltung','external'=>'Externe Veranstaltung','assembly'=>'Mitgliederversammlung','board'=>'Vorstandssitzung','other'=>'Tätigkeit'];$period=$from===$to?(string)$from:$from.'–'.$to;$text="Tätigkeitsbericht {$period}\n";$seen=[];$activities=[];
        $fingerprints=static function(array $event,\DateTimeImmutable $date):array{
            $title=mb_strtolower(trim(preg_replace('/[^\pL\pN]+/u',' ',(string)($event['title']??''))),'UTF-8');$keys=['date-title|'.$date->format('Y-m-d').'|'.$title];
            foreach(['source_reference','source_url'] as $field){$source=mb_strtolower(trim((string)($event[$field]??'')),'UTF-8');if($source!=='')$keys[]='source|'.$source;}
            return array_values(array_unique($keys));
        };
        $alreadySeen=static function(array $keys,array &$seen):bool{foreach($keys as $key)if(isset($seen[$key]))return true;foreach($keys as $key)$seen[$key]=true;return false;};
        foreach($rows as $r){$date=(new \DateTimeImmutable($r['starts_at']))->setTimezone($tz);if($alreadySeen($fingerprints($r,$date),$seen))continue;if(!$activities)$text.="\nDurchgeführte Tätigkeiten\n";$activities[]=$r;$text.="\n".$date->format('d.m.Y').' – '.$types[$r['activity_kind']].': '.$r['title'].($r['location']!==''?' · '.$r['location']:'')."\n".($r['report_summary']!==''?$r['report_summary']."\n":'');}
        $website=$this->website;
        if(!$website&&ProductionRuntime::nativeManager())$website=WebsiteCalendar::fromNative($this->db,$a['club_id'],\Kulturhus\Native\Integration\ManagerBridge::clubKey($a['club_id']),\Kulturhus\Native\Shared\Identity::forClub($this->db,\Kulturhus\Native\Integration\ManagerBridge::clubKey($a['club_id']))['origin']);
        elseif(!$website&&getenv('VEREIN_MODE')==='production')$website=new WebsiteCalendar($a['club_id']);
        $listed=[];
        if($website){foreach($website->appointments($a['club_id'],$start,$end) as $event){if(!empty($event['cancelled'])||$event['starts_at']< $start||$event['starts_at']>=$end||$event['starts_at']>=gmdate('Y-m-d\TH:i:s\Z'))continue;$date=(new \DateTimeImmutable($event['starts_at']))->setTimezone($tz);if($alreadySeen($fingerprints($event,$date),$seen))continue;$listed[]=['id'=>$event['id'],'title'=>$event['title'],'date'=>$date->format('Y-m-d'),'url'=>$event['source_url']];}
            if($website->notice()!=='')$text.="\nACHTUNG: Website-Veranstaltungen nicht abrufbar; Bericht unvollständig.\n";
        }
        if($listed){$text.="\nDurchgeführte Veranstaltungen\n";foreach($listed as $r)$text.="\n".$r['date'].' – '.$r['title']."\nQuelle: ".$r['url']."\n";}
        $minutes=new Minutes($this->db,$this->actor);$protocols=[];
        if($includeMinutes&&$minutes->canRead()){for($year=$from;$year<=$to;$year++)foreach($minutes->listing(['year'=>(string)$year,'state'=>'published']) as $m){if($m['meeting_on']>date('Y-m-d'))continue;$protocols[]=['id'=>$m['id'],'title'=>$m['title'],'date'=>$m['meeting_on']];}if($protocols){$text.="\nNachweise: Sitzungsprotokolle\n";foreach($protocols as $m)$text.="\n".$m['date'].' – '.$m['title']."\n";}}
        return ['text'=>$text,'activities'=>$activities,'website_completed'=>$listed,'minutes'=>$protocols];
    }
}
