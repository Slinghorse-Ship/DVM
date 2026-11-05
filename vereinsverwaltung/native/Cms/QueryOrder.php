<?php declare(strict_types=1);
namespace Kulturhus\Native\Cms;

use InvalidArgumentException;

/** Bounded typed ordering with explicit null placement and a stable identity tie-break. */
final class QueryOrder
{
    private bool $textBudget=false;
    public function __construct(private readonly DataModel $model) {}
    public function requiresTextBudget():bool{return $this->textBudget;}

    /** MySQL must compare every permitted text byte, then restore the caller's session exactly. */
    public static function run(\PDO $db,string $sql,array $parameters,bool $textBudget=false):\PDOStatement
    {
        if(!$textBudget)return \Kulturhus\Native\Shared\Db::run($db,$sql,$parameters);
        $original=\Kulturhus\Native\Shared\Db::run($db,'SELECT @@SESSION.max_sort_length AS max_sort_length,@@SESSION.sort_buffer_size AS sort_buffer_size')->fetch(\PDO::FETCH_ASSOC);
        $changed=[];
        try{
            // Native richtext is <=1 MB HTML; three full scalar keys fit this bounded server buffer.
            foreach(['max_sort_length'=>4194304,'sort_buffer_size'=>268435456]as$key=>$minimum){if((int)$original[$key]<$minimum){$db->exec('SET SESSION '.$key.'='.$minimum);$changed[]=$key;}}
            return \Kulturhus\Native\Shared\Db::run($db,$sql,$parameters);
        }finally{foreach(array_reverse($changed)as$key)$db->exec('SET SESSION '.$key.'='.(int)$original[$key]);}
    }

    public function compile(array $order,?string $payloadAlias=null): array
    {[$sql,$args]=$this->plan($order,$payloadAlias);return[$sql,$args];}

    /** Materialize each authorized scalar key once before windowing; never keep a whole draft. */
    public function window(array $order,?string $payloadAlias=null):array
    {[,,$columns,$args,$sql]=$this->plan($order,$payloadAlias);return[$columns,$args,$sql];}

    private function plan(array $order,?string $payloadAlias):array
    {
        $this->textBudget=false;
        if($payloadAlias!==null)new QueryField($this->model,'p',$payloadAlias);
        if (!array_is_list($order) || count($order) > 3) {
            throw new InvalidArgumentException(
                "Bis zu drei Sortierfelder als Liste angeben.",
            );
        }
        if (!$order) {
            return ["p.slug COLLATE utf8mb4_bin,p.id", [],["p.slug COLLATE utf8mb4_bin AS dvm_sort0"],[],"chosen.dvm_sort0,chosen.id"];
        }
        $parts = [];
        $parameters = [];
        $used = [];
        $aliases=0;$columns=[];$columnParameters=[];$windowParts=[];
        foreach ($order as $term) {
            if (is_array($term)) {
                $term += [
                    "direction" => "asc",
                    "nulls" => "last",
                    "case_fold" => false,
                ];
            }
            if (
                !is_array($term) ||
                array_diff(array_keys($term), [
                    "field",
                    "direction",
                    "nulls",
                    "case_fold",
                    "aggregate",
                ]) ||
                !is_string($term["field"] ?? null) ||
                !is_string($term["direction"]) ||
                !in_array(
                    strtolower($term["direction"]),
                    ["asc", "desc"],
                    true,
                ) ||
                !in_array($term["nulls"], ["first", "last"], true) ||
                !is_bool($term["case_fold"]) ||
                isset($used[$term["field"]])
            ) {
                throw new InvalidArgumentException(
                    "Sortierfeld, Richtung, Leerwertposition und Textvergleich prüfen.",
                );
            }
            $field = $term["field"];
            $used[$field] = true;
            $aggregate=$term['aggregate']??null;
            if($aggregate!==null&&!in_array($aggregate,['min','max','count'],true))throw new InvalidArgumentException('Mehrfachsortierung benötigt min,max oder count.');
            [$expression,$expressionParameters,$type]=$this->projection($field,$aggregate,'p',0,$aliases,$payloadAlias);
            if ($term["case_fold"] ?? false) {
                if (
                    !in_array(
                        $type,
                        [
                            "text",
                            "textarea",
                            "richtext",
                            "select",
                            "url",
                            "email",
                        ],
                        true,
                    )
                ) {
                    throw new InvalidArgumentException(
                        "Groß-/Kleinschreibung nur bei Textsortierung einstellbar.",
                    );
                }
                $expression = "LOWER(" . $expression . ") COLLATE utf8mb4_bin";
            }
            $column='dvm_sort'.count($columns);$columns[]=$expression.' AS '.$column;array_push($columnParameters,...$expressionParameters);
            $windowParts[]='(chosen.'.$column.' IS NULL) '.($term['nulls']==='first'?'DESC':'ASC');
            $windowParts[]='chosen.'.$column.' '.strtoupper($term['direction']);
            $parts[] =
                "(" .
                $expression .
                " IS NULL) " .
                (($term["nulls"] ?? "last") === "first" ? "DESC" : "ASC");
            $parts[] =
                $expression . " " . strtoupper($term["direction"] ?? "asc");
            array_push(
                $parameters,
                ...$expressionParameters,
                ...$expressionParameters,
            );
        }
        $parts[] = "p.id";
        $windowParts[]='chosen.id';
        return [implode(",", $parts), $parameters,$columns,$columnParameters,implode(',',$windowParts)];
    }
    private function noteTextBudget(string $type,string $field):void
    {
        if(in_array($type,['textarea','richtext','url'],true)||$type==='text'&&!in_array($field,['title','slug','template','renderer'],true))$this->textBudget=true;
    }

    private function projection(string $field,?string $aggregate,string $alias,int $depth,int &$aliases,?string $payloadAlias=null):array
    {
        if($depth>3||strlen($field)>800)throw new InvalidArgumentException('Höchstens drei Seitenverknüpfungen sortieren.');
        if(str_starts_with($field,'custom.')&&$payloadAlias===null){
            $parsed='qod'.(++$aliases);[$value,$args,$type]=$this->projection($field,$aggregate,$alias,$depth,$aliases,$parsed);
            return ['(SELECT '.$value.' FROM '.QueryField::document($alias).' '.$parsed.')',$args,$type];
        }
        $query=new QueryField($this->model,$alias,$payloadAlias);$relation=$query->relation($field);
        if($relation!==null){
            if($depth>=3)throw new InvalidArgumentException('Höchstens drei Seitenverknüpfungen sortieren.');
            [$source,$targetField]=$relation;[$sourceSql,$sourceArgs,$sourceType,,$sourceGuard,$sourceGuardArgs]=$query->resolve($source);
            $many=str_starts_with($sourceType,'collection:');if($many&&$aggregate===null)throw new InvalidArgumentException('Mehrfachsortierung benötigt min,max oder count.');
            $target='qo'.(++$aliases);[$policy,$policyArgs]=$this->model->pageGuard($target);
            $previousBudget=$this->textBudget;
            [$value,$valueArgs,$type]=$this->projection($targetField,$many?null:$aggregate,$target,$depth+1,$aliases);
            if($many&&$aggregate==='count')$this->textBudget=$previousBudget;
            $condition=$target.'.club_id='.$alias.'.club_id AND NOT EXISTS(SELECT 1 FROM khcms_trash qot WHERE qot.page_id='.$target.'.id AND qot.club_id='.$target.'.club_id) AND ('.$policy.')';
            if($many){
                [$table,$tableArgs,,$shape,$shapeArgs]=$query->values($source);$items='qoi'.(++$aliases);$function=strtoupper($aggregate);
                $sql='CASE WHEN '.$sourceGuard.' AND '.$shape.' THEN (SELECT '.$function.'('.$value.') FROM '.$table.' '.$items.' JOIN khcms_pages '.$target.' ON '.$target.'.id='.$items.'.value COLLATE utf8mb4_unicode_ci AND '.$target.'.id='.$items.'.value COLLATE utf8mb4_bin WHERE '.$condition.') ELSE NULL END';
                return [$sql,[...$sourceGuardArgs,...$shapeArgs,...$valueArgs,...$tableArgs,...$policyArgs],$aggregate==='count'?'integer':$type];
            }
            $sql=($sourceGuard!==''?'CASE WHEN '.$sourceGuard.' THEN ':'').'(SELECT '.$value.' FROM khcms_pages '.$target.' WHERE '.$target.'.id=('.$sourceSql.') COLLATE utf8mb4_unicode_ci AND '.$target.'.id='.$sourceSql.' AND '.$condition.')'.($sourceGuard!==''?' ELSE NULL END':'');
            return [$sql,[...$sourceGuardArgs,...$valueArgs,...$sourceArgs,...$sourceArgs,...$policyArgs],$type];
        }
        [$expression,$args,$type,,$guard,$guardArgs]=$query->resolve($field);
        if(str_starts_with($type,'collection:')){
            if($aggregate===null)throw new InvalidArgumentException('Mehrfachsortierung benötigt min,max oder count.');
            if($aggregate!=='count')$this->noteTextBudget(substr($type,11),$field);
            $records=$type==='collection:repeater';if($records&&$aggregate!=='count')throw new InvalidArgumentException('Datensätze nach Anzahl oder einem einzelnen Unterfeld sortieren.');
            [$table,$tableArgs,$definition,$shape,$shapeArgs]=$query->values($field,$records);$items='qoi'.(++$aliases);$value=$records?'*':Selectors::cast($items.'.value',$definition['type']);
            $expression='CASE WHEN '.$guard.' AND '.$shape.' THEN (SELECT '.strtoupper($aggregate).'('.$value.') FROM '.$table.' '.$items.') ELSE NULL END';
            return [$expression,[...$guardArgs,...$shapeArgs,...$tableArgs],$aggregate==='count'?'integer':$definition['type']];
        }
        if($aggregate!==null)throw new InvalidArgumentException('Einzelwerte benötigen keine Mehrfachsortierung.');
        $this->noteTextBudget($type,$field);
        return [$guard!==''?'CASE WHEN '.$guard.' THEN '.$expression.' ELSE NULL END':$expression,[...$guardArgs,...$args],$type];
    }

}
