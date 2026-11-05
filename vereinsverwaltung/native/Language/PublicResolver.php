<?php declare(strict_types=1);
namespace Kulturhus\Native\Language;
use Kulturhus\Native\Shared\{Access,Db};
use Kulturhus\Native\Cms\{NewsChannels,FieldPolicy};
/** Reads only current public originals and explicitly published, matching translation revisions. */
final class PublicResolver
{
    public function __construct(private \PDO $db,private Access $access){}
    /** Language switch links for an unprefixed current source page; stale caller snapshots are refused. */
    public function sourceContext(array $published):?array
    {
        $id=$published['id']??null;$revision=$published['published_revision']??$published['published_version']??$published['version']??null;
        if(!is_string($id)||!preg_match('/\A[a-f0-9]{32}\z/D',$id)||!is_int($revision))return null;
        $row=$this->publicOriginal('p.id=?',[$id]);if(!$row||(int)$row['published_revision']!==$revision)return null;
        $config=(new Settings($this->db,$this->access))->state()['config'];$payload=json_decode($row['payload_json'],true,64,JSON_THROW_ON_ERROR);
        return ['locale'=>$config['source'],'content_locale'=>$config['source'],'path'=>$payload['slug'],'canonical_path'=>$payload['slug'],'redirect'=>null,'alternates'=>$this->alternates($row,$config)];
    }
    public function contextBySourceSlug(string $slug):?array
    {
        if(strlen($slug)>180||!preg_match('~\A[a-z0-9]+(?:-[a-z0-9]+)*(?:/[a-z0-9]+(?:-[a-z0-9]+)*)*\z~D',$slug))return null;
        $row=$this->publicOriginal('p.published_slug=?',[$slug]);if(!$row)return null;return $this->sourceContext(['id'=>$row['id'],'published_revision'=>(int)$row['published_revision']]);
    }
    /** Renderer adapter: validates identity/revision, then rereads public data rather than trusting caller text. */
    public function project(array $published,string $locale):?array
    {
        Settings::code($locale);$context=$this->sourceContext($published);if(!$context)return null;
        if($locale!==$context['locale'])return $this->resolve($context['alternates'][$locale]??$locale.'/'.$context['canonical_path'])['page']??null;
        $row=$this->publicOriginal('p.id=?',[$published['id']]);if(!$row)return null;$page=json_decode($row['payload_json'],true,64,JSON_THROW_ON_ERROR);$page=FieldPolicy::publicSource($this->db,$this->access->club(),$page);$page['custom_fields']=\Kulturhus\Native\Cms\FieldValues::publicValues($page['field_definitions']??[],$page['custom_fields']??[]);if(isset($page['custom_field_text']))$page['custom_field_text']=array_intersect_key($page['custom_field_text'],$page['custom_fields']);
        return $page+['id'=>$row['id'],'version'=>(int)$row['published_revision'],'published_revision'=>(int)$row['published_revision'],'published_version'=>(int)$row['published_revision'],'published_at'=>$row['published_at'],'language'=>$locale,'content_language'=>$locale,'language_path'=>$page['slug'],'source_slug'=>$page['slug']];
    }
    /** Fixed public aliases p/r; independent subqueries keep catalogue counts and pagination exact. */
    public function eventSearch(string $locale,string $query):array
    {
        $this->access->require('website','read');$settings=new Settings($this->db,$this->access);$chain=$settings->chain($locale);$query=Settings::text($query,150);if($query==='')return ['1=1',[]];
        $source=$settings->state()['config']['source'];$locales=array_values(array_filter($chain,static fn(string $code):bool=>$code!==$source));$columns=[];$parameters=[$query];$markers=implode(',',array_fill(0,count($locales),'?'));
        foreach(['title','excerpt','body','event_location','event_address'] as $field){
            $original="JSON_UNQUOTE(JSON_EXTRACT(r.payload_json,'$.".$field."'))";
            if(!$locales){$columns[]=$original;continue;}
            $path="'$.fields.".$field."'";$columns[]='COALESCE((SELECT JSON_UNQUOTE(JSON_EXTRACT(lr.payload_json,'.$path.')) FROM native_language_pages lp JOIN native_language_revisions lr ON lr.club_id=lp.club_id AND lr.page_id=lp.page_id AND lr.locale=lp.locale AND lr.revision=lp.published_revision WHERE lp.club_id=? AND lp.page_id=p.id AND lp.locale IN ('.$markers.') AND lr.source_revision=p.published_revision AND JSON_CONTAINS_PATH(lr.payload_json,\'one\','.$path.')=1 ORDER BY FIELD(lp.locale,'.$markers.') LIMIT 1),'.$original.')';
            array_push($parameters,$this->access->club(),...$locales,...$locales);
        }
        return ["LOCATE(? COLLATE utf8mb4_unicode_ci,CONCAT_WS(' ',".implode(',',$columns).') COLLATE utf8mb4_unicode_ci)>0',$parameters];
    }
    public function resolve(string $path):?array
    {
        if(strlen($path)>197||!preg_match('~\A([a-z]{2,3}(?:-[a-z0-9]{2,8}){0,3})/([a-z0-9]+(?:-[a-z0-9]+)*(?:/[a-z0-9]+(?:-[a-z0-9]+)*)*)\z~D',$path,$parts))return null;
        $settings=new Settings($this->db,$this->access);$config=$settings->state()['config'];$locale=$parts[1];$slug=$parts[2];$known=[];foreach($config['languages'] as $language)$known[$language['code']]=$language;
        if(!isset($known[$locale])||!$known[$locale]['enabled']||$locale===$config['source'])return null;
        $route=Db::run($this->db,'SELECT page_id FROM native_language_routes WHERE club_id=? AND locale=? AND slug=?',[$this->access->club(),$locale,$slug])->fetchColumn();
        $row=$this->publicOriginal($route?'p.id=?':'p.published_slug=?',[$route?:$slug]);if(!$row)return null;
        $page=json_decode($row['payload_json'],true,64,JSON_THROW_ON_ERROR);$chain=$settings->chain($locale);$patches=[];$current=null;
        foreach($chain as $code){if($code===$config['source'])continue;
            $translation=Db::run($this->db,'SELECT r.payload_json FROM native_language_pages p JOIN native_language_revisions r ON r.club_id=p.club_id AND r.page_id=p.page_id AND r.locale=p.locale AND r.revision=p.published_revision WHERE p.club_id=? AND p.page_id=? AND p.locale=? AND r.source_revision=?',[$this->access->club(),$row['id'],$code,(int)$row['published_revision']])->fetchColumn();
            if($translation){$payload=json_decode($translation,true,64,JSON_THROW_ON_ERROR);$patches[$code]=$payload;if($code===$locale)$current=$payload;}
        }
        // A retired or stale translation URL does not silently become another page's fallback.
        if($route&&!$current&&$slug!==$page['slug'])return null;
        foreach(array_reverse($chain) as $code)if(isset($patches[$code]))$page=Translations::publicOverlay($this->db,$this->access->club(),$page,$patches[$code]);
        $page=FieldPolicy::publicSource($this->db,$this->access->club(),$page);$page['custom_fields']=\Kulturhus\Native\Cms\FieldValues::publicValues($page['field_definitions']??[],$page['custom_fields']??[]);
        if(isset($page['custom_field_text']))$page['custom_field_text']=array_intersect_key($page['custom_field_text'],$page['custom_fields']);
        $target=$locale.'/'.($current['slug']??$page['slug']);$contentLocale=$patches?array_key_first($patches):$config['source'];
        $page+=['id'=>$row['id'],'version'=>(int)$row['published_revision'],'published_revision'=>(int)$row['published_revision'],'published_version'=>(int)$row['published_revision'],'published_at'=>$row['published_at']];
        $page['language']=$locale;$page['content_language']=$contentLocale;$page['language_path']=$target;$page['source_slug']=$page['slug'];
        return ['page'=>$page,'locale'=>$locale,'content_locale'=>$contentLocale,'path'=>$path,'canonical_path'=>$target,'redirect'=>$target!==$path?$target:null,'alternates'=>$this->alternates($row,$config)];
    }
    /** Localized published menu labels and paths; retains original ordering/parent identities. */
    public function navigation(array $links,string $locale):array
    {
        $settings=new Settings($this->db,$this->access);$settings->language($locale);
        foreach($links as &$link){$original=$this->publicOriginal('p.id=?',[$link['id']]);if(!$original)continue;$config=$settings->state()['config'];$alternates=$this->alternates($original,$config);$path=$alternates[$locale]??$locale.'/'.$link['slug'];$resolved=$this->resolve($path);if(!$resolved)continue;$link['slug']=$resolved['canonical_path'];$link['title']=$resolved['page']['title'];$link['label']=$resolved['page']['nav_label']?:$resolved['page']['title'];}
        return $links;
    }
    public function alternates(array $original,array $config):array
    {
        $payload=json_decode($original['payload_json'],true,64,JSON_THROW_ON_ERROR);$links=[$config['source']=>$payload['slug']];
        foreach($config['languages'] as $language){if(!$language['enabled']||$language['code']===$config['source'])continue;
            $json=Db::run($this->db,'SELECT r.payload_json FROM native_language_pages p JOIN native_language_revisions r ON r.club_id=p.club_id AND r.page_id=p.page_id AND r.locale=p.locale AND r.revision=p.published_revision WHERE p.club_id=? AND p.page_id=? AND p.locale=? AND r.source_revision=?',[$this->access->club(),$original['id'],$language['code'],(int)$original['published_revision']])->fetchColumn();
            if($json)$links[$language['code']]=$language['code'].'/'.json_decode($json,true,64,JSON_THROW_ON_ERROR)['slug'];
        }return $links;
    }
    private function publicOriginal(string $where,array $parameters):?array
    {
        $this->access->require('website','read');
        [$policy,$policyParameters]=\Kulturhus\Native\Cms\TemplatePolicy::readFilter($this->db,$this->access,'p',true);
        return Db::run($this->db,'SELECT p.id,p.published_revision,p.published_at,r.payload_json FROM khcms_pages p JOIN khcms_revisions r ON r.club_id=p.club_id AND r.page_id=p.id AND r.revision=p.published_revision LEFT JOIN khcms_archives a ON a.page_id=p.id AND a.club_id=p.club_id LEFT JOIN khcms_trash t ON t.page_id=p.id AND t.club_id=p.club_id WHERE p.club_id=? AND '.$where.' AND a.page_id IS NULL AND t.page_id IS NULL AND '.NewsChannels::where('website').' AND ('.$policy.') LIMIT 1',[$this->access->club(),...$parameters,...$policyParameters])->fetch()?:null;
    }
}
