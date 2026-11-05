<?php declare(strict_types=1);
namespace Kulturhus\Native\Web;
use Kulturhus\Native\Language\PublicResolver;

/** Explicit request context: only fresh public projections enter localized lists and links. */
final class PublishedLanguage
{
    public function __construct(private ?PublicResolver $resolver=null,private ?string $locale=null){}
    public function page(array $current):?array{return $this->resolver!==null&&$this->locale!==null?$this->resolver->project($current,$this->locale):$current;}
    public function event(array $catalogueOriginal):?array
    {
        // A cancellation notice remains a public catalogue original even after its page was withdrawn.
        if(!empty($catalogueOriginal['cancellation_notice']))return $catalogueOriginal;
        $page=$this->page($catalogueOriginal);if($page===null)return null;
        foreach(['event_status','event_sequence','event_changed_at','cancellation_notice','event_source','event_external_id']as $key)if(array_key_exists($key,$catalogueOriginal))$page[$key]=$catalogueOriginal[$key];
        return $page;
    }
    public function path(string $sourceSlug):string
    {
        if($this->resolver===null||$this->locale===null)return $sourceSlug;
        $source=$this->resolver->contextBySourceSlug($sourceSlug);if($source===null)return $sourceSlug;
        if($this->locale===$source['locale'])return $source['canonical_path'];
        $path=$source['alternates'][$this->locale]??$this->locale.'/'.$source['canonical_path'];
        return $this->resolver->resolve($path)['canonical_path']??$sourceSlug;
    }
    public function eventSearch(string $query):?array{return $this->resolver!==null&&$this->locale!==null?$this->resolver->eventSearch($this->locale,$query):null;}
    public static function pagePath(array $page):string{return $page['language_path']??$page['slug'];}
}
