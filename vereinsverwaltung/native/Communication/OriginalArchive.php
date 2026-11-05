<?php declare(strict_types=1);
namespace Kulturhus\Native\Communication;

use PDO;
use DomainException;
use Kulturhus\Native\Shared\{Access,Db,SecretBox};

/** Retain exact native rows before destructive provider changes; never a send source. */
final class OriginalArchive
{
    private const TABLES=['attention'=>'native_social_attention','object'=>'native_social_objects'];
    public function __construct(private PDO $db,private SecretBox $box){}

    /** MySQL JSON may reorder object keys; list order and exact value types matter. */
    public static function sameData(array $a,array $b):bool
    {
        $ordered=static function(array $value)use(&$ordered):array{foreach($value as$key=>$item)if(is_array($item))$value[$key]=$ordered($item);if(!array_is_list($value))ksort($value);return $value;};
        return $ordered($a)===$ordered($b);
    }

    private static function table(string $type):string
    {
        return self::TABLES[$type]??throw new DomainException('Originalart prüfen.');
    }
    private static function packed(array $row):array
    {
        $binary=[];
        foreach($row as$key=>$value)if(is_string($value)&&($key==='thumbnail_blob'||!mb_check_encoding($value,'UTF-8'))){$row[$key]=base64_encode($value);$binary[]=$key;}
        ksort($row);sort($binary);return ['row'=>$row,'binary'=>$binary];
    }

    private function sourceContext(string $club,string $type,array $row):array
    {
        if($type!=='attention')return [];
        $binding=null;
        if($row['kind']==='message'){
            $thread=MessageReplyProvider::threadId($club,$row['channel'],$row['parent_id']);
            $bytes=Db::run($this->db,'SELECT ciphertext FROM native_social_inbox WHERE club_id=? AND id=?',[$club,$thread])->fetchColumn();
            if(is_string($bytes))$binding=$this->box->open('social-inbox:'.$club.':'.$thread,$bytes)['connection_id']??null;
        }else{
            $payload=Db::run($this->db,'SELECT payload_json FROM native_social_objects WHERE club_id=? AND channel=? AND remote_id=?',[$club,$row['channel'],$row['parent_id']])->fetchColumn();
            if(is_string($payload))$binding=json_decode($payload,true,64,JSON_THROW_ON_ERROR)['connection_id']??null;
        }
        return is_string($binding)&&preg_match('/\A[a-f0-9]{32,64}\z/D',$binding)?['connection_id'=>$binding]:[];
    }

    /** Internal call in the same transaction as the original mutation. */
    public function retain(string $club,string $type,array $row,string $reason):string
    {
        self::table($type);
        if(!$this->db->inTransaction()||($row['club_id']??null)!==$club||!in_array($reason,['changed','removed','expired','disconnected','reviewed'],true))throw new DomainException('Originalerhalt benötigt den gebundenen Änderungsvorgang.');
        Content::id($row['id']??'');$packed=self::packed($row);$context=$this->sourceContext($club,$type,$row);if($context)$packed['source_context']=$context;$hash=$this->box->fingerprint($type.':'.json_encode($packed,JSON_THROW_ON_ERROR));$id=substr(hash('sha256',$club.':'.$type.':'.$row['id'].':'.$hash),0,32);
        $sealed=$this->box->seal('social-original:'.$club.':'.$id,['source_type'=>$type]+$packed);
        Db::run($this->db,'INSERT INTO native_social_originals(club_id,id,source_type,source_id,channel,kind,parent_id,snapshot_hash,ciphertext,reason,archived_at) VALUES(?,?,?,?,?,?,?,?,?,?,UTC_TIMESTAMP(6)) ON DUPLICATE KEY UPDATE id=id',[$club,$id,$type,$row['id'],$row['channel'],$row['kind'],$row['parent_id']??$row['remote_id'],$hash,$sealed,$reason]);
        $stored=Db::run($this->db,'SELECT source_type,source_id,snapshot_hash FROM native_social_originals WHERE club_id=? AND id=?',[$club,$id])->fetch();
        if(!$stored||$stored['source_type']!==$type||$stored['source_id']!==$row['id']||!hash_equals($stored['snapshot_hash'],$hash))throw new DomainException('Originalarchiv konnte nicht eindeutig bestätigt werden.');
        return $id;
    }

    /** Trusted internal SQL selection; club binding cannot be widened by the clause. */
    public function retainWhere(string $club,string $type,string $clause,array $parameters,string $reason):void
    {
        $table=self::table($type);$last='';
        if(!$this->db->inTransaction())throw new DomainException('Originalerhalt benötigt den Änderungsvorgang.');
        do{
            $ids=Db::run($this->db,'SELECT id FROM '.$table.' WHERE club_id=? AND ('.$clause.') AND id>? ORDER BY id LIMIT 100 FOR UPDATE',[$club,...$parameters,$last])->fetchAll(PDO::FETCH_COLUMN);
            foreach($ids as$id){
                $row=Db::run($this->db,'SELECT * FROM '.$table.' WHERE club_id=? AND id=? FOR UPDATE',[$club,$id])->fetch();
                if(!$row)throw new DomainException('Original inzwischen geändert.');
                $this->retain($club,$type,$row,$reason);$last=$id;
            }
        }while(count($ids)===100);
    }

    public function get(Access $access,string $id):array
    {
        $access->require('social','manage');Content::id($id);
        $archive=Db::run($this->db,'SELECT * FROM native_social_originals WHERE club_id=? AND id=?',[$access->club(),$id])->fetch();
        if(!$archive)throw new DomainException('Ursprüngliche Fassung nicht verfügbar.');
        $data=$this->box->open('social-original:'.$access->club().':'.$id,$archive['ciphertext']);$row=$data['row'];$type=$data['source_type'];self::table($type);
        $packed=['row'=>$row,'binary'=>$data['binary']];if(isset($data['source_context']))$packed['source_context']=$data['source_context'];$hash=$this->box->fingerprint($type.':'.json_encode($packed,JSON_THROW_ON_ERROR));
        if($type!==$archive['source_type']||($row['club_id']??null)!==$access->club()||($row['id']??null)!==$archive['source_id']||($row['channel']??null)!==$archive['channel']||($row['kind']??null)!==$archive['kind']||($row['parent_id']??$row['remote_id']??null)!==$archive['parent_id']||!hash_equals($hash,$archive['snapshot_hash']))throw new DomainException('Ursprüngliche Fassung gehört nicht zum gewählten Bezug.');
        foreach($data['binary']as$key){$bytes=base64_decode($row[$key],true);if($bytes===false)throw new DomainException('Ursprüngliche Datei beschädigt.');$row[$key]=$bytes;}
        unset($archive['ciphertext']);return $archive+['original'=>$row,'source_context'=>$data['source_context']??[]];
    }

    public function history(Access $access,string $type,string $source,mixed $page=1):array
    {
        $access->require('social','manage');self::table($type);Content::id($source);
        $page=filter_var($page,FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>1000000]]);if($page===false)throw new DomainException('Originalseite prüfen.');
        return Db::atomic($this->db,function()use($access,$type,$source,$page):array{
            $access->require('social','manage');
            $args=[$access->club(),$type,$source];$where='club_id=? AND source_type=? AND source_id=?';$total=(int)Db::run($this->db,'SELECT COUNT(*) FROM native_social_originals WHERE '.$where,$args)->fetchColumn();$pages=max(1,(int)ceil($total/25));if($page>$pages)throw new DomainException('Originalseite nicht verfügbar.');
            $rows=Db::run($this->db,'SELECT id,reason,archived_at FROM native_social_originals WHERE '.$where.' ORDER BY archived_at DESC,id DESC LIMIT 25 OFFSET '.(($page-1)*25),$args)->fetchAll();
            return ['rows'=>$rows,'total'=>$total,'page'=>$page,'pages'=>$pages];
        });
    }
}
