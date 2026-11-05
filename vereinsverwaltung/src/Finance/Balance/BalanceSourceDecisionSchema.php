<?php declare(strict_types=1);
namespace Kulturhus\Verein;
use DomainException;

/** Versioned original annual source decisions; existing history payloads remain unchanged. */
final class BalanceSourceDecisionSchema
{
    public static function bad():never{throw new DomainException('Ursprünglichen Vorgang der Belegübernahme vollständig prüfen.',422);}
    public static function identity(string $type,string $id):void
    {
        if(!in_array($type,['invoice','transaction','depreciation'],true)||$id===''||!mb_check_encoding($id,'UTF-8')||strlen($id)>160||preg_match('/[\x00-\x1f]/',$id))self::bad();
    }
    public static function hash(mixed $value):string{return hash('sha256',BalanceSourceHistory::json($value));}
    public static function input(array $input):array
    {
        $keys=['operation','request_key','year','version','before_sha256','source_type','source_id','options','expected_hash','reviewed','reason'];
        if(array_keys($input)!==$keys||!in_array($input['operation'],['import','correct'],true)||!is_string($input['request_key'])||!preg_match('/\A[a-f0-9]{32}\z/D',$input['request_key'])||!is_int($input['year'])||!is_int($input['version'])||$input['version']<1||$input['version']>=PHP_INT_MAX||!is_array($input['options'])||!is_bool($input['reviewed'])||!is_string($input['reason'])||!is_string($input['source_type'])||!is_string($input['source_id']))self::bad();
        BalanceCalculation::year($input['year']);self::identity($input['source_type'],$input['source_id']);
        foreach(['before_sha256','expected_hash']as$key)if(!is_string($input[$key])||!preg_match('/\A[a-f0-9]{64}\z/D',$input[$key]))self::bad();
        if($input['operation']==='import'&&$input['reason']!=='')self::bad();
        return $input;
    }
    public static function context(mixed $context):array
    {
        if(!is_array($context))self::bad();
        if(($context['format']??null)==='annual-source-reference-v1'){
            if(array_keys($context)!==['format','request_key','primary_type','primary_id','primary_revision','primary_sha256']||!is_string($context['request_key'])||!preg_match('/\A[a-f0-9]{32}\z/D',$context['request_key'])||!is_string($context['primary_type'])||!is_string($context['primary_id'])||!is_int($context['primary_revision'])||$context['primary_revision']<1||$context['primary_revision']>1000||!is_string($context['primary_sha256'])||!preg_match('/\A[a-f0-9]{64}\z/D',$context['primary_sha256']))self::bad();
            self::identity($context['primary_type'],$context['primary_id']);return $context;
        }
        if(array_keys($context)!==['format','request_key','request','actor','targets','result','source_label']||($context['format']??null)!=='annual-source-decision-v1'||!is_array($context['request'])||!is_string($context['actor'])||$context['actor']===''||!is_array($context['targets'])||!array_is_list($context['targets'])||!$context['targets']||count($context['targets'])>10000||!is_array($context['result'])||array_keys($context['result'])!==['version','annual_result_cents','asset_total_cents']||!is_array($context['source_label'])||array_keys($context['source_label'])!==['description','counterparty','account_name'])self::bad();
        $request=self::input($context['request']);if(!$request['reviewed']||$context['request_key']!==$request['request_key'])self::bad();
        foreach($context['result']as$value)if(!is_int($value))self::bad();if($context['result']['version']<=$request['version'])self::bad();
        foreach($context['source_label']as$value)if(!is_string($value))self::bad();$seen=[];
        foreach($context['targets']as$target){if(!is_array($target)||array_keys($target)!==['type','id','revision','label']||!is_string($target['type'])||!is_string($target['id'])||!is_int($target['revision'])||$target['revision']<1||$target['revision']>1000)self::bad();self::identity($target['type'],$target['id']);if(!is_array($target['label'])||array_keys($target['label'])!==['description','counterparty','account_name']||count(array_filter($target['label'],'is_string'))!==3)self::bad();$key=$target['type'].':'.$target['id'];if(isset($seen[$key]))self::bad();$seen[$key]=$target['label'];}
        if(!isset($seen[$request['source_type'].':'.$request['source_id']])||$seen[$request['source_type'].':'.$request['source_id']]!==$context['source_label'])self::bad();return $context;
    }
}
