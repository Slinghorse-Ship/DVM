<?php declare(strict_types=1);
namespace Kulturhus\Verein;
use DomainException;
require_once __DIR__.'/AssetSale.php';
require_once __DIR__.'/AssetPartialDisposal.php';

/** Evidenced complete disposal or one cost-proven component scrapping. No guessed value loss. */
final class AssetDisposal
{
    public static function unpack(mixed $snapshot):array
    {
        if(!is_array($snapshot))throw new DomainException('Gespeicherten Abschreibungsstand prüfen.');
        if(!array_key_exists('plan',$snapshot))return [$snapshot,null];
        if(array_keys($snapshot)===['plan','substance']){require_once __DIR__.'/OriginalSubstance.php';OriginalSubstance::snapshot($snapshot);return [$snapshot['plan'],null];}
        if(array_keys($snapshot)===['plan','exceptional']){
            require_once __DIR__.'/OriginalExceptionalDepreciation.php';OriginalExceptionalDepreciation::snapshot($snapshot);return [$snapshot['plan'],null];
        }
        if(array_keys($snapshot)===['plan','own_book']){
            require_once __DIR__.'/OwnIntangiblePlans.php';OwnIntangiblePlans::snapshot($snapshot);return [$snapshot['plan'],null];
        }
        if(array_keys($snapshot)===['plan','partnership_book']){
            require_once __DIR__.'/PartnershipBookPlans.php';PartnershipBookPlans::snapshot($snapshot);return [$snapshot['plan'],null];
        }
        if(array_keys($snapshot)===['plan','valuation']&&is_array($snapshot['plan'])&&is_array($snapshot['valuation'])){
            require_once __DIR__.'/AssetValuation.php';$source=$snapshot['valuation']['source']??null;
            if(!is_array($source)||AssetValuation::fromBookPlan($snapshot['plan'])!==$source['plan']
                ||AssetValuation::calculate($source['plan'],$source['period'],$source['events'],$source['previous'])!==$snapshot['valuation'])throw new DomainException('Ursprüngliche Anlagenbewertung und bestätigter Originalplan stimmen nicht überein.');
            return [$snapshot['plan'],null];
        }
        if(array_keys($snapshot)!==['plan','disposal']||!is_array($snapshot['plan'])||!is_array($snapshot['disposal']))
            throw new DomainException('Gespeicherten Anlagenabgang prüfen.');
        return [$snapshot['plan'],$snapshot['disposal']];
    }
    public static function check(array $plan,mixed $proof):void
    {
        if(is_array($proof)&&($proof['kind']??null)==='partial_scrap'){
            AssetPartialDisposal::preview($plan,$proof,(int)substr($plan['acquired_on']??'',0,4));return;
        }
        if(!is_array($proof)||!in_array($proof['kind']??null,['scrap','sale','software_end'],true)||($proof['complete']??null)!==true
            ||!is_string($proof['date']??null)||!TaxCatalog::documentYear($proof['date'])||!is_string($plan['acquired_on']??null)||$proof['date']<$plan['acquired_on']
            ||!is_string($proof['reason']??null)||trim($proof['reason'])===''||mb_strlen($proof['reason'])>2000
            ||!is_string($proof['document_id']??null)||!preg_match('/^[a-f0-9]{32}$/D',$proof['document_id'])
            ||!is_int($proof['revision']??null)||$proof['revision']<1||!is_string($proof['sha256']??null)||!preg_match('/^[a-f0-9]{64}$/D',$proof['sha256']))
            throw new DomainException('Vollständigen Anlagenabgang, Datum und Nachweis prüfen. Schenkung, Teilabgang und Entschädigung gesondert behandeln.');
        if($proof['kind']==='software_end'){
            if(array_diff(array_keys($proof),['kind','date','reason','document_id','revision','sha256','no_proceeds','complete','rights_ended'])
                ||($proof['rights_ended']??null)!==true||($proof['no_proceeds']??null)!==true
                ||($plan['account']??null)!=='01350'||($plan['method']??'linear_monthly')!=='linear_monthly')
                throw new DomainException('Ende aller Rechte der gekauften Softwarelizenz ohne Erlös oder Entschädigung nachweisen. Bloße Nichtnutzung reicht nicht.');
            return;
        }
        if($proof['kind']==='sale')AssetSale::check($plan,$proof);
        elseif(array_diff(array_keys($proof),['kind','date','reason','document_id','revision','sha256','no_proceeds','complete'])||($proof['no_proceeds']??null)!==true)
            throw new DomainException('Ausmusterung ohne Erlös vollständig nachweisen.');
        if(!in_array($plan['account']??'',AssetTypes::codes('disposable'),true))throw new DomainException('Ausmusterung ist hier für bewegliche Ausstattung vorgesehen.');
    }
    public static function schedule(array $plan,int $year,?array $proof=null):array
    {
        $ordinary=AssetBooks::schedule($plan,$year);
        if($proof===null)return $ordinary;
        self::check($plan,$proof);$end=(int)substr($proof['date'],0,4);
        if($proof['kind']==='partial_scrap'){
            $v=AssetPartialDisposal::preview($plan,$proof,$year)['book'];
            return ['year'=>$year,'months'=>$ordinary['months'],
                'opening_cents'=>$v['book_opening_cents']+$v['additions_cents'],
                'depreciation_cents'=>$v['depreciation_cents'],'closing_cents'=>$v['book_closing_cents'],
                'disposal_cents'=>$v['book_disposals_cents']];
        }
        if($year<$end)return $ordinary;
        if($year>$end)return ['year'=>$year,'months'=>0,'opening_cents'=>0,'depreciation_cents'=>0,'closing_cents'=>0,'disposal_cents'=>0];
        $s=AssetBooks::schedule($plan,$year,(int)substr($proof['date'],5,2));
        $s['disposal_cents']=$s['closing_cents'];$s['closing_cents']=0;return $s;
    }
    /** Only this fully checked, zero-value asset event may lack monetary journal lines. */
    public static function nonPosting(array $proposal):bool
    {
        if(($proposal['type']??null)!=='depreciation'||!empty($proposal['issues'])||($proposal['vouchers']??null)!==[])return false;
        try{
            $row=$proposal['snapshot']['annual']??null;if(!is_array($row)||(int)($row['year']??0)!==($proposal['year']??null)
                ||($proposal['id']??null)!==($row['item_id']??'').':'.$proposal['year'])return false;
            $snapshot=json_decode($row['snapshot'],true,512,JSON_THROW_ON_ERROR);[$plan,$proof]=self::unpack($snapshot);
            if(isset($snapshot['own_book'])){
                $value=OwnIntangiblePlans::snapshot($snapshot);$h=$value['hgb'];
                return $value['book_source']['period']===BalancePeriod::calendar($proposal['year'])&&$value['events']===[]
                    &&(int)$row['opening_cents']===$h['book_opening_cents']&&(int)$row['closing_cents']===$h['book_closing_cents']&&(int)$row['depreciation_cents']===0;
            }
            if(isset($snapshot['partnership_book'])){
                $value=PartnershipBookPlans::snapshot($snapshot);if($value['source']['period']!==BalancePeriod::calendar($proposal['year']))return false;
                $book=$value['book'];
                return (int)$row['opening_cents']===$book['book_opening_cents']+$book['additions_cents']&&(int)$row['closing_cents']===$book['book_closing_cents']
                    &&(int)$row['depreciation_cents']===0&&$book['impairment_cents']===0&&$book['writeup_cents']===0&&$book['book_disposals_cents']===0;
            }
            if(isset($snapshot['valuation'])){
                $value=$snapshot['valuation'];if($value['source']['period']!==BalancePeriod::calendar($proposal['year']))return false;
                $book=$value['book'];
                return (int)$row['opening_cents']===$book['book_opening_cents']+$book['additions_cents']&&(int)$row['closing_cents']===$book['book_closing_cents']
                    &&(int)$row['depreciation_cents']===0&&$book['impairment_cents']===0&&$book['writeup_cents']===0&&$book['book_disposals_cents']===0;
            }
            if(!$proof||(int)substr($proof['date'],0,4)!==($proposal['year']??null))return false;
            $s=self::schedule($plan,$proposal['year'],$proof);
            foreach(['opening_cents','depreciation_cents','closing_cents'] as $key)if((int)$row[$key]!==$s[$key])return false;
            return $s['depreciation_cents']===0&&$s['disposal_cents']===0;
        }catch(\Throwable){return false;}
    }
}
