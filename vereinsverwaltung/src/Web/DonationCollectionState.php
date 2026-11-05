<?php declare(strict_types=1);
namespace Kulturhus\Verein\Web;

/** Posted decisions take precedence over navigation filters; no business writes. */
final class DonationCollectionState
{
    public static function read(array $post,array $query):array
    {
        $own=in_array($post['action']??null,['donation-collection-preview','donation-collection-issue'],true);
        $data=$own&&is_array($post['data']??null)?$post['data']:[];
        $scalar=static fn(array $input,string $key,string $fallback=''):string=>is_string($input[$key]??null)?$input[$key]:$fallback;
        $contact=$own?$scalar($data,'contact_id'):$scalar($query,'contact');
        $year=$own?$scalar($post,'collection_year',substr($scalar($data,'from'),0,4)):$scalar($query,'year',date('Y'));
        $validYear=preg_match('/^[0-9]{4}$/D',$year)===1&&(int)$year>=2000&&(int)$year<=(int)date('Y');
        $invalid=$own&&(!is_array($post['data']??null)||array_diff(array_keys($data),['contact_id','from','to','payment_ids','waiver_ids','reviewed'])!==[]);
        foreach(['contact_id','from','to','reviewed']as$key)if(isset($data[$key])&&!is_string($data[$key]))$invalid=true;
        $selected=[];
        foreach(['payment_ids','waiver_ids']as$key){$ids=$data[$key]??[];$valid=is_array($ids)&&array_is_list($ids)&&count($ids)<=100;foreach(is_array($ids)?$ids:[]as$id)if(!is_string($id))$valid=false;$invalid=$invalid||!$valid;$selected[$key]=$valid?$ids:[];}
        return ['data'=>$data,'contact'=>$contact,'year'=>$year,'valid_year'=>$validYear,'selected'=>$selected,'invalid_shape'=>$invalid,'own'=>$own,'raw'=>$own?json_encode($post['data']??null,JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE):''];
    }

    /** Keep unavailable, claimed and repeated choices until the user removes them. */
    public static function missing(array $selected,array $rows):array
    {
        $available=array_fill_keys(array_column($rows,'id'),1);$missing=[];
        foreach($selected as$id){if(($available[$id]??0)>0)--$available[$id];else$missing[]=$id;}
        return $missing;
    }
}
