<?php declare(strict_types=1);
namespace Kulturhus\Verein;
use DomainException;
use PDO;
require_once __DIR__.'/EditorialModules.php';
require_once dirname(__DIR__, 1).'/Website/WebsiteWorkspace.php';

/** The existing transport is shared; each module limits its own operations. */
class EditorialModuleWorkspace
{
    protected WebsiteWorkspace $bridge;
    public function __construct(PDO $db,string $actor,public readonly string $module)
    {if(!isset(EditorialModules::AREAS[$module]))throw new DomainException('Unbekanntes Redaktionsmodul.');$this->bridge=new WebsiteWorkspace($db,$actor);}
    public function areas():array {return array_intersect_key($this->bridge->areas(),array_fill_keys(EditorialModules::AREAS[$this->module],true));}
    public function canRead():bool {try{return $this->bridge->canRead()&&count($this->areas())>0;}catch(DomainException){return false;}}
    public function canWrite(string $area):bool {return in_array($area,EditorialModules::AREAS[$this->module],true)&&$this->bridge->canWrite($area);}
    public function canConfigure():bool {foreach(EditorialModules::AREAS[$this->module] as $area)if($area!=='technical'&&$this->canWrite($area))return true;return false;}
    public function canManage():bool {return $this->bridge->canManage();}
    public function editorialRead(string $area,int $id=0,string $query='',int $page=1,array $filters=[]):array
    {if(!isset($this->areas()[$area]))throw new DomainException('Dieser Inhalt gehört nicht zum freigegebenen Modul.');return $this->bridge->editorialRead($area,$id,$query,$page,$filters);}
    public function editorialWrite(string $operation,array $data,string $version,string $requestKey):array
    {
        $area=\Kulturhus\Verein\EditorialPolicy::operation($operation);
        $socialConnection=$this->module==='social'&&$operation==='settings/refresh-instagram'&&$this->canManage()&&$this->canConfigure();
        if(!in_array($area,EditorialModules::AREAS[$this->module],true)&&!($this->module==='website'&&$area==='image')&&!$socialConnection)throw new DomainException('Diese Aktion gehört zu einem anderen Modul.');
        return $this->bridge->editorialWrite($operation,$data,$version,$requestKey);
    }
    public static function publicUrl():string {return WebsiteWorkspace::publicUrl();}
}
