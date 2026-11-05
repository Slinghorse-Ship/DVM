<?php declare(strict_types=1);
namespace Kulturhus\Native\Language;
use Kulturhus\Native\Shared\{Access,Db};
/** Explicit message identifiers. No HTML rewriting, executable pack or global translator replacement. */
final class Catalog
{
    private const MESSAGES=[
        'de'=>['languages'=>'Sprachen','settings'=>'Einstellungen','website'=>'Website','dashboard'=>'Übersicht','save'=>'Speichern','publish'=>'Veröffentlichen','unpublish'=>'Zurückziehen','restore'=>'Wiederherstellen','history'=>'Verlauf','source'=>'Originalfassung','translation'=>'Übersetzung','language'=>'Sprache','admin_language'=>'Meine Oberflächensprache','page'=>'Seite','title'=>'Seitentitel','slug'=>'Sprachpfad','excerpt'=>'Kurztext','body'=>'Inhalt','back'=>'Zurück','next'=>'Weiter','rights'=>'Sprachrechte','edit'=>'Bearbeiten','version'=>'Fassung','saved'=>'Gespeichert','fallback'=>'Rückfallsprache','enabled'=>'Aktiv','configuration'=>'Sprachen verwalten','empty'=>'Noch keine Übersetzung','cancel'=>'Abbrechen','custom_fields'=>'Weitere Textfelder','sections'=>'Inhaltsabschnitte'],
        'en'=>['languages'=>'Languages','settings'=>'Settings','website'=>'Website','dashboard'=>'Dashboard','save'=>'Save','publish'=>'Publish','unpublish'=>'Unpublish','restore'=>'Restore','history'=>'History','source'=>'Source revision','translation'=>'Translation','language'=>'Language','admin_language'=>'My interface language','page'=>'Page','title'=>'Page title','slug'=>'Language path','excerpt'=>'Summary','body'=>'Content','back'=>'Back','next'=>'Next','rights'=>'Language permissions','edit'=>'Edit','version'=>'Revision','saved'=>'Saved','fallback'=>'Fallback language','enabled'=>'Enabled','configuration'=>'Manage languages','empty'=>'No translation yet','cancel'=>'Cancel','custom_fields'=>'Additional text fields','sections'=>'Content sections'],
    ];
    public function __construct(private \PDO $db,private Access $access){}
    public static function keys():array{return [...array_keys(self::MESSAGES['de']),'review_copy',...array_keys(ManagerLabels::messages('de')),...array_keys(AccountMessages::messages('de')),...array_keys(ManagerMessages::messages('de'))];}
    public function locale():string
    {
        if(\Kulturhus\Native\Demo\Runtime::active())return \Kulturhus\Native\Demo\Runtime::locale();
        $actor=$this->access->user();try{$locale=Db::run($this->db,'SELECT locale FROM native_language_preferences WHERE club_id=? AND actor_id=?',[$actor['club_id'],$actor['id']])->fetchColumn();}catch(\PDOException $error){if($error->getCode()!=='42S02')throw $error;$locale=false;}
        if($locale&&in_array($locale,array_column($this->available(),'code'),true))return $locale;return $this->configuration()['source'];
    }
    public function select(string $locale):void
    {
        if(\Kulturhus\Native\Demo\Runtime::active()){if(!in_array($locale,['de','en'],true)||session_status()!==PHP_SESSION_ACTIVE)throw new \DomainException('Demosprache auswählen.');$_SESSION['demo_locale']=$locale;return;}
        Settings::code($locale);Db::atomic($this->db,function()use($locale):void{
            $actor=$this->access->user();if(!in_array($locale,array_column($this->available(),'code'),true))throw new \DomainException('Diese Oberflächensprache ist nicht verfügbar.');
            Db::run($this->db,'INSERT INTO native_language_preferences (club_id,actor_id,locale) VALUES (?,?,?) ON DUPLICATE KEY UPDATE locale=VALUES(locale)',[$actor['club_id'],$actor['id'],$locale]);
        });
    }
    /** Interface packs are independent of publicly enabling another website language. */
    public function available():array
    {
        $languages=['de'=>['code'=>'de','label'=>'Deutsch'],'en'=>['code'=>'en','label'=>'English']];foreach($this->configuration()['languages'] as $language)if($language['enabled']||isset($languages[$language['code']]))$languages[$language['code']]=['code'=>$language['code'],'label'=>$language['label']];return array_values($languages);
    }
    public function messages(?string $locale=null):array
    {
        $locale??=$this->locale();Settings::code($locale);if(!in_array($locale,array_column($this->available(),'code'),true))throw new \DomainException('Diese Oberflächensprache ist nicht verfügbar.');$config=$this->configuration();$languages=array_column($config['languages'],null,'code');$chain=[];$next=$locale;
        while($next!==null){if(isset($chain[$next])||count($chain)>20)throw new \DomainException('Ungültiger Sprachpaketrückfall.');$chain[$next]=true;$next=$languages[$next]['fallback']??null;}
        $messages=self::MESSAGES['de']+['review_copy'=>'Kopierte Übersetzung prüfen und vor der Freigabe speichern.']+ManagerLabels::messages('de')+AccountMessages::messages('de')+ManagerMessages::messages('de');
        foreach(array_reverse(array_keys($chain)) as $code){
            $base=explode('-',$code,2)[0];$pack=self::MESSAGES[$code]??self::MESSAGES[$base]??[];
            // A locale without an installed pack keeps its declared fallback; it must not reset it to German.
            if(in_array($base,['de','en'],true))$pack+=ManagerLabels::messages($code)+AccountMessages::messages($code)+ManagerMessages::messages($code);
            if($base==='en')$pack['review_copy']='Review and save the copied translation before publishing.';
            $messages=array_replace($messages,$pack,$languages[$code]['messages']??[]);
        }return $messages;
    }
    /** Only content controls for bounded editors; resolve the same live locale and overrides. */
    public function contentMessages(?string $locale=null):array
    {
        $locale??=$this->locale();Settings::code($locale);if(!in_array($locale,array_column($this->available(),'code'),true))throw new \DomainException('Diese Oberflächensprache ist nicht verfügbar.');
        $languages=array_column($this->configuration()['languages'],null,'code');$chain=[];$next=$locale;
        while($next!==null){if(isset($chain[$next])||count($chain)>20)throw new \DomainException('Ungültiger Sprachpaketrückfall.');$chain[$next]=true;$next=$languages[$next]['fallback']??null;}
        $installed=static fn(string $code):array=>ManagerMessages::baseMessages($code)+PhotoMessages::messages($code)+MediaMessages::messages($code)+ContentMessages::messages($code);
        $messages=$installed('de');
        foreach(array_reverse(array_keys($chain))as$code){
            if(in_array(explode('-',$code,2)[0],['de','en'],true))$messages=array_replace($messages,$installed($code));
            $messages=array_replace($messages,array_intersect_key($languages[$code]['messages']??[],$messages));
        }
        return $messages;
    }
    public function text(string $id,?string $locale=null):string
    {
        if(!in_array($id,self::keys(),true))throw new \InvalidArgumentException('Unbekannter Oberflächentext.');return $this->messages($locale)[$id];
    }
    private function configuration():array
    {
        $actor=$this->access->user();try{$json=Db::run($this->db,'SELECT config_json FROM native_language_settings WHERE club_id=?',[$actor['club_id']])->fetchColumn();}catch(\PDOException $error){if($error->getCode()!=='42S02')throw $error;$json=false;}
        return $json?json_decode($json,true,32,JSON_THROW_ON_ERROR):['source'=>'de','languages'=>[['code'=>'de','label'=>'Deutsch','enabled'=>true,'fallback'=>null,'messages'=>[]]]];
    }
}
