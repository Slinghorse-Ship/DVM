<?php declare(strict_types=1);
namespace Kulturhus\Verein\Web;

/** Whole HTTP read intent; never infer another asset or coerce a requested year. */
final class AssetBookSelection
{
    public const ACTIONS=['asset-book-save','asset-book-year','asset-partial-prepare','asset-partial-book'];
    public static function scalar(mixed $value):bool{return is_string($value)&&preg_match('//u',$value)===1&&!preg_match('/[\x00-\x1f\x7f]/',$value);}
    public static function year(string $value):bool{return preg_match('/\A(?:19[0-9]{2}|20[0-9]{2}|21[0-9]{2}|2200)\z/D',$value)===1;}
    public static function context(string $actor,array $query,array $post,string $method,string $defaultYear):array{return ['actor'=>$actor,'method'=>$method,'default_year'=>$defaultYear,'query'=>array_intersect_key($query,array_flip(['item','year','partial_review'])),'post'=>array_diff_key($post,['csrf'=>true])];}
    public static function read(array $query,array $post,string $method,string $defaultYear):array
    {
        $posted=$method==='POST';$action=$post['action']??null;$related=$posted&&in_array($action,self::ACTIONS,true);$annual=$related&&in_array($action,['asset-book-year','asset-partial-prepare'],true);
        $source=$related?$post:$query;$item=array_key_exists('item',$source)?$source['item']:'';
        $year=array_key_exists('year',$source)?$source['year']:($annual?'':($related&&array_key_exists('year',$query)?$query['year']:$defaultYear));
        $review=array_key_exists('partial_review',$source)?$source['partial_review']:($related&&array_key_exists('partial_review',$query)?$query['partial_review']:'');
        $unsupported=[];$fields=[];foreach(['item'=>$item,'year'=>$year,'partial_review'=>$review]as$key=>$value){if(self::scalar($value))$fields[$key]=$value;else{$fields[$key]='';$unsupported[($related?'post.':'query.').$key]=$value;}}
        if($related)foreach(['item','year','partial_review']as$key)if(array_key_exists($key,$query)&&!self::scalar($query[$key]))$unsupported['query.'.$key]=$query[$key];
        $state='requested';
        if(!in_array($method,['GET','HEAD','POST'],true)||($posted&&!$related))$state='invalid-action';
        elseif($unsupported!==[])$state='unsupported';
        elseif($related&&$item==='')$state='invalid-item';
        elseif(!self::year($fields['year']))$state='invalid-year';
        elseif($related&&array_filter(['item','year','partial_review'],static fn(string$key):bool=>array_key_exists($key,$query)&&$query[$key]!==$fields[$key]))$state='conflict';
        elseif(!$related&&$item==='')$state='picker';
        if($related&&$action==='asset-partial-book'&&$fields['partial_review']==='')$state='invalid-review';
        $raw=['query'=>array_intersect_key($query,array_flip(['item','year','partial_review']))];if($posted)$raw['post']=array_diff_key($post,['csrf'=>true]);
        return ['state'=>$state,'fields'=>$fields,'item'=>$fields['item'],'year'=>self::year($fields['year'])?(int)$fields['year']:null,'year_text'=>$fields['year'],'review'=>$fields['partial_review'],'unsupported'=>$unsupported,'raw'=>$raw,'source'=>$related?'post':'query','action'=>$related?$action:null,'plan'=>null,'items'=>[],'original_status'=>200,'message'=>'','open_review'=>null];
    }
    public static function sameReview(array $state,array $record):bool{return is_string($record['item_id']??null)&&is_int($record['year']??null)&&$state['item']===$record['item_id']&&$state['year_text']===(string)$record['year'];}
}
