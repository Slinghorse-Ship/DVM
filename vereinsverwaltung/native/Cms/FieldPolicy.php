<?php declare(strict_types=1);
namespace Kulturhus\Native\Cms;

use DomainException;
use InvalidArgumentException;
use Kulturhus\Native\Shared\{Access,Db};
use PDO;

/** Current and captured native field rights, shared by storage, queries and presentation. */
final class FieldPolicy
{
    public const DEFAULTS=['read'=>'read','edit'=>'edit'];

    public static function normalize(mixed $input):array
    {
        if(!is_array($input)||($input!==[]&&array_is_list($input))||array_diff(array_keys($input),['read','edit']))throw new InvalidArgumentException('Feldrechte nur für Lesen und Bearbeiten festlegen.');
        if($input===[])return [];
        $rules=array_replace(self::DEFAULTS,$input);
        if(!in_array($rules['read'],['read','edit','publish','manage'],true)||!in_array($rules['edit'],['edit','publish','manage'],true))throw new InvalidArgumentException('Feldrechte müssen die Website-Grundberechtigung beibehalten.');
        return $rules;
    }

    /** Portable restrictions contain native roles only, never identities or source definitions. */
    public static function normalizeHistory(mixed $input):array
    {
        if(!is_array($input)||($input!==[]&&array_is_list($input))||count($input)>10000)throw new InvalidArgumentException('Geschlossene Feldrechtehistorie prüfen.');$result=[];
        foreach($input as$path=>$rules){if(!is_string($path)||count($parts=explode('.',$path))>4)throw new InvalidArgumentException('Historischen Feldpfad prüfen.');foreach($parts as$part)CustomFields::key($part);$result[$path]=self::historyRules($rules);}
        if(strlen(json_encode($result,JSON_THROW_ON_ERROR))>2*1024*1024)throw new InvalidArgumentException('Feldrechtehistorie auf 2 MiB begrenzen.');ksort($result,SORT_STRING);return $result;
    }
    private static function historyRules(mixed $input):array
    {
        if(!is_array($input)||count($input)!==2||array_diff(array_keys($input),['read','edit']))throw new InvalidArgumentException('Geschlossene historische Feldrollen prüfen.');
        foreach(['read'=>['read','edit','publish','manage'],'edit'=>['edit','publish','manage']]as$kind=>$allowed){$roles=$input[$kind]??null;if(!is_array($roles)||!array_is_list($roles)||count(array_filter($roles,'is_string'))!==count($roles)||count($roles)>count($allowed)||array_diff($roles,$allowed)||count(array_unique($roles))!==count($roles)||!in_array($kind,$roles,true))throw new InvalidArgumentException('Native historische Feldrollen prüfen.');$input[$kind]=array_values(array_intersect($allowed,$roles));}return $input;
    }
    private static function definitionHistory(array $field):array
    {
        $rules=array_replace(self::DEFAULTS,self::normalize($field['access']??[]));$history=['read'=>array_values(array_unique(['read',$rules['read']])),'edit'=>array_values(array_unique(['edit',$rules['edit']]))];
        return isset($field['access_history'])?self::joinHistory($history,self::historyRules($field['access_history'])):$history;
    }
    public static function capturedHistory(array $field):array{return self::definitionHistory($field);}
    private static function joinHistory(array $one,array $two):array
    {return self::historyRules(['read'=>array_values(array_unique([...$one['read'],...$two['read']])),'edit'=>array_values(array_unique([...$one['edit'],...$two['edit']]))]);}
    public static function withoutHistory(array $definitions):array
    {
        foreach($definitions as&$field){unset($field['access_history']);if(isset($field['item_definitions']))$field['item_definitions']=self::withoutHistory($field['item_definitions']);}unset($field);return $definitions;
    }
    public static function exportHistory(PDO $db,Access $access,array $payload):array
    {
        self::assertCopy($db,$access,$payload);$catalog=self::catalog($db,$access->club());$history=[];
        $walk=function(array$definitions,string$prefix='',?array$parents=null)use(&$walk,&$history,$catalog):void{
            foreach($definitions as$key=>$field){$path=$prefix.$key;$rules=self::joinHistory(self::definitionHistory($field),self::definitionHistory($catalog[$key]));if($parents!==null)$rules=self::joinHistory($rules,$parents);$history[$path]=$rules;$walk($field['item_definitions']??[],$path.'.',$rules);}
        };$walk($payload['field_definitions']??[]);return self::normalizeHistory($history);
    }
    /** Existing canonical definitions and every declared restriction must independently allow import. */
    public static function bindHistory(PDO $db,Access $access,array $payload,array $history):array
    {
        $history=self::normalizeHistory($history);if(!$history)return $payload;$catalog=self::catalog($db,$access->club());$caps=self::capabilities($access,'edit');$used=[];
        $walk=function(array$definitions,string$prefix='')use(&$walk,$history,&$used,$catalog,$caps):array{
            foreach($definitions as$key=>&$field){$path=$prefix.$key;if(isset($history[$path])){$field['access_history']=self::joinHistory(self::definitionHistory($field),$history[$path]);if(!self::allowed($field,$catalog,$caps,'edit'))throw new DomainException('Für historische Importangaben fehlen native Feldrechte.');$used[$path]=true;}if(isset($field['item_definitions']))$field['item_definitions']=$walk($field['item_definitions'],$path.'.');}unset($field);return $definitions;
        };$payload['field_definitions']=$walk($payload['field_definitions']??[]);if(array_diff_key($history,$used))throw new InvalidArgumentException('Historische Feldrechte gehören zu vorhandenen Originalfeldern.');return $payload;
    }

    /** Same model transaction; immediate public revocation must also invalidate cached HTML/feeds. */
    public static function refresh(PDO $db,Access $access):void
    {
        Db::atomic($db,static function()use($db,$access):void{$club=$access->require('website','manage')['club_id'];Db::run($db,'INSERT IGNORE INTO khcms_state(club_id,generation) VALUES(?,0)',[$club]);Db::run($db,'SELECT generation FROM khcms_state WHERE club_id=? FOR UPDATE',[$club])->fetchColumn();Db::run($db,'UPDATE khcms_state SET generation=generation+1 WHERE club_id=?',[$club]);});
    }

    /** No role cache: every operation uses current active grants, including revocations. */
    private static function capabilities(Access $access,string $operation='read'):array
    {
        $access->require('website','read');if($operation==='edit')$access->require('website','edit');
        $caps=[];foreach(['read','edit','publish','manage'] as $cap)try{$access->require('website',$cap);$caps[]=$cap;}catch(DomainException){}
        return $caps;
    }

    private static function catalog(PDO $db,string $club):array
    {
        if($club===''||strlen($club)>64||str_contains($club,"\0"))throw new InvalidArgumentException('Vereinsbindung für Feldrechte prüfen.');
        $out=[];foreach(Db::run($db,"SELECT definition_key,definition_json FROM khcms_definitions WHERE club_id=? AND kind='field'",[$club])->fetchAll() as $row){
            $field=json_decode($row['definition_json'],true,512,JSON_THROW_ON_ERROR);
            if(!is_array($field)||($field['key']??null)!==$row['definition_key'])throw new DomainException('Aktuelle Feldrechte sind nicht verfügbar.');
            try{self::normalize(array_key_exists('access',$field)?$field['access']:[]);}catch(InvalidArgumentException){throw new DomainException('Aktuelle Feldrechte sind nicht verfügbar.');}$out[$row['definition_key']]=$field;
        }
        return $out;
    }

    private static function allowed(array $snapshot,array $catalog,array $caps,string $operation='read'):bool
    {
        if(!in_array('read',$caps,true)||($operation==='edit'&&!in_array('edit',$caps,true)))return false;
        $key=$snapshot['key']??null;if(!is_string($key)||!isset($catalog[$key]))return false;
        foreach([$catalog[$key],$snapshot] as $definition){
            try{$rules=array_replace(self::DEFAULTS,self::normalize(array_key_exists('access',$definition)?$definition['access']:[]));}catch(InvalidArgumentException){return false;}
            if(!in_array($rules['read'],$caps,true)||($operation==='edit'&&!in_array($rules['edit'],$caps,true)))return false;
            if(array_key_exists('access_history',$definition)){try{$history=self::historyRules($definition['access_history']);}catch(InvalidArgumentException){return false;}if(array_diff($history['read'],$caps)||($operation==='edit'&&array_diff($history['edit'],$caps)))return false;}
        }
        return true;
    }

    public static function canRead(PDO $db,Access $access,array $snapshot):bool
    {try{return self::allowed($snapshot,self::catalog($db,$access->club()),self::capabilities($access));}catch(DomainException){return false;}}

    public static function canEdit(PDO $db,Access $access,array $snapshot):bool
    {try{return self::allowed($snapshot,self::catalog($db,$access->club()),self::capabilities($access,'edit'),'edit');}catch(DomainException){return false;}}

    /** Internal readable snapshot for renderers/indexing. Client DTOs use the methods below. */
    public static function readableSource(PDO $db,Access $access,array $payload):array
    {if(self::emptySource($payload))return $payload;return self::project($payload,self::catalog($db,$access->club()),self::capabilities($access),false);}

    /** Server-side business workflows only. HTTP serializers MUST use privatePayload instead. */
    public static function internalSource(PDO $db,Access $access,array $payload):array
    {if(self::emptySource($payload))return $payload;return self::project($payload,self::catalog($db,$access->club()),self::capabilities($access),false,true);}

    /** Actor-free anonymous source: an administrator never widens public field visibility. */
    public static function publicSource(PDO $db,string $club,array $payload):array
    {if(self::emptySource($payload))return $payload;return self::project($payload,self::catalog($db,$club),['read'],true);}

    private static function emptySource(array $payload):bool
    {return empty($payload['field_definitions'])&&empty($payload['custom_fields'])&&empty($payload['custom_field_text'])&&empty($payload['custom_media_ids'])&&empty($payload['custom_file_metadata']);}

    public static function privatePayload(PDO $db,Access $access,array $payload):array
    {return FieldValues::privatePayload(self::readableSource($db,$access,$payload),$access);}

    public static function publicPayload(PDO $db,string $club,array $payload):array
    {return FieldValues::publicPayload(self::publicSource($db,$club,$payload));}

    private static function project(array $payload,array $catalog,array $caps,bool $public,bool $keepPasswords=false):array
    {
        [$definitions,$values]=self::projectLevel($payload['field_definitions']??[],$payload['custom_fields']??[],$catalog,$caps,$public,0,$keepPasswords);
        $payload['field_definitions']=$definitions;$payload['custom_fields']=$values;
        if(isset($payload['custom_field_text']))$payload['custom_field_text']=array_intersect_key($payload['custom_field_text'],$definitions);
        if(isset($payload['template_definition'])){
            $template=&$payload['template_definition'];$originalFields=$template['fields']??[];$template['fields']=array_values(array_intersect($originalFields,array_keys($definitions)));
            if(isset($template['field_contexts']))$template['field_contexts']=array_intersect_key($template['field_contexts'],$definitions);
            if(isset($template['field_groups']))$template['field_groups']=array_intersect_key($template['field_groups'],$definitions);
            if(isset($template['groups'])&&array_diff($originalFields,array_keys($definitions))){
                $groups=[];foreach($template['groups'] as $group){$group['fields']=array_values(array_intersect($group['fields']??[],array_keys($definitions)));$groups[$group['key']]=$group;}
                do{$changed=false;foreach($groups as $key=>$group)if(!$group['fields']&&!array_filter($groups,static fn(array $child):bool=>($child['parent']??'')===$key)){unset($groups[$key]);$changed=true;}}while($changed);
                $template['groups']=array_values($groups);
            }
            unset($template);
        }
        $media=FieldValues::references($payload,'media');if(isset($payload['custom_media_ids']))$payload['custom_media_ids']=$media;
        if(isset($payload['media_metadata']))$payload['media_metadata']=array_intersect_key($payload['media_metadata'],array_fill_keys([...($payload['media_ids']??[]),...ContentSections::mediaIds($payload),...$media],true));
        if(isset($payload['custom_file_metadata'])){$files=FieldValues::references($payload,'file');$payload['custom_file_metadata']=array_values(array_filter($payload['custom_file_metadata'],static fn(array $file):bool=>in_array($file['id'],$files,true)));}
        return $payload;
    }

    private static function projectLevel(array $definitions,array $values,array $catalog,array $caps,bool $public,int $depth,bool $keepPasswords=false):array
    {
        if($depth>3)throw new DomainException('Feldstruktur ist nicht verfügbar.');$outDefinitions=[];$outValues=[];
        foreach($definitions as $key=>$field){
            if(($field['key']??null)!==$key||!self::allowed($field,$catalog,$caps)||(!$keepPasswords&&($field['type']??null)==='password')||($public&&($field['display']??'visible')==='hidden'))continue;
            $value=$values[$key]??null;
            if(($field['type']??null)==='repeater'){
                $children=[];$items=[];
                foreach($value??[] as $item){if($public&&!($item['public']??true))continue;[$children,$childValues]=self::projectLevel($field['item_definitions']??[],$item['values']??[],$catalog,$caps,$public,$depth+1,$keepPasswords);$item['values']=$childValues;$items[]=$item;}
                if(!$items)[$children]=self::projectLevel($field['item_definitions']??[],[],$catalog,$caps,$public,$depth+1,$keepPasswords);
                $field['item_definitions']=$children;$field['fields']=array_values(array_intersect($field['fields']??[],array_keys($children)));$value=$items;
            }
            $outDefinitions[$key]=$field;$outValues[$key]=$value;
        }
        foreach($outDefinitions as &$field)foreach(['show_when','required_when'] as $kind)foreach($field[$kind]['conditions']??[] as $condition)if(!isset($outDefinitions[$condition['field']])){unset($field[$kind]);break;}unset($field);
        return [$outDefinitions,$outValues];
    }

    /** Safe controls derived from current AND original definitions; this is never a saved schema. */
    public static function editorDefinitions(PDO $db,Access $access,array $definitions,?array $captured=null):array
    {
        return self::editorLevel($definitions,$captured??[] ,self::catalog($db,$access->club()),self::capabilities($access),0);
    }

    private static function editorLevel(array $definitions,array $captured,array $catalog,array $caps,int $depth):array
    {
        if($depth>3)throw new DomainException('Feldstruktur ist nicht verfügbar.');$result=[];
        foreach($definitions as $key=>$field){
            $old=$captured[$key]??null;
            if(!self::allowed($field,$catalog,$caps)||($old!==null&&!self::allowed($old,$catalog,$caps)))continue;
            if(!self::allowed($field,$catalog,$caps,'edit')||($old!==null&&!self::allowed($old,$catalog,$caps,'edit'))){if(($field['display']??'visible')!=='hidden')$field['display']='readonly';}
            if(($field['type']??null)==='repeater'){
                $field['_field_items_editable']=true;try{self::assertLevelEditable($field['item_definitions']??[],$catalog,$caps,$depth+1);if($old!==null)self::assertLevelEditable($old['item_definitions']??[],$catalog,$caps,$depth+1);}catch(DomainException){$field['_field_items_editable']=false;}
                $field['item_definitions']=self::editorLevel($field['item_definitions']??[],$old['item_definitions']??[],$catalog,$caps,$depth+1);
                $field['fields']=array_values(array_intersect($field['fields']??[],array_keys($field['item_definitions'])));
            }
            $result[$key]=$field;
        }
        foreach($result as &$field)foreach(['show_when','required_when'] as $kind)foreach($field[$kind]['conditions']??[] as $condition)if(!isset($result[$condition['field']])){unset($field[$kind]);if(($field['display']??'visible')!=='hidden')$field['display']='readonly';break;}
        unset($field);return $result;
    }

    /** Before typed normalization, after password/preserved-item stubs resolve against originals.
     * Uneditable originals retain their EXACT definition as well as value; recapturing a looser
     * current definition while merely saving another field must not grant access to old secrets.
     * @return array{definitions:array,values:array}
     */
    public static function prepare(PDO $db,Access $access,array $definitions,array $values,array $previous=[],bool $validationOnly=false):array
    {
        if(!$definitions&&empty($previous['field_definitions']))return ['definitions'=>$definitions,'values'=>$values];
        if($validationOnly&&$values!==($previous['custom_fields']??[]))throw new DomainException('Nur bestätigte Originalwerte ohne Feldänderung prüfen.');
        [$defs,$result]=self::prepareLevel($definitions,$values,$previous['field_definitions']??[],$previous['custom_fields']??[],self::catalog($db,$access->club()),self::capabilities($access,$validationOnly?'read':'edit'),0);
        return ['definitions'=>$defs,'values'=>$result];
    }

    private static function prepareLevel(array $definitions,array $values,array $oldDefinitions,array $original,array $catalog,array $caps,int $depth):array
    {
        if($depth>3)throw new DomainException('Feldstruktur ist nicht verfügbar.');
        foreach($oldDefinitions as $key=>$old)if(!isset($definitions[$key])&&!self::allowed($old,$catalog,$caps,'edit'))throw new DomainException('Eine geschützte Originalangabe darf nicht entfernt werden.');
        foreach($definitions as $key=>$field){
            $old=$oldDefinitions[$key]??null;
            if(isset($field['access_history'])||isset($old['access_history'])){$history=self::definitionHistory($field);if($old!==null)$history=self::joinHistory($history,self::definitionHistory($old));if(isset($catalog[$key]))$history=self::joinHistory($history,self::definitionHistory($catalog[$key]));$field['access_history']=$history;$definitions[$key]=$field;}
            $allowed=self::allowed($field,$catalog,$caps,'edit')&&($old===null||self::allowed($old,$catalog,$caps,'edit'));
            if(!$allowed){
                if(array_key_exists($key,$values)&&$values[$key]!==($original[$key]??null))throw new DomainException('Für diese Originalangabe fehlt die Bearbeitungsberechtigung.');
                $values[$key]=$original[$key]??null;if($old!==null){if(isset($field['access_history']))$old['access_history']=$field['access_history'];$definitions[$key]=$old;}continue;
            }
            if(($field['type']??null)!=='repeater')continue;
            if(!array_key_exists($key,$values)&&$old!==null){try{self::assertLevelEditable($old['item_definitions']??[],$catalog,$caps,$depth+1);}catch(DomainException){$values[$key]=$original[$key]??[];$definitions[$key]=$old;continue;}}
            $oldItems=array_column(is_array($original[$key]??null)?$original[$key]:[],null,'id');$newItems=$values[$key]??[];
            if(!is_array($newItems))throw new InvalidArgumentException('Datensatzliste prüfen.');$children=$field['item_definitions']??[];$oldChildren=$old['item_definitions']??[];
            foreach($newItems as $position=>$item){
                if($position==='__complete')continue;
                if(!is_array($item))throw new InvalidArgumentException('Datensatzangabe prüfen.');$prior=$oldItems[$item['id']??'']??null;
                if(isset($item['remove'])){if($prior!==null)self::assertLevelEditable($oldChildren,$catalog,$caps,$depth+1);continue;}
                if(isset($item['values_json'])){if(isset($item['values'])||!is_string($item['values_json'])||strlen($item['values_json'])>1048576)throw new InvalidArgumentException('Datensatzangabe prüfen.');$item['values']=json_decode($item['values_json'],true,32,JSON_THROW_ON_ERROR);unset($item['values_json']);}
                if(!is_array($item['values']??[]))throw new InvalidArgumentException('Datensatzangabe prüfen.');
                [$resolved,$childValues]=self::prepareLevel($children,$item['values']??[],$oldChildren,$prior['values']??[],$catalog,$caps,$depth+1);$definitions[$key]['item_definitions']=$resolved;$values[$key][$position]['values']=$childValues;unset($values[$key][$position]['values_json']);
                unset($oldItems[$item['id']??'']);
            }
            if($oldItems)self::assertLevelEditable($oldChildren,$catalog,$caps,$depth+1);
        }
        return [$definitions,$values];
    }

    public static function assertCopy(PDO $db,Access $access,array $payload):void
    {self::assertLevelEditable($payload['field_definitions']??[],self::catalog($db,$access->club()),self::capabilities($access,'edit'),0);\Kulturhus\Native\Fieldtypes\Runtime::assertBindings($db,$access,$payload['field_definitions']??[]);}

    private static function assertLevelEditable(array $definitions,array $catalog,array $caps,int $depth):void
    {
        if($depth>3)throw new DomainException('Feldstruktur ist nicht verfügbar.');
        foreach($definitions as $field){if(!self::allowed($field,$catalog,$caps,'edit'))throw new DomainException('Eine geschützte Originalangabe ist nicht vollständig bearbeitbar.');if(($field['type']??null)==='repeater')self::assertLevelEditable($field['item_definitions']??[],$catalog,$caps,$depth+1);}
    }

    /** Schema DTOs retain permitted type/condition metadata, never a protected child definition. */
    public static function modelDefinitions(PDO $db,Access $access,array $definitions):array
    {
        return self::project(['field_definitions'=>$definitions,'custom_fields'=>[]],self::catalog($db,$access->club()),self::capabilities($access),false,true)['field_definitions'];
    }

    public static function modelTemplate(PDO $db,Access $access,array $template):array
    {
        $definitions=(new DataModel($db,$access))->templateFields($template);
        return self::project(['template_definition'=>$template,'field_definitions'=>$definitions,'custom_fields'=>[]],self::catalog($db,$access->club()),self::capabilities($access),false,true)['template_definition'];
    }

    /** Current schema visibility BEFORE aggregate/pagination. Alias is a definition table. */
    public static function definitionFilter(PDO $db,Access $access,string $alias='d'):array
    {
        if(!preg_match('/\A[a-z][a-z0-9_]*\z/D',$alias))throw new InvalidArgumentException('Gebundenen Definitionsfilter prüfen.');
        $caps=self::capabilities($access);$column='`'.$alias.'`.definition_json';$validRead="'[\"read\",\"edit\",\"publish\",\"manage\"]'";$validEdit="'[\"edit\",\"publish\",\"manage\"]'";
        $sql='`'.$alias.'`.club_id=? AND `'.$alias."`.kind='field' AND JSON_UNQUOTE(JSON_EXTRACT(".$column.",'$.key'))=`".$alias.'`.definition_key';
        $sql.=" AND (JSON_CONTAINS_PATH($column,'one','$.access')=0 OR (JSON_TYPE(JSON_EXTRACT($column,'$.access'))='ARRAY' AND JSON_LENGTH(JSON_EXTRACT($column,'$.access'))=0) OR (JSON_TYPE(JSON_EXTRACT($column,'$.access'))='OBJECT' AND JSON_CONTAINS('[\"read\",\"edit\"]',JSON_KEYS(JSON_EXTRACT($column,'$.access')))=1))";
        $sql.=" AND JSON_CONTAINS($validRead,JSON_QUOTE(COALESCE(JSON_UNQUOTE(JSON_EXTRACT($column,'$.access.read')),'read')))=1 AND JSON_CONTAINS($validEdit,JSON_QUOTE(COALESCE(JSON_UNQUOTE(JSON_EXTRACT($column,'$.access.edit')),'edit')))=1";
        $sql.=" AND COALESCE(JSON_UNQUOTE(JSON_EXTRACT($column,'$.access.read')),'read') IN (".implode(',',array_fill(0,count($caps),'?')).')';
        return ['('.$sql.')',[$access->club(),...$caps]];
    }

    /** Per-row field predicate BEFORE filtering/count/order. Public callers JOIN published alias r.
     * $fieldPath is e.g. addresses.street; it never includes the external custom. prefix.
     * @return array{0:string,1:array}
     */
    public static function queryGuard(PDO $db,Access $access,string $fieldPath,string $alias='p',bool $public=false,?string $payloadAlias=null):array
    {
        $payloadAlias??=$public?'r':$alias;
        foreach([$alias,$payloadAlias] as $identifier)if(!preg_match('/\A[a-z][a-z0-9_]*\z/D',$identifier))throw new InvalidArgumentException('Gebundenen Feldfilter prüfen.');
        $segments=explode('.',$fieldPath);if(count($segments)>4)throw new InvalidArgumentException('Feldpfad auf drei Datensatzebenen begrenzen.');foreach($segments as $segment)CustomFields::key($segment);
        $caps=$public?['read']:self::capabilities($access);$club=$access->require('website','read')['club_id'];$catalog=self::catalog($db,$club);$column='`'.$payloadAlias.'`.'.($public?'payload_json':'draft_json');$parts=['`'.$alias.'`.club_id=?'];$parameters=[$club];$path='$.field_definitions';$parent=null;
        foreach($segments as $key){
            if(!isset($catalog[$key])||($parent!==null&&(($parent['type']??null)!=='repeater'||!in_array($key,$parent['fields']??[],true))))return ['0=1',[]];
            $definition=$catalog[$key];if(!self::allowed($definition,$catalog,$caps)||($definition['type']??null)==='password')return ['0=1',[]];
            $path.=($parent===null?'.':'.item_definitions.').$key;
            $definitionSql='JSON_EXTRACT('.$column.',?)';$accessPath=$path.'.access';
            $parts[]="JSON_TYPE($definitionSql)='OBJECT'";$parameters[]=$path;
            $parts[]="JSON_UNQUOTE(JSON_EXTRACT($column,?))=?";array_push($parameters,$path.'.type',$definition['type']);
            if(isset($definition['fieldtype'])){
                if(!\Kulturhus\Native\Fieldtypes\Runtime::queryAvailable($db,$access,$definition))return ['0=1',[]];$binding=\Kulturhus\Native\Fieldtypes\Binding::normalize($definition['fieldtype']);
                $parts[]="JSON_TYPE(JSON_EXTRACT($column,?))='OBJECT' AND JSON_LENGTH(JSON_EXTRACT($column,?))=4";array_push($parameters,$path.'.fieldtype',$path.'.fieldtype');
                foreach($binding as$property=>$value){$parts[]="JSON_UNQUOTE(JSON_EXTRACT($column,?))=?";array_push($parameters,$path.'.fieldtype.'.$property,$value);}
            }else{$parts[]="(JSON_CONTAINS_PATH($column,'one',?)=0 OR JSON_TYPE(JSON_EXTRACT($column,?))='NULL')";array_push($parameters,$path.'.fieldtype',$path.'.fieldtype');}
            if($public){$parts[]="COALESCE(JSON_UNQUOTE(JSON_EXTRACT($column,?)),'visible')<>'hidden'";$parameters[]=$path.'.display';}
            $parts[]="(JSON_CONTAINS_PATH($column,'one',?)=0 OR (JSON_TYPE(JSON_EXTRACT($column,?))='ARRAY' AND JSON_LENGTH(JSON_EXTRACT($column,?))=0) OR (JSON_TYPE(JSON_EXTRACT($column,?))='OBJECT' AND JSON_CONTAINS('[\"read\",\"edit\"]',JSON_KEYS(JSON_EXTRACT($column,?)))=1))";array_push($parameters,$accessPath,$accessPath,$accessPath,$accessPath,$accessPath);
            foreach(['read'=>['read','edit','publish','manage'],'edit'=>['edit','publish','manage']] as $cap=>$valid){$parts[]="COALESCE(JSON_UNQUOTE(JSON_EXTRACT($column,?)),?) IN (".implode(',',array_fill(0,count($valid),'?')).')';array_push($parameters,$accessPath.'.'.$cap,self::DEFAULTS[$cap],...$valid);}
            [$historySql,$historyParameters]=self::historyPredicate($column,$path.'.access_history',$caps);$parts[]=$historySql;array_push($parameters,...$historyParameters);
            $parts[]="COALESCE(JSON_UNQUOTE(JSON_EXTRACT($column,?)),'read') IN (".implode(',',array_fill(0,count($caps),'?')).')';array_push($parameters,$accessPath.'.read',...$caps);$parent=$definition;
        }
        return ['('.implode(' AND ',$parts).')',$parameters];
    }
    private static function historyPredicate(string $column,string $path,array $caps):array
    {
        $parameters=[$path];$json=static function(string$suffix='')use($column,$path,&$parameters):string{$parameters[]=$path.$suffix;return "JSON_EXTRACT($column,?)";};
        $terms=["JSON_TYPE(".$json().")='OBJECT'",'JSON_LENGTH('.$json().')=2',"JSON_CONTAINS('[\"read\",\"edit\"]',JSON_KEYS(".$json().'))=1'];
        foreach(['read'=>['read','edit','publish','manage'],'edit'=>['edit','publish','manage']]as$kind=>$roles){$valid=json_encode($roles,JSON_THROW_ON_ERROR);$terms[]="JSON_TYPE(".$json('.'.$kind).")='ARRAY'";$terms[]='JSON_LENGTH('.$json('.'.$kind).') BETWEEN 1 AND '.count($roles);$terms[]="JSON_CONTAINS('$valid',".$json('.'.$kind).')=1';$terms[]='JSON_CONTAINS('.$json('.'.$kind).',\'"'.$kind.'"\')=1';$unique=[];foreach($roles as$role)$unique[]='JSON_CONTAINS('.$json('.'.$kind).',\'"'.$role.'"\')';$terms[]='JSON_LENGTH('.$json('.'.$kind).')=('.implode('+',$unique).')';}
        $parameters[]=json_encode(array_values($caps),JSON_THROW_ON_ERROR);$terms[]='JSON_CONTAINS(?,'.$json('.read').')=1';
        return ["(JSON_CONTAINS_PATH($column,'one',?)=0 OR (".implode(' AND ',$terms).'))',$parameters];
    }
}
