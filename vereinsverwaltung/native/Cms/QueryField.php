<?php declare(strict_types=1);
namespace Kulturhus\Native\Cms;
use InvalidArgumentException;

/** Shared typed SQL projection. Paths and values remain parameters, never SQL input. */
final class QueryField
{
    public function __construct(private readonly DataModel $model,private readonly string $alias='p',private readonly ?string $payloadAlias=null)
    {if(!preg_match('/\A[a-z][a-z0-9_]*\z/D',$alias)||$payloadAlias!==null&&!preg_match('/\A[a-z][a-z0-9_]*\z/D',$payloadAlias))throw new InvalidArgumentException('Gebundenen Suchalias prüfen.');}

    /** Existing six-part projection contract; only trusted SQL identifiers vary. */
    public function resolve(string $field):array
    {
        $result=$this->rawResolve($field);
        foreach([0,4] as $i)$result[$i]=str_replace(['p.','`p`.'],[$this->alias.'.','`'.$this->alias.'`.'],$result[$i]);
        if($this->payloadAlias!==null)foreach([0,4] as $i)$result[$i]=str_replace([$this->alias.'.draft_json','`'.$this->alias.'`.draft_json'],[$this->payloadAlias.'.draft_json','`'.$this->payloadAlias.'`.draft_json'],$result[$i]);
        return $result;
    }

    private function definition(string $key):array
    {
        try{return $this->model->field($key);}catch(\PDOException $error){throw $error;}catch(\RuntimeException $error){throw new InvalidArgumentException('Unbekanntes oder nicht verfügbares Suchfeld.',0,$error);}
    }

    /** Parse a MEDIUMTEXT original once; subsequent guarded paths read native JSON. */
    public static function document(string $alias,bool $strict=false):string
    {
        if(!preg_match('/\A[a-z][a-z0-9_]*\z/D',$alias))throw new InvalidArgumentException('Gebundenen Suchalias prüfen.');
        $input=$strict?$alias.'.draft_json':"CASE WHEN JSON_VALID($alias.draft_json) THEN $alias.draft_json ELSE JSON_OBJECT() END";
        return "JSON_TABLE($input, '$' COLUMNS(draft_json JSON PATH '$'))";
    }

    /** Compiler-owned SQL only; bound user values never participate in this decision. */
    public static function usesDocument(string $sql,string $alias='p'):bool
    {
        if(!preg_match('/\A[a-z][a-z0-9_]*\z/D',$alias))throw new InvalidArgumentException('Gebundenen Suchalias prüfen.');
        return str_contains($sql,$alias.'.draft_json')||str_contains($sql,'`'.$alias.'`.draft_json');
    }

    /** A relation is followed only after the canonical source field resolved successfully. */
    public function relation(string $field):?array
    {
        if(str_starts_with($field,'parent_id.'))return ['parent_id',substr($field,10)];
        if(!str_starts_with($field,'custom.'))return null;
        $segments=explode('.',substr($field,7));$key=CustomFields::key(array_shift($segments));$definition=$this->definition($key);$prefix='custom.'.$key;$depth=0;
        while($segments){
            if(in_array($definition['type'],['page-reference','pagetable'],true))return [$prefix,implode('.',$segments)];
            if($definition['type']!=='repeater'||++$depth>3)return null;
            $key=CustomFields::key(array_shift($segments));if(!isset($definition['item_definitions'][$key]))return null;
            $definition=$definition['item_definitions'][$key];$prefix.='.'.$key;
        }
        return null;
    }

    /** One JSON_TABLE row per actual stored scalar, including nested repeated groups. */
    public function values(string $field,bool $records=false):array
    {
        if(!str_starts_with($field,'custom.'))throw new InvalidArgumentException('Mehrfachfeld wählen.');
        $segments=explode('.',substr($field,7));$key=CustomFields::key(array_shift($segments));$definition=$this->definition($key);$path='$.custom_fields.'.$key;$snapshot='$.field_definitions.'.$key;$checks=[];$args=[];
        $check=function(array $field,string $at)use(&$checks,&$args):void{
            $checks[]="COALESCE(JSON_EXTRACT(".($this->payloadAlias??$this->alias).".draft_json,?),CAST('false' AS JSON))=CAST(? AS JSON)";
            array_push($args,$at.'.multiple',!empty($field['multiple'])?'true':'false');
        };
        $check($definition,$snapshot);
        if(FieldValues::collection($definition))$path.='[*]';
        foreach($segments as $segment){
            CustomFields::key($segment);
            if($definition['type']!=='repeater'||!isset($definition['item_definitions'][$segment]))throw new InvalidArgumentException('Datensatz-Unterfeld nicht gefunden.');
            $definition=$definition['item_definitions'][$segment];$path.='.values.'.$segment;$snapshot.='.item_definitions.'.$segment;$check($definition,$snapshot);
            if(FieldValues::collection($definition))$path.='[*]';
        }
        if(in_array($definition['type'],['password','comments'],true)||$definition['type']==='repeater'&&!$records)throw new InvalidArgumentException('Dieses Feld hat keine abfragbaren Einzelwerte.');
        if($definition['type']==='pagetable')$definition['type']='page-reference';
        return ["JSON_TABLE(JSON_EXTRACT(CASE WHEN JSON_VALID(".($this->payloadAlias??$this->alias).".draft_json) THEN ".($this->payloadAlias??$this->alias).".draft_json ELSE JSON_OBJECT() END, ?), '$[*]' COLUMNS (".($records?"position FOR ORDINALITY":"value LONGTEXT PATH '$' NULL ON EMPTY ERROR ON ERROR")."))",[$path],$definition,implode(' AND ',$checks),$args];
    }

    private function shapeGuard(string $field):array
    {
        $segments=explode('.',substr($field,7));$key=CustomFields::key(array_shift($segments));$definition=$this->definition($key);$path='$.field_definitions.'.$key;$parts=[];$args=[];
        while(true){$parts[]="COALESCE(JSON_EXTRACT(p.draft_json,?),CAST('false' AS JSON))=CAST(? AS JSON)";array_push($args,$path.'.multiple',!empty($definition['multiple'])?'true':'false');if(!$segments)break;
            $key=CustomFields::key(array_shift($segments));if($definition['type']!=='repeater'||!isset($definition['item_definitions'][$key]))throw new InvalidArgumentException('Datensatz-Unterfeld nicht gefunden.');$definition=$definition['item_definitions'][$key];$path.='.item_definitions.'.$key;
        }
        return [implode(' AND ',$parts),$args];
    }

    private function rawResolve(string $field): array
    {
        $guard = "";
        $guardParameters = [];
        $parameters = [];
        $definition = [
            "key" => $field,
            "label" => $field,
            "type" => "text",
            "required" => false,
            "min" => null,
            "max" => null,
            "options" => [],
        ];
        if (str_starts_with($field, "custom.")) {
            $segments=explode('.',substr($field,7));
            if(count($segments)>4)throw new InvalidArgumentException('Feldpfad darf höchstens drei Datensatzebenen enthalten.');
            $key = CustomFields::key(array_shift($segments));
            $definition = $this->definition($key);
            if(in_array($definition['type'],['password','comments'],true))throw new InvalidArgumentException('Dieses Feld ist nicht abfragbar.');
            if($segments || FieldValues::collection($definition)) {
                $path='$.custom_fields.'.$key;
                $definitionPath='$.field_definitions.'.$key;
                foreach($segments as$segment){
                    CustomFields::key($segment);
                    if($definition['type']!=='repeater'||!isset($definition['item_definitions'][$segment]))throw new InvalidArgumentException('Datensatz-Unterfeld nicht gefunden.');
                    $path.='[*].values.'.$segment;$definitionPath.='.item_definitions.'.$segment;
                    $definition=$definition['item_definitions'][$segment];
                }
                if(in_array($definition['type'],['password','comments'],true))throw new InvalidArgumentException('Dieses Feld ist nicht abfragbar.');
                if(!empty($definition['multiple']))$path.='[*]';
                [$accessGuard,$accessParameters]=$this->model->fieldGuard(implode('.',[$key,...$segments]));
                [$shape,$shapeArgs]=$this->shapeGuard($field);$accessGuard.=' AND '.$shape;$accessParameters=[...$accessParameters,...$shapeArgs];
                return ['JSON_EXTRACT(p.draft_json,?)',[$path],'collection:'.$definition['type'],$definition,'(JSON_UNQUOTE(JSON_EXTRACT(p.draft_json,?))=? AND '.$accessGuard.')',[$definitionPath.'.type',$definition['type'],...$accessParameters]];
            }
            $path =
                ($definition["type"] === "richtext"
                    ? '$.custom_field_text.'
                    : '$.custom_fields.') . $key;
            $guard = "JSON_UNQUOTE(JSON_EXTRACT(p.draft_json,?)) = ?";
            $guardParameters = [
                '$.field_definitions.' . $key . ".type",
                $definition["type"],
            ];
            [$accessGuard,$accessParameters]=$this->model->fieldGuard($key);[$shape,$shapeArgs]=$this->shapeGuard($field);$accessGuard.=' AND '.$shape;$accessParameters=[...$accessParameters,...$shapeArgs];$guard='('.$guard.' AND '.$accessGuard.')';$guardParameters=[...$guardParameters,...$accessParameters];
            // JSON null and absent values both become SQL NULL; the string "null" remains text.
            $expression =
                "CASE WHEN JSON_TYPE(JSON_EXTRACT(p.draft_json,?))='NULL' THEN NULL ELSE JSON_UNQUOTE(JSON_EXTRACT(p.draft_json,?)) END";
            $parameters = [$path, $path];
        } elseif (
            in_array(
                $field,
                [
                    "title",
                    "template",
                    "parent_id",
                    "nav_order",
                    "excerpt",
                    "body",
                    "event_location",
                ],
                true,
            )
        ) {
            $path = '$.' . $field;
            $expression =
                "CASE WHEN JSON_TYPE(JSON_EXTRACT(p.draft_json,?))='NULL' THEN NULL ELSE JSON_UNQUOTE(JSON_EXTRACT(p.draft_json,?)) END";
            $parameters = [$path, $path];
            if ($field === "parent_id") {
                $definition["type"] = "page-reference";
            } elseif ($field === "nav_order") {
                $definition["type"] = "integer";
                $definition["min"] = -100000;
                $definition["max"] = 100000;
            } elseif ($field === "body") {
                $definition["type"] = "textarea";
            }
        } elseif (in_array($field, ["event_start", "event_day", "news_date"], true)) {
            $expression =
                "CASE WHEN JSON_TYPE(JSON_EXTRACT(p.draft_json,?))='NULL' THEN NULL ELSE NULLIF(JSON_UNQUOTE(JSON_EXTRACT(p.draft_json,?)),'') END";
            $datePath=$field==='news_date'?'$.news_date':'$.event_start';
            $parameters = [$datePath,$datePath];
            $definition['type']=$field==='event_start'?'datetime':'date';
            if ($field === "event_day") {
                $expression = "LEFT(" . $expression . ",10)";
                $definition["type"] = "date";
            }
        } elseif(in_array($field,['event_end','event_start_utc'],true)) {
            $expression="NULLIF(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(p.draft_json,?)),'null'),'')";$parameters=['$.'.$field];$definition['type']='instant';
        } elseif (
            in_array($field, ["search_text", "event_search_text"], true)
        ) {
            // Preserve the editor's original cross-field search, including its separating spaces.
            $parts = [];
            $keys = ["title", "slug", "excerpt", "body", "event_location", "content_sections_text"];
            if ($field === "event_search_text") {
                $keys[] = "event_address";
            }
            foreach ($keys as $key) {
                $parts[] =
                    "COALESCE(CASE WHEN JSON_TYPE(JSON_EXTRACT(p.draft_json,'$." .
                    $key .
                    "'))='NULL' THEN NULL ELSE JSON_UNQUOTE(JSON_EXTRACT(p.draft_json,'$." .
                    $key .
                    "')) END,'')";
            }
            $expression = "CONCAT(" . implode(", ' ', ", $parts) . ")";
            $definition["type"] = "textarea";
        } elseif (in_array($field,['created_at','updated_at','published_at'],true)) {
            $expression='p.'.$field;$definition['type']='timestamp';
        } elseif ($field === 'id') {
            $expression='p.id';$definition['type']='page-reference';
        } elseif ($field === "renderer") {
            $expression =
                "COALESCE(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(p.draft_json,'$.template_definition.renderer')),'null'),JSON_UNQUOTE(JSON_EXTRACT(p.draft_json,'$.template')))";
        } elseif ($field === 'archived') {
            $definition['type']='boolean';$expression="CASE WHEN EXISTS(SELECT 1 FROM khcms_archives query_archive WHERE query_archive.page_id=p.id AND query_archive.club_id=p.club_id) THEN 'true' ELSE 'false' END";
        } elseif ($field === "published") {
            $definition["type"] = "boolean";
            $expression =
                "CASE WHEN p.published_revision IS NULL THEN 'false' ELSE 'true' END";
        } elseif ($field === "slug") {
            $expression = "p.slug";
        } elseif ($field === "status") {
            $expression =
                "CASE WHEN p.published_revision IS NULL THEN 'draft' WHEN p.published_revision=p.version THEN 'published' ELSE 'unpublished_changes' END";
            $definition["type"] = "select";
            $definition["options"] = [
                "draft",
                "published",
                "unpublished_changes",
            ];
        } else {
            throw new InvalidArgumentException("Unbekanntes Suchfeld.");
        }
        $type = $definition["type"];
        $expression = match ($type) {
            "integer" => "CAST((" . $expression . ") AS SIGNED)",
            "decimal" => "CAST((" . $expression . ") AS DECIMAL(24,6))",
            "timestamp" => $expression,
            default => "(" . $expression . ") COLLATE utf8mb4_bin",
        };
        return [
            $expression,
            $parameters,
            $type,
            $definition,
            $guard,
            $guardParameters,
        ];
    }
}
