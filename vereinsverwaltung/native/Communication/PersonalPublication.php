<?php declare(strict_types=1);
namespace Kulturhus\Native\Communication;

use DomainException;
use PDO;
use Kulturhus\Native\Shared\{Access,Authority,Db,SecretBox};
use Kulturhus\Native\Cms\{EventDetails,Pages};
use Kulturhus\Native\Delivery\{DeferredDelivery,Outbox,UncertainDelivery};
use Kulturhus\Native\Integration\ManagerEventSource;
use Kulturhus\Native\Integration\StadtLandFunk\{Connection,OwnPostsPage,Sender};

/** Personal app publication, with a committed photo checkpoint before its single event write. */
final class PersonalPublication
{
    public function __construct(private PDO $db,private SecretBox $box,private ?Connection $connection=null)
    {$this->connection??=new Connection($db,$box);}

    private static function source(PDO $db,Access $access,string $item):array
    {
        $access->require('social','read');
        $row=Db::run($db,'SELECT page_id,revision,snapshot_json FROM native_social_event_origins WHERE club_id=? AND item_id=? FOR SHARE',[$access->club(),$item])->fetch();
        if(!$row)throw new DomainException('Veranstaltung zuerst als Quelle auswählen.');
        $snapshot=json_decode($row['snapshot_json'],true,64,JSON_THROW_ON_ERROR);$kind=($snapshot['_source_kind']??'website')==='manager'?'manager':'website';
        if($kind==='manager')$current=(new ManagerEventSource($db,$access))->get($row['page_id'],(int)$row['revision']);
        else{
            $access->require('website','read');
            $revision=Db::run($db,'SELECT published_revision FROM khcms_pages WHERE club_id=? AND id=? FOR SHARE',[$access->club(),$row['page_id']])->fetchColumn();
            if($revision===false||(int)$revision!==(int)$row['revision'])throw new DomainException('Veranstaltungsquelle geändert oder zurückgezogen.');
            Db::run($db,'SELECT page_id FROM khcms_events WHERE club_id=? AND page_id=? FOR SHARE',[$access->club(),$row['page_id']])->fetch();
            Db::run($db,'SELECT page_id FROM native_event_sources WHERE club_id=? AND page_id=? FOR SHARE',[$access->club(),$row['page_id']])->fetch();
            $current=(new Pages($db,$access))->events()->publicId($row['page_id']);
        }
        if(!$current||(int)$current['published_version']!==(int)$row['revision']||EventDetails::cancelled($current)||EventDetails::start($snapshot)===null)throw new DomainException('Aktuelle veröffentlichte Veranstaltung mit Beginn erforderlich.');
        return ['kind'=>$kind,'id'=>$row['page_id'],'revision'=>(int)$row['revision'],'hash'=>hash('sha256',$row['snapshot_json']),'snapshot'=>$snapshot];
    }

    /** Called under the outbox's current authority locks, before any provider transport. */
    public static function guardSource(PDO $db,Access $access,array $payload):void
    {
        $source=self::source($db,$access,$payload['approval']['item']);
        foreach(['kind','id','revision','hash']as$key)if(($payload['source'][$key]??null)!==$source[$key])throw new DomainException('Ursprüngliche Veranstaltungsbindung geändert.');
    }

    private static function location(array $snapshot):array
    {
        $name=Content::text($snapshot['event_location']??'',300,true);$address=trim($snapshot['event_address']??'');
        $location=['locationName'=>$name];
        if($address!==''){
            $location['locationLookupString']=$address;
            if(preg_match('/\A(.+?)(?:\r?\n|,\s*)([0-9]{5})\s+([^\r\n]+)\z/u',$address,$parts)){
                $location['addressStreet']=trim($parts[1]);$location['addressZip']=$parts[2];$location['addressCity']=trim($parts[3]);
            }
        }
        return $location;
    }

    /** Existing original for this source and current connection, without provider traffic. */
    public function existing(Access $access,string $item):?string
    {
        $access->require('social','read');$access->require('social','publish');
        return Db::atomic($this->db,function()use($access,$item):?string{
            $club=$access->club();$identity=$this->connection->publicationIdentity($access);
            $origin=Db::run($this->db,'SELECT page_id,snapshot_json FROM native_social_event_origins WHERE club_id=? AND item_id=? FOR SHARE',[$club,$item])->fetch();
            if(!$origin)return null;
            $snapshot=json_decode($origin['snapshot_json'],true,64,JSON_THROW_ON_ERROR);$kind=($snapshot['_source_kind']??'website')==='manager'?'manager':'website';
            $dedupe=hash('sha256','stadtlandfunk:'.$identity['connection_id'].':'.$kind.':'.$origin['page_id']);
            $id=Db::run($this->db,"SELECT id FROM native_delivery_jobs WHERE club_id=? AND kind='stadtlandfunk' AND dedupe=?",[$club,hash('sha256','stadtlandfunk:'.$dedupe)])->fetchColumn();
            $access->require('social','publish');if($access->club()!==$club)throw new DomainException('Vereinszuordnung geändert.');
            return $id===false?null:$id;
        });
    }

    public function queue(Access $access,string $item,int $version,string $due,string $mediaRoot,bool $confirmed=false):string
    {
        $access->require('social','publish');if(!$confirmed)throw new DomainException('StadtLand.Funk als Veröffentlichungsziel bestätigen.');
        if(preg_match('/\A\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\z/D',$due)){
            $date=\DateTimeImmutable::createFromFormat('!Y-m-d H:i:s',$due,new \DateTimeZone('UTC'));
            if(!$date||$date->format('Y-m-d H:i:s')!==$due)throw new DomainException('Planungszeit prüfen.');
        }else $due=Content::time($due);
        return Db::atomic($this->db,function()use($access,$item,$version,$due,$mediaRoot):string{
            $publication=PublicationSnapshot::social($this->db,$access,$item,$mediaRoot);$content=$publication['item'];
            if($content['version']!==$version||$content['kind']!=='post'||count($publication['images'])!==1)throw new DomainException('Aktuelle freigegebene Veranstaltungsfassung erforderlich.');
            $source=self::source($this->db,$access,$item);$identity=$this->connection->publicationIdentity($access);$file=(new Images($this->db,$access,$mediaRoot))->jpeg($publication['images'][0]['id']);
            $payload=['channel'=>'stadtlandfunk','kind'=>'event','identity'=>$identity,'source'=>array_intersect_key($source,array_flip(['kind','id','revision','hash'])),
                'image_id'=>$publication['images'][0]['id'],'image_hash'=>$file['sha256'],
                'event'=>['title'=>$content['title'],'text'=>$content['body'].(($content['destination_url']??'')!==''?"\n".$content['destination_url']:''),'startTime'=>EventDetails::start($source['snapshot'])->getTimestamp()*1000,'customLocationDefinition'=>self::location($source['snapshot'])],
                'approval'=>['actor'=>$content['approved_by'],'authority'=>$content['approved_authority']??'native','item'=>$item,'version'=>$version]];
            if(($end=EventDetails::end($source['snapshot']))!==null)$payload['event']['endTime']=$end->getTimestamp()*1000;
            if (!empty($identity['organization_id'])) $payload['event'] += ['organizationId'=>$identity['organization_id'],'geoAreaIds'=>[$identity['home_area_id']]];
            $dedupe=hash('sha256','stadtlandfunk:'.$identity['connection_id'].':'.$source['kind'].':'.$source['id']);
            $old=Db::run($this->db,'SELECT id,ciphertext FROM native_delivery_jobs WHERE club_id=? AND dedupe=? FOR SHARE',[$access->club(),hash('sha256','stadtlandfunk:'.$dedupe)])->fetch();
            if($old){$original=$this->box->open('job:'.$access->club().':'.$old['id'],$old['ciphertext']);if($original!==$payload)throw new DomainException('Für diese Veranstaltung besteht bereits ein eigener Veröffentlichungsauftrag.');return $old['id'];}
            $id=(new Outbox($this->db,$this->box))->enqueue($access->club(),'stadtlandfunk',$dedupe,$payload,$due,$access);
            $stored=Db::run($this->db,'SELECT ciphertext FROM native_delivery_jobs WHERE club_id=? AND id=? FOR SHARE',[$access->club(),$id])->fetchColumn();
            if($this->box->open('job:'.$access->club().':'.$id,$stored)!==$payload)throw new DomainException('Eine andere Fassung dieser Veranstaltung wurde bereits eingeplant.');
            return $id;
        });
    }

    private static function providerId(mixed $id):string
    {
        if(!is_string($id)||!preg_match('/\A[A-Za-z0-9_-]{1,50}\z/D',$id))throw new DomainException('Anbieterkennung nicht bestätigt.');return $id;
    }

    private static function reference(string $temp,string $media,int $expiry,string $post=''):string
    {return 'sf:'.self::providerId($temp).':'.self::providerId($media).':'.$expiry.':'.($post===''?'':self::providerId($post));}

    private static function parse(?string $reference):?array
    {
        if($reference===null||$reference==='')return null;
        if(!preg_match('/\Asf:([A-Za-z0-9_-]{1,50}):([A-Za-z0-9_-]{1,50}):([0-9]{1,11}):([A-Za-z0-9_-]{0,50})\z/D',$reference,$match))throw new DomainException('Früherer Veröffentlichungsstand nicht lesbar.');
        return ['temp'=>$match[1],'media'=>$match[2],'expiry'=>(int)$match[3],'post'=>$match[4]];
    }

    public static function postId(?string $reference):?string
    {$state=self::parse($reference);return $state&&$state['post']!==''?$state['post']:null;}

    private function context(string $club,array $payload,array $job):Access
    {
        if(($job['kind']??null)!=='stadtlandfunk'||($job['club_id']??null)!==$club||($payload['channel']??null)!=='stadtlandfunk')throw new DomainException('Falscher Veröffentlichungsauftrag.');
        $access=Authority::resolve($this->db,['created_by'=>$job['actor_id'],'authority'=>$job['authority'],'club_id'=>$club]);$access->require('social','publish');
        if($access->club()!==$club||$this->connection->publicationIdentity($access,$payload['identity']['connection_id'])!==$payload['identity'])throw new DomainException('Ursprüngliche Kontobindung geändert.');
        return $access;
    }

    public function send(string $club,array $payload,array $job,string $mediaRoot):string
    {
        if(!$this->db->inTransaction())throw new DomainException('Veröffentlichung nur aus einem dauerhaft übernommenen Auftrag.');
        $current=Db::run($this->db,"SELECT * FROM native_delivery_jobs WHERE club_id=? AND id=? FOR SHARE",[$club,$job['id']??''])->fetch();
        if(!$current||$current['status']!=='processing'||(int)$current['attempts']!==(int)($job['attempts']??-1)+1)throw new DomainException('Aktuelle Auftragsübernahme fehlt.');
        foreach(['kind','actor_id','authority','capability','ciphertext','external_id']as$key)if(($job[$key]??null)!==$current[$key])throw new DomainException('Ursprünglicher Auftrag geändert.');
        if($this->box->open('job:'.$club.':'.$current['id'],$current['ciphertext'])!==$payload)throw new DomainException('Ursprüngliche Veröffentlichungsfassung geändert.');
        ImageUsage::storedImage($this->db,$club,$payload['image_id']);$access=$this->context($club,$payload,$job);self::guardSource($this->db,$access,$payload);$file=(new Images($this->db,$access,$mediaRoot))->jpeg($payload['image_id']);
        if(!hash_equals($payload['image_hash'],$file['sha256']))throw new DomainException('Freigegebene Bildbytes geändert.');
        $state=self::parse($job['external_id']??null);$binding=$payload['identity']['connection_id'];
        if($state===null||($state['post']===''&&$state['expiry']<=time()+60)){
            $photo=$this->connection->upload($access,$binding,$file['path'],$file['sha256']);$temp=self::providerId($photo['id']??null);$media=self::providerId($photo['mediaItem']['id']??null);$expires=$photo['expirationTime']??null;
            if(!is_int($expires)||$expires<=1000*(time()+60)||$expires>4102444800000)throw new DomainException('Temporäre Bildlaufzeit nicht bestätigt.');
            throw new DeferredDelivery(self::reference($temp,$media,intdiv($expires,1000)),'stadtlandfunk_image_ready');
        }
        if($state['post']!=='')throw new UncertainDelivery($job['external_id'],'stadtlandfunk_existing_attempt');
        $event=$payload['event'];$event['temporaryMediaItemIds']=[$state['temp']];$reference=$job['external_id'];
        try{
            $created=$this->connection->createEvent($access,$binding,$event);$post=self::providerId($created['postId']??$created['post']['id']??null);
            if(isset($created['post']['id'])&&$created['post']['id']!==$post)throw new DomainException('Widersprüchliche Veranstaltungskennung.');
            $reference=self::reference($state['temp'],$state['media'],$state['expiry'],$post);
            $original=$this->connection->publicationRead($access,$binding,'/grapevine/post/'.$post);
            if(!self::matches($original,$payload,$state['media'],$post))throw new DomainException('Veröffentlichtes Original nicht unabhängig bestätigt.');
            $this->context($club,$payload,$job);return $reference;
        }catch(\Throwable){throw new UncertainDelivery($reference,'stadtlandfunk_create_unknown');}
    }

    private static function matches(array $post,array $payload,string $media,?string $id=null):bool
    {
        $event=$post['happening']??null;$expected=$payload['event'];
        if(!is_array($event)||($post['type']??null)!=='HAPPENING'||!is_string($post['id']??null)||($id!==null&&$post['id']!==$id)||!Sender::owns($event,$payload['identity'])||(($event['geoAreaId']??null)!==$payload['identity']['home_area_id']&&!in_array($payload['identity']['home_area_id'],$event['geoAreaIds']??[],true)))return false;
        foreach(['title','text','startTime','endTime']as$key)if(($event[$key]??null)!==($expected[$key]??null))return false;
        if(!empty($event['filterStatus'])||($event['hiddenForOtherHomeAreas']??false)!==false||!is_array($event['images']??null)||count($event['images'])!==1||($event['images'][0]['id']??null)!==$media)return false;
        $address=$event['customAddress']??[];$location=$expected['customLocationDefinition'];if(($address['name']??null)!==$location['locationName'])return false;
        foreach(['addressStreet'=>'street','addressZip'=>'zip','addressCity'=>'city']as$key=>$field)if(isset($location[$key])&&($address[$field]??null)!==$location[$key])return false;
        if(isset($location['locationLookupString'])&&!isset($location['addressStreet'])){
            $actual=trim(($address['street']??'').' '.($address['zip']??'').' '.($address['city']??''));
            if(preg_replace('/\s+/u',' ',$actual)!==preg_replace('/\s+/u',' ',$location['locationLookupString']))return false;
        }
        return true;
    }

    /** Read-only provider reconciliation; never creates an event, even after an uncertain response. */
    public function reconcile(Access $access,string $jobId):array
    {
        $access->require('social','publish');Content::id($jobId);
        return Db::atomic($this->db,function()use($access,$jobId):array{
            $job=Db::run($this->db,"SELECT * FROM native_delivery_jobs WHERE club_id=? AND id=? AND kind='stadtlandfunk' FOR UPDATE",[$access->club(),$jobId])->fetch();
            if($job&&$job['status']==='processing'&&$job['started_at']!==null&&strtotime($job['started_at'].' UTC')<=time()-600){
                Db::run($this->db,"UPDATE native_delivery_jobs SET status='uncertain',reason='stadtlandfunk_interrupted_review' WHERE club_id=? AND id=?",[$access->club(),$jobId]);$job['status']='uncertain';
            }
            if(!$job||!in_array($job['status'],['sent','uncertain'],true))throw new DomainException('Abzugleichender ursprünglicher Auftrag nicht verfügbar.');
            $payload=$this->box->open('job:'.$access->club().':'.$jobId,$job['ciphertext']);$this->context($access->club(),$payload,$job);self::guardSource($this->db,$access,$payload);
            $state=self::parse($job['external_id']);$result=['status'=>$job['status'],'publication_confirmed'=>false,'remote_id'=>null,'permalink'=>null];
            if($state===null)return $result;
            $matches=[];$binding=$payload['identity']['connection_id'];
            if($state['post']!==''){
                $post=$this->connection->publicationRead($access,$binding,'/grapevine/post/'.$state['post']);if(self::matches($post,$payload,$state['media'],$state['post']))$matches[]=$state['post'];
            }else{
                $pages=1;$seen=[];$sequence=null;
                for($page=0;$page<$pages;$page++){
                    $list=$this->connection->publicationRead($access,$binding,Sender::postsPath($payload['identity'],$page));
                    $list=OwnPostsPage::read($list,$page);
                    if($page===0){$pages=max(1,$list['total_pages']);$sequence=OwnPostsPage::sequence($list);}elseif(OwnPostsPage::sequence($list)!==$sequence)throw new DomainException('Veröffentlichungsliste inzwischen geändert.');
                    if($page<$pages-1&&count($list['content'])!==50)throw new DomainException('Frühere Veröffentlichungsseite nicht vollständig bestätigt.');
                    foreach($list['content']as$post){$id=self::providerId($post['id']??null);if(isset($seen[$id]))throw new DomainException('Veröffentlichungsliste enthält doppelte Originale.');$seen[$id]=true;if(self::matches($post,$payload,$state['media']))$matches[]=$id;}
                }
            }
            if(count($matches)!==1)return $result;
            $reference=self::reference($state['temp'],$state['media'],$state['expiry'],$matches[0]);
            Db::run($this->db,"UPDATE native_delivery_jobs SET status='sent',external_id=?,reason=NULL,completed_at=COALESCE(completed_at,UTC_TIMESTAMP()) WHERE id=? AND club_id=?",[$reference,$jobId,$access->club()]);
            return ['status'=>'sent','publication_confirmed'=>true,'remote_id'=>$matches[0],'permalink'=>'https://www.stadtlandfunk.app/post-detail?'.http_build_query(['id'=>$matches[0]])];
        });
    }
}
