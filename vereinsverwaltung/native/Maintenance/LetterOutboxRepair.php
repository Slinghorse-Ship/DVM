<?php declare(strict_types=1);
namespace Kulturhus\Native\Maintenance;

use Kulturhus\Native\Shared\Db;
use Kulturhus\Verein\{Documents,DocumentCatalog,Permissions,MySqlBackup};
use PDO;

/** Explicit, original-bound local maintenance; never sends a letter or imports old CMS data. */
final class LetterOutboxRepair
{
    private const MAX_DOCUMENTS=250;
    private const MAX_JOURNAL_BYTES=8388608;
    public function __construct(private PDO $db,private string $actorId,private string $dsn,private string $privateRoot,private ?\Closure $checkpoint=null){}

    public function preview():array
    {
        return Db::atomic($this->db,function():array{
            $actor=$this->authority();$where=$this->selection();$docs=new Documents($this->db,$actor['id']);$rows=[];$total=0;$cursor=null;
            do{
                $arguments=[$actor['club_id']];$window='';
                if($cursor!==null){$window=' AND (d.document_date>? OR (d.document_date=? AND d.id>?))';array_push($arguments,$cursor['document_date'],$cursor['document_date'],$cursor['id']);}
                $batch=Db::run($this->db,'SELECT d.id,d.document_date '.$where.$window.' ORDER BY d.document_date,d.id LIMIT 100',$arguments)->fetchAll();
                foreach($batch as$item){
                    if(!$docs->canEdit($item['id']))continue;
                    $total++;if(count($rows)>=self::MAX_DOCUMENTS)continue;
                    $snapshot=$this->snapshot($item['id'],true);$rows[]=['id'=>$item['id'],'snapshot'=>$snapshot,'target_path'=>'/Orga/'.substr($snapshot['document']['document_date'],0,4).'/Ausgang - Briefe'];
                }
                if($batch)$cursor=$batch[array_key_last($batch)];
            }while(count($batch)===100);
            return ['database'=>$this->databaseIdentity(),'actor'=>$actor['id'],'club'=>$actor['club_id'],'documents'=>$rows,'selected'=>count($rows),'remaining'=>$total-count($rows)];
        });
    }

    public function prepare():array
    {
        $basis=$this->preview();if(!$basis['selected'])return ['status'=>'empty','selected'=>0,'remaining'=>0];
        $directory=$this->directory();$id=bin2hex(random_bytes(16));$file=$directory.'/'.$id.'.json';
        $journal=['format'=>1,'operation'=>$id,'state'=>'prepared','basis'=>$basis,'basis_sha256'=>self::digest($basis),'backup'=>null,'outputs'=>[]];
        $this->writeJournal($file,$journal,true);
        return ['status'=>'prepared','plan'=>$file,'selected'=>$basis['selected'],'remaining'=>$basis['remaining']];
    }

    public function inspect(string $file):array
    {
        $this->authority();$journal=$this->readJournal($file);$this->assertBinding($journal);
        $verified=false;if($journal['backup']!==null){try{$this->verifyBackup($journal);$verified=true;}catch(\DomainException){}}
        return ['status'=>$journal['state'],'selected'=>$journal['basis']['selected'],'remaining'=>$journal['basis']['remaining'],'backup_verified'=>$verified];
    }

    public function apply(string $file):array
    {
        if($this->db->inTransaction())throw new \DomainException('Wartung benötigt eine eigene Verbindung ohne laufenden Vorgang.');
        $this->authority();$this->assertPlanPath($file);$lockPath=$file.'.lock';if(is_link($lockPath))throw new \DomainException('Private Vorgangssperre prüfen.');
        $old=umask(0077);try{$lock=fopen($lockPath,'c+');}finally{umask($old);}if(!$lock||!flock($lock,LOCK_EX|LOCK_NB))throw new \DomainException('Dieser Reparaturvorgang läuft bereits.');
        try{
            $journal=$this->readJournal($file);$this->assertBinding($journal);
            if($journal['state']==='done')return $this->result($journal,true);
            if($journal['state']==='committing'){
                $original=$this->matches($journal['basis']['documents'],'snapshot');$committed=$this->matches($journal['outputs'],'snapshot');
                if($committed&&!$original){$this->verifyBackup($journal);$journal['state']='done';$this->writeJournal($file,$journal);return $this->result($journal,true);}
                if(!$original)throw new \DomainException('Gespeicherte Briefe haben sich geändert. Keine Wiederholung oder Rücknahme ausgeführt.');
            }elseif(!$this->matches($journal['basis']['documents'],'snapshot'))throw new \DomainException('Der geprüfte Ausgangsstand hat sich geändert. Neuen Vorschlag prüfen.');
            if($journal['backup']===null){
                $backup=$this->directory().'/before-'.$journal['operation'].'-'.bin2hex(random_bytes(8)).'.sql';
                MySqlBackup::create($this->db,$this->dsn,$backup);$journal['backup']=['path'=>$backup,'sha256'=>hash_file('sha256',$backup)];$journal['state']='backed_up';$this->writeJournal($file,$journal);
            }
            $this->verifyBackup($journal);$this->point('backup-ready');
            Db::atomic($this->db,function()use(&$journal,$file):void{
                $actor=$this->authority();$docs=new Documents($this->db,$actor['id']);$outputs=[];
                foreach($journal['basis']['documents']as$row){
                    if((int)Db::run($this->db,'SELECT COUNT(*) '.$this->selection().' AND d.id=?',[$actor['club_id'],$row['id']])->fetchColumn()!==1)throw new \DomainException('Ein Brief gehört nicht mehr zum geprüften Vorschlag.');
                    $current=$this->snapshot($row['id'],true);if($current!==$row['snapshot'])throw new \DomainException('Ein Brief wurde inzwischen geändert. Alle Änderungen dieses Versuchs wurden zurückgenommen.');
                    $original=$current['document'];
                    $folder=DocumentCatalog::folder($this->db,$actor['club_id'],['Orga',substr($original['document_date'],0,4),'Ausgang - Briefe'],'orga');
                    $docs->update($row['id'],(int)$original['version'],['title'=>$original['title'],'kind'=>$original['kind'],'document_date'=>$original['document_date'],'folder'=>$folder,'notes'=>$original['notes'],'state'=>$original['state'],'links'=>[],'reason'=>$this->reason($journal['operation'])]);
                    $after=$this->snapshot($row['id'],true);$expected=$original;$expected['folder_id']=$folder;$expected['version']=(int)$original['version']+1;
                    $prefix=$this->history($row['id'],$actor['club_id'],(int)$current['history_last']);$last=Db::run($this->db,'SELECT * FROM document_history WHERE club_id=? AND document_id=? ORDER BY sequence DESC LIMIT 1',[$actor['club_id'],$row['id']])->fetch();$details=json_decode($last['details'],true,32,JSON_THROW_ON_ERROR);
                    if($after['document']!==$expected||$after['files_sha256']!==$current['files_sha256']||$after['links_sha256']!==$current['links_sha256']||self::digest($prefix)!==$current['history_sha256']||$after['history_count']!==$current['history_count']+1||$last['action']!=='metadata'||$last['actor_id']!==$actor['id']||($details['reason']??null)!==$this->reason($journal['operation'])||$docs->document($row['id'])['path']!==$row['target_path'])throw new \DomainException('Die Verschiebung würde ursprüngliche Angaben, Rechte, Dateien oder Historie ändern. Alle Änderungen dieses Versuchs wurden zurückgenommen.');
                    $outputs[]=['id'=>$row['id'],'snapshot'=>$after];
                }
                $journal['outputs']=$outputs;$journal['state']='committing';$this->writeJournal($file,$journal);$this->point('before-commit');
            });
            $this->point('after-commit');$journal['state']='done';$this->writeJournal($file,$journal);$this->point('done');return $this->result($journal,false);
        }finally{flock($lock,LOCK_UN);fclose($lock);}
    }

    private function authority():array
    {
        if($this->db->getAttribute(PDO::ATTR_DRIVER_NAME)!=='mysql')throw new \DomainException('Eigene native MySQL-Verbindung erforderlich.');
        $actor=Permissions::actor($this->db,$this->actorId);if(!(new Documents($this->db,$this->actorId))->canScope('orga',true))throw new \DomainException('Schreibberechtigung für Organisationsdokumente erforderlich.');return $actor;
    }
    private function databaseIdentity():string{return hash('sha256',(string)$this->db->query('SELECT @@server_uuid')->fetchColumn()."\0".(string)$this->db->query('SELECT DATABASE()')->fetchColumn());}
    private function selection():string
    {
        return "FROM documents d JOIN document_folders p ON p.club_id=d.club_id AND p.id=d.folder_id JOIN document_files f ON f.club_id=d.club_id AND f.document_id=d.id AND f.revision=(SELECT MAX(v.revision) FROM document_files v WHERE v.club_id=d.club_id AND v.document_id=d.id) WHERE d.club_id=? AND d.origin='generated' AND d.kind='other' AND p.name='Eingang - noch zuordnen' AND p.scope='orga' AND p.system_key LIKE 'auto:%' AND CASE WHEN JSON_VALID(f.snapshot) THEN JSON_UNQUOTE(JSON_EXTRACT(f.snapshot,'$.kind')) ELSE NULL END='letter' AND NOT EXISTS(SELECT 1 FROM document_links l WHERE l.club_id=d.club_id AND l.document_id=d.id) AND COALESCE((SELECT h.action FROM document_history h WHERE h.club_id=d.club_id AND h.document_id=d.id AND h.action IN ('trashed','trash-restored') ORDER BY h.sequence DESC LIMIT 1),'')<>'trashed'";
    }
    private function snapshot(string $id,bool $write=false):array
    {
        $actor=$this->authority();$view=(new Documents($this->db,$actor['id']))->document($id,$write);
        $document=Db::run($this->db,'SELECT * FROM documents WHERE club_id=? AND id=?'.($write&&$this->db->inTransaction()?' FOR UPDATE':''),[$actor['club_id'],$id])->fetch();
        $files=Db::run($this->db,'SELECT revision,name,mime,sha256,created_at,created_by,OCTET_LENGTH(bytes) AS bytes,SHA2(bytes,256) AS content_sha256,SHA2(snapshot,256) AS snapshot_sha256 FROM document_files WHERE club_id=? AND document_id=? ORDER BY revision',[$actor['club_id'],$id])->fetchAll();
        $history=$this->history($id,$actor['club_id']);
        return ['document'=>$document,'files_sha256'=>self::digest($files),'history_sha256'=>self::digest($history),'history_count'=>count($history),'history_last'=>$history?(int)end($history)['sequence']:0,'links_sha256'=>self::digest($view['links'])];
    }
    private function history(string $id,string $club,?int $through=null):array{return Db::run($this->db,'SELECT * FROM document_history WHERE club_id=? AND document_id=?'.($through!==null?' AND sequence<=?':'').' ORDER BY sequence',$through===null?[$club,$id]:[$club,$id,$through])->fetchAll();}
    private function matches(array $rows,string $key):bool
    {
        if(!$rows)return false;foreach($rows as$row){try{$snapshot=$this->snapshot($row['id']);}catch(\DomainException){return false;}if($snapshot!==$row[$key])return false;}return true;
    }
    private function assertBinding(array $journal):void
    {
        $actor=$this->authority();if(($journal['basis']['database']??null)!==$this->databaseIdentity()||($journal['basis']['club']??null)!==$actor['club_id']||($journal['basis']['actor']??null)!==$actor['id'])throw new \DomainException('Dieser geprüfte Vorschlag gehört zu einer anderen Datenbank oder einem anderen Zugang.');
    }
    private static function digest(array $value):string{return hash('sha256',json_encode($value,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));}
    private function reason(string $id):string{return 'Erzeugten Brief der Ausgangsablage zugeordnet. Vorgang '.$id;}
    private function point(string $stage):void{if($this->checkpoint)($this->checkpoint)($stage);}
    private function result(array $journal,bool $replayed):array{return ['status'=>'done','selected'=>$journal['basis']['selected'],'remaining'=>$journal['basis']['remaining'],'replayed'=>$replayed,'backup'=>$journal['backup']['path']];}

    private function directory(bool $create=true):string
    {
        $root=rtrim($this->privateRoot,'/');if($root===''||realpath($root)!==$root||is_link($root)||!is_dir($root)||(fileperms($root)&0077))throw new \DomainException('Vorhandenes privates Betriebsverzeichnis mit Modus 0700 erforderlich.');
        foreach([dirname(__DIR__,2).'/public',dirname(__DIR__,4).'/web']as$public){$resolved=realpath($public);if($resolved&&($root===$resolved||str_starts_with($root,$resolved.'/')))throw new \DomainException('Wartungsnachweise müssen außerhalb des öffentlichen Webroots liegen.');}
        foreach(['/maintenance','/maintenance/letter-outbox']as$relative){$path=$root.$relative;if(is_link($path)||file_exists($path)&&!is_dir($path))throw new \DomainException('Privater Wartungspfad ist belegt.');if(!is_dir($path)&&(!$create||!mkdir($path,0700)))throw new \DomainException('Privater Wartungspfad fehlt oder konnte nicht angelegt werden.');if(realpath($path)!==$path||(fileperms($path)&0077))throw new \DomainException('Privaten Wartungspfad prüfen.');}
        return $root.'/maintenance/letter-outbox';
    }
    private function assertPlanPath(string $file):void
    {
        if(dirname($file)!==$this->directory(false)||!preg_match('/\A[a-f0-9]{32}\.json\z/D',basename($file))||is_link($file))throw new \DomainException('Eigenen privaten Reparaturvorschlag auswählen.');
    }
    private function readJournal(string $file):array
    {
        $this->assertPlanPath($file);if(!is_file($file)||(fileperms($file)&0077)||filesize($file)>self::MAX_JOURNAL_BYTES)throw new \DomainException('Privater geprüfter Reparaturvorschlag fehlt.');
        try{$j=json_decode(file_get_contents($file),true,64,JSON_THROW_ON_ERROR);}catch(\JsonException){throw new \DomainException('Gespeicherten Reparaturvorschlag prüfen.');}
        if(($j['format']??null)!==1||($j['operation']??null)!==basename($file,'.json')||!in_array($j['state']??null,['prepared','backed_up','committing','done'],true)||!is_array($j['basis']??null)||($j['basis_sha256']??null)!==self::digest($j['basis'])||!is_array($j['basis']['documents']??null)||count($j['basis']['documents'])<1||count($j['basis']['documents'])>self::MAX_DOCUMENTS||$j['basis']['selected']!==count($j['basis']['documents'])||!is_array($j['outputs']??null))throw new \DomainException('Gespeicherten Reparaturvorschlag prüfen.');
        $ids=array_column($j['basis']['documents'],'id');
        if(count($ids)!==count(array_unique($ids))||count($ids)!==$j['basis']['selected'])throw new \DomainException('Doppelte oder fehlende Briefe im gespeicherten Vorschlag.');
        foreach($j['basis']['documents']as$row)if(!is_string($row['id']??null)||!preg_match('/\A[a-f0-9]{32}\z/D',$row['id'])||!is_array($row['snapshot']??null)||!is_string($row['target_path']??null))throw new \DomainException('Ursprünglichen Briefvorschlag prüfen.');
        if(in_array($j['state'],['committing','done'],true)&&array_column($j['outputs'],'id')!==$ids)throw new \DomainException('Vollständigen gespeicherten Abschlussstand prüfen.');
        if($j['state']!=='prepared'&&!is_array($j['backup']??null))throw new \DomainException('Ursprünglicher Sicherungsnachweis fehlt.');
        foreach($j['outputs']as$row)if(!is_array($row['snapshot']??null))throw new \DomainException('Gespeicherten Abschlussstand prüfen.');
        if(!is_int($j['basis']['remaining']??null)||$j['basis']['remaining']<0)throw new \DomainException('Gespeicherten restlichen Umfang prüfen.');
        return $j;
    }
    private function verifyBackup(array $journal):void
    {
        $b=$journal['backup'];if(!is_array($b)||dirname($b['path']??'')!==$this->directory(false)||!preg_match('/\Abefore-'.preg_quote($journal['operation'],'/').'-[a-f0-9]{16}\.sql\z/D',basename($b['path']??''))||is_link($b['path'])||!is_file($b['path'])||(fileperms($b['path'])&0077)||!is_string($b['sha256']??null)||!hash_equals($b['sha256'],hash_file('sha256',$b['path'])))throw new \DomainException('Ursprüngliche native Sicherung fehlt oder wurde geändert. Keine Verschiebung ausgeführt.');
    }
    private function writeJournal(string $file,array $journal,bool $new=false):void
    {
        $this->assertPlanPath($file);$bytes=json_encode($journal,JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE).PHP_EOL;if(strlen($bytes)>self::MAX_JOURNAL_BYTES)throw new \DomainException('Reparaturvorschlag ist zu groß.');
        $temporary=$file.'.partial-'.bin2hex(random_bytes(8));$old=umask(0077);try{$stream=fopen($temporary,'xb');}finally{umask($old);}if(!$stream)throw new \DomainException('Vorgangsstand konnte nicht gespeichert werden.');
        try{for($offset=0;$offset<strlen($bytes);$offset+=$written){$written=fwrite($stream,substr($bytes,$offset));if(!$written)throw new \RuntimeException('Privater Vorgangsstand konnte nicht vollständig gespeichert werden.');}if(!fflush($stream)||(function_exists('fsync')&&!fsync($stream)))throw new \RuntimeException('Privater Vorgangsstand konnte nicht abgeschlossen werden.');fclose($stream);$stream=null;if($new?!link($temporary,$file):!rename($temporary,$file))throw new \RuntimeException('Vorgangsstand konnte nicht bereitgestellt werden.');}
        finally{if(is_resource($stream))fclose($stream);if(is_file($temporary))unlink($temporary);}
    }
}
