<?php declare(strict_types=1);
namespace Kulturhus\Native\Language;
use Kulturhus\Native\Shared\{Access,Db};
use Kulturhus\Native\Cms\{ClubPublicationLock,Conflict};
/** Per-club declarative language graph and administrator-reviewed message packs. */
final class Settings
{
    use ClubPublicationLock;
    public function __construct(private \PDO $db,private Access $access){}
    public static function code(mixed $code):string
    {
        if(!is_string($code)||strlen($code)>16||!preg_match('/\A[a-z]{2,3}(?:-[a-z0-9]{2,8}){0,3}\z/D',$code))throw new \InvalidArgumentException('Sprachkürzel wie de, en, en-gb oder zh-hant-tw verwenden.');
        return $code;
    }
    public function state():array
    {
        $this->access->require('website','read');
        try{$row=Db::run($this->db,'SELECT version,config_json FROM native_language_settings WHERE club_id=?',[$this->access->club()])->fetch();}
        catch(\PDOException $error){if($error->getCode()!=='42S02')throw $error;$row=false;}
        return $row?['version'=>(int)$row['version'],'config'=>json_decode($row['config_json'],true,32,JSON_THROW_ON_ERROR)]:['version'=>0,'config'=>['source'=>'de','languages'=>[['code'=>'de','label'=>'Deutsch','enabled'=>true,'fallback'=>null,'messages'=>[]]]]];
    }
    public function language(string $locale,bool $enabled=true):array
    {
        self::code($locale);
        foreach($this->state()['config']['languages'] as $language)if($language['code']===$locale&&(!$enabled||$language['enabled']))return $language;
        throw new \DomainException('Diese Sprache ist nicht verfügbar.');
    }
    public function chain(string $locale):array
    {
        $chain=[];
        while($locale!==null){$language=$this->language($locale);$chain[]=$locale;$locale=$language['fallback'];if(count($chain)>20)throw new \DomainException('Ungültige Sprachreihenfolge.');}
        return $chain;
    }
    public function save(array $input,int $expected):array
    {
        $this->access->require('website','manage');
        if($expected<0||$expected>4294967293)throw new \InvalidArgumentException('Versionsstand prüfen.');
        if(array_diff(array_keys($input),['source','languages'])||!is_array($input['languages']??null)||!array_is_list($input['languages'])||count($input['languages'])<1||count($input['languages'])>20)throw new \InvalidArgumentException('Eine bis zwanzig Sprachen angeben.');
        $source=self::code($input['source']??null);$languages=[];
        foreach($input['languages'] as $row){
            if(!is_array($row)||array_diff(array_keys($row),['code','label','enabled','fallback','messages']))throw new \InvalidArgumentException('Sprachangaben prüfen.');
            $code=self::code($row['code']??null);$label=self::text($row['label']??null,80);$enabled=$row['enabled']??null;$fallback=$row['fallback']??null;
            if($fallback!==null)$fallback=self::code($fallback);
            if(isset($languages[$code])||$label===''||!is_bool($enabled))throw new \InvalidArgumentException('Eindeutige Sprachen mit Namen und Aktivierung angeben.');
            $messages=$row['messages']??[];
            if(!is_array($messages)||count($messages)>count(Catalog::keys())||array_diff(array_keys($messages),Catalog::keys()))throw new \InvalidArgumentException('Unbekannter Sprachpaket-Schlüssel.');
            foreach($messages as $key=>$value)$messages[$key]=self::text($value,500);
            $languages[$code]=compact('code','label','enabled','fallback','messages');
        }
        if(!isset($languages[$source])||!$languages[$source]['enabled']||$languages[$source]['fallback']!==null)throw new \InvalidArgumentException('Die Quellsprache muss aktiv sein und hat keinen Rückfall.');
        foreach($languages as $code=>$row){$seen=[];$next=$code;while($next!==null){if(isset($seen[$next])||!isset($languages[$next])||($row['enabled']&&!$languages[$next]['enabled']))throw new \InvalidArgumentException('Sprachrückfälle müssen aktiv, vorhanden und ohne Kreis sein.');$seen[$next]=true;$next=$languages[$next]['fallback'];}}
        $config=['source'=>$source,'languages'=>array_values($languages)];
        return Db::atomic($this->db,function()use($expected,$config,$languages,$source):array{
            $actor=$this->access->require('website','manage');$this->lockClub();$old=$this->state();if($old['version']!==$expected)throw new Conflict('Die Sprachen wurden inzwischen geändert. Bitte neu laden.');
            // Existing source contents keep their language and all existing URL namespaces.
            if($old['version']>0&&$old['config']['source']!==$source)throw new \DomainException('Die Quellsprache bestehender Inhalte bleibt erhalten.');
            foreach($old['config']['languages'] as $previous)if(!isset($languages[$previous['code']]))throw new \DomainException('Bestehende Sprache deaktivieren; ihre ursprünglichen Adressen bleiben reserviert.');
            foreach($languages as $code=>$language){if($code===$source)continue;
                if(Db::run($this->db,'SELECT 1 FROM khcms_pages WHERE club_id=? AND (slug=? OR slug LIKE ? OR published_slug=? OR published_slug LIKE ?) LIMIT 1',[$this->access->club(),$code,$code.'/%',$code,$code.'/%'])->fetchColumn())throw new \DomainException('Der Sprachpfad ist bereits durch eine Originalseite belegt.');
                if(Db::run($this->db,'SELECT 1 FROM khcms_path_history WHERE club_id=? AND (slug=? OR slug LIKE ?) LIMIT 1',[$this->access->club(),$code,$code.'/%'])->fetchColumn())throw new \DomainException('Der Sprachpfad ist bereits durch eine frühere Originaladresse belegt.');
            }
            $version=$expected+1;$json=json_encode($config,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
            Db::run($this->db,'INSERT INTO native_language_settings (club_id,version,config_json,actor_id,changed_at) VALUES (?,?,?,?,UTC_TIMESTAMP(6)) ON DUPLICATE KEY UPDATE version=VALUES(version),config_json=VALUES(config_json),actor_id=VALUES(actor_id),changed_at=VALUES(changed_at)',[$this->access->club(),$version,$json,$actor['id']]);
            Db::run($this->db,'INSERT INTO native_language_settings_revisions (club_id,version,config_json,actor_id,changed_at) VALUES (?,?,?,?,UTC_TIMESTAMP(6))',[$this->access->club(),$version,$json,$actor['id']]);
            $this->invalidate();return ['version'=>$version,'config'=>$config];
        });
    }
    public function revisions():array
    {
        $this->access->require('website','manage');return Db::run($this->db,'SELECT version,config_json,actor_id,changed_at FROM native_language_settings_revisions WHERE club_id=? ORDER BY version DESC LIMIT 100',[$this->access->club()])->fetchAll();
    }
    /** Called by original-page writers under the same club publication mutex. */
    public function assertSourcePath(string $slug,?string $pageId=null):void
    {
        $config=$this->state()['config'];$first=explode('/',$slug,2)[0];
        foreach($config['languages'] as $language)if($language['code']!==$config['source']&&$language['code']===$first)throw new \DomainException('Dieser Pfad ist für eine Website-Sprache reserviert.');
        // The table is absent only in legacy standalone CMS fixtures before explicit installation.
        if($this->state()['version']===0)return;
        $owners=Db::run($this->db,'SELECT DISTINCT page_id FROM native_language_routes WHERE club_id=? AND slug=?',[$this->access->club(),$slug])->fetchAll(\PDO::FETCH_COLUMN);
        foreach($owners as $owner)if($pageId!==$owner)throw new \DomainException('Dieser Pfad gehört bereits zu einer anderen übersetzten Seite.');
    }
    public function rights(string $actor,string $locale):array
    {
        $this->access->require('website','manage');$this->language($locale,false);$this->subject($actor);
        $row=Db::run($this->db,'SELECT version,capabilities_json FROM native_language_rights WHERE club_id=? AND actor_id=? AND locale=?',[$this->access->club(),$actor,$locale])->fetch();
        return ['version'=>(int)($row['version']??0),'capabilities'=>$row?json_decode($row['capabilities_json'],true,8,JSON_THROW_ON_ERROR):[]];
    }
    public function saveRights(string $actor,string $locale,array $capabilities,int $expected):void
    {
        if(!array_is_list($capabilities)||array_diff($capabilities,['edit','publish'])||count(array_unique($capabilities))!==count($capabilities)||$expected<0||$expected>4294967293)throw new \InvalidArgumentException('Sprachrechte prüfen.');
        Db::atomic($this->db,function()use($actor,$locale,$capabilities,$expected):void{
            $manager=$this->access->require('website','manage');$this->lockClub();$old=$this->rights($actor,$locale);if($old['version']!==$expected)throw new Conflict('Die Sprachrechte wurden inzwischen geändert.');$version=$expected+1;$json=json_encode($capabilities,JSON_THROW_ON_ERROR);
            Db::run($this->db,'INSERT INTO native_language_rights (club_id,actor_id,locale,version,capabilities_json) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE version=VALUES(version),capabilities_json=VALUES(capabilities_json)',[$this->access->club(),$actor,$locale,$version,$json]);
            Db::run($this->db,'INSERT INTO native_language_right_revisions (club_id,subject_id,locale,version,capabilities_json,actor_id,changed_at) VALUES (?,?,?,?,?,?,UTC_TIMESTAMP(6))',[$this->access->club(),$actor,$locale,$version,$json,$manager['id']]);
        });
    }
    public function require(string $locale,string $capability,bool $enabled=true):array
    {
        if(!in_array($capability,['edit','publish'],true))throw new \InvalidArgumentException('Ungültiges Sprachrecht.');
        $actor=$this->access->require('website',$capability);$this->language($locale,$enabled);
        try{$this->access->require('website','manage');return $actor;}catch(\DomainException){}
        $json=Db::run($this->db,'SELECT capabilities_json FROM native_language_rights WHERE club_id=? AND actor_id=? AND locale=?',[$this->access->club(),$actor['id'],$locale])->fetchColumn();
        if(!$json||!in_array($capability,json_decode($json,true,8,JSON_THROW_ON_ERROR),true))throw new \DomainException('Für diese Sprache fehlt die Berechtigung.');return $actor;
    }
    private function subject(string $actor):void
    {
        if(!Db::run($this->db,'SELECT 1 FROM native_users WHERE id=? AND club_id=? AND active=1',[$actor,$this->access->club()])->fetchColumn())throw new \DomainException('Person in diesem Verein nicht verfügbar.');
    }
    public function invalidate():void{Db::run($this->db,'UPDATE khcms_state SET generation=generation+1 WHERE club_id=?',[$this->access->club()]);}
    public static function text(mixed $value,int $max):string
    {
        if(!is_string($value)||!mb_check_encoding($value,'UTF-8')||mb_strlen($value)>$max||preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f]/u',$value))throw new \InvalidArgumentException('Gültigen Text innerhalb der Feldlänge angeben.');return trim($value);
    }
}
