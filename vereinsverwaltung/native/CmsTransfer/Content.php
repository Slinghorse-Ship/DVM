<?php declare(strict_types=1);
namespace Kulturhus\Native\CmsTransfer;

use InvalidArgumentException;
use Kulturhus\Native\Cms\{ContentSections,CustomFields,FieldConditions,FieldGroups,FieldValues,HomeLayout,NewsChannels,TemplateContexts,TemplatePolicy,Templates,WebsiteDocuments};
use Kulturhus\Native\Files\Metadata;

/** Closed portable values, without runtime identities, hashes of passwords or captured authorities. */
final class Content
{
    public const FIELD_KEYS=['key','label','type','required','min','max','options','show_when','required_when','multiple','max_items','fields','access'];
    public const TEMPLATE_KEYS=['key','label','renderer','fields','groups','policy','field_contexts','url_policy'];
    public const PAGE_KEYS=['title','slug','template','body','body_html','excerpt','seo_title','seo_description','noindex','nav_visible','nav_label','nav_order','parent_id','media_ids','gallery_items','event_start','event_timezone','event_start_utc','event_end','event_location','event_address','event_status','event_ticket_url','event_reference_url','event_price','news_date','news_channels','content_sections','document_links','home_layout','page_policy','custom_fields'];
    public const EXPANDED_FIELDS_LIMIT=2000;
    public const VALUE_LIMIT=20000;
    public const ITEM_LIMIT=2000;
    public const PASSWORD_LIMIT=64;

    public static function field(array $field):array
    {self::portableDefinition($field);return CustomFields::definition(array_intersect_key($field,array_flip(self::FIELD_KEYS)));}
    public static function assertFieldBudget(array $definitions):void
    {
        if(count($definitions)>200)throw new InvalidArgumentException('Höchstens 200 Paketdefinitionen verwenden.');$fields=[];foreach($definitions as$field){self::closed($field,self::FIELD_KEYS,'Felddefinition');$field=CustomFields::definition($field);if(isset($fields[$field['key']]))throw new InvalidArgumentException('Doppelte Felddefinition im Paket.');$fields[$field['key']]=$field;}self::expansion($fields);
    }
    public static function template(array $template):array
    {self::portableDefinition($template);return array_intersect_key($template,array_flip(self::TEMPLATE_KEYS));}
    public static function page(array $page):array
    {
        self::portableDefinition($page);
        if(isset($page['template_definition'])){if(!is_array($page['template_definition']))throw new InvalidArgumentException('Gespeicherte Vorlagendefinition prüfen.');self::portableDefinition($page['template_definition']);}
        foreach($page['field_definitions']??[]as$field){if(!is_array($field))throw new InvalidArgumentException('Gespeicherte Felddefinition prüfen.');self::portableDefinition($field);}
        $out=array_intersect_key($page,array_flip(self::PAGE_KEYS));
        $out['custom_fields']=self::portableValues($page['field_definitions']??[],$page['custom_fields']??[]);
        // Captured PDF names/hashes and legacy source authority are freshly captured in the destination.
        if(isset($out['document_links']))$out['document_links']=array_map(static fn($link)=>array_intersect_key($link,array_flip(['id','label'])),$out['document_links']);
        return $out;
    }

    /** Local reviewed code identities cannot silently turn into different portable native values. */
    private static function portableDefinition(array $definition,int $depth=0):void
    {
        if($depth>4)throw new InvalidArgumentException('Begrenzte gespeicherte Felddefinition prüfen.');
        foreach(['fieldtype','inputfield','frontend_renderer']as$key)if(array_key_exists($key,$definition)&&$definition[$key]!==null)throw new InvalidArgumentException('Lokale Typ- oder Rendererbindungen können nicht als natives CMS-Paket übertragen werden.');
        if(isset($definition['item_definitions'])){
            if(!is_array($definition['item_definitions']))throw new InvalidArgumentException('Gespeicherte Datensatzdefinition prüfen.');
            foreach($definition['item_definitions']as$child){if(!is_array($child))throw new InvalidArgumentException('Gespeicherte Datensatzdefinition prüfen.');self::portableDefinition($child,$depth+1);}
        }
    }

    public static function validate(array $content):array
    {
        $fields=[];
        foreach($content['fields']as$field){self::closed($field,self::FIELD_KEYS,'Felddefinition');$field=CustomFields::definition($field);if(isset($fields[$field['key']]))throw new InvalidArgumentException('Doppelte Felddefinition im Paket.');$fields[$field['key']]=$field;}
        self::expansion($fields);$resolved=[];foreach($fields as$key=>$field)$resolved[$key]=self::resolve($key,$fields,[]);
        $templates=[];
        foreach($content['templates']as$template){
            self::closed($template,self::TEMPLATE_KEYS,'Vorlagendefinition');$key=CustomFields::key($template['key']??null);
            if(isset($templates[$key])||!is_string($template['renderer']??null)||!isset(Templates::all()[$template['renderer']])||!is_array($template['fields']??null)||!array_is_list($template['fields'])||count($template['fields'])>100)throw new InvalidArgumentException('Eindeutige deklarative Vorlage erforderlich.');
            $template['label']=CustomFields::text($template['label']??'',160);if($template['label']==='')throw new InvalidArgumentException('Vorlagenbezeichnung fehlt.');
            foreach($template['fields']as$field)if(!is_string($field)||!isset($fields[$field]))throw new InvalidArgumentException('Vorlagenfeld fehlt im Paket.');if(count(array_unique($template['fields']))!==count($template['fields']))throw new InvalidArgumentException('Doppelte Vorlagenfelder sind nicht erlaubt.');
            if(isset($template['groups']))$template['groups']=FieldGroups::normalize($template['groups'],$template['fields']);
            if(isset($template['policy']))$template['policy']=TemplatePolicy::normalize($template['policy']);
            if(isset($template['field_contexts']))$template['field_contexts']=TemplateContexts::normalize($template['field_contexts'],$template['fields']);
            if(isset($template['url_policy']))$template['url_policy']=\Kulturhus\Native\Routing\Policy::normalize($template['url_policy']);
            TemplateContexts::resolve($template,array_intersect_key($resolved,array_fill_keys($template['fields'],true)));$templates[$key]=$template;
        }
        foreach($fields as$field)FieldConditions::bind($field,$resolved);
        FieldConditions::validate($resolved,array_values($templates));
        foreach($templates as$template)foreach(['parents','children']as$kind)foreach($template['policy'][$kind]??[]as$key)if(!isset($templates[$key]))throw new InvalidArgumentException('Familienvorlage fehlt im Paket.');
        $assets=[];
        foreach($content['assets']as$asset){
            self::closed($asset,['id','kind','name','mime','bytes','sha256','metadata'],'Dateinachweis');self::id($asset['id']??null);
            if(isset($assets[$asset['id']])||!in_array($asset['kind']??null,['image','file','document'],true)||!is_int($asset['bytes']??null)||$asset['bytes']<1||!is_string($asset['sha256']??null)||!preg_match('/\A[a-f0-9]{64}\z/D',$asset['sha256']))throw new InvalidArgumentException('Eindeutigen Dateinachweis prüfen.');
            $asset['name']=CustomFields::text($asset['name']??'',240);$asset['mime']=CustomFields::text($asset['mime']??'',100);if($asset['name']===''||$asset['mime']==='')throw new InvalidArgumentException('Dateiname und Format fehlen.');
            if(!is_array($asset['metadata']??null))throw new InvalidArgumentException('Dateiangaben fehlen.');$asset['metadata']=Metadata::normalize($asset['metadata'],$asset['name']);$assets[$asset['id']]=$asset;
        }
        $pages=[];$budget=['values'=>0,'items'=>0,'passwords'=>0];
        foreach($content['pages']as$page){
            self::closed($page,['id','source_version','payload','field_policy_history'],'Seitenstand');self::id($page['id']??null);
            if(isset($pages[$page['id']])||isset($assets[$page['id']])||!is_int($page['source_version']??null)||$page['source_version']<1)throw new InvalidArgumentException('Eindeutiger gespeicherter Seitenstand erforderlich.');
            self::closed($page['payload']??null,self::PAGE_KEYS,'Seiteninhalt');$payload=$page['payload'];$template=$templates[$payload['template']??'']??throw new InvalidArgumentException('Seitenvorlage fehlt im Paket.');
            Templates::normalize($payload,array_fill_keys(array_keys($templates),true));
            $defs=TemplateContexts::resolve($template,array_intersect_key($resolved,array_fill_keys($template['fields'],true)));
            self::values($defs,$payload['custom_fields']??[],0,$budget);self::pageHistory($page['field_policy_history']??[],$defs);$pages[$page['id']]=$page;
        }
        foreach($pages as$page){$payload=$page['payload'];$defs=TemplateContexts::resolve($templates[$payload['template']],array_intersect_key($resolved,array_fill_keys($templates[$payload['template']]['fields'],true)));foreach(self::references($payload,$defs,'page')as$id)if(!isset($pages[$id]))throw new InvalidArgumentException('Eine Seitenabhängigkeit fehlt im Paket.');foreach(['image','file','document']as$kind)foreach(self::references($payload,$defs,$kind)as$id)if(!isset($assets[$id])||($kind==='image'?$assets[$id]['kind']!=='image':($kind==='document'?$assets[$id]['kind']!=='document':$assets[$id]['kind']==='image')))throw new InvalidArgumentException('Eine passende Dateiabängigkeit fehlt im Paket.');}
        foreach($fields as$field)foreach(['show_when','required_when']as$kind)foreach($field[$kind]['conditions']??[]as$rule){$type=$fields[$rule['field']]['type']??'';$literal=$rule['value']??null;if(!is_string($literal))continue;
            if($type==='page-reference'&&!isset($pages[$literal]))throw new InvalidArgumentException('Der Seitenbezug einer Feldregel fehlt im Paket.');
            if($type==='media-reference'&&(!isset($assets[$literal])||$assets[$literal]['kind']!=='image'))throw new InvalidArgumentException('Der Bildbezug einer Feldregel fehlt im Paket.');
            if($type==='file-reference'&&(!isset($assets[$literal])||$assets[$literal]['kind']==='image'))throw new InvalidArgumentException('Der Dateibezug einer Feldregel fehlt im Paket.');
        }
        $languages=[];$seen=[];
        foreach($content['languages']as$row){self::closed($row,['page_id','locale','payload','field_policy_history'],'Sprachentwurf');self::id($row['page_id']??null);$locale=\Kulturhus\Native\Language\Settings::code($row['locale']??null);self::closed($row['payload']??null,['slug','fields'],'Sprachinhalt');if(!isset($pages[$row['page_id']])||isset($seen[$row['page_id'].':'.$locale])||!is_array($row['payload']['fields']??null))throw new InvalidArgumentException('Eindeutiger Sprachentwurf einer Paket-Seite erforderlich.');self::language($row['payload'],$pages[$row['page_id']]['payload'],$templates,$resolved);self::languagePolicy($row['field_policy_history']??[],$row['payload']['fields']['custom_fields']??[]);$seen[$row['page_id'].':'.$locale]=true;$languages[]=$row;}
        return ['fields'=>$fields,'resolved'=>$resolved,'templates'=>$templates,'pages'=>$pages,'assets'=>$assets,'languages'=>$languages];
    }

    public static function references(array $payload,array $definitions,string $kind):array
    {
        $out=match($kind){'page'=>empty($payload['parent_id'])?[]:[$payload['parent_id']],'image'=>[...($payload['media_ids']??[]),...ContentSections::mediaIds($payload)],'document'=>array_column($payload['document_links']??[],'id'),'file'=>[],default=>throw new InvalidArgumentException('Referenzart prüfen.')};
        $types=match($kind){'page'=>['page-reference','pagetable'],'image'=>['media-reference'],'file'=>['file-reference'],default=>[]};
        self::walk($definitions,$payload['custom_fields']??[],function(array$field,mixed$value)use(&$out,$types){if(!in_array($field['type'],$types,true)||$value===null)return;foreach(FieldValues::collection($field)?$value:[$value]as$id)$out[]=$id;});
        return array_values(array_unique($out));
    }

    public static function modelMap(array $model,string $prefix):array
    {
        $map=['fields'=>[],'templates'=>[]];foreach(['fields','templates']as$kind){$used=[];foreach($model[$kind]as$key=>$_){$target=substr($prefix.'_'.$key,0,38).'_'.substr(hash('sha256',$key),0,24);if(isset($used[$target]))throw new InvalidArgumentException('Modellkennungen lassen sich nicht eindeutig zuordnen. Andere Importkennung wählen.');$used[$target]=true;$map[$kind][$key]=$target;}}return $map;
    }
    public static function mappedField(array $field,array $map,array $ids=[],array $definitions=[]):array
    {
        $oldKey=$field['key'];$field['key']=$map['fields'][$oldKey];if(isset($field['fields']))$field['fields']=array_map(static fn($key)=>$map['fields'][$key],$field['fields']);
        foreach(['show_when','required_when']as$kind)foreach($field[$kind]['conditions']??[]as$i=>$rule){$field[$kind]['conditions'][$i]['field']=$map['fields'][$rule['field']];$reference=in_array($definitions[$rule['field']]['type']??null,['page-reference','media-reference','file-reference'],true);if($reference&&isset($rule['value'])&&is_string($rule['value']))$field[$kind]['conditions'][$i]['value']=self::mappedId($rule['value'],$ids);}
        return $field;
    }
    public static function mappedTemplate(array $template,array $map):array
    {
        $template['key']=$map['templates'][$template['key']];$template['fields']=array_map(static fn($key)=>$map['fields'][$key],$template['fields']);
        foreach($template['groups']??[]as$i=>$group)$template['groups'][$i]['fields']=array_map(static fn($key)=>$map['fields'][$key],$group['fields']);
        $contexts=[];foreach($template['field_contexts']??[]as$key=>$context)$contexts[$map['fields'][$key]]=$context;if(isset($template['field_contexts']))$template['field_contexts']=$contexts;
        foreach(['parents','children']as$kind)if(isset($template['policy'][$kind]))$template['policy'][$kind]=array_map(static fn($key)=>$map['templates'][$key],$template['policy'][$kind]);return $template;
    }
    public static function mappedPage(array $payload,array $definitions,array $map,array $ids,array $slugs,string $actor,array $secrets=[],array $sectionIds=[]):array
    {
        $payload['template']=$map['templates'][$payload['template']];$payload['parent_id']=empty($payload['parent_id'])?null:self::mappedId($payload['parent_id'],$ids);
        $payload['media_ids']=array_map(static fn($id)=>self::mappedId($id,$ids),$payload['media_ids']??[]);
        foreach($payload['gallery_items']??[]as$i=>$item)$payload['gallery_items'][$i]['id']=self::mappedId($item['id'],$ids);
        foreach($payload['content_sections']??[]as$i=>$section){$payload['content_sections'][$i]['id']=$sectionIds[$section['id']]??bin2hex(random_bytes(16));if($section['media_id']!==null)$payload['content_sections'][$i]['media_id']=self::mappedId($section['media_id'],$ids);$payload['content_sections'][$i]['button_url']=self::url($section['button_url']??'',$slugs);$payload['content_sections'][$i]['body_html']=self::html($section['body_html']??'',$slugs);}
        foreach($payload['document_links']??[]as$i=>$link)$payload['document_links'][$i]=['id'=>self::mappedId($link['id'],$ids),'label'=>$link['label']??''];
        $payload['custom_fields']=self::mapValues($definitions,$payload['custom_fields']??[],$map,$ids,$slugs,$actor,$secrets,[]);
        $payload['body_html']=self::html($payload['body_html']??'',$slugs);if(isset($payload['home_layout']['hero_html']))$payload['home_layout']['hero_html']=self::html($payload['home_layout']['hero_html'],$slugs);
        $payload['nav_visible']=false;$payload['nav_label']='';$payload['nav_order']=0;return $payload;
    }
    public static function secretInputs(array $definitions,array $values,array $path=[]):array
    {
        $out=[];foreach($definitions as$key=>$field){$next=[...$path,$key];if($field['type']==='password')$out[]=['path'=>$next,'label'=>$field['label'],'required'=>!empty($field['required'])||isset($field['required_when'])];elseif($field['type']==='repeater')foreach($values[$key]??[]as$i=>$item)$out=[...$out,...self::secretInputs($field['item_definitions'],$item['values'],[...$next,$i])];}return $out;
    }
    public static function mappedLanguage(array $payload,array $map,array $slugs,array $sectionIds,array $definitions=[]):array
    {
        if(isset($payload['fields']['custom_fields'])){$mapped=[];foreach($payload['fields']['custom_fields']as$key=>$value)$mapped[$map['fields'][$key]]=($definitions[$key]['type']??null)==='richtext'&&is_string($value)?self::html($value,$slugs):$value;$payload['fields']['custom_fields']=$mapped;}
        if(isset($payload['fields']['content_sections'])){$mapped=[];foreach($payload['fields']['content_sections']as$key=>$value){if(isset($value['body_html']))$value['body_html']=self::html($value['body_html'],$slugs);$mapped[$sectionIds[$key]??throw new InvalidArgumentException('Sprachabschnitt gehört nicht zum Paketoriginal.')]=$value;}$payload['fields']['content_sections']=$mapped;}
        if(isset($payload['fields']['home_layout']['hero_html']))$payload['fields']['home_layout']['hero_html']=self::html($payload['fields']['home_layout']['hero_html'],$slugs);
        if(isset($payload['fields']['body_html']))$payload['fields']['body_html']=self::html($payload['fields']['body_html'],$slugs);return $payload;
    }
    public static function mappedLanguagePolicy(array $history,array $map):array
    {$out=[];foreach($history as$key=>$rules)$out[$map['fields'][$key]??throw new InvalidArgumentException('Sprachrecht gehört nicht zum Paketfeld.')]=$rules;return $out;}
    public static function mappedPageHistory(array $history,array $map):array
    {$out=[];foreach($history as$path=>$rules){$keys=[];foreach(explode('.',$path)as$key)$keys[]=$map['fields'][$key]??throw new InvalidArgumentException('Originalrecht gehört nicht zum Paketfeld.');$out[implode('.',$keys)]=$rules;}return $out;}
    public static function resolve(string $key,array $fields,array $seen):array
    {
        if(isset($seen[$key])||count($seen)>3||!isset($fields[$key]))throw new InvalidArgumentException('Datensatzdefinitionen fehlen oder enthalten einen Kreis.');$field=$fields[$key];$seen[$key]=true;
        if($field['type']==='repeater'){$field['item_definitions']=[];foreach($field['fields']as$child)$field['item_definitions'][$child]=self::resolve($child,$fields,$seen);}return $field;
    }
    private static function portableValues(array $definitions,array $values):array
    {
        $out=[];foreach($definitions as$key=>$field){if($field['type']==='password'||!array_key_exists($key,$values))continue;$value=$values[$key];if($field['type']==='repeater'){$value=[];foreach($values[$key]??[]as$item)$value[]=['values'=>self::portableValues($field['item_definitions'],$item['values']),'public'=>(bool)$item['public'],'restricted'=>!empty($item['editors'])];}$out[$key]=$value;}return $out;
    }
    private static function language(array $payload,array $source,array $templates,array $resolved):void
    {
        $textFields=['title'=>200,'nav_label'=>100,'excerpt'=>1000,'body'=>1000000,'body_html'=>1000000,'seo_title'=>200,'seo_description'=>500,'event_location'=>300,'event_address'=>1000,'event_price'=>200];self::closed($payload['fields'],[...array_keys($textFields),'custom_fields','home_layout','content_sections'],'Sprachfelder');\Kulturhus\Native\Cms\Templates::normalize(['title'=>'Sprachentwurf','slug'=>$payload['slug']??'']);
        $template=$templates[$source['template']];$definitions=TemplateContexts::resolve($template,array_intersect_key($resolved,array_fill_keys($template['fields'],true)));
        foreach($payload['fields']as$key=>$value){if(isset($textFields[$key])){CustomFields::text($value,$textFields[$key]);continue;}
            if($key==='custom_fields'){if(!is_array($value))throw new InvalidArgumentException('Sprachfelder prüfen.');foreach($value as$name=>$translated){$field=$definitions[$name]??null;if(!$field||!in_array($field['type'],['text','textarea','richtext'],true)||FieldValues::collection($field)||($field['display']??'visible')!=='visible')throw new InvalidArgumentException('Nur sichtbare originale Textfelder übersetzen.');CustomFields::value($field,$translated);}}
            elseif($key==='home_layout'){self::closed($value,array_diff(array_keys(HomeLayout::LABELS),['sections']),'Startseitentexte');HomeLayout::normalize(array_replace(HomeLayout::defaults(),$source['home_layout']??[],$value));}
            else{if(!is_array($value)||count($value)>20)throw new InvalidArgumentException('Sprachabschnitte prüfen.');$sections=array_column($source['content_sections']??[],null,'id');foreach($value as$id=>$texts){if(!isset($sections[$id]))throw new InvalidArgumentException('Sprachabschnitt fehlt im Paketoriginal.');self::closed($texts,['heading','body_html','alt','caption','button_label'],'Sprachabschnitt');foreach($texts as$name=>$text)CustomFields::text($text,$name==='body_html'?20000:500);}}
        }
    }
    private static function pageHistory(mixed $history,array $definitions):void
    {
        foreach(\Kulturhus\Native\Cms\FieldPolicy::normalizeHistory($history)as$path=>$_){$level=$definitions;$parts=explode('.',$path);foreach($parts as$i=>$key){$field=$level[$key]??throw new InvalidArgumentException('Originalrecht benötigt einen vollständigen eigenen Paket-Feldpfad.');if($i<count($parts)-1&&$field['type']!=='repeater')throw new InvalidArgumentException('Originalrecht-Unterpfad benötigt eine Datensatzdefinition.');$level=$field['item_definitions']??[];}}
    }
    private static function languagePolicy(mixed $history,array $values):void
    {
        if(!is_array($history)||($history!==[]&&array_is_list($history))||array_diff(array_keys($history),array_keys($values)))throw new InvalidArgumentException('Sprachrechte benötigen eigene übersetzte Textfelder.');
        foreach($history as$rules){self::closed($rules,['read','edit'],'Sprachrechte');foreach(['read'=>['read','edit','publish','manage'],'edit'=>['edit','publish','manage']]as$kind=>$allowed){$caps=$rules[$kind]??null;if(!is_array($caps)||!array_is_list($caps)||!in_array($kind,$caps,true)||count($caps)>count($allowed)||count(array_filter($caps,'is_string'))!==count($caps)||$caps!==array_values(array_intersect($allowed,$caps)))throw new InvalidArgumentException('Sprachrechte müssen eindeutige geordnete native Berechtigungen sein.');}}
    }
    private static function values(array $definitions,mixed $values,int $depth,array &$budget):void
    {
        if(!is_array($values)||($values!==[]&&array_is_list($values))||array_diff(array_keys($values),array_keys($definitions))||$depth>4)throw new InvalidArgumentException('Unbekannte oder zu tiefe Feldwerte im Paket.');
        foreach($definitions as$field)if($field['type']==='password'&&++$budget['passwords']>self::PASSWORD_LIMIT)throw new InvalidArgumentException('Paket auf höchstens 64 lokale Passwortfelder begrenzen.');
        foreach($values as$key=>$value){$field=$definitions[$key];$budget['values']+=is_array($value)?count($value):1;if($budget['values']>self::VALUE_LIMIT)throw new InvalidArgumentException('Paket auf höchstens 20.000 einzelne Feldwerte begrenzen.');if($field['type']==='password')throw new InvalidArgumentException('Passwortwerte gehören nicht in ein CMS-Paket.');if($field['type']==='repeater'){if(!is_array($value)||!array_is_list($value)||count($value)>($field['max_items']??50))throw new InvalidArgumentException('Datensatzliste prüfen.');foreach($value as$item){if(++$budget['items']>self::ITEM_LIMIT)throw new InvalidArgumentException('Paket auf höchstens 2000 Datensätze begrenzen.');self::closed($item,['values','public','restricted'],'Datensatz');if(!is_bool($item['public']??null)||!is_bool($item['restricted']??null))throw new InvalidArgumentException('Datensatzsichtbarkeit prüfen.');self::values($field['item_definitions'],$item['values']??null,$depth+1,$budget);}}else{$field['required']=false;CustomFields::value($field,$value);}}
    }
    private static function expansion(array $fields):void
    {
        $costs=[];$active=[];$visit=function(string $key)use(&$visit,&$costs,&$active,$fields):array{
            if(isset($active[$key])||!isset($fields[$key]))throw new InvalidArgumentException('Datensatzdefinitionen fehlen oder enthalten einen Kreis.');if(isset($costs[$key]))return $costs[$key];$active[$key]=true;$nodes=1;$height=1;
            foreach($fields[$key]['fields']??[]as$child){[$childNodes,$childHeight]=$visit($child);$nodes+=$childNodes;$height=max($height,$childHeight+1);if($nodes>100||$height>4)throw new InvalidArgumentException('Verschachtelte Paketfelder überschreiten die sichere Verarbeitungsgrenze.');}
            unset($active[$key]);return $costs[$key]=[$nodes,$height];
        };
        $total=0;foreach(array_keys($fields)as$key){[$nodes]=$visit($key);$total+=$nodes;if($total>self::EXPANDED_FIELDS_LIMIT)throw new InvalidArgumentException('Paket auf höchstens 2000 aufgelöste Feldzuordnungen begrenzen.');}
    }
    private static function mapValues(array $definitions,array $values,array $map,array $ids,array $slugs,string $actor,array $secrets,array $path):array
    {
        $out=[];foreach($definitions as$key=>$field){$next=[...$path,$key];$dest=$map['fields'][$key];if($field['type']==='password'){$secret=$secrets[implode('.',$next)]??null;if($secret!==null){if(!is_string($secret)||strlen($secret)>4096)throw new InvalidArgumentException('Lokale Passwortangabe prüfen.');if($secret!=='')$out[$dest]=$secret;}continue;}if(!array_key_exists($key,$values))continue;$value=$values[$key];
            if($field['type']==='repeater'){$value=[];foreach($values[$key]??[]as$i=>$item)$value[]=['version'=>0,'editors'=>$item['restricted']?[$actor]:[],'public'=>$item['public'],'values'=>self::mapValues($field['item_definitions'],$item['values'],$map,$ids,$slugs,$actor,$secrets,[...$next,$i])];}
            elseif(in_array($field['type'],['page-reference','pagetable','media-reference','file-reference'],true)&&$value!==null)$value=FieldValues::collection($field)?array_map(static fn($id)=>self::mappedId($id,$ids),$value):self::mappedId($value,$ids);
            elseif($field['type']==='richtext'&&$value!==null)$value=FieldValues::collection($field)?array_map(static fn($html)=>self::html($html,$slugs),$value):self::html($value,$slugs);
            $out[$dest]=$value;
        }return $out;
    }
    private static function walk(array $definitions,array $values,callable $call):void
    {foreach($definitions as$key=>$field){$value=$values[$key]??null;if($field['type']==='repeater'){foreach($value??[]as$item)self::walk($field['item_definitions'],$item['values'],$call);}else$call($field,$value);}}
    private static function html(string $html,array $slugs):string
    {$html=\Kulturhus\Native\Shared\RichText::sanitize($html);return preg_replace_callback('/\bhref="([^"]*)"/i',static fn($m)=>'href="'.htmlspecialchars(self::url(html_entity_decode($m[1],ENT_QUOTES|ENT_HTML5,'UTF-8'),$slugs),ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8').'"',$html)??$html;}
    private static function url(string $url,array $slugs):string
    {foreach($slugs as$old=>$new)foreach(['/', '/site/']as$prefix)if($url===$prefix.$old||str_starts_with($url,$prefix.$old.'#')||str_starts_with($url,$prefix.$old.'?'))return $prefix.$new.substr($url,strlen($prefix.$old));return $url;}
    private static function mappedId(string $id,array $map):string
    {return $map[$id]??throw new InvalidArgumentException('Nicht zugeordnete Paketabhängigkeit.');}
    private static function closed(mixed $value,array $keys,string $label):void
    {if(!is_array($value)||($value!==[]&&array_is_list($value))||array_diff(array_keys($value),$keys))throw new InvalidArgumentException($label.': unbekannte Eigenschaft.');}
    private static function id(mixed $id):void
    {if(!is_string($id)||!preg_match('/\A[a-f0-9]{32}\z/D',$id))throw new InvalidArgumentException('Gültige lokale Paketkennung erforderlich.');}
}
