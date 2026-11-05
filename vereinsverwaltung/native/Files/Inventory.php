<?php declare(strict_types=1);
namespace Kulturhus\Native\Files;

use DomainException;
use InvalidArgumentException;
use PDO;
use Kulturhus\Native\Cms\{ClubPublicationLock,Conflict,UploadTransaction};
use Kulturhus\Native\Shared\{Access,Db};

/** Observe aged own unassigned uploads and explicitly copy a chosen original; never auto-delete it. */
final class Inventory
{
    use ClubPublicationLock;
    public const GRACE_SECONDS=3600;
    private string $root;
    public function __construct(private PDO $db,private Access $access,string $root)
    {new BlobStore($root);$this->root=realpath($root);}
    public function listing(string $after=''):array
    {
        if($after!=='')AssetVersions::identifier($after);$club=$this->access->require('website','manage')['club_id'];$owner=$this->owner($club);$candidates=[];$complete=true;$scanned=0;$shards=[];
        $batch=[];$flush=function()use(&$batch,&$candidates,&$complete,$club):void{
            if($batch===[])return;$marks=implode(',',array_fill(0,count($batch),'?'));$known=Db::run($this->db,'SELECT id FROM khcms_file_blobs WHERE club_id=? AND id IN ('.$marks.') UNION ALL SELECT source_id AS id FROM khcms_file_recoveries WHERE club_id=? AND source_id IN ('.$marks.')',[$club,...$batch,$club,...$batch])->fetchAll(PDO::FETCH_COLUMN);
            foreach(array_diff($batch,$known)as$id)try{$descriptor=$this->inspect($club,$id,false);if($descriptor['recent'])continue;unset($descriptor['path'],$descriptor['recent']);$candidates[$id]=$descriptor;ksort($candidates,SORT_STRING);if(count($candidates)>11)array_pop($candidates);}catch(DomainException){$complete=false;}$batch=[];
        };
        if(is_dir($owner))foreach(new \FilesystemIterator($owner,\FilesystemIterator::SKIP_DOTS)as$entry){
            $prefix=$entry->getFilename();if(!preg_match('/\A[a-f0-9]{2}\z/D',$prefix)||$entry->isLink()||!$entry->isDir()||realpath($entry->getPathname())!==$entry->getPathname()){$complete=false;continue;}
            if($after!==''&&strcmp($prefix.str_repeat('f',30),$after)<=0)continue;$shards[$prefix]=$entry->getPathname();
        }
        ksort($shards,SORT_STRING);$lastPrefix='';
        foreach($shards as$prefix=>$path){
            $prefix=(string)$prefix;
            // Finish each ordered shard. A cursor can then advance past ALL its unordered directory entries.
            foreach(new \FilesystemIterator($path,\FilesystemIterator::SKIP_DOTS)as$entry){
                $scanned++;$id=$entry->getFilename();if(!preg_match('/\A[a-f0-9]{32}\z/D',$id)||substr($id,0,2)!==$prefix||$entry->isLink()||!$entry->isDir()){$complete=false;continue;}
                if($after!==''&&strcmp($id,$after)<=0)continue;$batch[]=$id;if(count($batch)===64)$flush();
            }
            $flush();$lastPrefix=(string)$prefix;if(count($candidates)>10||$scanned>=4096)break;
        }
        $moreShards=$lastPrefix!==''&&strcmp((string)array_key_last($shards),$lastPrefix)>0;
        $rows=[];foreach($candidates as$id=>$row){if(!$this->known($id,$club))$rows[]=$row;if(count($rows)===11)break;}$extra=count($candidates)>10;$rows=array_slice($rows,0,10);$hasMore=$extra||$moreShards;
        $next=$extra?($rows!==[]?$rows[array_key_last($rows)]['id']:(string)array_key_last($candidates)):($moreShards?$lastPrefix.str_repeat('f',30):'');
        return ['rows'=>$rows,'has_more'=>$hasMore,'next_after'=>$next,'after'=>$after,'complete'=>$complete,'scanned'=>$scanned];
    }
    public function prepare(string $id):array
    {
        AssetVersions::identifier($id);$club=$this->access->require('website','manage')['club_id'];
        $accepted=Db::run($this->db,'SELECT asset_id,source_sha256 FROM khcms_file_recoveries WHERE club_id=? AND source_id=?',[$club,$id])->fetch();
        if($accepted){$source=$this->inspect($club,$id,true);if(!hash_equals($accepted['source_sha256'],$source['sha256']))throw new Conflict('Frühere Wiederherstellungsquelle wurde verändert.');$asset=(new AssetVersions($this->db,$this->access,$this->root))->get($accepted['asset_id'],true);return ['id'=>$id,'accepted'=>true,'asset_id'=>$asset['id'],'title'=>$asset['metadata']['title']];}
        if($this->known($id,$club))throw new Conflict('Dateifassung wurde bereits angenommen. Aktuelle Dateien öffnen.');
        $row=$this->inspect($club,$id,true);if($row['recent'])throw new DomainException('Neue Uploaddatei wird noch verarbeitet. Später erneut prüfen.');unset($row['path'],$row['recent']);return $row;
    }
    public function recover(string $id,string $fingerprint,string $name):array
    {
        AssetVersions::identifier($id);if(!preg_match('/\A[a-f0-9]{64}\z/D',$fingerprint))throw new InvalidArgumentException('Ursprüngliche Dateigruppe erneut prüfen.');
        return UploadTransaction::run($this->db,function($transaction)use($id,$fingerprint,$name):array{
            $actor=$this->access->require('website','manage');$this->access->require('website','edit');$club=$actor['club_id'];$this->lockClub();
            $original=$this->inspect($club,$id,true);if($original['recent']||!hash_equals($fingerprint,$original['fingerprint']))throw new Conflict('Ursprüngliche Dateigruppe wurde verändert. Neu prüfen.');
            $accepted=Db::run($this->db,'SELECT source_sha256,asset_id FROM khcms_file_recoveries WHERE club_id=? AND source_id=? FOR UPDATE',[$club,$id])->fetch();
            $versions=new AssetVersions($this->db,$this->access,$this->root);
            if($accepted){if(!hash_equals($accepted['source_sha256'],$original['sha256']))throw new Conflict('Frühere Wiederherstellungsquelle wurde verändert. Neu prüfen.');return ['created'=>false,'asset'=>$versions->get($accepted['asset_id'],true)];}
            if($this->known($id,$club))throw new Conflict('Dateifassung wurde bereits angenommen. Aktuellen Stand prüfen.');
            $created=$versions->uploadMany([['path'=>$original['path'],'name'=>$name]],null,$transaction)[0];
            if(!hash_equals($original['sha256'],$this->inspect($club,$id,true)['sha256']))throw new Conflict('Ursprüngliche Dateigruppe wurde während der Wiederherstellung verändert.');
            Db::run($this->db,'INSERT INTO khcms_file_recoveries VALUES(?,?,?,?,?,UTC_TIMESTAMP(6))',[$club,$id,$original['sha256'],$created['id'],$actor['id']]);return ['created'=>true,'asset'=>$created];
        });
    }
    private function inspect(string $club,string $id,bool $hash):array
    {
        $shard=$this->owner($club).'/'.substr($id,0,2);if(is_link($shard)||!is_dir($shard)||realpath($shard)!==$shard)throw new DomainException('Unveränderten privaten Dateispeicher prüfen.');$directory=$shard.'/'.$id;$path=$directory.'/blob.bin';
        if(is_link($directory)||!is_dir($directory)||realpath($directory)!==$directory||is_link($path)||!is_file($path)||!is_readable($path)||realpath($path)!==$path)throw new DomainException('Unveränderte private Dateigruppe prüfen.');
        $iterator=new \FilesystemIterator($directory,\FilesystemIterator::SKIP_DOTS);$count=0;foreach($iterator as$entry)if(++$count>1||$entry->getFilename()!=='blob.bin'||$entry->isLink())throw new DomainException('Dateigruppe enthält unbekannte Daten.');
        $bytes=filesize($path);$changed=max(filemtime($directory),filemtime($path));if(!is_int($bytes)||$bytes<1||$bytes>Formats::MAX_BYTES||!is_int($changed))throw new DomainException('Dateigröße prüfen.');
        $row=['id'=>$id,'bytes'=>$bytes,'changed_at'=>gmdate('Y-m-d H:i:s',$changed),'recent'=>$changed>time()-self::GRACE_SECONDS,'path'=>$path];
        if($hash){$row['sha256']=hash_file('sha256',$path);$row['fingerprint']=hash('sha256',$club."\0".$id."\0".$bytes."\0".$row['sha256']);}return $row;
    }
    private function owner(string $club):string
    {$owner=$this->root.'/'.hash('sha256',$club);if(is_link($owner)||(file_exists($owner)&&(!is_dir($owner)||realpath($owner)!==$owner)))throw new DomainException('Privaten Vereinsspeicher prüfen.');return $owner;}
    private function known(string $id,string $club):bool
    {return(bool)Db::run($this->db,'SELECT 1 FROM khcms_file_blobs WHERE id=? AND club_id=? UNION ALL SELECT 1 FROM khcms_file_recoveries WHERE source_id=? AND club_id=?',[$id,$club,$id,$club])->fetchColumn();}
}
