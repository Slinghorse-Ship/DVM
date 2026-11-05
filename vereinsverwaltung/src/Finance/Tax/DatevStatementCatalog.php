<?php declare(strict_types=1);
namespace Kulturhus\Verein;
use DomainException;

/** Printed PDF facts. A bookkeeping group is NOT an approved tax/XML mapping. */
final class DatevStatementCatalog
{
    private static function label(string $value):string
    {
        // PDF typography only; digits, negations and tax wording are retained.
        $value=strtr($value,["\u{00AD}"=>'',"\u{2010}"=>'-',"\u{2011}"=>'-','ﬁ'=>'fi','ﬂ'=>'fl','ﬀ'=>'ff','ﬃ'=>'ffi','ﬄ'=>'ffl']);
        return mb_convert_case(trim((string)preg_replace('/\s+/u',' ',$value)),MB_CASE_FOLD,'UTF-8');
    }
    private static function canonical(mixed $value):mixed
    {
        if(!is_array($value))return $value;
        if(!array_is_list($value))ksort($value,SORT_STRING);
        foreach($value as &$item)$item=self::canonical($item);unset($item);
        return $value;
    }
    public static function positions(int $year):array
    {
        $catalogPath=dirname(__DIR__, 2).'/../data/skr42-'.$year.'.json';
        if(!is_file($catalogPath))return [];
        $raw=str_replace("\r\n","\n",file_get_contents($catalogPath));$catalog=json_decode($raw,true,64,JSON_THROW_ON_ERROR);
        if(($catalog['statement_schema']??0)===1){
            if(($catalog['edition']??'')!=='balance'||($catalog['chart']??'')!=='SKR42'||($catalog['year']??0)!==$year)
                throw new DomainException('Gedruckte Kontenzuordnung und Jahresquelle widersprechen sich.');
            return self::classify($catalog,array_column($catalog['accounts'],'statement','code'));
        }
        $path=dirname(__DIR__, 2).'/../resources/accounting/statements/'.$year.'.json';
        if(!is_file($path))return [];
        $data=json_decode(file_get_contents($path),true,64,JSON_THROW_ON_ERROR);
        $hash=$data['sha256']??'';unset($data['sha256']);
        $actual=hash('sha256',json_encode(self::canonical($data),JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRESERVE_ZERO_FRACTION));
        if(!is_file($catalogPath)||!is_string($hash)||!hash_equals($hash,$actual)||($data['year']??0)!==$year||($data['schema']??0)!==1||($data['edition']??'')!=='balance')
            throw new DomainException('Gedruckte Kontenzuordnung benötigt Prüfung.');
        if(!hash_equals($data['catalog_sha256'],hash('sha256',$raw))||!hash_equals($data['pdf_sha256'],$catalog['sha256']))
            throw new DomainException('Gedruckte Kontenzuordnung gehört zu einem anderen Kontenplan.');
        $positions=[];
        foreach($catalog['accounts'] as $row){
            $code=(string)$row['code'];$index=$data['accounts'][$code]??null;
            if(!is_int($index)||!isset($data['positions'][$index]))throw new DomainException('Gedruckte Kontenzuordnung unvollständig.');
            $positions[$code]=$data['positions'][$index]['statement'];
        }
        return self::classify($catalog,$positions);
    }
    /** Same pure classifier for legacy supplements and future PDF imports. */
    public static function classify(array $catalog,array $positions):array
    {
        $rules=json_decode(file_get_contents(dirname(__DIR__, 2).'/../resources/accounting/statement-groups.json'),true,64,JSON_THROW_ON_ERROR);$byLabel=[];
        foreach($rules['groups'] as $group=>$labels)foreach($labels as $label){
            $label=self::label($label);
            if(isset($byLabel[$label]))throw new DomainException('Abschlussposten ist mehreren Bilanzbereichen zugeordnet.');
            $byLabel[$label]=$group;
        }
        $out=[];
        foreach($catalog['accounts'] as $row){
            $code=(string)$row['code'];$position=$positions[$code]??null;$group=null;
            if(!preg_match('/^[0-9]{5}$/D',$code)||isset($out[$code])||!is_array($position)||!is_string($position['label']??null)||!is_array($position['marks']??null)
                ||!in_array($position['kind']??null,['balance','guv','statistical'],true)||!in_array($position['status']??null,['printed','not_printed'],true))
                throw new DomainException('Gedruckte Kontenzuordnung unvollständig.');
            if($position['status']==='printed'&&!isset($row['alternatives'])&&!isset($row['range_issue'])&&$row['function']!=='R'){
                $candidate=$byLabel[self::label($position['label'])]??null;
                if($candidate!==null&&str_contains($rules['class_guards'][$candidate],$code[0])
                    &&$position['kind']===(in_array($candidate,['revenue','expense'],true)?'guv':'balance'))$group=$candidate;
            }
            $out[$code]=['statement'=>$position['label'],'closing_marks'=>$position['marks'],'source_status'=>$position['status'],'group'=>$group];
        }
        return $out;
    }
}
