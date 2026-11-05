<?php declare(strict_types=1);
namespace Kulturhus\Verein;
use PDO;
use DomainException;

/** Narrow access to the existing private signature; no general settings permission implied. */
final class BoardSignature
{
    public function __construct(private PDO $db,private string $actor) {}
    private function read(callable $operation):mixed
    {
        $own=!$this->db->inTransaction();
        try{if($own)$this->db->exec('START TRANSACTION');$result=$operation();if($own)$this->db->commit();return$result;}
        catch(\Throwable$failure){if($own&&$this->db->inTransaction())$this->db->rollBack();self::busy($failure);throw$failure;}
    }
    private static function busy(\Throwable$failure):void
    {
        if($failure instanceof \PDOException&&in_array((int)($failure->errorInfo[1]??0),[1205,3572],true))throw new DomainException('Briefpapier oder Unterschrift wird gerade bearbeitet. Bitte erneut prüfen.',503,$failure);
    }
    public function canUse():bool
    {
        try{$this->read(fn():array=>$this->authorize());return true;}catch(DomainException){return false;}
    }
    private function authorize():array
    {
        $a=Permissions::actor($this->db,$this->actor);
        if($a['role']!=='editor'&&(!empty($a['is_superuser'])||DocumentCatalog::query($this->db,"SELECT 1 FROM admission_board WHERE club_id=? AND actor_id=? AND enabled=1 AND office='1. Vorstand'".($this->db->inTransaction()?' FOR SHARE NOWAIT':''),[$a['club_id'],$a['id']])->fetchColumn()))return$a;
        throw new DomainException('Die Unterschrift kann nur der erste Vorstand oder ein Superuser verwalten und verwenden.',403);
    }
    public function state():array
    {
        return$this->read(function():array{
            $a=$this->authorize();$r=DocumentCatalog::query($this->db,"SELECT payload,version FROM club_settings WHERE club_id=? AND section='paper' FOR SHARE NOWAIT",[$a['club_id']])->fetch();
            return ['data'=>$r?json_decode($r['payload'],true,512,JSON_THROW_ON_ERROR):SettingsSchema::defaults('paper'),'version'=>(int)($r['version']??0)];
        });
    }
    public function asset():?array
    {
        return$this->read(function():?array{
            $a=$this->authorize();$id=$this->state()['data']['signature']??'';if($id==='')return null;
            $r=DocumentCatalog::query($this->db,'SELECT id,mime,bytes,sha256 FROM settings_assets WHERE club_id=? AND id=? FOR SHARE NOWAIT',[$a['club_id'],$id])->fetch();
            if(!$r||!hash_equals($r['sha256'],hash('sha256',$r['bytes'])))throw new DomainException('Die hinterlegte Unterschrift ist nicht verfügbar. Bitte neu hochladen.');
            return$r;
        });
    }
    public function save(string $bytes,int $version,bool $reviewed):void
    {
        $this->authorize();if(!$reviewed)throw new DomainException('Bitte die Unterschrift prüfen und ihre Verwendung bestätigen.');
        if(strlen($bytes)>2097152)throw new DomainException('Unterschrift als PNG oder JPG bis 2 MB auswählen.');
        $info=@getimagesizefromstring($bytes);
        if(!$info||!in_array($info['mime'],['image/png','image/jpeg'],true)||min($info[0],$info[1])<10||max($info[0],$info[1])>4000||$info[0]*$info[1]>8000000)throw new DomainException('PNG/JPG mit 10 bis 4.000 Pixeln und höchstens 8 Megapixeln verwenden.');
        $image=@imagecreatefromstring($bytes);if(!$image)throw new DomainException('Das Bild konnte nicht gelesen werden.');
        try{imagesavealpha($image,true);ob_start();imagepng($image,null,6);$clean=(string)ob_get_clean();}finally{unset($image);}
        if(strlen($clean)>2097152)throw new DomainException('Bitte ein kleineres Unterschriftenbild verwenden.');
        $club=$this->read(fn():array=>$this->authorize())['club_id'];
        try{\Kulturhus\Verein\Infrastructure\Database\Transactions::run($this->db,function()use($club,$version,$clean):void{
            if(!DocumentCatalog::query($this->db,'SELECT id FROM clubs WHERE id=? FOR UPDATE NOWAIT',[$club])->fetchColumn())throw new DomainException('Eigenen aktiven Verein auswählen.',403);
            $a=$this->authorize();if($a['club_id']!==$club)throw new DomainException('Vereinszuordnung inzwischen geändert.',403);
            DocumentCatalog::query($this->db,"SELECT section FROM club_settings WHERE club_id=? AND section='paper' FOR UPDATE NOWAIT",[$club])->fetch();$old=$this->state();if($old['version']!==$version)throw new DomainException('Das Briefpapier oder die Unterschrift wurde inzwischen geändert. Bitte neu laden und erneut prüfen.',409);
            $id=bin2hex(random_bytes(16));$q=$this->db->prepare('INSERT INTO settings_assets(id,club_id,mime,bytes,sha256) VALUES(?,?,?,?,?)');
            $q->bindValue(1,$id);$q->bindValue(2,$a['club_id']);$q->bindValue(3,'image/png');$q->bindValue(4,$clean,PDO::PARAM_LOB);$q->bindValue(5,hash('sha256',$clean));$q->execute();
            $data=$old['data'];$data['signature']=$id;$json=json_encode($data,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);$time=gmdate('c');
            $this->db->prepare("INSERT INTO club_settings VALUES(?,'paper',?,?,?) ON DUPLICATE KEY UPDATE payload=VALUES(payload),version=VALUES(version),updated_at=VALUES(updated_at)")->execute([$a['club_id'],$json,$version+1,$time]);
            $this->db->prepare('INSERT INTO settings_revisions VALUES(?,?,?,?,?,?)')->execute([$a['club_id'],'paper',$version+1,$json,$a['id'],$time]);
            $this->db->prepare('INSERT INTO audit_events(club_id,actor_id,action,object_id,object_version,happened_at) VALUES(?,?,?,?,?,?)')->execute([$a['club_id'],$a['id'],'settings.signature.updated','paper',$version+1,$time]);
        });}catch(\Throwable$failure){self::busy($failure);throw$failure;}
    }
}
