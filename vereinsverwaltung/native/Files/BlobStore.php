<?php declare(strict_types=1);
namespace Kulturhus\Native\Files;

use DomainException;
use InvalidArgumentException;
use RuntimeException;
use Kulturhus\Native\Cms\UploadTransaction;
use PDO;

/** Private write-once local bytes. Names, extensions and POST paths never select a storage path. */
final class BlobStore
{
    private string $root;
    public function __construct(string $root)
    {
        if(preg_match('~^[a-z][a-z0-9+.-]*://~i',$root)||is_link($root))throw new InvalidArgumentException('Lokalen privaten Dateispeicher auswählen.');
        if(!is_dir($root)&&!mkdir($root,0700,true))throw new RuntimeException('Dateispeicher nicht verfügbar.');
        $resolved=realpath($root);if($resolved===false)throw new RuntimeException('Dateispeicher nicht verfügbar.');
        foreach([dirname(__DIR__,2).'/public',$_SERVER['DOCUMENT_ROOT']??'']as$webroot){$web=$webroot!==''?realpath($webroot):false;if($web!==false&&($resolved===$web||str_starts_with($resolved,$web.DIRECTORY_SEPARATOR)))throw new InvalidArgumentException('Dateispeicher außerhalb des Webroots anlegen.');}
        $this->root=$resolved;
    }
    public function write(PDO $db,UploadTransaction $transaction,string $club,string $id,string $source,array $descriptor):void
    {
        $transaction->require($db);$directory=$this->directory($club,$id,true);$path=$directory.'/blob.bin';
        $out=fopen($path,'x+b');if(!$out)throw new RuntimeException('Neue Dateifassung konnte nicht angelegt werden.');
        $transaction->onRollback($db,static function()use($path,$directory):void{if(!is_link($path)&&is_file($path))@unlink($path);if(!is_link($directory)&&is_dir($directory))@rmdir($directory);});
        try{
            if(!chmod($path,0600))throw new RuntimeException('Privaten Dateispeicher prüfen.');
            $in=fopen($source,'rb');if(!$in)throw new RuntimeException('Uploaddatei nicht lesbar.');
            try{$written=stream_copy_to_stream($in,$out,Formats::MAX_BYTES+1);}finally{fclose($in);}
        }finally{fclose($out);}
        if($written!==$descriptor['bytes']||!hash_equals($descriptor['sha256'],hash_file('sha256',$path))||!hash_equals($descriptor['sha256'],hash_file('sha256',$source)))throw new RuntimeException('Uploaddatei wurde während der Speicherung verändert.');
        if(Formats::inspect($path,$descriptor['name'])!==$descriptor)throw new RuntimeException('Dateiformat wurde während der Speicherung verändert.');
    }
    public function file(string $club,string $id,array $descriptor):array
    {
        $path=$this->directory($club,$id,false).'/blob.bin';clearstatcache(true,$path);
        if(is_link($path)||!is_file($path)||realpath($path)!==$path||filesize($path)!==(int)$descriptor['bytes']||!hash_equals($descriptor['sha256'],hash_file('sha256',$path)))throw new DomainException('Ursprüngliche Dateifassung nicht verfügbar.');
        return ['path'=>$path,'name'=>$descriptor['original_name']??$descriptor['name'],'mime'=>$descriptor['mime'],'bytes'=>(int)$descriptor['bytes'],'sha256'=>$descriptor['sha256']];
    }
    /** Only an already committed purge plan may allow an absent own blob. */
    public function purge(string $club,string $id,array $descriptor):void
    {
        $directory=$this->directory($club,$id,false,true);$path=$directory.'/blob.bin';
        if(!file_exists($path)&&!is_link($path)){if(is_dir($directory)&&!is_link($directory)){if(iterator_count(new \FilesystemIterator($directory,\FilesystemIterator::SKIP_DOTS))!==0)throw new DomainException('Dateifassung enthält unbekannte Daten.');if(!rmdir($directory))throw new RuntimeException('Dateibereinigung unterbrochen.');}return;}
        $this->file($club,$id,$descriptor);
        if(iterator_count(new \FilesystemIterator($directory,\FilesystemIterator::SKIP_DOTS))!==1)throw new DomainException('Dateifassung enthält unbekannte Daten.');
        if(!unlink($path)||!rmdir($directory))throw new RuntimeException('Dateibereinigung unterbrochen.');
    }
    private function directory(string $club,string $id,bool $create,bool $missing=false):string
    {
        if(!preg_match('/\A[a-f0-9]{32}\z/D',$id)||$club==='')throw new InvalidArgumentException('Dateifassung prüfen.');
        $owner=$this->root.'/'.hash('sha256',$club);$shard=$owner.'/'.substr($id,0,2);$directory=$shard.'/'.$id;
        foreach([$owner,$shard,$directory]as$part){
            if($create&&!file_exists($part)&&!is_link($part)&&!mkdir($part,0700))throw new RuntimeException('Dateispeicher konnte nicht angelegt werden.');
            if(is_link($part)||(file_exists($part)&&(!is_dir($part)||realpath($part)!==$part))||(!$create&&!$missing&&!is_dir($part)))throw new DomainException('Unveränderten privaten Dateispeicher prüfen.');
        }
        return $directory;
    }
}
