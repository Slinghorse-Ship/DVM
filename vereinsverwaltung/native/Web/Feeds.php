<?php declare(strict_types=1);
namespace Kulturhus\Native\Web;

use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use InvalidArgumentException;
use Kulturhus\Native\Cms\{EventDetails, Media, Pages};
use RuntimeException;

/** Local projections of immutable public CMS snapshots; no HTTP client or database writes. */
final class Feeds
{
    private string $origin;

    public function __construct(private Pages $pages, string $trustedOrigin, private string $clubName = '', private ?Media $media = null, string $basePath = '')
    {
        $parts = parse_url($trustedOrigin);
        if (!$parts || !in_array($parts['scheme'] ?? '', ['http','https'], true) || empty($parts['host'])
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
            || !in_array($parts['path'] ?? '', ['', '/'], true) || preg_match('/[\x00-\x20\x7f\\\\]/', $trustedOrigin)
            || filter_var($trustedOrigin, FILTER_VALIDATE_URL) === false) {
            throw new InvalidArgumentException('Eine feste gültige Website-Origin ohne Pfad oder Zugangsdaten ist erforderlich.');
        }
        if ($parts['scheme'] === 'http' && !in_array($parts['host'], ['127.0.0.1','localhost','[::1]'], true)) {
            throw new InvalidArgumentException('HTTP ist ausschließlich für lokale Loopback-Adressen vorgesehen.');
        }
        if(!preg_match('~\A(?:/[a-z0-9]+(?:-[a-z0-9]+)*)*\z~D',$basePath))throw new InvalidArgumentException('Festen öffentlichen Basispfad prüfen.');
        $this->origin = rtrim($trustedOrigin, '/').$basePath;
    }

    private function url(array $page): string
    {
        // CMS slugs permit path segments; encode each segment without introducing a query or fragment.
        return $this->origin.'/site/'.implode('/', array_map(rawurlencode(...), explode('/', $page['slug'])));
    }

    private static function xml(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE | ENT_DISALLOWED, 'UTF-8');
    }

    private static function publishedAt(array $page): DateTimeImmutable
    {
        return new DateTimeImmutable($page['published_at'], new DateTimeZone('UTC'));
    }

    private static function excerpt(array $page): string
    {
        $value = ($page['excerpt'] ?? '') !== '' ? $page['excerpt'] : $page['body'];
        // No HTML interpretation and no environment-dependent mbstring requirement.
        preg_match('/\A.{0,600}/us', $value, $match);
        return ($match[0] ?? '').(strlen($value) > strlen($match[0] ?? '') ? ' …' : '');
    }

    public function rss(string $selection = 'all'): string
    {
        return $this->document('rss',$selection)->contents();
    }

    private function rssBody(FeedDocument $out,string $selection): void
    {
        if (!in_array($selection, ['all', 'news', 'mixed'], true)) throw new InvalidArgumentException('Unbekannte RSS-Ausgabe.');
        $published = $this->pages->publicOriginals('rss',$selection==='all'?[]:($selection==='news'?['news']:['news','event']),$selection==='all'?null:50);
        $label = match ($selection) { 'news' => 'Aktuelles', 'mixed' => 'Aktuelles und Veranstaltungen', default => 'Veröffentlichungen' };
        $self = match ($selection) { 'news' => '/feeds/news', 'mixed' => '/feeds/alle', default => '/feeds/all.rss.xml' };
        $xml = '<?xml version="1.0" encoding="UTF-8"?>'."\n";
        $xml .= '<rss version="2.0" xmlns:atom="http://www.w3.org/2005/Atom"><channel><title>'.self::xml((trim($this->clubName) ?: 'Mein Verein').' · '.$label).'</title><link>'.self::xml($this->origin.'/site/start').'</link><description>Aktuelle Beiträge und Veranstaltungen.</description><language>de-DE</language><ttl>5</ttl>';
        $xml .= '<atom:link href="'.self::xml($this->origin.$self).'" rel="self" type="application/rss+xml"/>';
        $out->append($xml);$first=true;
        foreach ($published as $page) {
            $xml='';
            if($first){$xml.='<lastBuildDate>'.self::publishedAt($page)->format(DATE_RSS).'</lastBuildDate>';$first=false;}
            // RSS descriptions are HTML-bearing. Escape plain text for that layer and then for XML.
            $excerpt = self::excerpt($page);
            if ($selection !== 'all' && EventDetails::isEvent($page) && ($start = EventDetails::start($page)) !== null) {
                $zone = new DateTimeZone($page['event_timezone']);
                $start = $start->setTimezone($zone);
                $facts = ['Datum: '.$start->format('d.m.Y'), 'Uhrzeit: '.$start->format('H:i').' Uhr'];
                if ($page['event_timezone'] !== 'Europe/Berlin') $facts[] = 'Zeitzone: '.$page['event_timezone'];
                if (($end=EventDetails::end($page))!==null) $facts[] = 'Ende: '.$end->setTimezone($zone)->format('d.m.Y H:i').' Uhr';
                if (($page['event_status']??'scheduled')!=='scheduled') $facts[] = 'Status: '.EventDetails::label($page);
                if (trim($page['event_location'] ?? '') !== '') $facts[] = 'Veranstaltungsort: '.trim($page['event_location']);
                if (trim($page['event_address'] ?? '') !== '') $facts[] = 'Anschrift: '.preg_replace('/\s+/u', ' ', trim($page['event_address']));
                if (($page['event_price']??'')!=='') $facts[] = 'Eintritt: '.str_replace('.',',',$page['event_price']).' EUR';
                if (!in_array($page['event_status']??'scheduled',['cancelled','sold_out'],true)&&($page['event_ticket_url']??'')!=='') $facts[] = 'Tickets: '.$page['event_ticket_url'];
                $excerpt = implode(' · ', $facts).($excerpt !== '' ? "\n\n".$excerpt : '');
            }
            $description = htmlspecialchars($excerpt, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $xml .= '<item><title>'.self::xml($page['title']).'</title><link>'.self::xml($this->url($page)).'</link>';
            $xml .= '<guid isPermaLink="false">'.self::xml($this->uid($page)).'</guid><pubDate>'.self::publishedAt($page)->format(DATE_RSS).'</pubDate>';
            $xml .= '<description>'.self::xml($description).'</description>';
            // One RSS enclosure represents the exact original cover, never a later image substitute.
            if ($this->media!==null && ($cover=$this->image(($page['media_ids']??[])[0]??null,$page))!==null) {
                $xml .= '<enclosure url="'.self::xml($cover['url']).'" length="'.$cover['bytes'].'" type="'.self::xml($cover['mime']).'"/>';
            }
            $xml .= '</item>';$out->append($xml);$out->addRecord();
        }
        $out->append('</channel></rss>'."\n");
    }

    private function uid(array $page): string
    {
        // Independent of slug, revision, feed address and title; page IDs are random and immutable.
        return $page['id'].'@kulturhus-native.invalid';
    }

    private function events(?string $id=null): \Generator
    {
        if ($id!==null && !preg_match('/^[a-f0-9]{32}$/D',$id)) throw new InvalidArgumentException('Ungültiger Kalendertermin.');
        $catalog=$this->pages->events();
        $rows=$id===null?$catalog->ordered():array_filter([$catalog->publicId($id)]);
        foreach($rows as$page)yield ['page'=>$page,'start'=>EventDetails::start($page),'end'=>EventDetails::end($page)];
    }

    public function eventsJson(): string
    {
        return $this->document('json')->contents();
    }

    private function eventsBody(FeedDocument $out): void
    {
        $out->append('{"version":1,"events":[');$separator='';
        foreach ($this->events() as $event) {
            $page = $event['page'];
            $item = [
                'id'=>$page['id'], 'uid'=>$this->uid($page), 'title'=>$page['title'],
                'url'=>$this->url($page), 'slug'=>$page['slug'],
                'excerpt'=>$page['excerpt'], 'description'=>$page['body'],
                'start_utc'=>$event['start']?->format('Y-m-d\TH:i:s\Z'),
                'start_local'=>$page['event_start'], 'timezone'=>$page['event_timezone'],
                'location'=>$page['event_location'],
                'end_utc'=>$event['end']?->format('Y-m-d\TH:i:s\Z'),
                'address'=>$page['event_address'] ?? '', 'status'=>$page['event_status'],
                'ticket_url'=>in_array($page['event_status'],['cancelled','sold_out'],true)?null:($page['event_ticket_url'] ?? ''),
                'price'=>$page['event_price'] ?? '', 'sequence'=>$page['event_sequence'],
                'published_at'=>self::publishedAt($page)->format('Y-m-d\TH:i:s\Z'),
                'published_version'=>$page['published_version'],
            ];
            if ($this->media!==null) {
                $item['image']=null;
                $item['images']=[];
                foreach($page['media_ids']??[]as$index=>$id) {
                    $image=$this->image($id,$page);
                    if($index===0)$item['image']=$image;
                    if($image!==null)$item['images'][]=$image;
                }
            }
            $out->append($separator.json_encode($item,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT));$separator=',';$out->addRecord();
        }
        $out->append(']}'."\n");
    }

    /** Image metadata stays private unless this exact asset is currently publicly referenced. */
    private function image(?string $id,array $page): ?array
    {
        if ($id===null || $this->media===null) return null;
        try {$image=$this->media->publicVariant($id,'large',$page);}
        catch (RuntimeException) {return null;}
        if ($image===null || ($bytes=@filesize($image['path']))===false) return null;
        return ['url'=>$this->origin.'/media/'.$id.'/large','mime'=>$image['mime'],'width'=>$image['width'],'height'=>$image['height'],'bytes'=>$bytes,'alt'=>$image['alt']];
    }

    /** Finish and validate the entire representation before any HTTP byte is exposed. */
    public function document(string $format,string $selection='all',?string $event=null):FeedDocument
    {
        if(!in_array($format,['rss','json','ics'],true))throw new InvalidArgumentException('Unbekannte Feed-Ausgabe.');
        $club=$this->pages->publicState()['club'];
        for($attempt=0;$attempt<4;$attempt++) {
            $state=$this->pages->publicState();$out=new FeedDocument();
            try{
                if($state['club']!==$club)throw new DomainException('Öffentliche Inhalte nicht verfügbar.');
                if($format==='rss')$this->rssBody($out,$selection);
                elseif($format==='json')$this->eventsBody($out);
                else $this->calendarBody($out,$event);
                $current=$this->pages->publicState();
                if($current['club']!==$club)throw new DomainException('Öffentliche Inhalte nicht verfügbar.');
                if($state===$current){$out->seal();return $out;}
            }catch(\Throwable $error){$out->close();throw $error;}
            $out->close();
        }
        throw new RuntimeException('Die Website wird gerade veröffentlicht. Bitte erneut laden.');
    }

    private static function calendarText(string $value): string
    {
        $value = str_replace(["\r\n", "\r"], "\n", $value);
        return str_replace(['\\', ';', ',', "\n"], ['\\\\', '\\;', '\\,', '\\n'], $value);
    }

    /** Fold each content line at <=75 octets, including the continuation's leading space. */
    private static function fold(string $line): string
    {
        if(!preg_match('//u',$line))throw new InvalidArgumentException('Kalendertext ist kein gültiges UTF-8.');
        $result='';$offset=0;$length=strlen($line);$continuation=false;
        do{
            $part=substr($line,$offset,$continuation?74:75);
            while(!preg_match('//u',$part))$part=substr($part,0,-1);
            $result.=($continuation?' ':'').$part."\r\n";
            $offset+=strlen($part);$continuation=true;
        }while($offset<$length);
        return $result;
    }

    public function ics(?string $id=null): string
    {
        return $this->document('ics','all',$id)->contents();
    }

    private function calendarBody(FeedDocument $out,?string $id):void
    {
        foreach(['BEGIN:VCALENDAR','VERSION:2.0','PRODID:-//DVM//DeinVereinsManager//DE','CALSCALE:GREGORIAN']as$line)$out->append(self::fold($line));
        foreach ($this->events($id) as $event) {
            if ($event['start'] === null) continue;
            $page = $event['page'];
            $lines = ['BEGIN:VEVENT'];
            $lines[] = 'UID:'.$this->uid($page);
            $lines[] = 'DTSTAMP:'.self::publishedAt($page)->format('Ymd\THis\Z');
            $lines[] = 'LAST-MODIFIED:'.(new DateTimeImmutable($page['event_changed_at'],new DateTimeZone('UTC')))->format('Ymd\THis\Z');
            $lines[] = 'SEQUENCE:'.(int)$page['event_sequence'];
            $lines[] = 'DTSTART:'.$event['start']->format('Ymd\THis\Z');
            if($event['end']!==null && $event['end']>$event['start']) $lines[]='DTEND:'.$event['end']->format('Ymd\THis\Z');
            $lines[]='STATUS:'.match($page['event_status']){'cancelled'=>'CANCELLED','postponed'=>'TENTATIVE',default=>'CONFIRMED'};
            $lines[] = 'SUMMARY:'.self::calendarText($page['title']);
            $lines[] = 'DESCRIPTION:'.self::calendarText($page['body']);
            $lines[] = 'LOCATION:'.self::calendarText($page['event_location']);
            $lines[] = 'URL:'.$this->url($page);
            $lines[] = 'END:VEVENT';
            foreach($lines as$line)$out->append(self::fold($line));
            $out->addRecord();
        }
        $out->append(self::fold('END:VCALENDAR'));
    }

    /** Compatibility projection: the first 50 public matches, without loading all originals. */
    public function search(string $query): array
    {
        return $this->searchUrls($this->pages->publicSearch($query,1,50))['rows'];
    }

    public function searchPage(string $query,mixed $page=1,?string $locale=null):array
    {
        return $this->searchUrls($this->pages->publicSearch($query,$page,\Kulturhus\Native\Cms\PublicSearch::PAGE_SIZE,$locale));
    }

    private function searchUrls(array $list):array
    {
        foreach($list['rows']as &$row)$row['url']=$this->url($row);unset($row);
        return $list;
    }
}
