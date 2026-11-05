<?php declare(strict_types=1);
namespace Kulturhus\Verein;
use DomainException;
require_once dirname(__DIR__, 2).'/Finance/Balance/BalancePeriod.php';

/** List hints only, never filing/source/receipt approval. Full evidence is checked on use. */
final class TaxFilingListMetadata
{
    public static function projection():string
    {
        // Keep original JSON in MySQL, not in a buffered PDO list of large histories.
        // CASE also handles damaged legacy JSON without invoking JSON_EXTRACT on it.
        return "CASE WHEN JSON_VALID(form_data) THEN JSON_OBJECT(
            'form_type',CONVERT(JSON_TYPE(form_data) USING utf8mb4),
            'binding_type',JSON_TYPE(JSON_EXTRACT(form_data,'$.balance_preparation')),
            'dated',JSON_CONTAINS_PATH(form_data,'one','$.balance_preparation.period','$.balance_preparation.period_id'),
            'period_type',JSON_TYPE(JSON_EXTRACT(form_data,'$.balance_preparation.period')),
            'period_size',JSON_LENGTH(JSON_EXTRACT(form_data,'$.balance_preparation.period')),
            'start',JSON_UNQUOTE(JSON_EXTRACT(form_data,'$.balance_preparation.period.start')),
            'end',JSON_UNQUOTE(JSON_EXTRACT(form_data,'$.balance_preparation.period.end')),
            'period_id_type',JSON_TYPE(JSON_EXTRACT(form_data,'$.balance_preparation.period_id')),
            'period_id',JSON_UNQUOTE(JSON_EXTRACT(form_data,'$.balance_preparation.period_id')),
            'kind_type',JSON_TYPE(JSON_EXTRACT(form_data,'$.balance_preparation.profile.kind')),
            'kind',JSON_UNQUOTE(JSON_EXTRACT(form_data,'$.balance_preparation.profile.kind'))
        ) ELSE NULL END AS period_metadata";
    }
    public static function read(?string $json,int $year):array
    {
        try{$m=$json===null?null:json_decode($json,true,16,JSON_THROW_ON_ERROR);}
        catch(\JsonException){throw new DomainException('Zeitraum prüfen.');}
        if(!is_array($m)||!in_array($m['form_type']??null,['OBJECT','ARRAY'],true)
            ||!in_array($m['binding_type']??null,[null,'NULL','OBJECT','ARRAY'],true)
            ||!in_array($m['dated']??null,[0,1,false,true],true)||!in_array($m['kind_type']??null,[null,'NULL','STRING'],true))
            throw new DomainException('Zeitraum prüfen.');
        $dated=$m['dated']===1||$m['dated']===true;
        if($dated){
            if(($m['period_type']??null)!=='OBJECT'||($m['period_size']??null)!==2||($m['period_id_type']??null)!=='STRING')throw new DomainException('Zeitraum prüfen.');
            $p=BalancePeriod::check(['start'=>$m['start']??null,'end'=>$m['end']??null],$year);
            if(BalancePeriod::key($p)!==($m['period_id']??null))throw new DomainException('Zeitraum prüfen.');
        }else $p=BalancePeriod::calendar($year);
        return ['dated'=>$dated,'period'=>$p,'kind'=>($m['kind_type']??null)==='STRING'?$m['kind']:null];
    }
}
