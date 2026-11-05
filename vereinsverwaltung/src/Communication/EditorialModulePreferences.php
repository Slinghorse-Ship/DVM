<?php declare(strict_types=1);
namespace Kulturhus\Verein;
use DomainException;
use PDO;
require_once __DIR__.'/EditorialModuleWorkspace.php';

/** Module-local design/proposal preferences. Never schedules a publication or mailing. */
final class EditorialModulePreferences
{
    public const THEMES=['gold'=>'Kulturhus Gold','light'=>'Klar & Hell','night'=>'Kulturabend'];
    public function __construct(private PDO $db,private string $actor,private string $module)
    {if(!isset(EditorialModules::AREAS[$module]))throw new DomainException('Unbekanntes Redaktionsmodul.');}
    public static function defaults(string $module):array {return ['theme'=>'gold','format'=>$module==='social'?'story':($module==='newsletter'?'newsletter':'page'),'headline'=>'Kultur gemeinsam erleben','text'=>'Entdecke unser Programm und die Neuigkeiten aus dem Verein.','proposal_mode'=>'off'];}
    public static function normalize(string $module,array $input):array
    {
        if(!isset(EditorialModules::AREAS[$module]))throw new DomainException('Unbekanntes Redaktionsmodul.');
        $keys=array_keys(self::defaults($module));$actual=array_keys($input);sort($keys);sort($actual);
        if($keys!==$actual)throw new DomainException('Moduleinstellungen vollständig prüfen.');
        foreach($input as $value)if(!is_string($value)||!preg_match('//u',$value)||preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f]/',$value))throw new DomainException('Moduleinstellungen als Text eingeben.');
        if(!isset(self::THEMES[$input['theme']])||!in_array($input['proposal_mode'],['off','on_open'],true)||!in_array($input['format'],$module==='social'?['story','post']:[$module==='newsletter'?'newsletter':'page'],true))throw new DomainException('Design, Format und Vorschlagsmodus prüfen.');
        $input['headline']=trim($input['headline']);$input['text']=trim($input['text']);
        if($input['headline']===''||mb_strlen($input['headline'])>120||mb_strlen($input['text'])>600)throw new DomainException('Titel bis 120 und Vorschlagstext bis 600 Zeichen eingeben.');
        return $input;
    }
    private function access(bool $write=false):array
    {
        $workspace=new EditorialModuleWorkspace($this->db,$this->actor,$this->module);
        if(!$workspace->canRead()||($write&&!$workspace->canConfigure()))throw new DomainException('Keine Berechtigung für diese Moduleinstellungen.');
        return Permissions::actor($this->db,$this->actor);
    }
    public function get():array
    {
        $a=$this->access();$q=$this->db->prepare('SELECT payload,version FROM club_settings WHERE club_id=? AND section=?');$q->execute([$a['club_id'],'module:'.$this->module]);$r=$q->fetch();
        if(!$r)return ['version'=>0,'data'=>self::defaults($this->module)];
        try{$input=json_decode($r['payload'],true,16,JSON_THROW_ON_ERROR);}catch(\JsonException){throw new DomainException('Gespeicherte Moduleinstellungen müssen geprüft werden.');}
        if(!is_array($input))throw new DomainException('Gespeicherte Moduleinstellungen müssen geprüft werden.');
        return ['version'=>(int)$r['version'],'data'=>self::normalize($this->module,$input)];
    }
    public function save(array $input,int $version):void
    {
        $a=$this->access(true);$data=self::normalize($this->module,$input);
        if($this->db->inTransaction())throw new DomainException('Moduleinstellungen benötigen eine eigene Schreibtransaktion.');$this->db->beginTransaction();
        try{
            $q=$this->db->prepare('SELECT id FROM clubs WHERE id=? FOR UPDATE');$q->execute([$a['club_id']]);
            if(!$q->fetchColumn())throw new DomainException('Verein wurde nicht gefunden.');
            if($this->access(true)['club_id']!==$a['club_id'])throw new DomainException('Vereinszuordnung wurde geändert.');
            $old=$this->get();if($version!==$old['version'])throw new DomainException('Moduleinstellungen wurden inzwischen geändert. Bitte neu laden.');
            $json=json_encode($data,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);$now=gmdate('c');$section='module:'.$this->module;
            $q=$this->db->prepare('INSERT INTO club_settings(club_id,section,payload,version,updated_at) VALUES(?,?,?,?,?) ON DUPLICATE KEY UPDATE payload=VALUES(payload),version=VALUES(version),updated_at=VALUES(updated_at)');$q->execute([$a['club_id'],$section,$json,$version+1,$now]);
            $q=$this->db->prepare('INSERT INTO settings_revisions(club_id,section,version,payload,actor_id,happened_at) VALUES(?,?,?,?,?,?)');$q->execute([$a['club_id'],$section,$version+1,$json,$this->actor,$now]);
            $q=$this->db->prepare('INSERT INTO audit_events(club_id,actor_id,action,object_id,object_version,happened_at) VALUES(?,?,?,?,?,?)');$q->execute([$a['club_id'],$this->actor,'editorial.module.settings',$section,$version+1,$now]);
            $this->db->commit();
        }catch(\Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
    }
}
