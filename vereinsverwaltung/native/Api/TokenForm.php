<?php declare(strict_types=1);
namespace Kulturhus\Native\Api;

/** Safe original form values; credentials and CSRF are never retained or displayed. */
final class TokenForm
{
    public const FIELDS=['action','request_id','label','days','scopes','token_id'];
    public static function capture(array $post):array
    {
        $fields=[];
        foreach(self::FIELDS as $key){
            $present=array_key_exists($key,$post);
            $fields[$key]=['present'=>$present,'value'=>$present?$post[$key]:null];
        }
        $allowed=match($post['action']??null){
            'api-token-create'=>['action','csrf','request_id','label','days','password','scopes'],
            'api-token-confirm'=>['action','csrf','request_id','factor'],
            'api-token-revoke'=>['action','csrf','token_id'],
            default=>['csrf','password','factor'],
        };
        return ['original'=>true,'fields'=>$fields,'extra_names'=>array_values(array_diff(array_keys($post),$allowed))];
    }
    public static function value(array $draft,string $key):mixed
    {
        return $draft['fields'][$key]['value']??null;
    }
    public static function editable(array $draft,array $allowed,bool $retryAllowed):bool
    {
        if(!$retryAllowed||!($draft['original']??false)||($draft['extra_names']??[])!==[])return false;
        if(self::value($draft,'action')!=='api-token-create')return false;
        $request=self::value($draft,'request_id');$label=self::value($draft,'label');$days=self::value($draft,'days');
        if(!is_string($request)||!preg_match('/\A[a-f0-9]{32}\z/D',$request))return false;
        if(!is_string($label)||!preg_match('//u',$label)||preg_match('/[\x00-\x1f\x7f]/',$label))return false;
        if(!is_string($days)||!in_array($days,['7','30','90'],true))return false;
        if($draft['fields']['token_id']['present']??false)return false;
        $scopes=($draft['fields']['scopes']['present']??false)?self::value($draft,'scopes'):[];
        if(!is_array($scopes)||!array_is_list($scopes)||count($scopes)>count(Scopes::ALL))return false;
        foreach($scopes as $scope)if(!is_string($scope)||!in_array($scope,$allowed,true))return false;
        return count(array_unique($scopes))===count($scopes);
    }
}
