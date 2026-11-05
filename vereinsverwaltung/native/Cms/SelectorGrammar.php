<?php declare(strict_types=1);
namespace Kulturhus\Native\Cms;

use InvalidArgumentException;

/** Bounded human-readable syntax that produces the same closed typed selector tree. */
final class SelectorGrammar
{
    private int $position=0;
    private int $terms=0;
    private array $tokens=[];
    private const OPERATORS=['='=>'eq','!='=>'ne','>'=>'gt','>='=>'gte','<'=>'lt','<='=>'lte','*='=>'contains','^='=>'starts_with'];
    private const WORDS=['eq','ne','gt','gte','lt','lte','in','contains','starts_with','contains_ci','starts_with_ci'];

    public static function parse(string $source):array
    {
        if(strlen($source)>16384||!mb_check_encoding($source,'UTF-8')||preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/',$source))throw new InvalidArgumentException('Suchausdruck als UTF-8-Text bis16KiB angeben.');
        if(trim($source)==='')return[];
        $parser=new self();$parser->scan($source);$node=$parser->expression(0);
        if($parser->peek()!==null)throw new InvalidArgumentException('Unerwartetes Zeichen im Suchausdruck.');
        $tree=isset($node['all'])?$node['all']:[$node];self::depth($tree,0);
        return$tree;
    }

    /** Lossless human syntax for stored typed trees, including nested groups and null. */
    public static function format(array $selectors):string
    {
        $render=function(array $nodes,string $join,int $depth)use(&$render):string{
            if($depth>3||!array_is_list($nodes))throw new InvalidArgumentException('Gespeicherte Suchgruppen prüfen.');$parts=[];
            foreach($nodes as $node){
                if(is_array($node)&&(array_keys($node)===['any']||array_keys($node)===['all'])){$key=array_key_first($node);$parts[]='('.$render($node[$key],$key==='any'?' OR ':' AND ',$depth+1).')';continue;}
                if(!is_array($node)||!is_string($node['field']??null)||!is_string($node['op']??null)||!array_key_exists('value',$node))throw new InvalidArgumentException('Gespeicherte Suchbedingung prüfen.');
                $value=$node['value'];$literal=static fn(mixed $item):string=>json_encode($item,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
                $parts[]=$node['field'].' '.$node['op'].' '.($node['op']==='in'&&is_array($value)?'['.implode(', ',array_map($literal,$value)).']':$literal($value));
            }
            return implode($join,$parts);
        };
        $source=$render($selectors,' AND ',0);self::parse($source);return $source;
    }

    private function scan(string $source):void
    {
        $length=strlen($source);$offset=0;
        while($offset<$length){
            if(str_contains(" \r\n\t",$source[$offset])){++$offset;continue;}
            if(count($this->tokens)>=8192)throw new InvalidArgumentException('Suchausdruck enthält zu viele Zeichenfolgen.');
            $rest=substr($source,$offset);
            if($source[$offset]==='"'){
                $end=$offset+1;$escaped=false;
                for(;$end<$length;++$end){$character=$source[$end];if($escaped){$escaped=false;continue;}if($character==='\\'){$escaped=true;continue;}if($character==='"')break;}
                if($end===$length)throw new InvalidArgumentException('Suchtext mit doppeltem Anführungszeichen schließen.');
                try{$value=json_decode(substr($source,$offset,$end-$offset+1),true,2,JSON_THROW_ON_ERROR);}catch(\JsonException){throw new InvalidArgumentException('Suchtext enthält eine ungültige Escape-Sequenz.');}
                $this->tokens[]=['kind'=>'value','value'=>$value];$offset=$end+1;continue;
            }
            if(preg_match('/\A(?:!=|>=|<=|\*=|\^=|[=><(),\[\]])/D',$rest,$match)){$this->tokens[]=['kind'=>'symbol','value'=>$match[0]];$offset+=strlen($match[0]);continue;}
            if(preg_match('/\A-?(?:0|[1-9][0-9]*)(?:\.[0-9]+)?/D',$rest,$match)){$this->tokens[]=['kind'=>'value','value'=>$match[0]];$offset+=strlen($match[0]);continue;}
            if(preg_match('/\A[a-zA-Z][a-zA-Z0-9_]*(?:\.[a-zA-Z][a-zA-Z0-9_]*)*/D',$rest,$match)){$this->tokens[]=['kind'=>'word','value'=>$match[0]];$offset+=strlen($match[0]);continue;}
            throw new InvalidArgumentException('Suchausdruck enthält ein unzulässiges Zeichen.');
        }
    }

    private function peek():?array{return$this->tokens[$this->position]??null;}
    private function take():array{return$this->tokens[$this->position++]??throw new InvalidArgumentException('Suchausdruck ist unvollständig.');}
    private function accept(string $value):bool
    {if(($this->peek()['value']??null)===$value){++$this->position;return true;}return false;}
    private function keyword(string $word):bool
    {if(($this->peek()['kind']??null)==='word'&&strtolower($this->peek()['value'])===$word){++$this->position;return true;}return false;}
    private function expect(string $value):void{if(!$this->accept($value))throw new InvalidArgumentException('Suchausdruck benötigt '.$value.'.');}
    private static function group(string $key,array $nodes):array
    {
        $flat=[];foreach($nodes as$node){if(isset($node[$key]))array_push($flat,...$node[$key]);else$flat[]=$node;}
        return count($flat)===1?$flat[0]:[$key=>$flat];
    }
    private function expression(int $depth):array
    {
        $nodes=[$this->conjunction($depth)];while($this->keyword('or'))$nodes[]=$this->conjunction($depth);
        return self::group('any',$nodes);
    }
    private function conjunction(int $depth):array
    {
        $nodes=[$this->term($depth)];while($this->keyword('and'))$nodes[]=$this->term($depth);
        return self::group('all',$nodes);
    }
    private function term(int $depth):array
    {
        if($depth>3)throw new InvalidArgumentException('Suchgruppen höchstens drei Ebenen verschachteln.');
        if($this->accept('(')){$node=$this->expression($depth+1);$this->expect(')');return$node;}
        if(++$this->terms>20)throw new InvalidArgumentException('Höchstens20 Suchbedingungen verwenden.');
        $field=$this->take();if($field['kind']!=='word'||!preg_match('/\A[a-z][a-z0-9_]*(?:\.[a-z][a-z0-9_]*)*\z/D',$field['value']))throw new InvalidArgumentException('Gültigen Suchfeldnamen angeben.');
        $token=$this->take();if(!in_array($token['kind'],['symbol','word'],true))throw new InvalidArgumentException('Gültigen Suchoperator angeben.');
        $operator=self::OPERATORS[$token['value']]??($token['kind']==='word'?strtolower($token['value']):'');
        if(!in_array($operator,self::WORDS,true))throw new InvalidArgumentException('Unbekannter Suchoperator.');
        if($operator==='in'){
            $this->expect('[');$value=[$this->literal()];while($this->accept(',')){if(count($value)>=100)throw new InvalidArgumentException('IN erlaubt höchstens100 Werte.');$value[]=$this->literal();}$this->expect(']');
        }else$value=$this->literal();
        return['field'=>$field['value'],'op'=>$operator,'value'=>$value];
    }
    private function literal():mixed
    {
        $token=$this->take();if($token['kind']==='value')return$token['value'];
        if($token['kind']==='word')return match(strtolower($token['value'])){'true'=>true,'false'=>false,'null'=>null,default=>throw new InvalidArgumentException('Suchtexte in doppelte Anführungszeichen setzen.')};
        throw new InvalidArgumentException('Einen Suchwert angeben.');
    }
    private static function depth(array $nodes,int $depth):void
    {
        if($depth>3)throw new InvalidArgumentException('Suchgruppen höchstens drei Ebenen verschachteln.');
        foreach($nodes as$node)foreach(['all','any']as$key)if(isset($node[$key]))self::depth($node[$key],$depth+1);
    }
}
