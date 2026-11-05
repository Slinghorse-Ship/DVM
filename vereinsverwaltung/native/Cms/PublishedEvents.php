<?php declare(strict_types=1);
namespace Kulturhus\Native\Cms;
use PDO;
/** Calendar, digests and public website consume the same published CMS revision. */
final class PublishedEvents
{
    public function __construct(private PDO $db,private string $club){}
    public function between(string $from,string $until,bool $includeCancelled=false):array
    {
        $result=[];
        foreach((new Events($this->db,$this->club))->between($from,$until,$includeCancelled) as $p){
            $date=EventDetails::start($p);$finish=EventDetails::end($p) ?? $date;
            $result[]=['id'=>$p['id'],'revision'=>$p['published_version'],'slug'=>$p['slug'],'title'=>$p['title'],'starts_at'=>$date->format('Y-m-d\TH:i:s\Z'),'ends_at'=>$finish->format('Y-m-d\TH:i:s\Z'),'end_known'=>$finish>$date,'timezone'=>$p['event_timezone']??'Europe/Berlin','location'=>trim($p['event_location'].' '.($p['event_address']??'')),'description'=>$p['body'],'media_ids'=>$p['media_ids']??[],'source'=>$p['event_source'],'external_id'=>$p['event_external_id'],'status'=>$p['event_status'],'cancelled'=>EventDetails::cancelled($p)];
        }
        return $result;
    }
}
