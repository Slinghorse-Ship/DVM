<?php declare(strict_types=1);
namespace Kulturhus\Native\Extensions;
use Kulturhus\Native\Shared\Db;

/** Host contracts: public title property, complete response method and read-only URLs. */
final class Runtime
{
    private Hooks $hooks;
    private Urls $urls;
    private \Kulturhus\Native\TemplateRenderers\Registry $renderers;
    private ?\Kulturhus\Native\Fieldtypes\Registry $fieldtypes=null;
    private array $failures=[];
    private array $loaded=[];
    public static function catalog(): Catalog { return HostPlugins::catalog(); }
    public function __construct(private readonly \PDO $db,private readonly string $club,?Catalog $catalog=null,array $requested=[])
    {
        $catalog??=self::catalog();$this->failures=$catalog->diagnostics();$this->hooks=new Hooks();$this->urls=new Urls($this->hooks);$this->renderers=new \Kulturhus\Native\TemplateRenderers\Registry();if(class_exists(\Kulturhus\Native\Fieldtypes\Registry::class))$this->fieldtypes=new \Kulturhus\Native\Fieldtypes\Registry();
        $publicGuard=fn():bool=>(bool)Db::run($this->db,'SELECT 1 FROM clubs WHERE id=?',[$this->club])->fetchColumn();
        $this->hooks->define(new Contract('site.title','property',$publicGuard,static fn(array $args):bool=>count($args)===1&&is_string($args['value']??null)&&mb_strlen($args['value'])<=500,static fn(mixed $value):bool=>is_string($value)&&mb_strlen($value)<=500));
        $this->hooks->define(new Contract('site.response','method',$publicGuard,static fn(array $args):bool=>array_keys($args)===['response']&&self::validResponse($args['response']),self::validResponse(...)));
        $states=[];foreach(Db::run($db,'SELECT * FROM native_extensions WHERE club_id=?',[$club])->fetchAll() as $row)$states[$row['plugin_id']]=$row;
        $initialized=[];$guards=[];$selected=[];$ordered=$catalog->ordered();
        $select=function(string $id)use(&$select,&$selected,$catalog):void{
            if(isset($selected[$id]))return;$selected[$id]=true;
            foreach($catalog->get($id)->definition()->dependencies as $dependency=>$minimum)$select($dependency);
        };
        foreach($ordered as $id=>$plugin)if($plugin->definition()->autoload||in_array($id,$requested,true))$select($id);
        foreach($ordered as $id=>$plugin){
            if(!isset($selected[$id]))continue;
            $definition=$plugin->definition();$row=$states[$id]??null;
            if(!$row||!(bool)$row['enabled']||$row['version']!==$definition->version)continue;
            try{if($row['source_hash']!==$catalog->fingerprint($id))continue;}catch(\Throwable){$this->failures[]=['owner'=>$id,'code'=>'source_unavailable'];continue;}
            $ready=true;foreach($definition->dependencies as $dependency=>$minimum)if(!isset($initialized[$dependency])){$ready=false;break;}
            if(!$ready){$this->failures[]=['owner'=>$id,'code'=>'dependency_unavailable'];continue;}
            $dependencyGuards=[];foreach($definition->dependencies as $dependency=>$minimum)$dependencyGuards[]=$guards[$dependency];
            $guard=function()use($id,$row,$dependencyGuards,$catalog):bool{
                try{
                    foreach($dependencyGuards as $dependencyGuard)if(!$dependencyGuard())return false;
                    if(!hash_equals($row['source_hash'],$catalog->fingerprint($id)))return false;
                    $actor=(new Access($this->db,$row['updated_by']))->actor();if($actor['club_id']!==$this->club)return false;
                    $current=Db::run($this->db,'SELECT enabled,revision,source_hash,updated_by FROM native_extensions WHERE club_id=? AND plugin_id=?',[$this->club,$id])->fetch();
                    return $current&&(bool)$current['enabled']&&(int)$current['revision']===(int)$row['revision']&&hash_equals($current['source_hash'],$row['source_hash'])&&$current['updated_by']===$row['updated_by'];
                }catch(\Throwable){return false;}
            };
            try{
                if(!$guard())continue;
                $configuration=$definition->configuration(json_decode($row['configuration'],true,32,JSON_THROW_ON_ERROR));
                $state=hash('sha256',json_encode([$row['revision'],$row['updated_by'],$configuration],JSON_THROW_ON_ERROR));
                $context=new Context($db,$club,$id,$configuration,$guard,false,new \Kulturhus\Native\TemplateRenderers\Scope($this->renderers,$id,$row['source_hash'],$guard,$catalog->sources($id),$state),$this->fieldtypes!==null?new \Kulturhus\Native\Fieldtypes\Scope($this->fieldtypes,$id,$row['source_hash'],$guard,$catalog->sources($id)):null);
                $plugin->initialize(new HookScope($this->hooks,$id,$guard),new UrlScope($this->urls,$id,$guard),$context);
                if(!$guard())throw new \DomainException('Erweiterungsfreigabe geändert.');
                $initialized[$id]=true;$guards[$id]=$guard;$this->loaded[]=$id;
            }catch(\Throwable){$this->hooks->remove($id);$this->urls->remove($id);$this->renderers->remove($id);$this->fieldtypes?->remove($id);$this->failures[]=['owner'=>$id,'code'=>'initialization_failed'];}
        }
    }
    public static function validResponse(mixed $response): bool
    {
        if(!is_array($response)||!is_string($response['body']??null)||strlen($response['body'])>8388608||!is_array($response['headers']??null)||array_diff(array_keys($response),['status','body','headers'])||!in_array($response['status']??200,[200,204,404],true))return false;
        foreach($response['headers'] as $name=>$value){
            if(!is_string($name)||!is_string($value)||strlen($value)>200||preg_match('/[\r\n\x00]/',$value)||!in_array(strtolower($name),['content-type','content-language','cache-control'],true))return false;
            if(strtolower($name)==='content-type'&&!in_array($value,['text/html; charset=utf-8','application/json; charset=utf-8','text/plain; charset=utf-8','text/css; charset=utf-8'],true))return false;
        }
        return true;
    }
    public function property(string $name,string $value): string
    {
        if($name!=='site.title')throw new \DomainException('Unbekannte öffentliche Erweiterungseigenschaft.');
        return $this->hooks->dispatch($name,['value'=>$value],static fn(array $args):string=>$args['value']);
    }
    public function response(array $response): array { return $this->hooks->dispatch('site.response',['response'=>$response],static fn(array $args):array=>$args['response']); }
    public function url(string $method,string $path): ?array { return $this->renderers->asset($method,$path)??$this->urls->dispatch($method,$path); }
    public function renderers():\Kulturhus\Native\TemplateRenderers\Registry{return $this->renderers;}
    public function fieldtypes():\Kulturhus\Native\Fieldtypes\Registry{return $this->fieldtypes??throw new \DomainException('Lokale Feldtypen sind nicht installiert.');}
    public function failures(): array { return array_merge($this->failures,$this->hooks->failures()); }
    public function loaded(): array { return $this->loaded; }
    public static function publicResponse(\PDO $db,string $club,array $response): array { return (new self($db,$club))->response($response); }
}
