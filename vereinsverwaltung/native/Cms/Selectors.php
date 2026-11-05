<?php declare(strict_types=1);
namespace Kulturhus\Native\Cms;
use InvalidArgumentException;

/** Closed typed predicates. Identifiers come from code; every JSON path/value is bound. */
final class Selectors
{
    public function __construct(private readonly DataModel $model){}
    public function compile(array $selectors,?string $payloadAlias=null):array
    {if($payloadAlias!==null)new QueryField($this->model,'p',$payloadAlias);$terms=0;$aliases=0;return $this->combine($selectors,'AND',0,$terms,$aliases,$payloadAlias);}
    private function combine(array $selectors,string $junction,int $depth,int &$terms,int &$aliases,?string $payloadAlias):array
    {
        if(!array_is_list($selectors)||count($selectors)>20||$depth>3||($depth>0&&!$selectors))throw new InvalidArgumentException('Maximal20 Bedingungen und drei Gruppenebenen verwenden.');
        $where=[];$args=[];
        foreach($selectors as $selector){
            if(is_array($selector)&&(array_keys($selector)===['any']||array_keys($selector)===['all'])){
                $key=array_key_first($selector);if(!is_array($selector[$key]))throw new InvalidArgumentException('Filtergruppe als Liste angeben.');
                [$sql,$values]=$this->combine($selector[$key],$key==='any'?'OR':'AND',$depth+1,$terms,$aliases,$payloadAlias);
            }else{
                if(++$terms>20||!is_array($selector)||array_diff(array_keys($selector),['field','op','value'])||!array_key_exists('value',$selector)||!is_string($selector['field']??null)||!is_string($selector['op']??'eq'))throw new InvalidArgumentException('Selektor benötigt field,op und value; höchstens20 Bedingungen.');
                [$sql,$values]=$this->predicate($selector['field'],$selector['op']??'eq',$selector['value'],'p',0,$aliases,$payloadAlias);
            }
            $where[]='('.$sql.')';array_push($args,...$values);
        }
        return [implode(' '.$junction.' ',$where),$args];
    }
    private function predicate(string $field,string $operator,mixed $value,string $alias,int $depth,int &$aliases,?string $payloadAlias=null):array
    {
        if(strlen($field)>800||$depth>3)throw new InvalidArgumentException('Höchstens drei Seitenverknüpfungen verfolgen.');
        if(str_starts_with($field,'custom.')&&$payloadAlias===null){
            $parsed='qd'.(++$aliases);[$sql,$args]=$this->predicate($field,$operator,$value,$alias,$depth,$aliases,$parsed);
            return ['COALESCE((SELECT 1 FROM '.QueryField::document($alias).' '.$parsed.' WHERE '.$sql.' LIMIT 1),0)=1',$args];
        }
        $query=new QueryField($this->model,$alias,$payloadAlias);$relation=$query->relation($field);
        if($relation!==null){
            if($depth>=3)throw new InvalidArgumentException('Höchstens drei Seitenverknüpfungen verfolgen.');
            [$source,$targetField]=$relation;[$expression,$expressionArgs,$type,,$guard,$guardArgs]=$query->resolve($source);
            $target='q'.(++$aliases);[$policy,$policyArgs]=$this->model->pageGuard($target);
            [$comparison,$comparisonArgs]=$this->predicate($targetField,$operator,$value,$target,$depth+1,$aliases);
            $condition=$target.'.club_id='.$alias.'.club_id AND NOT EXISTS(SELECT 1 FROM khcms_trash qt WHERE qt.page_id='.$target.'.id AND qt.club_id='.$target.'.club_id) AND ('.$policy.')';
            if(str_starts_with($type,'collection:')){
                [$table,$tableArgs,,$shape,$shapeArgs]=$query->values($source);$items='qi'.(++$aliases);
                $sql=$guard.' AND '.$shape.' AND COALESCE((SELECT 1 FROM '.$table.' '.$items.' JOIN khcms_pages '.$target.' ON '.$target.'.id='.$items.'.value COLLATE utf8mb4_unicode_ci AND '.$target.'.id='.$items.'.value COLLATE utf8mb4_bin WHERE '.$condition.' AND ('.$comparison.') LIMIT 1),0)=1';
                return [$sql,[...$guardArgs,...$shapeArgs,...$tableArgs,...$policyArgs,...$comparisonArgs]];
            }
            return [($guard!==''?$guard.' AND ':'').'COALESCE((SELECT 1 FROM khcms_pages '.$target.' WHERE '.$target.'.id=('.$expression.') COLLATE utf8mb4_unicode_ci AND '.$target.'.id='.$expression.' AND '.$condition.' AND ('.$comparison.') LIMIT 1),0)=1',[...$guardArgs,...$expressionArgs,...$expressionArgs,...$policyArgs,...$comparisonArgs]];
        }
        [$expression,$expressionArgs,$type,$definition,$guard,$guardArgs]=$query->resolve($field);
        if(str_starts_with($type,'collection:')){
            if($value===null&&in_array($operator,['eq','ne'],true)){
                $records=$type==='collection:repeater';[$table,$tableArgs,,$shape,$shapeArgs]=$query->values($field,$records);$items='qi'.(++$aliases);
                return [($guard!==''?$guard.' AND ':'').$shape.' AND COALESCE((SELECT 1 FROM '.$table.' '.$items.($records?'':' WHERE '.$items.'.value IS NOT NULL').' LIMIT 1),0)='.($operator==='eq'?'0':'1'),[...$guardArgs,...$shapeArgs,...$tableArgs]];
            }
            [$table,$tableArgs,$scalar,$shape,$shapeArgs]=$query->values($field);unset($scalar['multiple']);$scalar['required']=false;
            $items='qi'.(++$aliases);$element=$items.'.value';$element=self::cast($element,$scalar['type']);
            // ne means no stored element equals the value, rather than one unequal element.
            [$comparison,$values]=$this->comparison($element,$scalar['type'],$scalar,$operator==='ne'?'eq':$operator,$value,$field,true);
            return [($guard!==''?$guard.' AND ':'').$shape.' AND COALESCE((SELECT 1 FROM '.$table.' '.$items.' WHERE '.$comparison.' LIMIT 1),0)='.($operator==='ne'?'0':'1'),[...$guardArgs,...$shapeArgs,...$tableArgs,...$values]];
        }
        [$comparison,$values]=$this->comparison($expression,$type,$definition,$operator,$value,$field);
        return [($guard!==''?$guard.' AND ':'').$comparison,[...$guardArgs,...$expressionArgs,...$values]];
    }
    public static function cast(string $expression,string $type):string
    {return match($type){'integer'=>'CAST(('.$expression.') AS SIGNED)','decimal'=>'CAST(('.$expression.') AS DECIMAL(24,6))',default=>'('.$expression.') COLLATE utf8mb4_bin'};}
    private function comparison(string $expression,string $type,array $definition,string $operator,mixed $value,string $field,bool $member=false):array
    {
        $allowed=['eq','ne','in'];
        if(in_array($type,['text','textarea','richtext','email','url','select'],true))array_push($allowed,'contains','starts_with','contains_ci','starts_with_ci');
        if(in_array($type,['integer','decimal','date','datetime','timestamp','instant'],true))array_push($allowed,'gt','gte','lt','lte');
        if(!in_array($operator,$allowed,true))throw new InvalidArgumentException('Operator ist für diesen Feldtyp nicht erlaubt.');
        $normalize=function(mixed $entry)use($definition,$field,$type,$operator):mixed{
            if($entry===null)return null;
            if(in_array($type,['timestamp','instant'],true)){
                if(!is_string($entry)||!preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}Z\z/D',$entry))throw new InvalidArgumentException('UTC-Zeit im Format JJJJ-MM-TTTHH:MM:SSZ angeben.');
                $date=\DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z',$entry,new \DateTimeZone('UTC'));if(!$date||$date->format('Y-m-d\TH:i:s\Z')!==$entry)throw new InvalidArgumentException('Gültige UTC-Zeit angeben.');return $type==='instant'?$entry:$date->format('Y-m-d H:i:s');
            }
            if(empty($definition['fieldtype'])&&(in_array($operator,['contains','starts_with','contains_ci','starts_with_ci'],true)||$type==='select'&&str_starts_with($field,'custom.')))return $entry===''?null:CustomFields::text($entry,$type==='select'?200:100000);
            $entry=$this->model->fieldValue($definition,$entry,true);
            if($entry===null&&!str_starts_with($field,'custom.')&&!in_array($field,['parent_id','event_start','event_day','news_date'],true))throw new InvalidArgumentException('Leeren Suchwert für dieses Feld prüfen.');
            return is_bool($entry)?($entry?'true':'false'):$entry;
        };
        $placeholder=match($type){'decimal'=>'CAST(? AS DECIMAL(24,6))','integer'=>'CAST(? AS SIGNED)',default=>'?'};
        if($operator==='in'){
            if(!is_array($value)||!array_is_list($value)||!$value||count($value)>100)throw new InvalidArgumentException('IN benötigt1–100 Suchwerte.');
            $values=array_map($normalize,$value);if(in_array(null,$values,true))throw new InvalidArgumentException('Nullwerte separat mit eq/ne abfragen.');
            return [$expression.' IN ('.implode(',',array_fill(0,count($values),$placeholder)).')',$values];
        }
        $value=$normalize($value);
        if($value===null){if($member)throw new InvalidArgumentException('Leere Mehrfachwerte ausdrücklich mit eq/ne null abfragen.');if(!in_array($operator,['eq','ne'],true))throw new InvalidArgumentException('Nullwerte erlauben nur eq/ne.');return [$expression.($operator==='eq'?' IS NULL':' IS NOT NULL'),[]];}
        if(in_array($operator,['contains','starts_with','contains_ci','starts_with_ci'],true)){
            if(str_ends_with($operator,'_ci')){$expression='LOWER('.$expression.') COLLATE utf8mb4_bin';$value=mb_strtolower((string)$value,'UTF-8');}
            $value=str_replace(['!','%','_'],['!!','!%','!_'],(string)$value);
            return [$expression." LIKE ? ESCAPE '!'",[(str_starts_with($operator,'contains')?'%':'').$value.'%']];
        }
        return [$expression.' '.['eq'=>'=','ne'=>'<>','gt'=>'>','gte'=>'>=','lt'=>'<','lte'=>'<='][$operator].' '.$placeholder,[$value]];
    }
}
