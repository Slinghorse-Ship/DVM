<?php declare(strict_types=1);
namespace Kulturhus\Native\Events;

use Closure;
use DomainException;
use RuntimeException;
use PDO;
use Kulturhus\Native\Shared\{Access,Db};
use Kulturhus\Native\Cms\{ClubPublicationLock,Conflict,Media,Pages};

/** Explicitly requested new provider photos; never changes a published original or local edit. */
final class EventbriteImages
{
    use ClubPublicationLock;
    private Closure $download;
    private Media $media;
    public function __construct(private PDO $db,private Access $access,private string $root,?callable $download=null)
    {
        $this->download=$download===null?ImageDownload::receive(...):static fn($url,$path,$seconds=20)=>$download($url,$path);
        $this->media=new Media($db,$access,$root);
    }
    /** At most ten downloads per request; another requested sync resumes without re-uploading known photos. */
    public function sync(array $results):array
    {
        $this->access->require('website','edit');$deadline=hrtime(true)+20000000000;$downloaded=0;$attached=0;$unchanged=0;$errors=[];$more=false;
        foreach($results as$result){
            if(!is_array($result)||!is_string($result['id']??null)||!in_array($result['status']??null,['draft','unchanged'],true))continue;
            try{
                $status=$this->receive($result['id'],function($url,$path)use(&$downloaded,$deadline):void{$downloaded++;($this->download)($url,$path,max(1,(int)ceil(($deadline-hrtime(true))/1000000000)));},$downloaded<10&&hrtime(true)<$deadline);
                if($status==='deferred'){$more=true;break;}
                if($status==='attached')$attached++;elseif($status==='unchanged')$unchanged++;
            }catch(DomainException|Conflict|RuntimeException $error){if(count($errors)<3)$errors[]=$error->getMessage();}
        }
        return compact('downloaded','attached','unchanged','errors','more');
    }
    public function receive(string $pageId,?callable $download=null,bool $allowDownload=true):string
    {
        if($this->db->inTransaction())throw new \LogicException('Anbieterbilder außerhalb einer laufenden Transaktion abrufen.');
        $frame=$this->frame($pageId);$source=$frame['source'];$metadata=$frame['metadata'];$url=$metadata['image_url']??'';
        if($url==='')return 'none';$url=ImageDownload::url($url);
        $known=$metadata['image_media_id']??null;
        if(($metadata['image_source_url']??null)===$url&&is_string($known)&&in_array($known,$frame['page']['media_ids'],true)){
            $asset=$this->media->variant($known,'original');
            if(!hash_equals((string)($metadata['image_sha256']??''),$asset['sha256']))throw new DomainException('Gespeichertes Anbieterbild prüfen.');
            return 'unchanged';
        }
        if(!$allowDownload)return 'deferred';
        $temporary=tempnam($this->root,'eventbrite-photo-');if($temporary===false)throw new RuntimeException('Privates Bildziel nicht verfügbar.');chmod($temporary,0600);
        try{
            ($download??$this->download)($url,$temporary);
            if(is_link($temporary)||!is_file($temporary)||filesize($temporary)<1||filesize($temporary)>ImageDownload::MAX_BYTES)throw new DomainException('Anbieterbildgröße prüfen.');
            $mime=(new \finfo(FILEINFO_MIME_TYPE))->file($temporary);$extension=['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'][$mime]??null;
            if($extension===null)throw new DomainException('Anbieter liefert kein unterstütztes Foto.');
            $downloadHash=hash_file('sha256',$temporary);
            $this->media->upload($temporary,'Eventbrite-'.$source['external_id'].'.'.$extension,$frame['page']['title'],null,function(array $asset)use($frame,$url,$downloadHash,$known):void{
                $this->access->require('website','edit');$this->lockClub();$this->current($frame);
                $variants=$asset['variants'];$variants['original']['provider_source']=['provider'=>'eventbrite','external_id'=>$frame['source']['external_id'],'page_id'=>$frame['page']['id'],'source_hash'=>$frame['source']['source_hash'],'image_url'=>$url,'download_sha256'=>$downloadHash];
                Db::run($this->db,'UPDATE khcms_media SET variants_json=? WHERE club_id=? AND id=?',[json_encode($variants,JSON_THROW_ON_ERROR),$frame['club'],$asset['id']]);
                $data=$frame['page'];unset($data['body_html']);
                $data['media_ids']=array_values(array_filter($data['media_ids'],static fn($id)=>$id!==$known));$data['media_ids'][]=$asset['id'];
                $page=(new Pages($this->db,$this->access))->save($data,$frame['page']['version']);
                $metadata=$frame['metadata'];$metadata['image_media_id']=$asset['id'];$metadata['image_source_url']=$url;$metadata['image_sha256']=$asset['sha256'];
                Db::run($this->db,"UPDATE native_event_sources SET metadata_json=?,imported_version=? WHERE club_id=? AND source='eventbrite' AND page_id=?",[json_encode($metadata,JSON_THROW_ON_ERROR),$page['version'],$frame['club'],$page['id']]);
            });
            return 'attached';
        }finally{if(is_file($temporary)||is_link($temporary))unlink($temporary);}
    }
    private function frame(string $id):array
    {
        if(!preg_match('/\A[a-f0-9]{32}\z/D',$id))throw new DomainException('Eigenen Eventbrite-Termin wählen.');
        return Db::atomic($this->db,function()use($id):array{
            $actor=$this->access->require('website','edit');$club=$actor['club_id'];
            $source=Db::run($this->db,"SELECT * FROM native_event_sources WHERE club_id=? AND source='eventbrite' AND page_id=? FOR SHARE",[$club,$id])->fetch();
            if(!$source||$source['state']!=='active')throw new DomainException('Aktiver eigener Eventbrite-Termin erforderlich.');
            $row=Db::run($this->db,'SELECT version,draft_json FROM khcms_pages WHERE club_id=? AND id=? FOR SHARE',[$club,$id])->fetch();
            $imported=Db::run($this->db,'SELECT payload_json FROM khcms_revisions WHERE club_id=? AND page_id=? AND revision=?',[$club,$id,$source['imported_version']])->fetchColumn();
            if(!$row||!is_string($imported)||json_decode($row['draft_json'],true,512,JSON_THROW_ON_ERROR)!==json_decode($imported,true,512,JSON_THROW_ON_ERROR))throw new Conflict('Lokale Terminbearbeitung erhalten. Anbieterbild separat prüfen.');
            $page=(new Pages($this->db,$this->access))->get($id);
            if($page['archived']||($page['trashed_at']??null)!==null)throw new DomainException('Aktiver eigener Eventbrite-Termin erforderlich.');
            $metadata=is_string($source['metadata_json'])?(json_decode($source['metadata_json'],true,32,JSON_THROW_ON_ERROR)??[]):[];
            if(!is_array($metadata)||($metadata!==[]&&array_is_list($metadata)))throw new DomainException('Gespeicherte Eventbrite-Bildquelle prüfen.');
            return compact('club','source','row','page','metadata');
        });
    }
    private function current(array $frame):void
    {
        if($this->access->club()!==$frame['club'])throw new Conflict('Vereinszuordnung geändert.');
        $source=Db::run($this->db,"SELECT * FROM native_event_sources WHERE club_id=? AND source='eventbrite' AND page_id=? FOR UPDATE",[$frame['club'],$frame['page']['id']])->fetch();
        $row=Db::run($this->db,'SELECT version,draft_json FROM khcms_pages WHERE club_id=? AND id=? FOR UPDATE',[$frame['club'],$frame['page']['id']])->fetch();
        if(!$source||!$row||$source['state']!=='active'||!hash_equals($source['source_hash'],$frame['source']['source_hash'])||$source['metadata_json']!==$frame['source']['metadata_json']||(int)$row['version']!==$frame['page']['version']||$row['draft_json']!==$frame['row']['draft_json'])throw new Conflict('Termin oder Anbieterbild wurde geändert. Neu abgleichen.');
    }
}
