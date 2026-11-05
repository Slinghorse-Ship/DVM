<?php declare(strict_types=1);
namespace Kulturhus\Verein\Web;
use DomainException;
use Kulturhus\Verein\Finance;

/** Retain rejected source facts; only a newly opened form receives new defaults. */
final class GoodsDonationState
{
    public static function form(array $post,string $today):array
    {
        $own=($post['action']??null)==='create-goods';
        $defaults=['donor'=>'','designation'=>'','description'=>'','received_on'=>$today,'valuation'=>'','origin'=>'unknown','evidence_present'=>'0','request_key'=>$own?'':bin2hex(random_bytes(16))];
        $values=[];$unsupported=[];
        foreach($defaults as$key=>$default){
            if(!$own){$values[$key]=$default;continue;}
            if(!array_key_exists($key,$post)){$values[$key]=$key==='evidence_present'?'0':'';continue;}
            if(!is_string($post[$key])){$unsupported[$key]=$post[$key];$values[$key]='';continue;}
            $values[$key]=$post[$key];
        }
        $positionFields=['position_description','position_amount','position_sphere','position_account','position_tax','position_booking_key','position_input_tax'];$rawPositions=[];
        foreach($positionFields as$field)if($own&&array_key_exists($field,$post))$rawPositions[$field]=$post[$field];
        $positions=[];$positionError='';
        if($rawPositions){try{$positions=Finance::positionsFromForm($post);}catch(DomainException $failure){$positionError=$failure->getMessage();}}
        $date=$values['received_on'];$dateValid=preg_match('/\A([0-9]{4})-([0-9]{2})-([0-9]{2})\z/D',$date,$parts)===1&&checkdate((int)$parts[2],(int)$parts[3],(int)$parts[1])&&($today===''||$date<=$today);
        return ['submitted'=>$own,'values'=>$values,'unsupported'=>$unsupported,'origin_valid'=>in_array($values['origin'],['private','business','unknown'],true),'evidence_valid'=>in_array($values['evidence_present'],['0','1'],true),'date_valid'=>$dateValid,'raw_positions'=>$rawPositions,'positions'=>$positions,'position_error'=>$positionError];
    }

    public static function validate(array $post):void
    {
        $state=self::form($post,'');
        if(!$state['submitted']||$state['unsupported'])throw new DomainException('Ursprüngliche Sachspendeneingaben prüfen.',422);
        if($state['position_error']!=='')throw new DomainException($state['position_error'],422);
        if(!preg_match('/\A[a-f0-9]{32}\z/D',$state['values']['request_key']))throw new DomainException('Ursprüngliche Vorgangskennung prüfen. Neue Sachspende ausdrücklich öffnen.',422);
        if(!$state['origin_valid'])throw new DomainException('Ursprung der Zuwendung prüfen.',422);
        if(!$state['evidence_valid'])throw new DomainException('Unterlagenvermerk ausdrücklich prüfen.',422);
    }

    public static function catalogue(Finance $finance,array $query):array
    {
        $raw=[];$unsupported=[];
        foreach(['goods_q'=>'','goods_origin'=>'','goods_evidence'=>'','goods_page'=>'1','goods_sort'=>'recent','goods_columns'=>'summary']as$key=>$default){
            if(!array_key_exists($key,$query)){$raw[$key]=$default;continue;}
            if(!is_string($query[$key])){$unsupported[$key]=$query[$key];$raw[$key]='';continue;}
            $raw[$key]=$query[$key];
        }
        try{
            if($unsupported)throw new DomainException('Sachspendensuche und Seite prüfen.',422);
            return $finance->goodsPage($raw['goods_page'],$raw['goods_q'],$raw['goods_origin'],$raw['goods_evidence'],$raw['goods_sort'],$raw['goods_columns'])+['available'=>true,'error'=>'','unsupported'=>[],'requested_page'=>$raw['goods_page']];
        }catch(DomainException $failure){
            return ['sort'=>$raw['goods_sort'],'columns'=>$raw['goods_columns'],'positions'=>[],'available'=>false,'error'=>$failure->getMessage(),'unsupported'=>$unsupported,'requested_page'=>$raw['goods_page'],'rows'=>[],'total'=>0,'page'=>1,'pages'=>1,'search'=>$raw['goods_q'],'origin'=>$raw['goods_origin'],'evidence'=>$raw['goods_evidence']];
        }
    }
}
