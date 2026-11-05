<?php declare(strict_types=1);
namespace Kulturhus\Native\Language;
use Kulturhus\Native\Shared\{Access,Db};
/** Framework-neutral endpoint. The caller supplies its current authenticated native Access and CSRF token. */
final class Controller
{
    public function __construct(private \PDO $db,private Access $access){}
    public function handle(string $method,array $input,string $csrf):array
    {
        $this->access->require('website','read');
        if(!in_array($method,['GET','HEAD','POST'],true))throw new \DomainException('Diese Anfrage ist nicht erlaubt.');
        if($method!=='POST')return $this->state($input);
        if($csrf===''||!is_string($input['csrf']??null)||!hash_equals($csrf,$input['csrf']))throw new \DomainException('Formular abgelaufen. Bitte neu laden.');
        $settings=new Settings($this->db,$this->access);$translations=new Translations($this->db,$this->access);$action=self::scalar($input,'action');
        if($action==='language-preference'){
            (new Catalog($this->db,$this->access))->select(self::scalar($input,'admin_locale'));
        }elseif($action==='language-settings'){
            $config=$settings->state()['config'];$rows=$input['languages']??null;if(!is_array($rows)||count($rows)>20)throw new \InvalidArgumentException('Sprachen prüfen.');$languages=[];
            foreach($rows as $row){if(!is_array($row))throw new \InvalidArgumentException('Sprachen prüfen.');$languages[]=['code'=>self::scalar($row,'code'),'label'=>self::scalar($row,'label'),'enabled'=>self::flag($row,'enabled'),'fallback'=>self::scalar($row,'fallback')?:null,'messages'=>json_decode(self::scalar($row,'messages','{}'),true,8,JSON_THROW_ON_ERROR)];}
            $new=self::scalar($input,'new_code');if($new!=='')$languages[]=['code'=>$new,'label'=>self::scalar($input,'new_label'),'enabled'=>true,'fallback'=>self::scalar($input,'new_fallback')?:$config['source'],'messages'=>[]];
            $settings->save(['source'=>$config['source'],'languages'=>$languages],self::integer($input,'version'));
        }elseif($action==='language-pack'){
            $config=$settings->state()['config'];$locale=self::scalar($input,'locale');$settings->language($locale,false);$pack=$input['message_pack']??null;if(!is_array($pack)||count($pack)>10||array_diff(array_keys($pack),Catalog::keys()))throw new \InvalidArgumentException('Sprachpaket prüfen.');
            foreach($config['languages'] as &$language)if($language['code']===$locale)foreach($pack as $key=>$value){$value=Settings::text($value,500);if($value==='')unset($language['messages'][$key]);else $language['messages'][$key]=$value;}unset($language);$settings->save($config,self::integer($input,'version'));
        }elseif($action==='language-rights'){
            $caps=[];foreach(['edit','publish'] as $cap)if(self::flag($input,$cap))$caps[]=$cap;
            $settings->saveRights(self::scalar($input,'subject'),self::scalar($input,'locale'),$caps,self::integer($input,'version'));
        }elseif(in_array($action,['language-save','language-publish','language-unpublish','language-restore'],true)){
            $page=self::scalar($input,'page');$locale=self::scalar($input,'locale');$version=self::integer($input,'version');
            if($action==='language-save'){
                $fields=$input['fields']??[];if(!is_array($fields))throw new \InvalidArgumentException('Übersetzungsfelder prüfen.');
                // Unchecked fields remain genuine fallbacks, rather than accidental empty translations.
                $selected=$input['translated_fields']??(isset($input['translation_selection'])?[]:array_keys($fields));if(!is_array($selected)||!array_is_list($selected)||array_diff($selected,array_keys($fields)))throw new \InvalidArgumentException('Übersetzungsfelder prüfen.');$fields=array_intersect_key($fields,array_flip($selected));
                if(isset($input['additional_fields'])){$additional=json_decode(self::scalar($input,'additional_fields'),true,32,JSON_THROW_ON_ERROR);if(!is_array($additional)||array_diff(array_keys($additional),['custom_fields','content_sections','home_layout']))throw new \InvalidArgumentException('Zusatztexte prüfen.');$fields=array_replace($fields,$additional);}
                if(isset($input['home_text'])){$values=$input['home_text'];$selected=$input['selected_home']??[];if(!is_array($values)||!is_array($selected)||!array_is_list($selected)||array_diff($selected,array_keys($values)))throw new \InvalidArgumentException('Startseitentexte prüfen.');if($selected)$fields['home_layout']=array_intersect_key($values,array_flip($selected));}
                if(isset($input['custom_text'])){$values=$input['custom_text'];$selected=$input['selected_custom']??[];if(!is_array($values)||!is_array($selected)||!array_is_list($selected)||array_diff($selected,array_keys($values)))throw new \InvalidArgumentException('Zusatztexte prüfen.');if($selected)$fields['custom_fields']=array_intersect_key($values,array_flip($selected));}
                if(isset($input['section_text'])){$values=$input['section_text'];$selected=$input['selected_sections']??[];if(!is_array($values)||!is_array($selected)||array_diff(array_keys($selected),array_keys($values)))throw new \InvalidArgumentException('Abschnittstexte prüfen.');$sections=[];foreach($selected as $id=>$names){if(!is_array($values[$id])||!is_array($names)||!array_is_list($names)||array_diff($names,array_keys($values[$id])))throw new \InvalidArgumentException('Abschnittstexte prüfen.');if($names)$sections[$id]=array_intersect_key($values[$id],array_flip($names));}if($sections)$fields['content_sections']=$sections;}
                $translations->save($page,$locale,['slug'=>self::scalar($input,'slug'),'fields'=>$fields],self::integer($input,'source_revision'),$version);
            }elseif($action==='language-publish')$translations->publish($page,$locale,$version);
            elseif($action==='language-unpublish')$translations->unpublish($page,$locale,$version);
            else $translations->restore($page,$locale,self::integer($input,'revision'),$version);
        }else throw new \DomainException('Unbekannte Sprachaktion.');
        return ['saved'=>true,'state'=>$this->state($input)];
    }
    public function state(array $input):array
    {
        $actor=$this->access->require('website','read');$settings=new Settings($this->db,$this->access);$state=$settings->state();$mode=self::scalar($input,'mode','pages');if(!in_array($mode,['pages','edit','history','settings','rights','pack'],true))throw new \InvalidArgumentException('Sprachansicht prüfen.');
        $manage=false;try{$this->access->require('website','manage');$manage=true;}catch(\DomainException){}
        $locales=array_values(array_filter($state['config']['languages'],static fn(array $language):bool=>$language['code']!==$state['config']['source']&&($language['enabled']||($manage&&in_array($mode,['rights','pack'],true)))));
        if(!$manage)$locales=array_values(array_filter($locales,static function(array $language)use($settings):bool{try{$settings->require($language['code'],'edit');return true;}catch(\DomainException){return false;}}));
        $locale=self::scalar($input,'locale',$locales[0]['code']??$state['config']['source']);$settings->language($locale,false);
        if(in_array($mode,['settings','rights','pack'],true)&&!$manage)throw new \DomainException('Für die Sprachverwaltung fehlt die Berechtigung.');
        $result=['mode'=>$mode,'settings'=>$state,'can_manage'=>$manage,'locale'=>$locale,'admin_locale'=>(new Catalog($this->db,$this->access))->locale(),'admin_languages'=>(new Catalog($this->db,$this->access))->available(),'messages'=>(new Catalog($this->db,$this->access))->messages(),'languages'=>$locales];
        if($mode==='pack'){$page=isset($input['pack_page'])?self::integer($input,'pack_page'):0;if($page>intdiv(count(Catalog::keys())-1,10))throw new \InvalidArgumentException('Sprachpaketseite prüfen.');$result['pack_page']=$page;$result['pack_keys']=array_slice(Catalog::keys(),$page*10,10);$result['pack']=$settings->language($locale,false)['messages'];$result['pack_defaults']=(new Catalog($this->db,$this->access))->messages($locale);return $result;}
        if($mode==='rights'){
            $result['people']=$this->access->directory('website');$subject=self::scalar($input,'subject',$result['people'][0]['id']??'');$result['subject']=$subject;$result['rights']=$subject!==''?$settings->rights($subject,$locale):['version'=>0,'capabilities'=>[]];return $result;
        }
        if(in_array($mode,['edit','history'],true)){
            $page=self::scalar($input,'page');$translations=new Translations($this->db,$this->access);$result['translation']=$translations->get($page,$locale);if(isset($input['source_revision'])&&$mode==='edit')$result['translation']['source_revision']=self::integer($input,'source_revision');$result['source']=$translations->sourceSnapshot($page,$locale,$result['translation']['source_revision']);$result['can_publish']=true;
            try{$settings->require($locale,'publish');}catch(\DomainException){$result['can_publish']=false;}
            if($mode==='history')$result['history']=$translations->revisions($page,$locale,isset($input['before'])?self::integer($input,'before'):4294967295);return $result;
        }
        if($mode==='pages'&&$locales){
            $settings->require($locale,'edit');$after=self::scalar($input,'after');if($after!==''&&!preg_match('/\A[a-f0-9]{32}\z/D',$after))throw new \InvalidArgumentException('Seitenstand prüfen.');$query=Settings::text(self::scalar($input,'q'),120);
            $filter=$after!==''?' AND p.id>?':'';$params=[$this->access->club()];if($after!=='')$params[]=$after;
            if($query!==''){$filter.=" AND JSON_UNQUOTE(JSON_EXTRACT(p.draft_json,'$.title')) LIKE ? ESCAPE '!'";$params[]='%'.str_replace(['!','%','_'],['!!','!%','!_'],$query).'%';}
            [$policy,$policyParameters]=\Kulturhus\Native\Cms\TemplatePolicy::readFilter($this->db,$this->access,'p',false,'edit');
            $rows=Db::run($this->db,'SELECT p.id,p.version,p.published_revision,JSON_UNQUOTE(JSON_EXTRACT(p.draft_json,\'$.title\')) AS title,p.slug FROM khcms_pages p LEFT JOIN khcms_archives a ON a.club_id=p.club_id AND a.page_id=p.id LEFT JOIN khcms_trash t ON t.club_id=p.club_id AND t.page_id=p.id WHERE p.club_id=? AND a.page_id IS NULL AND t.page_id IS NULL'.$filter.' AND ('.$policy.') ORDER BY p.id LIMIT 21',[...$params,...$policyParameters])->fetchAll();
            $result['has_more']=count($rows)>20;$result['pages']=array_slice($rows,0,20);$result['query']=$query;
        }return $result;
    }
    private static function scalar(array $input,string $key,string $default=''):string{if(!is_string($input[$key]??$default))throw new \InvalidArgumentException('Textangabe prüfen.');return $input[$key]??$default;}
    private static function flag(array $input,string $key):bool{if(!isset($input[$key]))return false;if($input[$key]!=='1')throw new \InvalidArgumentException('Schalter prüfen.');return true;}
    private static function integer(array $input,string $key):int{$value=self::scalar($input,$key);if(!preg_match('/\A(?:0|[1-9][0-9]{0,9})\z/D',$value)||(float)$value>4294967294)throw new \InvalidArgumentException('Versionsstand prüfen.');return (int)$value;}
}
