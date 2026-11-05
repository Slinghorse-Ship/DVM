<?php declare(strict_types=1);
namespace Kulturhus\Native\Communication;

use DomainException;
use PDO;
use Kulturhus\Native\Cms\{Conflict,UploadTransaction};
use Kulturhus\Native\Shared\{Access,Db,Identity};

/** Private immutable rendered MP4 bytes. Saving a version never publishes or changes an approval. */
final class Videos
{
    private string $root;
    public function __construct(private PDO $db, private Access $access, string $mediaRoot)
    { $this->root=dirname($mediaRoot).'/communication-videos'; }

    public function installed(): bool
    {
        $this->access->require('social','read');
        return (int)Db::run($this->db,"SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('native_social_video_sources','native_social_videos')")->fetchColumn()===2;
    }

    public function save(string $itemId,int $expectedVersion,int $identityVersion,string $source,string $originalName='Reel.mp4',?callable $associate=null): string
    {
        Content::id($itemId);
        if($expectedVersion<1||$identityVersion<0)throw new DomainException('Inhaltsstand prüfen.');
        $originalName=Content::text($originalName,255,true);
        if($this->db->inTransaction())throw new DomainException('Video benötigt einen eigenen Speichervorgang.');
        $actor=$this->access->require('social','edit');$club=$actor['club_id'];
        $this->schema();
        $item=(new Workspace($this->db,$this->access,'social'))->get($itemId);
        if($item['version']!==$expectedVersion)throw new Conflict('Inhalt wurde inzwischen geändert. Video erneut öffnen.');
        $identity=Identity::forClub($this->db,$club);
        if($identity['version']!==$identityVersion)throw new Conflict('Vereinsdaten wurden geändert. Video erneut öffnen.');
        if(is_link($source)||!is_file($source))throw new DomainException('Eigene MP4-Datei auswählen.');
        $input=@fopen($source,'rb');if(!$input)throw new DomainException('MP4-Datei ist nicht lesbar.');
        $sourceId=bin2hex(random_bytes(16));$id=bin2hex(random_bytes(16));
        $file='';$created=false;$output=null;$enteredTransaction=false;
        try {
            $stat=fstat($input);
            if(!$stat||$stat['size']<32||$stat['size']>ReelFile::MAX_BYTES)throw new DomainException('MP4-Datei darf höchstens 100 MiB groß sein.');
            $directory=$this->directory($club,true);$file=$directory.'/'.$sourceId.'.mp4';
            $output=@fopen($file,'x+b');if(!$output)throw new DomainException('Neue private Videodatei ist nicht anlegbar.');$created=true;
            if(!chmod($file,0600))throw new DomainException('Privaten Videospeicher prüfen.');
            $hash=hash_init('sha256');$bytes=0;
            while(!feof($input)) {
                $chunk=fread($input,1048576);if($chunk===false)throw new DomainException('MP4-Datei ist nicht lesbar.');
                $bytes+=strlen($chunk);if($bytes>ReelFile::MAX_BYTES)throw new DomainException('MP4-Datei darf höchstens 100 MiB groß sein.');
                hash_update($hash,$chunk);$position=0;
                while($position<strlen($chunk)){$written=fwrite($output,substr($chunk,$position));if($written===false||$written===0)throw new DomainException('Videodatei ist nicht speicherbar.');$position+=$written;}
            }
            if($bytes!==$stat['size']||!fflush($output))throw new DomainException('MP4-Datei wurde während des Lesens geändert.');
            fclose($output);$output=null;
            $digest=hash_final($hash);$profile=ReelFile::inspect($file);
            if($profile['bytes']!==$bytes||hash_file('sha256',$file)!==$digest)throw new DomainException('Gespeicherte Videobytes prüfen.');
            UploadTransaction::run($this->db,function(UploadTransaction $upload)use($club,$actor,$itemId,$expectedVersion,$identityVersion,$originalName,$sourceId,$id,$bytes,$digest,$profile,$file,$associate,&$enteredTransaction):void {
                $enteredTransaction=true;$upload->onRollback($this->db,static function()use($file):void{if(is_file($file)&&!unlink($file))throw new \RuntimeException('Neue Videodatei konnte nicht zurückgesetzt werden.');});
                $currentActor=$this->access->require('social','edit');
                if($currentActor['club_id']!==$club||$currentActor['id']!==$actor['id'])throw new DomainException('Vereinszugehörigkeit wurde geändert.');
                $current=(new Workspace($this->db,$this->access,'social'))->get($itemId,true);
                $version=Db::run($this->db,'SELECT version FROM native_club_settings WHERE club_id=? FOR SHARE',[$club])->fetchColumn();
                if($current['version']!==$expectedVersion)throw new Conflict('Inhalt wurde inzwischen geändert. Video erneut öffnen.');
                if(($version===false?0:(int)$version)!==$identityVersion)throw new Conflict('Vereinsdaten wurden geändert. Video erneut öffnen.');
                VideoQuota::assertUpload($this->db,$club,$bytes);
                Db::run($this->db,'INSERT INTO native_social_video_sources VALUES(?,?,?,?,?,?,?,UTC_TIMESTAMP(6))',[$sourceId,$club,$originalName,$bytes,$digest,json_encode($profile,JSON_THROW_ON_ERROR),$actor['id']]);
                Db::run($this->db,'INSERT INTO native_social_videos VALUES(?,?,?,?,?,?,?,UTC_TIMESTAMP(6))',[$id,$club,$itemId,$sourceId,$expectedVersion,Images::fingerprint($current),$identityVersion]);
                if($associate!==null)$associate($id);
            });
            return $id;
        } catch(\Throwable $error) {
            if(is_resource($output))fclose($output);
            // Once SQL starts, only a positively acknowledged rollback authorises deletion.
            if($created&&$file!==''&&!$enteredTransaction)@unlink($file);
            throw $error;
        } finally { fclose($input); }
    }

    public static function reuseIdentity(string $club,string $item,int $version,int $identity,string $source):string
    {
        Content::id($item);Content::id($source);
        if($version<1||$version>4294967295||$identity<0||$identity>4294967295)throw new DomainException('Aktuellen Inhaltsstand öffnen.');
        return substr(hash('sha256','video-reuse:'.json_encode([$club,$item,$version,$identity,$source],JSON_THROW_ON_ERROR)),0,32);
    }

    /** Read-only acknowledgement check, including after later approval, archive or physical removal. */
    public function reuseCheck(string $item,string $id):?array
    {
        Content::id($item);Content::id($id);if($this->db->inTransaction())throw new DomainException('Ergebnisprüfung benötigt einen eigenen aktuellen Lesevorgang.');
        $actor=$this->access->require('social','edit');$club=$actor['club_id'];$this->schema();(new Workspace($this->db,$this->access,'social'))->get($item);
        $row=Db::run($this->db,'SELECT v.id,v.source_id,v.item_version,v.identity_version,s.original_name FROM native_social_videos v JOIN native_social_video_sources s ON s.club_id=v.club_id AND s.id=v.source_id WHERE v.club_id=? AND v.item_id=? AND v.id=?',[$club,$item,$id])->fetch();
        if($row&&$id!==self::reuseIdentity($club,$item,(int)$row['item_version'],(int)$row['identity_version'],$row['source_id']))throw new DomainException('Ursprüngliche Videoübernahme auswählen.');
        foreach(['read','edit']as$cap){$current=$this->access->require('social',$cap);if($current['id']!==$actor['id']||$current['club_id']!==$club)throw new Conflict('Person oder Vereinszuordnung geändert.');}
        return $row?:null;
    }

    /** Reuse exact own bytes in a distinct draft; the shared source, quota and original decisions stay intact. */
    public function reuse(string $itemId,int $expectedVersion,int $identityVersion,string $videoId):array
    {
        Content::id($itemId);Content::id($videoId);
        if($expectedVersion<1||$expectedVersion>4294967295||$identityVersion<0||$identityVersion>4294967295)throw new DomainException('Aktuellen Inhaltsstand öffnen.');
        if($this->db->inTransaction())throw new DomainException('Videoübernahme benötigt einen eigenen aktuellen Vorgang.');
        $actor=$this->access->require('social','edit');$club=$actor['club_id'];$this->schema();
        return Db::atomic($this->db,function()use($actor,$club,$itemId,$expectedVersion,$identityVersion,$videoId):array{
            foreach(['edit','read']as$cap){$current=$this->access->require('social',$cap);if($current['id']!==$actor['id']||$current['club_id']!==$club)throw new Conflict('Person oder Vereinszuordnung geändert.');}
            $item=(new Workspace($this->db,$this->access,'social'))->get($itemId,true);
            $original=Db::run($this->db,'SELECT * FROM native_social_videos WHERE club_id=? AND id=? FOR SHARE',[$club,$videoId])->fetch();
            if(!$original)throw new DomainException('Eigene ursprüngliche Videofassung auswählen.');
            $id=self::reuseIdentity($club,$itemId,$expectedVersion,$identityVersion,$original['source_id']);
            $saved=Db::run($this->db,'SELECT * FROM native_social_videos WHERE id=? FOR SHARE',[$id])->fetch();
            if($saved){
                if($saved['club_id']!==$club||$saved['item_id']!==$itemId||$saved['source_id']!==$original['source_id']||(int)$saved['item_version']!==$expectedVersion||(int)$saved['identity_version']!==$identityVersion)throw new Conflict('Ursprüngliche Videoübernahme prüfen.');
                return ['video_id'=>$id,'source_id'=>$original['source_id'],'created'=>false];
            }
            if($item['kind']!=='reel'||!in_array($item['status'],['draft','review'],true))throw new DomainException('Video in einen eigenen Reel-Entwurf übernehmen.');
            $identity=Db::run($this->db,'SELECT version FROM native_club_settings WHERE club_id=? FOR SHARE',[$club])->fetchColumn();
            if($item['version']!==$expectedVersion||($identity===false?0:(int)$identity)!==$identityVersion)throw new Conflict('Inhalt oder Vereinsdaten inzwischen geändert. Aktuellen Stand öffnen.');
            if((int)$original['identity_version']!==$identityVersion)throw new Conflict('Video enthält einen früheren Vereinsstand. Neue Fassung speichern.');
            VideoQuota::assertUpload($this->db,$club,0);VideoArchive::assertSelectable($this->db,$club,$original['source_id']);
            $file=$this->file($videoId);$profile=ReelFile::inspect($file['path']);
            if($profile!=$file['profile']||@hash_file('sha256',$file['path'])!==$file['sha256'])throw new DomainException('Ursprüngliche Videobytes und Tonprofil erneut prüfen.');
            foreach(['edit','read']as$cap){$current=$this->access->require('social',$cap);if($current['id']!==$actor['id']||$current['club_id']!==$club)throw new Conflict('Person oder Vereinszuordnung geändert.');}
            Db::run($this->db,'INSERT INTO native_social_videos VALUES(?,?,?,?,?,?,?,UTC_TIMESTAMP(6))',[$id,$club,$itemId,$original['source_id'],$expectedVersion,Images::fingerprint($item),$identityVersion]);
            return ['video_id'=>$id,'source_id'=>$original['source_id'],'created'=>true];
        });
    }

    public function page(string $itemId,mixed $page=1,bool $archived=false): array
    {
        Content::id($itemId);
        if((!is_string($page)&&!is_int($page))||!preg_match('/^[1-9][0-9]{0,6}$/D',(string)$page)||(int)$page>1000000)throw new DomainException('Videoverlaufsseite prüfen.');
        $club=$this->access->require('social','read')['club_id'];$this->schema();
        (new Workspace($this->db,$this->access,'social'))->get($itemId);
        $archiveAvailable=VideoArchive::installed($this->db);
        $join=$archiveAvailable?' LEFT JOIN native_video_archives a ON a.club_id=v.club_id AND a.source_id=v.source_id':'';
        $state=$archiveAvailable?'COALESCE(a.archived,0)':'0';
        $counts=Db::run($this->db,'SELECT COUNT(*) AS all_total,COALESCE(SUM('.$state.'=1),0) AS archived_total FROM native_social_videos v'.$join.' WHERE v.club_id=? AND v.item_id=?',[$club,$itemId])->fetch();
        $archivedTotal=(int)$counts['archived_total'];$activeTotal=(int)$counts['all_total']-$archivedTotal;
        $total=$archived?$archivedTotal:$activeTotal;
        $pages=max(1,(int)ceil($total/6));$page=min((int)$page,$pages);
        $archiveFields=$archiveAvailable?',COALESCE(a.version,0) AS archive_version,COALESCE(a.archived,0) AS archived':',0 AS archive_version,0 AS archived';
        $rows=Db::run($this->db,'SELECT v.id,v.source_id,v.item_version,v.content_hash,v.identity_version,v.created_at,s.original_name,s.bytes,s.sha256,s.profile_json'.$archiveFields.' FROM native_social_videos v JOIN native_social_video_sources s ON s.club_id=v.club_id AND s.id=v.source_id'.$join.' WHERE v.club_id=? AND v.item_id=? AND '.$state.'=? ORDER BY v.created_at DESC,v.id DESC LIMIT 6 OFFSET '.(($page-1)*6),[$club,$itemId,(int)$archived])->fetchAll();
        $this->sameClub($club);
        return ['rows'=>$rows,'page'=>$page,'pages'=>$pages,'total'=>$total,'active_total'=>$activeTotal,'archived_total'=>$archivedTotal,'archived'=>$archived];
    }

    public function file(string $id): array
    {
        Content::id($id);$club=$this->access->require('social','read')['club_id'];$this->schema();
        $row=Db::run($this->db,'SELECT v.id,v.item_id,v.source_id,s.original_name,s.bytes,s.sha256,s.profile_json FROM native_social_videos v JOIN native_social_video_sources s ON s.club_id=v.club_id AND s.id=v.source_id WHERE v.club_id=? AND v.id=?',[$club,$id])->fetch();
        if(!$row)throw new DomainException('Eigene ursprüngliche Videodatei fehlt.');
        if(!VideoRemoval::available($this->db,$club,$row['source_id']))throw new DomainException('Videodatei im Papierkorb oder endgültig gelöscht.');
        Content::id($row['source_id']);(new Workspace($this->db,$this->access,'social'))->get($row['item_id']);
        $path=$this->directory($club,false).'/'.$row['source_id'].'.mp4';
        if(is_link($path)||!is_file($path)||filesize($path)!==(int)$row['bytes']||hash_file('sha256',$path)!==$row['sha256'])throw new DomainException('Ursprüngliche Videobytes sind nicht verfügbar.');
        $this->sameClub($club);
        return ['path'=>$path,'mime'=>'video/mp4','bytes'=>(int)$row['bytes'],'sha256'=>$row['sha256'],'name'=>$row['original_name'],'profile'=>json_decode($row['profile_json'],true,16,JSON_THROW_ON_ERROR)];
    }

    private function sameClub(string $club): void
    { if($this->access->require('social','read')['club_id']!==$club)throw new DomainException('Vereinszugehörigkeit wurde geändert.'); }
    private function schema(): void
    {
        if((int)Db::run($this->db,"SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('native_social_video_sources','native_social_videos')")->fetchColumn()!==2)throw new DomainException('Videomodul über den Installer aktualisieren.');
    }
    private function directory(string $club,bool $create): string
    {
        $directory=$this->root.'/'.hash('sha256',$club);
        foreach([$this->root,$directory]as$path){
            if(is_link($path))throw new DomainException('Privaten Videospeicher prüfen.');
            if(!is_dir($path)&&(!$create||(!@mkdir($path,0700,true)&&!is_dir($path))))throw new DomainException('Privater Videospeicher fehlt.');
            if(is_link($path)||realpath($path)!==$path)throw new DomainException('Privaten Videospeicher prüfen.');
        }
        return $directory;
    }
}
