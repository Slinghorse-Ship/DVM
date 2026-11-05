<?php declare(strict_types=1);
namespace Kulturhus\Verein;
require_once dirname(__DIR__, 2).'/Finance/Balance/BalanceSourceRead.php';
require_once __DIR__.'/FinancialInvestment.php';
require_once __DIR__.'/AssetOriginalDepreciation.php';
use DomainException;

/** One capability model for annual, dated, shared, disposal and tax asset paths.
 * Account numbers come from reviewed exact-year mappings, never number prefixes.
 * An asset category does not prove an individual tax election or useful life.
 */
final class AssetTypes
{
    public static function accounts():array
    {
        return BalanceSourceRead::once('asset_types',static fn()=>self::readAccounts());
    }
    private static function readAccounts():array
    {
        $raw=file_get_contents(dirname(__DIR__, 2).'/../resources/ebilanz/asset-types.json');
        $rules=file_get_contents(dirname(__DIR__, 2).'/../resources/ebilanz/skr42-6.9.json');
        if($raw===false||$rules===false)throw new DomainException('Geprüfte Anlagenarten fehlen.');
        $costRaw=file_get_contents(dirname(__DIR__, 2).'/../resources/accounting/reviews/cost-assets.json');
        if($costRaw===false)throw new DomainException('Originalquellen der Kostenanlagen fehlen.');
        $review=json_decode($costRaw,true,64,JSON_THROW_ON_ERROR);$catalogs=[];
        foreach($review['years']??[] as $source){
            $year=$source['source']['year'];$catalogs[$year]=file_get_contents(dirname(__DIR__, 2).'/../data/skr42-'.$year.'.json');
            if($catalogs[$year]===false||!hash_equals($source['source']['catalog_sha256'],hash('sha256',str_replace("\r\n","\n",$catalogs[$year]))))
                throw new DomainException('Originaler Jahreskontenplan der Kostenanlage wurde verändert.');
        }
        $poolBytes=file_get_contents(dirname(__DIR__, 2).'/../resources/accounting/reviews/pool-original-member-r73.json');if($poolBytes===false)throw new DomainException('Originalquellen der Sammelposten-Gegenstände fehlen.');
        static $cache=[];$key=hash('sha256',$raw."\0".$rules."\0".$costRaw."\0".$poolBytes);
        if(isset($cache[$key]))return $cache[$key];
        $data=json_decode($raw,true,64,JSON_THROW_ON_ERROR);$registry=json_decode($rules,true,64,JSON_THROW_ON_ERROR);
        if(($data['schema']??null)!==1)throw new DomainException('Anlagenarten prüfen.');
        $targets=[];
        foreach($data['types'] as $type)foreach($type['targets'] as $target){
            if(isset($targets[$target])||!in_array($type['kind'],['software','intangible','movable','gwg','land','financial','construction','intangible_advance','building','goodwill','immovable_fixture'],true))throw new DomainException('Widersprüchliche Anlagenarten.');
            $targets[$target]=['kind'=>$type['kind'],'code'=>$type['depreciation_code'],'target'=>$target];
        }
        $out=[];
        foreach($registry['rules'] as $rule){
            FinancialInvestment::pensionRule($rule);
            if($rule['group']!=='fixed_assets'||!in_array($rule['condition']??null,[null,'detail_choice','cost_schedule'],true))continue;
            $choices=$rule['allowed_targets']??[$rule['target']];
            if(!$choices||array_diff($choices,array_keys($targets)))continue;
            $definitions=$targets;
            if(isset($rule['asset_type'])){
                $source=$data['source_types'][$rule['asset_type']]??null;
                if($rule['asset_type']!=='immovable_fixture'||!is_array($source)||array_keys($source)!==['kind','depreciation_code','depreciation_target','targets']
                    ||$source['kind']!=='immovable_fixture'||$source['depreciation_code']!=='62200'||$source['depreciation_target']!=='is.netIncome.regular.operatingTC.deprAmort.fixAss.tan.other'
                    ||$source['targets']!==['bs.ass.fixAss.tan.landBuildings.buildingsOnOwnLand.buildings','bs.ass.fixAss.tan.landBuildings.buildingsOnNonOwnedLand']
                    ||array_diff($choices,$source['targets']))throw new DomainException('Quellgebundene unbewegliche Anlage vollständig prüfen.');
                foreach($source['targets'] as $target)$definitions[$target]=['kind'=>$source['kind'],'code'=>$source['depreciation_code'],'target'=>$target];
            }
            $type=$definitions[$rule['target']];$kinds=[];
            foreach($choices as $target){
                if($definitions[$target]['code']!==$type['code'])throw new DomainException('Sammelkonto benötigt eine einheitliche Abschreibungsart.');
                $kinds[$definitions[$target]['kind']]=true;
            }
            if(count($kinds)>1){
                if(array_diff(array_keys($kinds),['software','intangible']))throw new DomainException('Sammelkonto verbindet unterschiedliche Anlagenarten.');
                $type['kind']='intangible';
            }
            foreach($rule['codes'] as $code){
                if(isset($out[$code])&&$out[$code]!==$type)throw new DomainException('Anlagenart hat zwischen Jahresständen gewechselt. Ursprüngliche Zuordnung gesondert prüfen.');
                $out[$code]=$type;
            }
        }
        if(($review['schema']??null)!==1||($review['scope']??null)!=='book_cost_asset_lifecycle'||($review['production_authorized']??null)!==false)
            throw new DomainException('Quellprüfung der Kostenanlagen wurde verändert.');
        foreach($review['years'] as $source){
            foreach($source['assets'] as $asset){
                $type=$targets[$asset['target']]??null;$code=$asset['code'];
                if(!$type||!in_array($type['kind'],['land','financial'],true)||($type['code']??null)!==null
                    ||(isset($out[$code])&&$out[$code]!==$type))throw new DomainException('Quellzuordnung der Kostenanlage ist widersprüchlich.');
                $out[$code]=$type;
            }
        }
        $poolRaw=file_get_contents(dirname(__DIR__, 2).'/../resources/accounting/reviews/pool-original-member-r73.json');
        if($poolRaw===false)throw new DomainException('Originalquellen der Sammelposten-Gegenstände fehlen.');
        $pool=json_decode($poolRaw,true,64,JSON_THROW_ON_ERROR);
        if(($pool['schema']??null)!==1||($pool['scope']??null)!=='original_individual_pool_member'||($pool['production_authorized']??null)!==false||($pool['account']??null)!=='06750'||($pool['depreciation_code']??null)!=='62640'||($pool['target']??null)!=='bs.ass.fixAss.tan.otherEquipm.gwgsammelposten'||($pool['kind']??null)!=='pool_member'||!is_string($pool['source_stage_proof_sha256']??null)||!preg_match('/^[a-f0-9]{64}$/D',$pool['source_stage_proof_sha256'])||!is_array($pool['years']??null)||count($pool['years'])!==2)throw new DomainException('Originale Sammelposten-Fähigkeit vollständig prüfen.');
        $poolYears=[];foreach($pool['years'] as $source){$year=$source['year']??null;if(!in_array($year,[2025,2026],true)||isset($poolYears[$year])||!isset($catalogs[$year])||!is_string($source['catalog_sha256']??null)||!hash_equals($source['catalog_sha256'],hash('sha256',str_replace("\r\n","\n",$catalogs[$year]))))throw new DomainException('Originaler Jahreskontenplan des Sammelpostens wurde verändert.');$poolYears[$year]=true;}
        if(isset($out['06750']))throw new DomainException('Sammelposten darf keine gewöhnliche Anlage überschreiben.');
        $out['06750']=['kind'=>'pool_member','code'=>'62640','target'=>'bs.ass.fixAss.tan.otherEquipm.gwgsammelposten'];
        foreach($registry['rules'] as $rule)if(isset($rule['substance_original_contract'])){require_once __DIR__.'/OriginalSubstance.php';OriginalSubstance::rule($rule);$out['02250']=['kind'=>'substance','code'=>'62200','target'=>OriginalSubstance::TARGET];}
        ksort($out,SORT_STRING);if(count($cache)>=4)$cache=[];$cache[$key]=$out;return $out;
    }
    public static function costTarget(array $plan):string
    {
        FinancialInvestment::check($plan);
        $code=$plan['account']??'';$type=self::accounts()[$code]??null;$choices=[];
        $registry=json_decode(file_get_contents(dirname(__DIR__, 2).'/../resources/ebilanz/skr42-6.9.json'),true,64,JSON_THROW_ON_ERROR);
        foreach($registry['rules'] as $rule)if($rule['group']==='fixed_assets'&&in_array($code,$rule['codes'],true)){
            $found=$rule['allowed_targets']??[$rule['target']];
            if($choices&&$choices!==$found)throw new DomainException('Anlagenposition unterscheidet sich zwischen bestätigten Jahresständen.');$choices=$found;
        }
        if(!$choices&&$type)$choices=[$type['target']];
        $target=array_key_exists('target',$plan)?$plan['target']:(count($choices)===1?$choices[0]:null);
        if(!$type||!in_array($type['kind'],['land','financial','construction','intangible_advance'],true)||$type['kind']!==($plan['kind']??$plan['valuation_kind']??null)||!is_string($target)||!in_array($target,$choices,true))throw new DomainException('Anlagen im Bau: ursprüngliche Anlagenart und genaue Bilanzposition im Anschaffungsplan bestätigen.');
        return $target;
    }
    public static function codes(string $scope):array
    {
        $kinds=match($scope){'linear'=>['software','intangible','movable','building','goodwill','immovable_fixture','pool_member'],'ordinary_equipment'=>['movable','immovable_fixture'],'amortizable_intangible'=>['software','intangible'],'disposable'=>['movable','gwg'],'all'=>['software','intangible','movable','gwg','land','financial','construction','intangible_advance','building','goodwill','immovable_fixture','pool_member','substance'],
            'cost'=>['land','financial','construction','intangible_advance'],'software','intangible','movable','gwg','land','financial','construction','intangible_advance','building','goodwill','immovable_fixture','pool_member'=>[$scope],default=>throw new DomainException('Unbekannte Anlagenart.')};
        return array_map('strval',array_keys(array_filter(self::accounts(),static fn($a)=>in_array($a['kind'],$kinds,true))));
    }
    /** Legacy condition name remains immutable; supported acquired rights share 62000. */
    public static function mappingLabel(array $rule):string
    {
        return ($rule['condition']??null)==='software_schedule'?'Abschreibung erworbener Rechte und Software':$rule['label'];
    }
    public static function depreciationCode(string $code,bool $allowGwg=false):?string
    {
        $type=self::accounts()[$code]??null;
        return $type&&($allowGwg||$type['kind']!=='gwg')?$type['code']:null;
    }
}
