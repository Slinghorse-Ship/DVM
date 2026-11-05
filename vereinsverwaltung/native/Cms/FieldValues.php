<?php declare(strict_types=1);
namespace Kulturhus\Native\Cms;

use DomainException;
use InvalidArgumentException;
use Kulturhus\Native\Shared\Access;

/** Bounded typed collections. Item identity, rights and versions are checked against stored originals. */
final class FieldValues
{
    public static function normalize(array $field, mixed $value, bool $search = false, int $depth = 0): array
    {
        if ($depth > 3) throw new InvalidArgumentException('Datensätze dürfen höchstens drei Ebenen enthalten.');
        if ($value === null || $value === '') $value = [];
        if(is_array($value)&&array_key_exists('__complete',$value)){if($value['__complete']!=='1')throw new InvalidArgumentException('Datensatzformular unvollständig.');unset($value['__complete']);}
        // Native indexed controls keep stable names while their DOM order changes.
        if(is_array($value)&&!array_is_list($value)&&!array_filter(array_keys($value),static fn($key)=>!is_int($key)||$key<0))$value=array_values($value);
        if (!is_array($value) || !array_is_list($value) || count($value) > ($field['max_items'] ?? 50)) {
            throw new InvalidArgumentException($field['label'].': geordnete Liste innerhalb der Eintragsgrenze angeben.');
        }
        $out = []; $seen = [];
        foreach ($value as $item) {
            if ($field['type'] === 'repeater') {
                if (!is_array($item) || array_diff(array_keys($item), ['id','version','values','values_json','editors','public','remove'])) {
                    throw new InvalidArgumentException($field['label'].': Datensatz prüfen.');
                }
                if(isset($item['remove'])){if($item['remove']!=='1')throw new InvalidArgumentException('Entfernen-Auswahl prüfen.');continue;}
                $values = $item['values'] ?? [];
                if (isset($item['values_json'])) {
                    if (isset($item['values']) || !is_string($item['values_json']) || strlen($item['values_json']) > 1048576) throw new InvalidArgumentException('Ursprüngliche Datensatzangaben prüfen.');
                    try { $values = json_decode($item['values_json'], true, 32, JSON_THROW_ON_ERROR); }
                    catch (\JsonException) { throw new InvalidArgumentException('Ursprüngliche Datensatzangaben prüfen.'); }
                }
                if (!is_array($values) || ($values !== [] && array_is_list($values)) || array_diff(array_keys($values), array_keys($field['item_definitions'] ?? []))) throw new InvalidArgumentException('Datensatz enthält fremde Felder.');
                // A fresh empty form slot is not a new item and does not trigger child required errors.
                if (empty($item['id']) && self::emptyTree($values)) continue;
                $id = $item['id'] ?? '';
                if ($id === '') $id = bin2hex(random_bytes(16));
                if (!is_string($id) || !preg_match('/^[a-f0-9]{32}$/D', $id) || isset($seen[$id])) throw new InvalidArgumentException('Datensätze benötigen eindeutige Kennungen.');
                $version = filter_var($item['version'] ?? 0, FILTER_VALIDATE_INT, ['options'=>['min_range'=>0,'max_range'=>2147483646]]);
                if ($version === false) throw new InvalidArgumentException('Datensatzversion prüfen.');
                $editors = $item['editors'] ?? [];
                if (!is_array($editors) || !array_is_list($editors) || count($editors)>50) throw new InvalidArgumentException('Datensatzberechtigte als Liste auswählen.');
                foreach ($editors as $editor) if (!is_string($editor) || !preg_match('/^[a-zA-Z0-9_-]{1,64}$/D',$editor)) throw new InvalidArgumentException('Datensatzberechtigte prüfen.');
                $public = $item['public'] ?? true;
                if (!in_array($public, [true,false,0,1,'0','1'],true)) throw new InvalidArgumentException('Datensatzsichtbarkeit prüfen.');
                $typed = FieldConditions::values($field['item_definitions']??[],$values);
                $seen[$id] = true;
                $out[] = ['id'=>$id,'version'=>$version,'values'=>$typed,'editors'=>array_values(array_unique($editors)),'public'=>in_array($public,[true,1,'1'],true)];
            } else {
                if(is_array($item)&&!array_is_list($item)&&!array_diff(array_keys($item),['value','remove'])){
                    if(isset($item['remove'])){if($item['remove']!=='1')throw new InvalidArgumentException('Entfernen-Auswahl prüfen.');continue;}
                    $item=$item['value']??null;
                }
                $scalar = $field;
                unset($scalar['multiple']);
                if ($field['type'] === 'pagetable') $scalar['type'] = 'page-reference';
                $scalar['required'] = false;
                $typed = CustomFields::value($scalar,$item,$search);
                if ($typed !== null && !in_array($typed,$out,true)) $out[] = $typed;
            }
        }
        if (!$search && $field['required'] && !$out) throw new InvalidArgumentException($field['label'].': Pflichtfeld fehlt.');
        return $out;
    }

    public static function collection(array $field): bool
    { return !empty($field['multiple']) || in_array($field['type'],['repeater','pagetable'],true); }

    /** Publication/restore callers explicitly preserve immutable item versions; edits never trust posted rights. */
    public static function authorize(array $definitions, array $values, array $original, Access $access, bool $preserve = false): array
    {
        foreach ($definitions as $key=>$field) {
            if ($field['type'] !== 'repeater') continue;
            $previousItems=is_array($original[$key]??null)?$original[$key]:[];
            $before = array_column($previousItems,null,'id');
            $next = [];
            foreach ($values[$key]??[] as $item) {
                $old = $before[$item['id']] ?? null;
                if ($old !== null) {
                    if (!$preserve && $item['version'] !== $old['version']) throw new Conflict('Datensatz wurde inzwischen geändert. Aktuellen Entwurf laden.');
                    $changed = $item['values'] !== $old['values'] || $item['editors'] !== $old['editors'] || $item['public'] !== $old['public'];
                    if ($changed && !self::canEdit($old,$access)) throw new DomainException('Für diesen Datensatz fehlt die Bearbeitungsberechtigung.');
                    if ($item['editors'] !== $old['editors'] || $item['public'] !== $old['public']) $access->require('website','manage');
                    $item['values'] = self::authorize($field['item_definitions'],$item['values'],$old['values'],$access,$preserve);
                    if (!$preserve) $item['version'] = $old['version'] + ($changed ? 1 : 0);
                } else {
                    if (!$preserve && $item['version'] !== 0) throw new Conflict('Neuer Datensatz benötigt Versionsstand 0.');
                    if ($item['editors'] || !$item['public']) $access->require('website','manage');
                    $item['values'] = self::authorize($field['item_definitions'],$item['values'],[],$access,$preserve);
                    if (!$preserve) {$item['id']=bin2hex(random_bytes(16));$item['version'] = 1;}
                }
                foreach (($old!==null&&$item['editors']===$old['editors'])?[]:$item['editors'] as $editor) {
                    if (!in_array($editor,array_column($access->directory('website'),'id'),true)) throw new InvalidArgumentException('Datensatzberechtigte müssen aktive Mitglieder dieses Vereins sein.');
                }
                unset($before[$item['id']]); $next[]=$item;
            }
            foreach ($before as $removed) if (!self::canEdit($removed,$access)) throw new DomainException('Ein geschützter Datensatz darf nicht entfernt werden.');
            // Moving an item is also an edit, even if its field values stayed unchanged.
            if (!$preserve) foreach ($next as $position=>$item) {
                $oldPosition = array_search($item['id'],array_column($previousItems,'id'),true);
                if ($oldPosition !== false && $oldPosition !== $position && !self::canEdit($item,$access)) throw new DomainException('Ein geschützter Datensatz darf nicht verschoben werden.');
            }
            $values[$key] = $next;
        }
        return $values;
    }

    public static function canEdit(array $item, Access $access): bool
    {
        if (!$item['editors'] || in_array($access->user()['id'],$item['editors'],true)) return true;
        try { $access->require('website','manage'); return true; } catch (DomainException) { return false; }
    }

    /** References include private items and historical snapshots, so deletion cannot orphan them. */
    public static function references(array $payload, string $kind, bool $public = false): array
    {
        $types = match($kind) { 'page'=>['page-reference','pagetable'], 'media'=>['media-reference'], 'file'=>['file-reference'], default=>throw new InvalidArgumentException('Referenzart prüfen.') };
        $out=[];
        self::walk($payload['field_definitions']??[],$payload['custom_fields']??[],function($field,$value)use($types,&$out):void{
            if (in_array($field['type'],$types,true) && $value!==null) foreach(self::collection($field)?$value:[$value] as $id) $out[]=$id;
        },$public);
        return array_values(array_unique($out));
    }

    /** Item identities are scoped by page; a copied page retains them and the exact original rights. */
    public static function remapPages(array $payload, array $mapping): array
    {
        $payload['custom_fields']=self::mapPages($payload['field_definitions']??[],$payload['custom_fields']??[],$mapping);
        return $payload;
    }

    /** Public JSON callers receive values without password digests, private items or original ACLs. */
    public static function publicPayload(array$payload):array
    {
        $payload['custom_fields']=self::publicValues($payload['field_definitions']??[],$payload['custom_fields']??[]);
        $media=self::references($payload,'media',true);
        if(isset($payload['custom_media_ids']))$payload['custom_media_ids']=$media;
        if(isset($payload['media_metadata'])){
            $all=[...($payload['media_ids']??[]),...ContentSections::mediaIds($payload),...$media];
            $payload['media_metadata']=array_intersect_key($payload['media_metadata'],array_fill_keys($all,true));
        }
        if(isset($payload['custom_file_metadata'])){
            $files=self::references($payload,'file',true);
            $payload['custom_file_metadata']=array_values(array_filter($payload['custom_file_metadata'],static fn($file)=>in_array($file['id'],$files,true)));
        }
        // Historical schema definitions contain editorial conditions and assignments, not public data.
        unset($payload['field_definitions'],$payload['template_definition'],$payload['custom_field_text']);
        return $payload;
    }

    public static function publicValues(array$definitions,array$values):array
    {
        $out=[];
        foreach($definitions as$key=>$field){
            if($field['type']==='password'||($field['display']??'visible')==='hidden')continue;
            $value=$values[$key]??null;
            if($field['type']==='repeater'){
                $items=[];
                foreach($value??[]as$item)if($item['public']??true)$items[]=['values'=>self::publicValues($field['item_definitions'],$item['values'])];
                $value=$items;
            }
            $out[$key]=$value;
        }
        return $out;
    }

    /** Editorial APIs retain original item identities/ACLs, but never return password digests. */
    public static function privatePayload(array$payload, ?Access $access = null):array
    {
        $payload['custom_fields']=self::withoutPasswords($payload['field_definitions']??[],$payload['custom_fields']??[]);
        if($access!==null){
            $access->require('website','read');$manage=true;
            try{$access->require('website','manage');}catch(DomainException){$manage=false;}
            if(!$manage)$payload['custom_fields']=self::withoutAcls($payload['field_definitions']??[],$payload['custom_fields']);
        }
        return $payload;
    }

    private static function withoutAcls(array$definitions,array$values):array
    {
        foreach($definitions as$key=>$field)if($field['type']==='repeater')foreach($values[$key]??[]as$i=>$item){unset($values[$key][$i]['editors']);$values[$key][$i]['values']=self::withoutAcls($field['item_definitions'],$item['values']);}
        return $values;
    }

    private static function withoutPasswords(array$definitions,array$values):array
    {
        foreach($definitions as$key=>$field){
            if($field['type']==='password'){unset($values[$key]);continue;}
            if($field['type']==='repeater')foreach($values[$key]??[]as$i=>$item)$values[$key][$i]['values']=self::withoutPasswords($field['item_definitions'],$item['values']);
        }
        return $values;
    }

    public static function publicText(array$payload):string
    {
        $parts=[];
        self::walk($payload['field_definitions']??[],$payload['custom_fields']??[],static function($field,$value)use(&$parts):void{
            if(in_array($field['type'],['password','page-reference','pagetable','media-reference','file-reference'],true))return;
            foreach(self::collection($field)?($value??[]):[$value]as$item)if($item!==null)$parts[]=is_bool($item)?($item?'Ja':'Nein'):($field['type']==='richtext'?RichTextField::plain($item):(string)$item);
        },true);
        return implode(' ',$parts);
    }

    private static function mapPages(array $definitions,array $values,array $mapping):array
    {
        foreach($definitions as$key=>$field) {
            if($field['type']==='repeater') foreach($values[$key]??[] as$i=>$item) $values[$key][$i]['values']=self::mapPages($field['item_definitions']??[],$item['values'],$mapping);
            elseif(in_array($field['type'],['page-reference','pagetable'],true)&&($values[$key]??null)!==null) {
                $values[$key]=self::collection($field)?array_map(static fn($id)=>$mapping[$id]??$id,$values[$key]):($mapping[$values[$key]]??$values[$key]);
            }
        }
        return $values;
    }

    public static function walk(array $definitions,array $values,callable $visit,bool $public=false):void
    {
        foreach($definitions as$key=>$field) {
            if($public&&($field['display']??'visible')==='hidden')continue;
            $value=$values[$key]??null;
            if($field['type']==='repeater') foreach($value??[] as$item) {if($public&&!($item['public']??true))continue;self::walk($field['item_definitions']??[],$item['values'],$visit,$public);}
            else $visit($field,$value);
        }
    }

    public static function dateTime(mixed $value):string
    {
        if(!is_string($value))throw new InvalidArgumentException('Datum und Uhrzeit angeben.');
        $date=\DateTimeImmutable::createFromFormat('!Y-m-d\TH:i',$value,new \DateTimeZone('Europe/Berlin'));
        if(!$date||$date->format('Y-m-d\TH:i')!==$value)throw new InvalidArgumentException('Datum und Uhrzeit im Format JJJJ-MM-TTTHH:MM angeben.');
        return $value;
    }

    /** A saved digest may be restored; HTML controls never return the digest to the browser. */
    public static function password(mixed $value):string
    {
        if(!is_string($value)||strlen($value)>1024||strlen($value)<8||str_contains($value,"\0"))throw new InvalidArgumentException('Passwort mit 8 bis 1024 Bytes angeben.');
        if((password_get_info($value)['algoName']??'unknown')!=='unknown')return $value;
        return password_hash($value,PASSWORD_DEFAULT);
    }

    public static function keepPasswords(array$definitions,array$values,array$original,int &$passwordChanges=0):array
    {
        foreach($definitions as$key=>$field){
            if($field['type']==='password'){
                $value=$values[$key]??'';
                if(is_array($value)){
                    if(array_diff(array_keys($value),['value','clear'])||!is_string($value['value']??'')||(isset($value['clear'])&&$value['clear']!=='1'))throw new InvalidArgumentException('Passwortangabe prüfen.');
                    if(isset($value['clear'])){$values[$key]=null;continue;}
                    $value=$value['value']??'';
                }elseif(array_key_exists($key,$values)&&$values[$key]===null)continue;
                if(is_string($value)&&$value!==''&&$value!==($original[$key]??null)){
                    if((password_get_info($value)['algoName']??'unknown')!=='unknown')throw new InvalidArgumentException('Ein neues Passwort eingeben, keine gespeicherte Prüfsumme.');
                    if(++$passwordChanges>20)throw new InvalidArgumentException('Höchstens 20 neue Passwörter gleichzeitig speichern.');
                }
                $values[$key]=$value===''?($original[$key]??null):$value;
            }
            elseif($field['type']==='repeater'){
                $old=array_column(is_array($original[$key]??null)?$original[$key]:[],null,'id');
                foreach($values[$key]??[]as$i=>$item)if(is_array($item)){
                    $oldItem=$old[$item['id']??'']??null;
                    if($oldItem!==null){if(!array_key_exists('editors',$item))$item['editors']=$oldItem['editors'];if(!array_key_exists('public',$item))$item['public']=$oldItem['public'];}
                    if(isset($item['preserve'])){
                        if($item['preserve']!=='1'||!isset($old[$item['id']??'']))throw new DomainException('Ursprünglicher Datensatz nicht verfügbar.');
                        $item['values']=$old[$item['id']]['values'];unset($item['preserve']);
                    }
                    if(isset($item['values'])&&is_array($item['values']))$item['values']=self::keepPasswords($field['item_definitions'],$item['values'],$old[$item['id']??'']['values']??[],$passwordChanges);
                    $values[$key][$i]=$item;
                }
            }
        }
        return $values;
    }

    private static function emptyTree(mixed $value):bool
    {
        if(is_array($value))unset($value['__complete']);
        if(is_array($value)&&array_key_exists('values',$value)&&empty($value['id']))return self::emptyTree($value['values']);
        if(is_array($value)){foreach($value as$item)if(!self::emptyTree($item))return false;return true;}
        return $value===null||$value==='';
    }
}
