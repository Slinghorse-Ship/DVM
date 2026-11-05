<?php declare(strict_types=1);
namespace Kulturhus\Native\Routing;

use InvalidArgumentException;

/** Finite template URL declarations. No stored expression, callable or executable route. */
final class Policy
{
    public const MAX_SEGMENTS=8;
    public const MAX_VALUE=80;
    public const MAX_PAGE=83334;
    public const DEFAULTS=['segments'=>[],'pagination'=>['mode'=>'query','marker'=>'page','maximum'=>self::MAX_PAGE],'slash'=>'inherit','https'=>'inherit'];
    private function __construct(public readonly array $definition){}
    public static function from(mixed $input):self{return new self(self::normalize($input));}

    public static function normalize(mixed $input):array
    {
        if(!is_array($input)||(array_is_list($input)&&$input!==[])||array_diff(array_keys($input),array_keys(self::DEFAULTS)))throw new InvalidArgumentException('Nur URL-Segmente, Seitenwechsel, Slashregel und HTTPS-Vorgabe festlegen.');
        $policy=array_replace(self::DEFAULTS,$input);
        if(!in_array($policy['slash'],['inherit','always','never'],true)||!in_array($policy['https'],['inherit','https'],true))throw new InvalidArgumentException('Gültige Slash- und HTTPS-Regel auswählen.');
        $pagination=$policy['pagination'];
        if(!is_array($pagination)||(array_is_list($pagination)&&$pagination!==[])||array_diff(array_keys($pagination),array_keys(self::DEFAULTS['pagination'])))throw new InvalidArgumentException('Seitenwechsel mit Modus, festem Pfadwort und Obergrenze festlegen.');
        $pagination=array_replace(self::DEFAULTS['pagination'],$pagination);
        if(!in_array($pagination['mode'],['query','path','off'],true)||!self::slug($pagination['marker'])||!is_int($pagination['maximum'])||$pagination['maximum']<1||$pagination['maximum']>self::MAX_PAGE)throw new InvalidArgumentException('Seitenwechsel als Abfrage, Pfad oder ausgeschaltet mit gültiger Obergrenze festlegen.');
        $policy['pagination']=$pagination;
        if(!is_array($policy['segments'])||!array_is_list($policy['segments'])||count($policy['segments'])>self::MAX_SEGMENTS)throw new InvalidArgumentException('Bis zu acht feste Segmentregeln angeben.');
        $segments=[];$names=[];$effects=[];$optional=false;
        foreach($policy['segments']as$rule){
            if(!is_array($rule)||array_diff(array_keys($rule),['name','type','required','minimum','maximum','values','use'])||!is_string($rule['name']??null)||!preg_match('/\A[a-z][a-z0-9_]{0,31}\z/D',$rule['name'])||isset($names[$rule['name']]))throw new InvalidArgumentException('Jedes Segment benötigt einen eindeutigen kurzen Namen.');
            $type=$rule['type']??'slug';$required=$rule['required']??false;$use=$rule['use']??'context';
            if(!in_array($type,['slug','integer','date','choice'],true)||!is_bool($required)||!in_array($use,['context','event-year','event-query','event-archive','event-sort'],true)||($optional&&$required))throw new InvalidArgumentException('Festen Segmenttyp wählen; optionale Segmente stehen am Ende.');
            if(!$required)$optional=true;
            if($use!=='context'&&isset($effects[$use]))throw new InvalidArgumentException('Jeden Programmfilter nur einmal mit einem Segment verbinden.');
            $clean=['name'=>$rule['name'],'type'=>$type,'required'=>$required,'use'=>$use];
            if($type==='integer'){
                $minimum=$rule['minimum']??($use==='event-year'?1900:1);$maximum=$rule['maximum']??($use==='event-year'?2100:2147483647);
                if(!is_int($minimum)||!is_int($maximum)||$minimum<0||$maximum>2147483647||$minimum>$maximum||($use==='event-year'&&($minimum<1900||$maximum>2100)))throw new InvalidArgumentException('Gültige ganze Segmentgrenzen angeben.');
                $clean+=['minimum'=>$minimum,'maximum'=>$maximum];
            }elseif(isset($rule['minimum'])||isset($rule['maximum']))throw new InvalidArgumentException('Zahlengrenzen gehören nur zu Zahlensegmenten.');
            if($type==='choice'){
                $values=$rule['values']??[];
                if(!is_array($values)||!array_is_list($values)||count($values)<1||count($values)>40||count(array_unique($values,SORT_REGULAR))!==count($values))throw new InvalidArgumentException('Eine bis vierzig eindeutige erlaubte Segmentangaben auswählen.');
                foreach($values as$value)if(!self::slug($value)||($pagination['mode']==='path'&&$value===$pagination['marker']))throw new InvalidArgumentException('Kurze feste Pfadwerte ohne das Seitenwechselwort verwenden.');
                $clean['values']=$values;
            }elseif(isset($rule['values']))throw new InvalidArgumentException('Erlaubte Werte gehören nur zu einem Auswahlsegment.');
            if(($use==='event-year'&&$type!=='integer')||($use==='event-query'&&!in_array($type,['slug','choice'],true))||($use==='event-archive'&&($type!=='choice'||array_diff($clean['values'],['upcoming','archive'])))||($use==='event-sort'&&($type!=='choice'||array_diff($clean['values'],['asc','desc']))))throw new InvalidArgumentException('Der gewählte Programmfilter benötigt den passenden festen Segmenttyp.');
            $segments[]=$clean;$names[$clean['name']]=true;if($use!=='context')$effects[$use]=true;
        }
        $policy['segments']=$segments;return $policy;
    }

    public static function slug(mixed $value):bool{return is_string($value)&&strlen($value)<=self::MAX_VALUE&&(bool)preg_match('/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/D',$value);}
    public function fingerprint():string{return hash('sha256',json_encode($this->definition,JSON_THROW_ON_ERROR));}

    /** Exact positional match, with one reserved trailing pager; never tries alternative interpretations. */
    public function match(array $suffix,RequestPath $request,string $pageParameter='page'):?array
    {
        if(!in_array($pageParameter,['page','seite'],true))throw new InvalidArgumentException('Feste Seitenwechselart verwenden.');
        if(!array_is_list($suffix))throw new InvalidArgumentException('Pfadsegmente als geordnete Liste übergeben.');
        $pager=$this->definition['pagination'];$pathPage=null;
        if($pager['mode']==='path'&&in_array($pager['marker'],$suffix,true)){
            if(count(array_keys($suffix,$pager['marker'],true))!==1||count($suffix)<2||$suffix[count($suffix)-2]!==$pager['marker'])return null;
            $value=$suffix[array_key_last($suffix)];if(!self::pageValue($value,$pager['maximum']))return null;
            $pathPage=(int)$value;$suffix=array_slice($suffix,0,-2);
        }
        if(count($suffix)>count($this->definition['segments'])||count($suffix)>self::MAX_SEGMENTS)return null;
        $parameters=[];
        foreach($this->definition['segments']as$index=>$rule){
            if(!array_key_exists($index,$suffix)){if($rule['required']&&$suffix!==[])return null;continue;}
            $value=$suffix[$index];if(!is_string($value)||strlen($value)>self::MAX_VALUE)return null;
            $valid=match($rule['type']){
                'slug'=>self::slug($value),
                'integer'=>preg_match('/\A(?:0|[1-9][0-9]{0,9})\z/D',$value)&&((int)$value)>=$rule['minimum']&&((int)$value)<=$rule['maximum'],
                'date'=>preg_match('/\A([0-9]{4})-([0-9]{2})-([0-9]{2})\z/D',$value,$parts)&&checkdate((int)$parts[2],(int)$parts[3],(int)$parts[1]),
                'choice'=>in_array($value,$rule['values'],true),
            };
            if(!$valid)return null;$parameters[$rule['name']]=$rule['type']==='integer'?(int)$value:$value;
        }
        $queryPage=$request->value($pageParameter);
        if($queryPage!==null&&!self::pageValue($queryPage,$pager['maximum']))throw new InvalidArgumentException('Gültige positive Seitennummer angeben.');
        if($pathPage!==null&&$queryPage!==null&&$pathPage!==(int)$queryPage)throw new InvalidArgumentException('Pfad und Abfrage dürfen keine verschiedenen Seiten verlangen.');
        $page=$pathPage??($queryPage===null?1:(int)$queryPage);
        if($pager['mode']==='off'&&$page!==1)return null;
        return ['parameters'=>$parameters,'page'=>$page,'path_page'=>$pathPage!==null];
    }

    public function eventQuery(array $parameters,array $query):array
    {
        foreach($this->definition['segments']as$rule){if(!array_key_exists($rule['name'],$parameters)||$rule['use']==='context')continue;$value=$parameters[$rule['name']];[$key,$value]=match($rule['use']){'event-year'=>['jahr',(string)$value],'event-query'=>['q',$value],'event-archive'=>['archiv',$value==='archive'?'1':'0'],'event-sort'=>['sort',$value]};
            if(isset($query[$key])&&(!is_scalar($query[$key])||(string)$query[$key]!==$value))throw new InvalidArgumentException('Pfadfilter und Formularauswahl müssen übereinstimmen.');$query[$key]=$value;
        }return $query;
    }
    private static function pageValue(mixed $value,int $maximum):bool{return(is_string($value)||is_int($value))&&(bool)preg_match('/\A[1-9][0-9]{0,4}\z/D',(string)$value)&&(int)$value<=$maximum;}
}
