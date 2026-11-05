<?php declare(strict_types=1);
namespace Kulturhus\Verein;
require_once __DIR__.'/BalancePartnershipHoldings.php';
require_once __DIR__.'/BalanceOwnIntangibleMapping.php';
require_once __DIR__.'/BalancePoolOriginalValues.php';
use DomainException;
require_once dirname(__DIR__, 2).'/Finance/Tax/DatevStatementCatalog.php';
require_once dirname(__DIR__, 2).'/Finance/Assets/AssetTypes.php';
require_once __DIR__.'/BalanceCostAssetMapping.php';
require_once dirname(__DIR__, 2).'/Finance/Accounting/FinancialReserveXml.php';

/** Exact-year, deliberately bounded SKR42 rules. No fuzzy labels or class-digit guesses. */
final class BalanceTaxMapping
{
    /** Known account meanings are not interchangeable; ambiguous rules list their exact choices. */
    public static function choices(int $year,array $account,array $targets):array
    {
        if((TaxCatalog::accounts($year)[$account['skr_code']??'']['comparison_only']??false)===true)return [];
        $choices=$targets[$account['group']]??[];
        if(in_array($account['skr_code']??'',FinancialReserveXml::CODES,true))return FinancialReserveXml::choices($account,$choices);
        $registry=json_decode(file_get_contents(dirname(__DIR__, 2).'/../resources/ebilanz/skr42-6.9.json'),true,64,JSON_THROW_ON_ERROR);
        foreach($registry['rules'] as $rule)if((!isset($rule['years'])||in_array($year,$rule['years'],true))&&in_array((string)($account['skr_code']??''),$rule['codes'],true)){
            if($rule['group']!==$account['group']||in_array($rule['condition']??'',['partnership_original','own_intangible_original','pool_original'],true))return [];
            $choices=array_intersect_key($choices,array_fill_keys($rule['allowed_targets']??[$rule['target']],true));
            if(($rule['condition']??'')==='balance_direction'&&isset($account['amount_cents'])&&is_int($account['amount_cents'])){
                $target=$account['amount_cents']<0?$rule['negative_target']:$rule['target'];
                $choices=array_intersect_key($choices,[$target=>true]);
            }
            if(isset($rule['commercial_income_direction'])&&isset($account['amount_cents'])&&is_int($account['amount_cents'])&&$account['amount_cents']!==0){$target=$rule['commercial_income_direction'][$account['amount_cents']>0?'debit_target':'credit_target'];$choices=array_intersect_key($choices,[$target=>true]);}
            if(isset($rule['directional_balance'])&&(isset($account['closing_cents'])||isset($account['amount_cents']))){require_once __DIR__.'/BalanceDirectionalClosing.php';$choices=array_intersect_key($choices,array_fill_keys(BalanceDirectionalClosing::choices($year,$account),true));}
            foreach($rule['target_labels']??[] as $target=>$label)if(isset($choices[$target]))$choices[$target]=$label;
            if(($rule['condition']??'')==='reserve_purpose'){
                $short=['bs.eqLiab.equity.revenueRes.charityAppropriated.project'=>'Vorhaben / Projekt',
                    'bs.eqLiab.equity.revenueRes.charityAppropriated.workingCapital'=>'Laufende Ausgaben',
                    'bs.eqLiab.equity.revenueRes.unappropriated.assetManagement'=>'Vermögensverwaltung',
                    'bs.eqLiab.equity.revenueRes.unappropriated.otherMeansDueCourse'=>'Andere Mittel'];
                foreach($choices as $key=>&$label)$label=$short[$key]??$label;unset($label);
            }
            return $choices;
        }
        // Generic free-use accounts retain their ordinary side. Opposite-side
        // choices belong only to the exact original contracts handled above.
        $prefix=in_array($account['group'],['revenue','expense'],true)?'is.':(in_array($account['group'],['cash','receivables','inventory','prepaid','other_assets','fixed_assets'],true)?'bs.ass.':'bs.eqLiab.');
        $choices=array_filter($choices,static fn($label,$target)=>str_starts_with($target,$prefix),ARRAY_FILTER_USE_BOTH);
        unset($choices[BalanceOwnIntangibleMapping::TARGET],$choices[BalanceOwnIntangibleMapping::COUNTERS['48250']]);
        unset($choices[BalancePoolOriginalValues::TARGET],$choices[BalancePoolOriginalValues::EXPENSE]);
        if(in_array($account['skr_code']??'',['06750','62640'],true))return [];
        unset($choices['bs.eqLiab.liab.bank']); // The native sum field needs a reviewed child.
        return BalanceCostAssetMapping::choices($account,$choices);
    }
    /** Known ordinary sales codes only. A tax rate is never inferred for a free-use account. */
    public static function revenueRates(int $year):array
    {
        self::preview($year,[]); // Pin the same exact-year catalog as export.
        $rules=json_decode(file_get_contents(dirname(__DIR__, 2).'/../resources/ebilanz/skr42-6.9.json'),true,64,JSON_THROW_ON_ERROR);
        $prefix='is.netIncome.regular.operatingTC.grossTradingProfit.totalOutput.netSales.grossSales.';$out=[];
        foreach($rules['rules'] as $rule){
            if($rule['group']!=='revenue'||isset($rule['condition'])||(isset($rule['years'])&&!in_array($year,$rule['years'],true)))continue;
            $rate=match($rule['target']){$prefix.'generalRateVAT'=>19,$prefix.'reducedRateVAT'=>7,$prefix.'taxExemptUStG19_1'=>0,default=>null};
            if($rate!==null)foreach($rule['codes'] as $code)$out[$code]=$rate;
        }
        return $out;
    }
    /** A printed statement group is not itself an approved XML rule. */
    public static function chartChoices(int $year):array
    {
        self::preview($year,[]); // Includes exact-year catalog/hash and blocked-code checks.
        $registry=json_decode(file_get_contents(dirname(__DIR__, 2).'/../resources/ebilanz/skr42-6.9.json'),true,64,JSON_THROW_ON_ERROR);
        $catalog=TaxCatalog::accounts($year);$out=[];$positions=DatevStatementCatalog::positions($year);
        foreach($catalog as $code=>$account){
            if($account['blocked']||TaxCatalog::reserved((string)$code,$year)||$account['category']==='carry')continue;
            $out[$code]=['code'=>(string)$code,'name'=>$account['name'],'group'=>$positions[$code]['group']??null,'review_required'=>true,
                'statement'=>$positions[$code]['statement']??'','xml_rule'=>false];
        }
        foreach($registry['rules'] as $rule)if(!isset($rule['years'])||in_array($year,$rule['years'],true))foreach($rule['codes'] as $code){
            $out[$code]['group']=$rule['group'];$out[$code]['review_required']=isset($rule['condition']);
            $out[$code]['xml_rule']=true;
        }
        ksort($out,SORT_STRING);return $out;
    }
    /** Catalog coverage is separate from the actual journal's reviewed choices. */
    public static function coverage(int $year):array
    {
        $choices=self::chartChoices($year);$automatic=[];$conditional=[];$missing=[];
        foreach($choices as $code=>$account){
            $row=['code'=>(string)$code,'name'=>$account['name']];
            if(!$account['xml_rule'])$missing[]=$row+['statement_group'=>$account['group']];
            elseif($account['review_required'])$conditional[]=$row;
            else $automatic[]=$row;
        }
        $registry=json_decode(file_get_contents(dirname(__DIR__, 2).'/../resources/ebilanz/skr42-6.9.json'),true,64,JSON_THROW_ON_ERROR);
        return ['source'=>$registry['sources'][(string)$year],'bookable'=>count($choices),
            'automatic'=>count($automatic),'conditional'=>count($conditional),'missing'=>count($missing),
            'conditional_accounts'=>$conditional,'missing_accounts'=>$missing];
    }
    public static function checkChart(int $year,array $accounts):void
    {
        $numbered=array_filter($accounts,static fn($a)=>($a['skr_code']??'')!=='');
        if(!$numbered)return;
        $choices=self::chartChoices($year);
        foreach($numbered as $account)if(!isset($choices[$account['skr_code']])||($choices[$account['skr_code']]['group']!==null&&$choices[$account['skr_code']]['group']!==$account['group']))
            throw new DomainException('SKR42-Konto und Bilanzbereich benötigen eine geprüfte Jahreszuordnung.');
    }
    public static function preview(int $year,array $accounts,?array $schedule=null,?array $sourceValues=null):array
    {
        $schedulePeriod=null;
        if(isset($schedule['period'])){require_once __DIR__.'/BalancePeriod.php';$schedulePeriod=BalancePeriod::check($schedule['period'],$year);}
        $rules=json_decode(file_get_contents(dirname(__DIR__, 2).'/../resources/ebilanz/skr42-6.9.json'),true,64,JSON_THROW_ON_ERROR);
        $source=$rules['sources'][(string)$year]??null;
        if(!$source||!TaxCatalog::hasYear($year))throw new DomainException('Für dieses Jahr fehlt eine geprüfte Kontenzuordnung.');
        $file=dirname(__DIR__, 2).'/../data/skr42-'.$year.'.json';
        $catalogHash=hash('sha256',str_replace("\r\n","\n",file_get_contents($file)));
        if(!hash_equals($source['catalog_sha256'],$catalogHash)||!hash_equals($source['pdf_sha256'],TaxCatalog::sourceStatus($year)['sha256']))throw new DomainException('Der Kontenplan wurde geändert. Jahreszuordnung erneut prüfen.');
        $catalog=TaxCatalog::accounts($year);
        // A request may render hundreds of account rows. Validate the complete
        // registry once per exact content, not once per row. Hash checks above
        // still run on every call; balances and resolved choices are never cached.
        static $verified=[];
        $cacheKey=$year.':'.$catalogHash.':'.hash('sha256',json_encode($rules,JSON_THROW_ON_ERROR));
        if(!isset($verified[$cacheKey])){
            $byCode=[];
            foreach($rules['rules'] as $rule)if(!isset($rule['years'])||in_array($year,$rule['years'],true))foreach($rule['codes'] as $code){
                if(isset($byCode[$code])||!isset($catalog[$code])||$catalog[$code]['blocked']||TaxCatalog::reserved($code,$year))throw new DomainException('Kontenzuordnung und Jahreskatalog widersprechen sich.');
                if(($rule['condition']??null)==='pool_original'||isset($rule['pool_original_contract']))BalancePoolOriginalValues::rule($rule);
                FinancialInvestment::pensionRule($rule);
                if(isset($rule['pension_offset_contract'])){require_once dirname(__DIR__, 2).'/Finance/Assets/OriginalPensionOffsets.php';OriginalPensionOffsets::rule($rule);}
                if(isset($rule['substance_original_contract'])){require_once dirname(__DIR__, 2).'/Finance/Assets/OriginalSubstance.php';OriginalSubstance::rule($rule);}
                if(isset($rule['tax_equalization_contract'])){require_once dirname(__DIR__, 2).'/Finance/Assets/OriginalTaxEqualization.php';OriginalTaxEqualization::rule($rule);}
                if(isset($rule['basis_reduction_6b_contract'])){require_once dirname(__DIR__, 2).'/Finance/Assets/OriginalBasisReduction6b.php';OriginalBasisReduction6b::rule($rule);}
                if(isset($rule['basis_reduction_7g_contract'])){require_once dirname(__DIR__, 2).'/Finance/Assets/OriginalBasisReduction7g.php';OriginalBasisReduction7g::rule($rule);}
                if(isset($rule['special_tax_7b_contract'])){require_once dirname(__DIR__, 2).'/Finance/Assets/OriginalSpecialTax7b.php';OriginalSpecialTax7b::rule($rule);}
                if(isset($rule['special_tax_7g_contract'])){require_once dirname(__DIR__, 2).'/Finance/Assets/OriginalSpecialTax7g.php';OriginalSpecialTax7g::rule($rule);}
                if(isset($rule['exceptional_original_contract'])){require_once dirname(__DIR__, 2).'/Finance/Assets/OriginalExceptionalDepreciation.php';OriginalExceptionalDepreciation::rule($rule);}
                $byCode[$code]=$rule;
            }
            if(count($verified)>=4)array_shift($verified);
            $verified[$cacheKey]=$byCode;
        }
        $byCode=$verified[$cacheKey];$costMapping=BalanceCostAssetMapping::mapping($year,$accounts,$schedule);
        $out=['year'=>$year,'taxonomy'=>'6.9','revision'=>$rules['revision'],'catalog_sha256'=>$source['catalog_sha256'],
            'mapping'=>[],'rows'=>[],'review_count'=>0,'transmission'=>'unavailable'];
        foreach($accounts as $id=>$account){
            $code=(string)($account['skr_code']??'');$rule=$byCode[$code]??null;
            $message='Zuordnung durch die Buchhaltung prüfen.';
            $ok=$rule&&$account['group']===$rule['group'];
            if($ok&&in_array($rule['condition']??'',['reserve_purpose','trade_detail','detail_choice'],true)){
                $ok=false;$message=$rule['review_message'];
            }elseif($ok&&($rule['condition']??'')==='balance_direction'){
                $ok=isset($account['amount_cents'])&&is_int($account['amount_cents']);
                if($ok&&$account['amount_cents']<0)$rule['target']=$rule['negative_target'];
                $message='Zuerst den Kontensaldo aus den Buchungen ermitteln.';
            }elseif($ok&&in_array($rule['condition']??'',['own_intangible_original','pool_original'],true)){
                $ok=false;$message=$rule['review_message']; // Complete original source proof below is the only resolver.
            }elseif($ok&&($rule['condition']??'')==='partnership_original'){
                $ok=false;$message=$rule['review_message']; // Source-aware verified holding mapping below is the only resolver.
            }elseif($ok&&($rule['condition']??'')==='cost_schedule'){
                $ok=false;$message=$rule['review_message']; // Verified costMapping below is the only resolver.
            }elseif($ok&&($rule['condition']??'')==='vat_clearing_zero'){
                // Never infer tax settlement/netting from unrelated account balances.
                $ok=isset($account['amount_cents'])&&$account['amount_cents']===0;
                $message='Umsatzsteuerabschluss prüfen: Vorsteuer und Umsatzsteuer auf die verbleibende Forderung oder Verbindlichkeit abstimmen.';
            }elseif($ok&&in_array($rule['condition']??'',['equipment_disposal','equipment_sale','software_disposal'],true)){
                $ok=$schedule&&empty($schedule['issues'])&&($schedulePeriod!==null||($schedule['year']??null)===$year);$sum=0;$found=false;
                foreach(($schedule['items']??[]) as $item){$amount=($item['method']??$item['plan']['method']??null)==='cost'?BalanceCostAssetMapping::movement($item,$code):($schedulePeriod!==null?self::periodMovement($item,$code,$schedulePeriod):AssetSale::movement($item,$code,$year));if($amount!==null){$found=true;$sum+=$amount;}}
                $peers=array_filter($accounts,static fn($a)=>(string)($a['skr_code']??'')===$code);
                $ok=$ok&&$found&&count(array_filter($peers,static fn($a)=>isset($a['amount_cents'])))===count($peers)&&array_sum(array_column($peers,'amount_cents'))===$sum;
                $message='Erlös und Restbuchwert mit den vollständigen Anlagenabgängen abstimmen.';
            }elseif($ok&&isset($rule['condition'])){
                // These depreciation codes cover more than the implemented asset types.
                $allowed=AssetTypes::codes(match($rule['condition']){'software_schedule'=>'amortizable_intangible','equipment_schedule'=>'ordinary_equipment','building_schedule'=>'building','goodwill_schedule'=>'goodwill','gwg_schedule'=>'gwg','equipment_disposal'=>'disposable',default=>throw new DomainException('Unbekannte Zuordnungsregel.')});$sum=0;$found=false;
                $ok=$schedule&&empty($schedule['issues'])&&($schedulePeriod!==null||($schedule['year']??null)===$year);
                $field=$rule['condition']==='equipment_disposal'?'book_disposals_cents':'depreciation_cents';
                foreach(($schedule['items']??[]) as $item)if(in_array($schedulePeriod!==null?$item['plan']['account']:$item['skr_code'],$allowed,true)&&AssetOriginalDepreciation::code($schedulePeriod!==null?$item['plan']:$item)===$code){$found=true;$sum+=$schedulePeriod!==null?($item['schedule'][$field]??0):($item[$field]??0);}
                $peers=array_filter($accounts,static fn($a)=>(string)($a['skr_code']??'')===$code);
                $ok=$ok&&$found&&count($peers)===1&&isset($account['amount_cents'])&&$sum===$account['amount_cents'];
                $message='Abschreibung mit den zugehörigen Gegenständen abstimmen.';
            }
            if($code===''||!isset($catalog[$code]))$message='SKR42-Konto ergänzen oder individuelle Zuordnung prüfen.';
            elseif($catalog[$code]['blocked']||TaxCatalog::reserved($code,$year)){$ok=false;$message='Dieses SKR42-Konto ist nicht für Buchungen freigegeben.';}
            elseif($rule&&$account['group']!==$rule['group'])$message='Konto und Bilanzbereich stimmen nicht überein.';
            if(isset($costMapping[$id])){$ok=true;$rule=['target'=>$costMapping[$id],'label'=>BalanceCostAssetMapping::targets()[$account['group']][$costMapping[$id]]];}
            if($ok)$out['mapping'][$id]=$rule['target'];else ++$out['review_count'];
            $out['rows'][$id]=['name'=>$account['name'],'skr_code'=>$code,'status'=>$ok?'assigned':'review',
                'choice_required'=>in_array($rule['condition']??'',['reserve_purpose','trade_detail','detail_choice'],true),
                'target'=>$ok?$rule['target']:'','label'=>$ok?AssetTypes::mappingLabel($rule):'','message'=>$ok?'Automatisch zugeordnet.':$message];
        }
        if($sourceValues!==null&&!empty($sourceValues['pool_original_sources'])){
            if($sourceValues['year']!==$year||$sourceValues['tax']['accounts']!==$accounts)throw new DomainException('Sammelpostenzuordnung mit genau den geprüften Steuerwerten anzeigen.');
            foreach(BalancePoolOriginalValues::mapping($sourceValues) as $id=>$target){
                if(($out['rows'][$id]['status']??null)==='review')--$out['review_count'];
                $out['mapping'][$id]=$target;$out['rows'][$id]=array_replace($out['rows'][$id],['status'=>'assigned','target'=>$target,'label'=>$target===BalancePoolOriginalValues::TARGET?'Sammelposten':'Auflösung Sammelposten','message'=>'Mit ursprünglicher Jahreswahl, Einzelregistern und vollständigen Originaljournalen abgestimmt.']);
            }
        }
        if($sourceValues!==null&&!empty($sourceValues['partnership_holdings'])){
            if($sourceValues['year']!==$year||$sourceValues['tax']['accounts']!==$accounts)throw new DomainException('Originale Beteiligungszuordnung mit genau den geprüften Steuerwerten anzeigen.');
            foreach(BalancePartnershipHoldings::mapping($sourceValues) as $id=>$target){
                if(($out['rows'][$id]['status']??null)==='review')--$out['review_count'];
                $out['mapping'][$id]=$target;$out['rows'][$id]=array_replace($out['rows'][$id],['status'=>'assigned','target'=>$target,'label'=>'Geprüfter steuerlicher Originalansatz der Personengesellschaft','message'=>'Originalkapital, HGB-Register, Journal und steuerlicher Anlagenspiegel vollständig abgeglichen.','source'=>'original_partnership','choice_required'=>false]);
            }
        }
        if($sourceValues!==null&&(!empty($sourceValues['own_hgb_sources'])||!empty($sourceValues['own_intangible_sources']))){
            if($sourceValues['year']!==$year||$sourceValues['tax']['accounts']!==$accounts)throw new DomainException('Originale Entwicklungszuordnung mit genau den geprüften Jahreswerten anzeigen.');
            $hgb=!empty($sourceValues['own_hgb_sources']);
            foreach(BalanceOwnIntangibleMapping::mapping($sourceValues) as $id=>$target){
                if(($out['rows'][$id]['status']??null)==='review')--$out['review_count'];
                $out['mapping'][$id]=$target;$out['rows'][$id]=array_replace($out['rows'][$id],['status'=>'assigned','target'=>$target,'label'=>BalanceOwnIntangibleMapping::targets()[$accounts[$id]['group']][$target],'message'=>$hgb?'Originale HGB-Herstellungskosten, Jahresregister und sämtliche Journalbewegungen abgeglichen.':'Originaler HGB-Ansatz und belegte steuerliche Nichtaktivierung vollständig abgeglichen; der HGB-Betrag wird nicht als Steuerwert ausgegeben.','source'=>$hgb?'original_own_hgb':'original_own_tax','choice_required'=>false]);
            }
        }
        return $out;
    }
    /** Original dated movements, never a second annual valuation or prior-period sale. */
    private static function periodMovement(array $item,string $code,array $period):?int
    {
        $d=$item['disposal']??null;
        if($d===null||!BalancePeriod::contains($period,$d['date']))return null;
        $book=$item['schedule']['book_disposals_cents'];
        if(in_array($d['kind'],['scrap','partial_scrap'],true))return $code==='68950'?$book:null;
        if($d['kind']==='software_end')return $code==='68960'?$book:null;
        if($d['kind']!=='sale')return null;
        require_once __DIR__.'/BalancePeriodSale.php';$v=BalancePeriodSale::values($d,$book);
        if(($item['sale_values']??null)!==$v)throw new DomainException('Anlagenverkauf mit dem ursprünglichen Zeitraumnachweis abstimmen.');
        if($code===$v['sale_code'])return $v['gain']?$v['net_cents']:-$v['net_cents'];
        if($code===$v['book_code'])return $v['gain']?-$v['book_cents']:$v['book_cents'];
        if(in_array($code,[...AssetSale::saleCodes($d['rate'],$d['vat_kind']??'domestic'),'48550','68950'],true))return 0;
        return null;
    }
}
