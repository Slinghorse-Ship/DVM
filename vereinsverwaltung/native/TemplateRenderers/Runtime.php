<?php declare(strict_types=1);
namespace Kulturhus\Native\TemplateRenderers;
use Kulturhus\Native\Cms\{FieldPolicy,Pages,Media};

/** Host renderer bridge; the raw payload and database never enter the renderer context. */
final class Runtime
{
    public function __construct(private readonly \PDO $db,private readonly string $club,private readonly Registry $registry){}
    public function binding(array $page):?array
    {return isset($page['template_definition']['frontend_renderer'])?Binding::normalize($page['template_definition']['frontend_renderer']):null;}
    public function stamp(array $page):?string
    {try{$binding=$this->binding($page);if($binding===null)return 'native';$code='';foreach(['Runtime','Registry','Definition','RenderContext','Binding','Factory','HostFragments']as$file)$code.=hash_file('sha256',__DIR__.'/'.$file.'.php');return hash('sha256',$this->registry->stamp($binding).$code);}catch(\Throwable){return null;}}
    public function context(array $page,array $components,Pages $pages,Media $media,string $locale,string $contentLocale,?\Kulturhus\Native\Routing\Context $route=null,?\Kulturhus\Native\Language\PublicResolver $languages=null,?\Kulturhus\Native\Routing\Resolver $routes=null):RenderContext
    {
        $public=FieldPolicy::publicPayload($this->db,$this->club,$page);
        $dto=array_intersect_key($public,array_flip(['id','slug','title','excerpt','body','body_html','template','seo_title','seo_description','noindex','published_revision','published_version','published_at','news_date','event_start','event_end','event_location','event_address','event_status','event_ticket_url','event_price','custom_fields','media_ids','custom_media_ids']));
        $image=static function(string $id,string $size)use($media,$page):?array{
            if(!preg_match('/\A[a-f0-9]{32}\z/D',$id)||!in_array($size,['thumb','medium','large'],true))return null;
            $variant=$media->publicVariant($id,$size,$page);if(!$variant)return null;
            return ['url'=>'/media/'.$id.'/'.$size,'alt'=>$variant['alt'],'width'=>(int)$variant['width'],'height'=>(int)$variant['height']];
        };
        $db=$this->db;$club=$this->club;
        $link=static function(string $id)use($db,$club,$pages,$locale,$languages,$routes):?array{
            if(!preg_match('/\A[a-f0-9]{32}\z/D',$id))return null;
            // Public rows only; no draft, get(), path history or target metadata enters the DTO.
            $slug=\Kulturhus\Native\Shared\Db::run($db,'SELECT published_slug FROM khcms_pages WHERE club_id=? AND id=? AND published_revision IS NOT NULL',[$club,$id])->fetchColumn();
            if(!is_string($slug)||$slug==='')return null;$target=$pages->publicPage($slug);if(!$target||$target['id']!==$id)return null;
            $projected=$languages?->project($target,$locale)??$target;$path=$projected['language_path']??$projected['slug'];$url='/site/'.$path;if($routes){$r=$routes->resolve($url);if($r->context===null)return null;$url=$routes->pageLink($r->context,1);}return ['url'=>$url,'title'=>$projected['title']];
        };
        $routing=$route?->jsonSerialize()??[];
        return new RenderContext($dto,$locale,$contentLocale,$routing,$components,$image,$link);
    }
    public function render(array $page,RenderContext $context):string
    {$binding=$this->binding($page)??throw new \LogicException('Kein registrierter Renderer ausgewählt.');return $this->registry->render($binding,$context);}
    public function styles(array $page):string
    {$binding=$this->binding($page);if($binding===null)return '';$html='';foreach($this->registry->styles($binding)as$url)$html.='<link rel="stylesheet" href="'.htmlspecialchars($url,ENT_QUOTES,'UTF-8').'">';return $html;}
    public function listing(array $page):string
    {$binding=$this->binding($page);if($binding===null)return 'none';$kind=$this->registry->listing($binding);return $kind==='auto'?match($page['source_slug']??$page['slug']){'aktuelles'=>'news','veranstaltungen'=>'events',default=>'none'}:$kind;}
}
