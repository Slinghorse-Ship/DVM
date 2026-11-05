<?php declare(strict_types=1);
namespace Kulturhus\Native\Routing;

use Kulturhus\Native\Cms\{Pages,PathHistory};
use Kulturhus\Native\Language\{PublicResolver,Settings};
use Kulturhus\Native\Shared\{Access,Db};
use PDO;

/** Exact published owners and retained reservations always precede declared parameter matching. */
final class Resolver
{
    private Pages $pages;
    private PublicResolver $languages;
    private TemplatePolicies $policies;
    private array $tables=[];
    private bool $tablesLoaded=false;
    public function __construct(private PDO $db,private Access $access,public readonly Transport $transport)
    {
        $this->pages=new Pages($db,$access);$this->languages=new PublicResolver($db,$access);$this->policies=new TemplatePolicies($db,$access);
    }
    public static function systemPath(array $parts):bool
    {
        return isset($parts[0])&&in_array($parts[0],['file','file-preview','assets','api','vereinsverwaltung','login','logout','account','accounts','invite','reset','forgot','preview','frontend-edit','frontend-preview','home-preview','sections-preview','design-preview','media','media-preview','media-removed','media-storage','website-document','website-document-preview','feeds','search','suche','newsletter','newsletter-confirm','newsletter-unsubscribe','tickets','status','extensions','comments','meta-webhook','publication-image','publication-video','social-image','social-video','social-credit','chat-file','chat-image','chat-status','model-fields','reference-options','page-photo-status','album-photo-status'],true);
    }
    public function resolve(string|RequestPath $request):Resolution
    {
        return $this->dispatch(is_string($request)?new RequestPath($request):$request,true);
    }
    private function dispatch(RequestPath $request,bool $canonicalize):Resolution
    {
        $this->access->require('website','read');
        $parts=$request->parts;$mounted=($parts[0]??null)==='site';if($mounted)array_shift($parts);
        if(!$mounted&&self::systemPath($parts))return Resolution::unclaimed();
        // Only table availability is request-scoped; rights, owners, configuration and generation stay fresh.
        if(!$this->tablesLoaded){$this->tables=array_fill_keys(Db::run($this->db,'SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE()')->fetchAll(PDO::FETCH_COLUMN),true);$this->tablesLoaded=true;}
        $configuration=(new Settings($this->db,$this->access))->state()['config'];$generation=$this->pages->generation();
        if(!$parts){$current=$this->current('start');return $current===null?Resolution::missing():$this->result($current,[],$request,$generation,false,$canonicalize);}
        $prefixes=[];for($length=count($parts);$length>0;--$length){$prefix=implode('/',array_slice($parts,0,$length));if(strlen($prefix)<=197)$prefixes[$prefix]=$length;}
        $requestedLocale=null;foreach($configuration['languages']as$language)if($language['code']===($parts[0]??null)&&$language['code']!==$configuration['source'])$requestedLocale=$language;
        $occupied=$this->occupants(array_keys($prefixes),$requestedLocale['code']??null);
        foreach($prefixes as$prefix=>$length){
            $suffix=array_slice($parts,$length);$current=isset($occupied[$prefix])?$this->current($prefix):null;
            if($current!==null)return $this->result($current,$suffix,$request,$generation,false,$canonicalize);
            if(isset($occupied[$prefix])){
                if(isset($occupied[$prefix]['language'])||isset($occupied[$prefix]['tombstone']))return Resolution::missing();
                if(isset($occupied[$prefix]['history'])){
                    $history=$this->historical($prefix,$requestedLocale);
                    if($history!==null)return $this->result($history,$suffix,$request,$generation,true,$canonicalize);
                }
                return Resolution::missing();
            }
            if($length===count($parts)&&$prefix==='verein/satzung'){
                $legacy=$this->current('satzung');if($legacy!==null)return$this->result($legacy,[],$request,$generation,false,$canonicalize);
            }
        }
        // A configured language namespace never falls through to another page's segment contract.
        if($requestedLocale!==null)return Resolution::missing();
        return Resolution::unclaimed();
    }

    /** Build a pager only when its generated address still belongs to this exact current original. */
    public function pageLink(Context $context,int $page):string
    {
        $candidate=Links::path($context,$page);$resolved=$this->dispatch(new RequestPath($candidate),false);
        if($resolved->context!==null&&$resolved->context->pageId===$context->pageId&&$resolved->context->parameters===$context->parameters&&$resolved->context->page===$page)return$candidate;
        // Exact children, private paths and tombstones retain ownership; the existing query service remains usable.
        return Links::path($context,$page,true);
    }

    /** Recheck reservations as well as publication state: a new private draft also owns its address. */
    public function isCurrent(Context $context):bool
    {
        $resolved=$this->dispatch($context->request,false);
        return $resolved->context!==null&&hash_equals($context->fingerprint(),$resolved->context->fingerprint());
    }

    public function alternateLink(Context $context,string $base,string $locale):?string
    {
        $alternate=$context->withBase($base,$locale);
        foreach([false,true]as$query){$candidate=Links::path($alternate,null,$query);$resolved=$this->dispatch(new RequestPath($candidate),false);
            if($resolved->context!==null&&$resolved->context->pageId===$context->pageId&&$resolved->context->parameters===$context->parameters&&$resolved->context->page===$context->page)return$candidate;
        }return null;
    }

    public function policy(array $published):Policy{return$this->policies->forPage($published);}

    private function current(string $slug):?array
    {
        if(strlen($slug)>197)return null;
        $language=$this->languages->resolve($slug);
        if($language!==null)return['page'=>$language['page'],'base'=>$language['canonical_path'],'locale'=>$language['locale'],'content_locale'=>$language['content_locale'],'redirect'=>$language['redirect']!==null];
        if(strlen($slug)>180)return null;
        $page=$this->pages->publicPage($slug)??$this->pages->events()->publicPage($slug);if($page===null)return null;
        $context=$this->languages->sourceContext($page);$configuration=(new Settings($this->db,$this->access))->state()['config'];
        return['page'=>$page,'base'=>$slug,'locale'=>$context['locale']??$configuration['source'],'content_locale'=>$context['content_locale']??$configuration['source'],'redirect'=>false];
    }

    private function historical(string $prefix,?array $language):?array
    {
        $source=$prefix;
        if($language!==null&&str_starts_with($prefix,$language['code'].'/')){
            if(!$language['enabled'])return null;$source=substr($prefix,strlen($language['code'])+1);
        }else$language=null;
        $target=(new PathHistory($this->db,$this->access))->resolve($source);if($target===null)return null;
        $current=$this->current($target);if($current===null)return null;
        if($language!==null){$projected=$this->languages->project($current['page'],$language['code']);if($projected===null)return null;$current=['page'=>$projected,'base'=>$projected['language_path'],'locale'=>$language['code'],'content_locale'=>$projected['content_language'],'redirect'=>true];}
        return$current;
    }

    private function result(array $current,array $suffix,RequestPath $request,int $generation,bool $history=false,bool $canonicalize=true):Resolution
    {
        $published=$current['page'];$policy=$this->policies->forPage($published);$parameter=($published['slug']??null)==='veranstaltungen'?'seite':'page';
        $match=$policy->match($suffix,$request,$parameter);if($match===null)return Resolution::missing();
        $policy->eventQuery($match['parameters'],$request->queryValues());
        $context=new Context($published['id'],(int)($published['published_revision']??$published['version']),$published['slug'],$current['base'],$current['locale'],$current['content_locale'],$match['parameters'],$match['page'],$parameter,$policy,$request,$this->transport,$generation,$match['path_page']);
        $canonical=Links::path($context);
        // A history suffix is redirected only if its final address still belongs to the same original.
        if($history&&$suffix!==[]){$target=$this->dispatch(new RequestPath($canonical),false);if($target->context===null||$target->context->pageId!==$context->pageId||$target->context->parameters!==$context->parameters)return Resolution::missing();}
        if(!$canonicalize)return Resolution::page($context,$published);
        // Query services remain available if a generated pager belongs to another exact or reserved owner.
        if(!$match['path_page']&&!$history&&$policy->definition['pagination']['mode']==='path'&&$match['page']>1){
            $target=$this->dispatch(new RequestPath($canonical),false);
            if($target->context===null||$target->context->pageId!==$context->pageId||$target->context->parameters!==$context->parameters||$target->context->page!==$context->page)$canonical=Links::path($context,null,true);
        }
        $samePath=(new RequestPath($canonical))->path===$request->path;
        $sameTarget=$canonical===$request->path.($request->query===''?'':'?'.$request->query);
        $rewrite=$history||$current['redirect']||$this->transport->needsHttps($policy)||($policy->definition['slash']!=='inherit'&&!$samePath)||($policy->definition['pagination']['mode']==='path'&&($match['path_page']||$match['page']>1)&&!$sameTarget);
        if($rewrite)return Resolution::redirect($context,$published,$this->transport->needsHttps($policy)?$this->transport->absolute($canonical,true):$canonical);
        return Resolution::page($context,$published);
    }

    /** Metadata only. Draft text, private titles and identifiers never leave this reservation test. */
    private function occupants(array $prefixes,?string $locale):array
    {
        if(!$prefixes)return[];$wanted=array_fill_keys($prefixes,true);$lookup=$prefixes;
        if($locale!==null)foreach($prefixes as$prefix)if(str_starts_with($prefix,$locale.'/'))$lookup[]=substr($prefix,strlen($locale)+1);
        $lookup=array_values(array_unique(array_filter($lookup,static fn(string $path):bool=>strlen($path)<=197)));$markers=implode(',',array_fill(0,count($lookup),'?'));$club=$this->access->club();$found=[];
        $add=static function(string $path,string $type)use(&$found,$wanted,$locale):void{foreach([$path,...($locale===null?[]:[$locale.'/'.$path])]as$candidate)if(isset($wanted[$candidate]))$found[$candidate][$type]=true;};
        foreach(['khcms_pages'=>['slug','published_slug'],'khcms_path_history'=>['slug'],'khcms_events'=>['slug'],'khcms_purged_paths'=>['slug']]as$table=>$columns)foreach($columns as$column){if(!isset($this->tables[$table]))continue;$rows=Db::run($this->db,'SELECT `'.$column.'` FROM `'.$table.'` WHERE club_id=? AND `'.$column.'` COLLATE utf8mb4_bin IN ('.$markers.')',[$club,...$lookup])->fetchAll(PDO::FETCH_COLUMN);foreach($rows as$path)$add($path,match($table){'khcms_path_history'=>'history','khcms_purged_paths'=>'tombstone',default=>'owner'});}
        if(isset($this->tables['native_language_routes']))foreach(Db::run($this->db,"SELECT CONCAT(locale,'/',slug) FROM native_language_routes WHERE club_id=? AND CONCAT(locale,'/',slug) COLLATE utf8mb4_bin IN (".implode(',',array_fill(0,count($prefixes),'?')).')',[$club,...$prefixes])->fetchAll(PDO::FETCH_COLUMN)as$path)$found[$path]['language']=true;
        if(isset($this->tables['native_language_pages']))foreach(Db::run($this->db,"SELECT CONCAT(locale,'/',JSON_UNQUOTE(JSON_EXTRACT(draft_json,'$.slug'))) FROM native_language_pages WHERE club_id=? AND CONCAT(locale,'/',JSON_UNQUOTE(JSON_EXTRACT(draft_json,'$.slug'))) COLLATE utf8mb4_bin IN (".implode(',',array_fill(0,count($prefixes),'?')).')',[$club,...$prefixes])->fetchAll(PDO::FETCH_COLUMN)as$path)$found[$path]['language']=true;
        return$found;
    }
}
