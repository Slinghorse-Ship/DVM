<?php declare(strict_types=1);
namespace Kulturhus\Verein;
use PDO;
use DomainException;
require_once __DIR__.'/EditorialModuleWorkspace.php';

/** Scoped settings of the real website adapter. Unrelated channel fields stay intact. */
final class EditorialConnectionSettings
{
    public const FIELDS=['website'=>['public_theme'],'social'=>['facebook_url','facebook_page_id','facebook_page_access_token','facebook_auto_publish','facebook_post_template','instagram_username','instagram_auto_publish','instagram_post_template']];
    private WebsiteWorkspace $site;
    private EditorialModuleWorkspace $workspace;
    public function __construct(PDO $db,string $actor,private string $module)
    {if(!isset(self::FIELDS[$module]))throw new DomainException('Unbekannte Verbindung.');$this->site=new WebsiteWorkspace($db,$actor);$this->workspace=new EditorialModuleWorkspace($db,$actor,$module);}
    public function read():array
    {if(!$this->site->canManage()||!$this->workspace->canRead())throw new DomainException('Verbindungseinstellungen benötigen technische Administrationsrechte.');return $this->site->editorialRead('technical');}
    public static function merge(string $module,array $current,array $patch):array
    {
        $fields=self::FIELDS[$module]??null;
        if(!$fields||array_diff(array_keys($patch),$fields))throw new DomainException('Nur Einstellungen dieses Moduls ändern.');
        foreach($patch as $value)if(!is_string($value))throw new DomainException('Einstellungswerte als Text eingeben.');
        if($module==='website'&&(!isset($patch['public_theme'])||!in_array($patch['public_theme'],['kultur','sport','reit','hund','musik','feuerwehr','natur','bildung'],true)))throw new DomainException('Website-Design wählen.');
        foreach(['facebook_auto_publish','instagram_auto_publish'] as $key)if($module==='social'){
            if(isset($patch[$key])&&!in_array($patch[$key],['0','1'],true))throw new DomainException('Automatik prüfen.');
            $patch[$key]=$patch[$key]??'0';
        }
        $merged=array_replace($current,$patch);
        foreach($merged as $key=>$value)$merged[$key]=is_bool($value)?($value?'1':'0'):(string)$value;
        return $merged;
    }
    public function save(array $patch,string $version,string $requestKey):array
    {
        if(!$this->workspace->canConfigure())throw new DomainException('Schreibrecht für dieses Modul erforderlich.');
        $current=$this->read();
        if(!hash_equals((string)$current['version'],$version))throw new DomainException('Verbindungseinstellungen inzwischen geändert. Neu laden.');
        $data=self::merge($this->module,$current['settings'],$patch);
        return $this->site->editorialWrite('settings/save',$data,$version,$requestKey);
    }
}
