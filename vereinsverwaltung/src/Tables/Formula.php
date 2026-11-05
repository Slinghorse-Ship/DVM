<?php declare(strict_types=1);
namespace Kulturhus\Verein\Tables;

use Brick\Math\{BigDecimal,BigRational,RoundingMode};
use DomainException;

/** Bounded arithmetic grammar. Stored strings are never executable PHP or spreadsheet code. */
final class Formula
{
    private int $offset=0;
    private int $nodes=0;
    private array $tokens=[];
    private int $position=0;
    private const FUNCTIONS=['SUM'=>'SUM','SUMME'=>'SUM','AVG'=>'AVG','MITTELWERT'=>'AVG','MIN'=>'MIN','MAX'=>'MAX','ROUND'=>'ROUND','RUNDEN'=>'ROUND'];

    public static function parse(string $source,bool $link=false):array
    {
        if(strlen($source)>2000||!str_starts_with($source,'='))throw new DomainException('Formel oder Zellverknüpfung mit = beginnen.');
        $parser=new self();$source=substr($source,1);$length=strlen($source);
        while($parser->offset<$length){
            if(preg_match('/\G\s+/A',$source,$m,0,$parser->offset)){$parser->offset+=strlen($m[0]);continue;}
            if(!preg_match('/\G(?:\[([A-T])\]|@\{([a-f0-9]{32}):([a-f0-9]{32}):([A-T])\}|(\d{1,12}(?:[.,]\d{1,6})?)|([A-Z]+)|([+*\/();-]))/A',$source,$m,PREG_UNMATCHED_AS_NULL,$parser->offset))throw new DomainException('Formel prüfen: Zahlen, Zellverweise, + − * / und unterstützte Funktionen verwenden.');
            $parser->offset+=strlen($m[0]);
            $parser->tokens[]=$m[1]!==null?['ref',null,null,ord($m[1])-65]:($m[2]!==null?['ref',$m[2],$m[3],ord($m[4])-65]:($m[5]!==null?['number',str_replace(',','.',$m[5])]:($m[6]!==null?['name',$m[6]]:[$m[7]])));
            if(count($parser->tokens)>256)throw new DomainException('Formel auf 256 Bestandteile begrenzen.');
        }
        $tree=$parser->expression(0);
        if($parser->position!==count($parser->tokens))throw new DomainException('Formel vollständig prüfen; Funktionswerte mit Semikolon trennen.');
        if($link&&$tree[0]!=='ref')throw new DomainException('Eine Zellverknüpfung enthält genau einen Zellverweis nach dem =.');
        return $tree;
    }
    private function peek():?string{return $this->tokens[$this->position][0]??null;}
    private function node(array $node):array{if(++$this->nodes>256)throw new DomainException('Formel ist zu umfangreich.');return $node;}
    private function expression(int $depth):array
    {
        $left=$this->product($depth+1);
        while(in_array($this->peek(),['+','-'],true)){$op=$this->tokens[$this->position++][0];$left=$this->node([$op,$left,$this->product($depth+1)]);}
        return $left;
    }
    private function product(int $depth):array
    {
        $left=$this->atom($depth+1);
        while(in_array($this->peek(),['*','/'],true)){$op=$this->tokens[$this->position++][0];$left=$this->node([$op,$left,$this->atom($depth+1)]);}
        return $left;
    }
    private function atom(int $depth):array
    {
        if($depth>32)throw new DomainException('Formel höchstens 10 Klammern oder Funktionen tief verschachteln.');
        $token=$this->tokens[$this->position++]??null;if(!$token)throw new DomainException('Formel ist unvollständig.');
        if(in_array($token[0],['number','ref'],true))return $this->node($token);
        if(in_array($token[0],['+','-'],true))return $this->node(['unary',$token[0],$this->atom($depth+1)]);
        if($token[0]==='('){$inner=$this->expression($depth+1);if($this->peek()!==')')throw new DomainException('Schließende Formelklammer fehlt.');$this->position++;return $inner;}
        if($token[0]==='name'&&isset(self::FUNCTIONS[$token[1]])&&$this->peek()==='('){
            $this->position++;$args=[$this->expression($depth+1)];
            while($this->peek()===';'){$this->position++;$args[]=$this->expression($depth+1);if(count($args)>64)throw new DomainException('Bis zu 64 Funktionswerte verwenden.');}
            if($this->peek()!==')')throw new DomainException('Funktionswerte mit Semikolon trennen und Klammer schließen.');$this->position++;
            $name=self::FUNCTIONS[$token[1]];if($name==='ROUND'&&count($args)!==2)throw new DomainException('RUNDEN benötigt Zahl und Nachkommastellen.');
            return $this->node(['function',$name,$args]);
        }
        throw new DomainException('Unbekannte Formel oder Funktion. SUMME, MITTELWERT, MIN, MAX und RUNDEN verwenden.');
    }
    public static function runtime():void{require_once dirname(__DIR__,3).'/vendor/autoload.php';}
    public static function number(string $value):BigRational
    {
        self::runtime();if(!preg_match('/^-?\d{1,12}(?:[.,]\d{1,6})?$/D',$value))throw new DomainException('Diese Quelle enthält keine Zahl.');
        return BigRational::of(str_replace(',','.',$value));
    }
    private static function bounded(BigRational $value):BigRational
    {
        if(strlen(ltrim((string)$value->getNumerator(),'-'))>72||strlen((string)$value->getDenominator())>72)throw new DomainException('Berechnung überschreitet die Rechengrenze.');return $value;
    }
    public static function evaluate(array $tree,callable $reference,int &$budget):BigRational
    {
        self::runtime();if(--$budget<0)throw new DomainException('Zu viele Berechnungen. Liste aufteilen oder Verweise verkürzen.');
        if($tree[0]==='number')return self::number($tree[1]);
        if($tree[0]==='ref')return $reference($tree);
        if($tree[0]==='unary'){$value=self::evaluate($tree[2],$reference,$budget);return $tree[1]==='-'?$value->negated():$value;}
        if($tree[0]==='function'){
            $values=[];foreach($tree[2]as$arg)$values[]=self::evaluate($arg,$reference,$budget);$result=$values[0];
            if($tree[1]==='ROUND'){
                $places=$values[1];if((string)$places->getDenominator()!=='1'||!preg_match('/^[0-6]$/D',(string)$places->getNumerator()))throw new DomainException('RUNDEN benötigt 0 bis 6 Nachkommastellen.');
                return BigRational::of($result->toScale((int)(string)$places->getNumerator(),RoundingMode::HalfUp));
            }
            foreach(array_slice($values,1)as$value)$result=match($tree[1]){'SUM','AVG'=>self::bounded($result->plus($value)),'MIN'=>$result->compareTo($value)<=0?$result:$value,'MAX'=>$result->compareTo($value)>=0?$result:$value};
            return $tree[1]==='AVG'?self::bounded($result->dividedBy(count($values))):$result;
        }
        $left=self::evaluate($tree[1],$reference,$budget);$right=self::evaluate($tree[2],$reference,$budget);
        if($tree[0]==='/'&&$right->isZero())throw new DomainException('Division durch null.');
        return self::bounded(match($tree[0]){'+'=>$left->plus($right),'-'=>$left->minus($right),'*'=>$left->multipliedBy($right),'/'=>$left->dividedBy($right)});
    }
    public static function display(BigRational $value):string
    {
        $out=(string)$value->toScale(6,RoundingMode::HalfUp);$parts=explode('.',$out,2);
        if(strlen(ltrim($parts[0],'-'))>12)throw new DomainException('Ergebnis auf 12 Vorkomma- und 6 Nachkommastellen begrenzen.');
        if(isset($parts[1]))$out=rtrim(rtrim($out,'0'),'.');return $out==='-0'?'0':$out;
    }
    public static function compare(string $left,string $right):int{self::runtime();return BigDecimal::of(str_replace(',','.',$left===''?'0':$left))->compareTo(BigDecimal::of(str_replace(',','.',$right===''?'0':$right)));}
}
