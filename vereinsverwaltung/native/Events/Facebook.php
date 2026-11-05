<?php declare(strict_types=1);
namespace Kulturhus\Native\Events;

use PDO;
use Kulturhus\Native\Shared\{Access,Db,SecretBox};
use Kulturhus\Native\Integration\{Accounts,JsonHttp};
use Kulturhus\Native\Communication\Content;
use Kulturhus\Native\Cms\{ClubPublicationLock,Pages,EventDetails};

/** Reads the connected Page's events. Facebook event creation stays outside this application. */
final class Facebook
{
    use ClubPublicationLock;
    private \Closure $request;
    public function __construct(private PDO $db,private Access $access,private SecretBox $box,?callable $request=null)
    {
        $this->request=$request===null?static fn($method,$path,$headers,$body)=>JsonHttp::request('meta',$method,$path,$headers,$body):\Closure::fromCallable($request);
    }
    private function account(): array
    {
        $club=$this->access->require('website','edit')['club_id'];
        $revision=null;
        $account=(new Accounts($this->db,$this->box))->get($club,'meta',$revision);
        $account['_revision']=$revision;
        return $account;
    }
    private static function binding(array $account): string
    {
        return hash('sha256',$account['page_id'].':'.Accounts::binding($account));
    }
    private function current(string $club,int $version,array $account):void
    {
        if($this->access->require('website','edit')['club_id']!==$club)throw new \DomainException('Vereinszuordnung während der Facebook-Abfrage geändert.');
        $state=(new Workflow($this->db,$this->access))->requireSource('facebook');
        if($state['version']!==$version)throw new \DomainException('Veranstaltungsablauf während der Facebook-Abfrage geändert.');
        $actual=$this->account();
        if($actual['_revision']!==$account['_revision']||!hash_equals(self::binding($actual),self::binding($account)))throw new \DomainException('Meta-Verbindung während der Facebook-Abfrage geändert. Neu abgleichen.');
    }
    public static function normalize(array $event):array
    {
        $id=$event['id']??null;
        if(!is_string($id)||!preg_match('/^[0-9]{1,40}$/D',$id))throw new \DomainException('Facebook-Veranstaltungskennung prüfen.');
        $start=$event['start_time']??null;$end=$event['end_time']??'';
        if(!is_string($start)||!is_string($end))throw new \DomainException('Facebook-Terminzeiten prüfen.');
        $starts=self::time($start);$ends=$end===''?'':self::time($end);
        if($ends!==''&&$ends<$starts)throw new \DomainException('Facebook-Endzeit liegt vor dem Beginn.');
        if(isset($event['is_canceled'])&&!is_bool($event['is_canceled']))throw new \DomainException('Facebook-Absagestatus prüfen.');
        $place=$event['place']??[];
        if(!is_array($place)||($place!==[]&&array_is_list($place)))throw new \DomainException('Facebook-Veranstaltungsort prüfen.');
        $location=$place['location']??[];
        if(!is_array($location)||($location!==[]&&array_is_list($location)))throw new \DomainException('Facebook-Veranstaltungsadresse prüfen.');
        $address=[];foreach(['street','zip','city','country']as$key)$address[]=Content::text($location[$key]??'',250);
        $ticket=EventDetails::normalize(['event_ticket_url'=>$event['ticket_uri']??''])['event_ticket_url'];
        $utc=new \DateTimeImmutable($starts,new \DateTimeZone('UTC'));
        return ['external_id'=>$id,'title'=>Content::text($event['name']??'',200,true),'body'=>Content::text($event['description']??'',100000),
            'event_start'=>$utc->setTimezone(new \DateTimeZone('Europe/Berlin'))->format('Y-m-d\TH:i'),'event_timezone'=>'Europe/Berlin',
            'starts_at'=>str_replace(' ','T',$starts).'Z','ends_at'=>$ends===''?'':str_replace(' ','T',$ends).'Z',
            'event_location'=>Content::text($place['name']??'',300),'event_address'=>trim(implode(' ',array_filter($address,static fn($v)=>$v!==''))),
            'event_price'=>'','status'=>($event['is_canceled']??false)?'canceled':'live','ticket_url'=>$ticket,'image_url'=>''];
    }
    /** Graph uses ISO-8601 basic offsets; keep the shared scheduling parser canonical. */
    private static function time(string $value):string
    {
        if(preg_match('/\A(\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2})([+-]\d{2})(\d{2})\z/D',$value,$parts))$value=$parts[1].$parts[2].':'.$parts[3];
        return Content::time($value);
    }
    public function sync():array
    {
        if($this->db->inTransaction())throw new \DomainException('Facebook-Abfrage benötigt einen eigenen Vorgang.');
        $club=$this->access->require('website','edit')['club_id'];
        $workflow=(new Workflow($this->db,$this->access))->requireSource('facebook');$account=$this->account();
        $events=[];$seen=[];$cursors=[];$after=null;$complete=false;
        for($page=0;$page<100;$page++){
            $this->current($club,$workflow['version'],$account);
            $query=['fields'=>'id,name,description,start_time,end_time,place,is_canceled,ticket_uri','limit'=>100];
            if($after!==null)$query['after']=$after;
            $r=($this->request)('GET','/'.$account['api_version'].'/'.$account['page_id'].'/events?'.http_build_query($query,'','&',PHP_QUERY_RFC3986),['Authorization: Bearer '.$account['token']],null);
            $this->current($club,$workflow['version'],$account);
            $body=$r['body']??null;
            if(($r['status']??null)!==200||!is_array($body)||isset($body['error'])||!is_array($body['data']??null)||!array_is_list($body['data']))throw new \DomainException('Facebook-Veranstaltungen konnten nicht vollständig gelesen werden. Verbindung und Veranstaltungsrechte prüfen.');
            foreach($body['data']as$row){
                if(!is_array($row))throw new \DomainException('Facebook-Veranstaltung unvollständig.');
                $event=self::normalize($row);$id=$event['external_id'];
                if(isset($seen[$id])||count($events)>=5000)throw new \DomainException('Facebook-Veranstaltungsliste überlappt oder überschreitet das Lauflimit.');
                $seen[$id]=true;$events[]=$event;
            }
            if(isset($body['paging'])&&(!is_array($body['paging'])||(isset($body['paging']['next'])&&!is_string($body['paging']['next']))))throw new \DomainException('Facebook-Seitenfolge ungültig.');
            if(!isset($body['paging']['next'])||$body['paging']['next']===''){$complete=true;break;}
            $after=$body['paging']['cursors']['after']??null;
            if(!is_string($after)||$after===''||strlen($after)>2048||preg_match('/[\x00-\x20\x7f]/',$after)||isset($cursors[$after]))throw new \DomainException('Facebook-Seitenfolge unvollständig.');
            $cursors[$after]=true;
        }
        if(!$complete)throw new \DomainException('Facebook-Seitenlimit erreicht. Kein Teilimport.');
        return (new SourceImporter($this->db,$this->access,'facebook',['facebook_account_binding'=>self::binding($account)]))->import($events,$club,$workflow['version'],fn()=>$this->current($club,$workflow['version'],$account));
    }
    /** An explicit, version-bound association preserves the website id and slug across providers. */
    public function link(string $external,string $pageId,int $version):array
    {
        if(!preg_match('/^[0-9]{1,40}$/D',$external))throw new \DomainException('Facebook-Veranstaltungskennung prüfen.');
        Content::id($pageId);$club=$this->access->require('website','edit')['club_id'];
        $state=(new Workflow($this->db,$this->access))->requireSource('facebook');$account=$this->account();
        return Db::atomic($this->db,function()use($external,$pageId,$version,$club,$state,$account):array{
            $this->lockClub();$this->current($club,$state['version'],$account);
            $page=(new Pages($this->db,$this->access))->get($pageId);
            if($page['version']!==$version||!EventDetails::isEvent($page)||$page['archived']||($page['trashed_at']??null)!==null)throw new \DomainException('Aktuelle eigene Veranstaltung auswählen.');
            $same=Db::run($this->db,"SELECT page_id FROM native_event_sources WHERE club_id=? AND source='facebook' AND external_id=? FOR UPDATE",[$club,$external])->fetchColumn();
            if(is_string($same)&&$same!==$pageId)throw new \DomainException('Facebook-Termin bereits anders zugeordnet.');
            $prior=Db::run($this->db,'SELECT * FROM native_event_sources WHERE club_id=? AND page_id=? FOR UPDATE',[$club,$pageId])->fetch();
            $metadata=$prior?json_decode($prior['metadata_json']??'{}',true,32,JSON_THROW_ON_ERROR):[];
            if($prior&&$prior['source']==='facebook'){
                if($prior['external_id']!==$external)throw new \DomainException('Diese Website-Veranstaltung ist bereits einem anderen Facebook-Termin zugeordnet.');
                if(($metadata['facebook_account_binding']??'')===self::binding($account))return ['id'=>$pageId,'status'=>'linked'];
            }
            if($prior)SourceImporter::alias($this->db,$club,$prior['source'],$prior['external_id'],$pageId);
            SourceImporter::alias($this->db,$club,'facebook',$external,$pageId);
            $metadata['facebook_account_binding']=self::binding($account);
            if($prior)Db::run($this->db,'DELETE FROM native_event_sources WHERE club_id=? AND page_id=?',[$club,$pageId]);
            Db::run($this->db,"INSERT INTO native_event_sources(club_id,source,external_id,page_id,source_hash,state,imported_version,metadata_json) VALUES(?,'facebook',?,?,?,'active',?,?)",[$club,$external,$pageId,str_repeat('0',64),$version,json_encode($metadata,JSON_THROW_ON_ERROR)]);
            return ['id'=>$pageId,'status'=>'linked'];
        });
    }
}
