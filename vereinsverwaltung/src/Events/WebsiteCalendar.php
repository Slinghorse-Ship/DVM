<?php declare(strict_types=1);
namespace Kulturhus\Verein;

use Closure;
use DomainException;

/** Read-through calendar: one source event stays one entry, with no copied appointments or mail. */
final class WebsiteCalendar
{
    private ?array $events = null;
    private string $notice = '';
    private ?Closure $nativeFetch=null;
    private ?Closure $nativeIndex=null;

    public function __construct(private string $clubId, private ?Closure $fetch = null) {}

    public static function fromNative(\PDO $db,string $managerClub,string $nativeClub,string $origin):self
    {
        $calendar=new self($managerClub);
        $calendar->nativeFetch=static function()use($db,$managerClub,$nativeClub,$origin):array{
            $result=[];
            foreach((new \Kulturhus\Native\Cms\PublishedEvents($db,$nativeClub))->between('1900-01-01','2200-01-01',true) as $event){
                $id='website:'.$event['id'];$result[$id]=['id'=>$id,'club_id'=>$managerClub,'source'=>'website','source_id'=>$event['id'],'title'=>($event['cancelled']?'Abgesagt: ':'').$event['title'],'starts_at'=>$event['starts_at'],'ends_at'=>$event['ends_at'],'end_known'=>$event['end_known'],'cancelled'=>$event['cancelled'],'location'=>$event['location'],'description'=>$event['description'],'category'=>'kultur','source_url'=>rtrim($origin,'/').($event['slug']==='start'?'/':'/'.trim($event['slug'],'/').'/'),'version'=>$event['revision'],'attendees'=>[]];
            }return $result;
        };
        $calendar->nativeIndex=static function(bool $current=false,bool $includeLocation=false)use($db,$managerClub,$nativeClub):array{
            $rows=(new \Kulturhus\Native\Cms\Events($db,$nativeClub))->choiceIndex('1900-01-01','2200-01-01',$current,$includeLocation);$result=[];
            foreach($rows as$row){$item=['id'=>'website:'.$row['id'],'club_id'=>$managerClub,'title'=>(!$includeLocation&&$row['status']==='cancelled'?'Abgesagt: ':'').$row['title'],'starts_at'=>$row['starts_at'],'ends_at'=>$row['ends_at'],'source'=>'website','cancelled'=>$row['status']==='cancelled'];if($includeLocation)$item['location']=$row['location'];$result[]=$item;}
            return $result;
        };return $calendar;
    }
    public function notice(): string { return $this->notice; }

    /** Reference choices never need the published page body or media payload. */
    public function choiceIndex(string $clubId,bool $current=false,bool $includeLocation=false):array
    {
        if($clubId!==$this->clubId)return [];
        if($this->nativeIndex!==null)return ($this->nativeIndex)($current,$includeLocation);
        return array_map(static fn(array $event):array=>array_intersect_key($event,array_flip($includeLocation?['id','club_id','title','starts_at','ends_at','source','cancelled','location']:['id','club_id','title','starts_at','ends_at','source','cancelled'])),array_values($this->all()));
    }

    /** One canonical public source, without its page body or media. */
    public function appointmentMetadata(string $clubId,string $id,bool $includeLocation=false):array
    {
        foreach($this->choiceIndex($clubId,true,$includeLocation)as$row)if($row['id']===$id)return $row;
        throw new DomainException($this->notice?:'Diese Veranstaltung ist nicht mehr auf der Website veröffentlicht.');
    }

    public function appointments(string $clubId, string $fromUtc, string $toUtc): array
    {
        if ($clubId !== $this->clubId) return [];
        return array_values(array_filter($this->all(), static fn(array $event): bool =>
            $event['starts_at'] < $toUtc && ($event['ends_at'] > $fromUtc || $event['starts_at'] === $fromUtc)));
    }

    public function appointment(string $clubId, string $id): array
    {
        if ($clubId !== $this->clubId) throw new DomainException('Termin nicht verfügbar.');
        $event = $this->all()[$id] ?? null;
        if (!$event) throw new DomainException($this->notice ?: 'Diese Veranstaltung ist nicht mehr auf der Website veröffentlicht.');
        return $event;
    }

    private function all(): array
    {
        if($this->nativeFetch)return ($this->nativeFetch)();
        if ($this->events !== null) return $this->events;
        try {
            if(!$this->fetch)throw new DomainException('Native Website-Kalenderquelle ist nicht eingerichtet.');
            $data = ($this->fetch)();
            if (($data['complete'] ?? false) !== true || !is_array($data['rows'] ?? null) || count($data['rows']) > 5000) {
                throw new DomainException('Unvollständige Veranstaltungsdaten.');
            }
            $events = []; $externalIds = [];
            foreach ($data['rows'] as $row) {
                if (!is_array($row) || !is_int($row['id'] ?? null) || $row['id'] < 1
                    || !is_int($row['start_at'] ?? null) || $row['start_at'] <= 0 || !is_int($row['end_at'] ?? null)) {
                    throw new DomainException('Ungültige Veranstaltungsdaten.');
                }
                foreach (['external_id','title','summary','venue_name','venue_address','path','status'] as $field) {
                    if (!is_string($row[$field] ?? null)) throw new DomainException('Ungültige Veranstaltungsdaten.');
                }
                if (!preg_match('~^/(?!/)[a-zA-Z0-9/_%-]+/$~D', $row['path'])) throw new DomainException('Ungültige Veranstaltungsadresse.');
                $id = 'website:' . $row['id']; $externalId = trim($row['external_id']);
                if (isset($events[$id]) || ($externalId !== '' && isset($externalIds[$externalId]))) continue;
                $cancelled = in_array(strtolower($row['status']), ['canceled','cancelled'], true);
                $title = self::plain($row['title']);
                if ($title === '') throw new DomainException('Veranstaltungstitel fehlt.');
                $endKnown = $row['end_at'] > $row['start_at'];
                $events[$id] = [
                    'id'=>$id, 'club_id'=>$this->clubId, 'source'=>'website', 'source_id'=>$row['id'],
                    'title'=>($cancelled ? 'Abgesagt: ' : '') . $title,
                    'starts_at'=>gmdate('Y-m-d\TH:i:s\Z', $row['start_at']),
                    'ends_at'=>gmdate('Y-m-d\TH:i:s\Z', $endKnown ? $row['end_at'] : $row['start_at']),
                    'end_known'=>$endKnown, 'cancelled'=>$cancelled,
                    'location'=>self::plain(trim($row['venue_name'] . ' ' . $row['venue_address'])),
                    'description'=>self::plain($row['summary']), 'category'=>'kultur',
                    'source_url'=>rtrim(WebsiteWorkspace::publicUrl(), '/') . $row['path'],
                    'version'=>0, 'attendees'=>[],
                ];
                if ($externalId !== '') $externalIds[$externalId] = true;
            }
            return $this->events = $events;
        } catch (DomainException) {
            $this->notice = 'Website-Veranstaltungen konnten gerade nicht geladen werden. Die angezeigten Termine sind möglicherweise unvollständig. Bitte erneut laden.';
            return $this->events = [];
        }
    }

    private static function plain(string $value): string
    {
        return trim(html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }
}
