<?php declare(strict_types=1);
namespace Kulturhus\Native\Extensions;

/** Host code registers reviewed local instances; no HTTP upload/discovery/remote code. */
final class Catalog
{
    private array $plugins=[];
    private array $diagnostics=[];
    public function __construct(Plugin ...$plugins) { foreach($plugins as $plugin) $this->register($plugin); $this->ordered(); }
    public function register(Plugin $plugin): void
    {
        $id=$plugin->definition()->id;
        if(isset($this->plugins[$id]))throw new \DomainException('Erweiterung bereits registriert.');
        $this->plugins[$id]=$plugin;
    }
    public function get(string $id): Plugin { return $this->plugins[$id]??throw new \DomainException('Erweiterung nicht lokal registriert.'); }
    public function report(string $code): void {if($code!=='host_registry_failed')throw new \LogicException('Unbekannte Katalogdiagnose.');$this->diagnostics=[['owner'=>'reviewed-plugins','code'=>$code]];}
    public function diagnostics(): array {return $this->diagnostics;}
    public function fingerprint(string $id): string
    {
        $plugin=$this->get($id);$class=new \ReflectionClass($plugin);$file=$class->getFileName();
        if(!$file||!is_file($file)||is_link($file))throw new \DomainException('Erweiterungsquelle ungültig.');
        $source=hash_file('sha256',$file);
        if($plugin instanceof SourceFiles){$declared=[];foreach($this->sources($id)as$path)$declared[$path]=hash_file('sha256',$path);$source.=json_encode($declared,JSON_THROW_ON_ERROR);}
        return hash('sha256',json_encode($plugin->definition(),JSON_THROW_ON_ERROR).$source);
    }
    /** All executable helpers/assets belong to the reviewed local module directory. */
    public function sources(string $id):array
    {
        $plugin=$this->get($id);$main=(new \ReflectionClass($plugin))->getFileName();
        if(!$main||!is_file($main)||is_link($main))throw new \DomainException('Erweiterungsquelle fehlt.');
        $root=realpath(dirname($main));$files=[$main,...($plugin instanceof SourceFiles?$plugin->sourceFiles():[])];
        if(count($files)>65||!array_is_list($files))throw new \DomainException('Erweiterungsquellen begrenzen.');$out=[];
        foreach($files as$file){
            if(!is_string($file)||!str_starts_with($file,'/')||str_contains($file,'\\')||str_contains($file,"\0"))throw new \DomainException('Lokale Erweiterungsquelle erforderlich.');
            $cursor='';foreach(explode('/',trim($file,'/'))as$part){if($part===''||$part==='.'||$part==='..')throw new \DomainException('Erweiterungsquelle prüfen.');$cursor.='/'.$part;if(is_link($cursor))throw new \DomainException('Symbolische Erweiterungsquellen sind nicht erlaubt.');}
            $path=realpath($file);if(!$path||!str_starts_with($path,$root.'/')||!is_file($path)||filesize($path)>2097152)throw new \DomainException('Erweiterungsquelle außerhalb des Moduls oder zu groß.');$out[]=$path;
        }
        $out=array_values(array_unique($out));sort($out,SORT_STRING);return $out;
    }
    /** Stable topological order independent of installation order. */
    public function ordered(): array
    {
        $result=[];$visiting=[];$visited=[];
        $visit=function(string $id)use(&$visit,&$result,&$visiting,&$visited):void{
            if(isset($visited[$id]))return;
            if(isset($visiting[$id]))throw new \DomainException('Zyklische Erweiterungsabhängigkeit.');
            $visiting[$id]=true;$plugin=$this->get($id);
            foreach($plugin->definition()->dependencies as $dependency=>$version){if(version_compare($this->get($dependency)->definition()->version,$version,'<'))throw new \DomainException('Lokale Abhängigkeitsversion zu alt.');$visit($dependency);}
            unset($visiting[$id]);$visited[$id]=true;$result[$id]=$plugin;
        };
        foreach(array_keys($this->plugins) as $id)$visit($id);
        return $result;
    }
}
