<?php declare(strict_types=1);
namespace Kulturhus\Verein;
use DomainException;
/** Retained corporate investment classification, independent of tax deductibility. */
final class FinancialInvestment
{
    public const BOOK_TARGETS_R69=['silent_part'=>'bs.ass.fixAss.fin.particip.silent','silent_loan'=>'bs.ass.fixAss.fin.otherLoans.silent'];
    public const PENSION_ASSETS=['13800'=>['pension_reinsurance_unoffset'=>'bs.ass.fixAss.fin.otherFinAss.reInsurClaim'],'13820'=>['pension_reinsurance_unoffset'=>'bs.ass.fixAss.fin.otherFinAss.reInsurClaim']];
    public const CODES=['13800','13820','08000','08090','08200','08300','08040','08080','08500','09800','09000','09100','08140','08850','08100','08800','09600','09610','09700'];
    public const SECURITIES=['09000'=>['corp_domestic'=>'bs.ass.fixAss.fin.securities.shares','corp_foreign'=>'bs.ass.fixAss.fin.securities.shares','cert_corp_de'=>'bs.ass.fixAss.fin.securities.partCertificate','cert_corp_ab'=>'bs.ass.fixAss.fin.securities.partCertificate','bond_de'=>'bs.ass.fixAss.fin.securities.bonds','bond_ab'=>'bs.ass.fixAss.fin.securities.bonds','convert_de'=>'bs.ass.fixAss.fin.securities.convertBond','convert_ab'=>'bs.ass.fixAss.fin.securities.convertBond','fund_de'=>'bs.ass.fixAss.fin.securities.mutInvestm','fund_ab'=>'bs.ass.fixAss.fin.securities.mutInvestm','cert_de'=>'bs.ass.fixAss.fin.securities.partCertificate','cert_ab'=>'bs.ass.fixAss.fin.securities.partCertificate','warrant_de'=>'bs.ass.fixAss.fin.securities.warrants','warrant_ab'=>'bs.ass.fixAss.fin.securities.warrants','other_de'=>'bs.ass.fixAss.fin.securities.other','other_ab'=>'bs.ass.fixAss.fin.securities.other'],'09100'=>['cert_corp_de'=>'bs.ass.fixAss.fin.securities.partCertificate','cert_corp_ab'=>'bs.ass.fixAss.fin.securities.partCertificate','corp_domestic'=>'bs.ass.fixAss.fin.securities.shares','corp_foreign'=>'bs.ass.fixAss.fin.securities.shares']];
    public const CORPORATE_PROFILES=['corp_domestic','corp_foreign','cert_corp_de','cert_corp_ab'];
    public const LOANS=['08140'=>['loan_de_le25'=>'bs.ass.fixAss.fin.loansToAffil.corporations','loan_de_gt25'=>'bs.ass.fixAss.fin.loansToAffil.corporations','loan_ab_le25'=>'bs.ass.fixAss.fin.loansToAffil.corporations','loan_ab_gt25'=>'bs.ass.fixAss.fin.loansToAffil.corporations'],'08850'=>['loan_de_le25'=>'bs.ass.fixAss.fin.loansToParticip.corporations','loan_de_gt25'=>'bs.ass.fixAss.fin.loansToParticip.corporations','loan_ab_le25'=>'bs.ass.fixAss.fin.loansToParticip.corporations','loan_ab_gt25'=>'bs.ass.fixAss.fin.loansToParticip.corporations'],'08100'=>['loan_de_le25'=>'bs.ass.fixAss.fin.loansToAffil.corporations','loan_de_gt25'=>'bs.ass.fixAss.fin.loansToAffil.corporations','loan_ab_le25'=>'bs.ass.fixAss.fin.loansToAffil.corporations','loan_ab_gt25'=>'bs.ass.fixAss.fin.loansToAffil.corporations','loan_pg'=>'bs.ass.fixAss.fin.loansToAffil.partnerships.other','loan_sole'=>'bs.ass.fixAss.fin.loansToAffil.soleProprietor'],'08800'=>['loan_de_le25'=>'bs.ass.fixAss.fin.loansToParticip.corporations','loan_de_gt25'=>'bs.ass.fixAss.fin.loansToParticip.corporations','loan_ab_le25'=>'bs.ass.fixAss.fin.loansToParticip.corporations','loan_ab_gt25'=>'bs.ass.fixAss.fin.loansToParticip.corporations','loan_pg'=>'bs.ass.fixAss.fin.loansToParticip.partnerships.other','loan_sole'=>'bs.ass.fixAss.fin.loansToParticip.soleProprietor'],'09600'=>['loan_de_le25'=>'bs.ass.fixAss.fin.loansToSharehold.misc','loan_de_gt25'=>'bs.ass.fixAss.fin.loansToSharehold.misc','loan_ab_le25'=>'bs.ass.fixAss.fin.loansToSharehold.misc','loan_ab_gt25'=>'bs.ass.fixAss.fin.loansToSharehold.misc','loan_pg'=>'bs.ass.fixAss.fin.loansToSharehold.misc','loan_sole'=>'bs.ass.fixAss.fin.loansToSharehold.misc','loan_person_de'=>'bs.ass.fixAss.fin.loansToSharehold.misc','loan_person_ab'=>'bs.ass.fixAss.fin.loansToSharehold.misc'],'09610'=>['loan_de_le25'=>'bs.ass.fixAss.fin.loansToSharehold.gmbh','loan_de_gt25'=>'bs.ass.fixAss.fin.loansToSharehold.gmbh','loan_ab_le25'=>'bs.ass.fixAss.fin.loansToSharehold.gmbh','loan_ab_gt25'=>'bs.ass.fixAss.fin.loansToSharehold.gmbh','loan_pg'=>'bs.ass.fixAss.fin.loansToSharehold.gmbh','loan_sole'=>'bs.ass.fixAss.fin.loansToSharehold.gmbh','loan_person_de'=>'bs.ass.fixAss.fin.loansToSharehold.gmbh','loan_person_ab'=>'bs.ass.fixAss.fin.loansToSharehold.gmbh'],'09700'=>['loan_de_le25'=>'bs.ass.fixAss.fin.otherLoans.other','loan_de_gt25'=>'bs.ass.fixAss.fin.otherLoans.other','loan_ab_le25'=>'bs.ass.fixAss.fin.otherLoans.other','loan_ab_gt25'=>'bs.ass.fixAss.fin.otherLoans.other','loan_pg'=>'bs.ass.fixAss.fin.otherLoans.other','loan_sole'=>'bs.ass.fixAss.fin.otherLoans.other','loan_person_de'=>'bs.ass.fixAss.fin.otherLoans.other','loan_person_ab'=>'bs.ass.fixAss.fin.otherLoans.other']];
    public const LOAN_PROFILES=['loan_de_le25','loan_de_gt25','loan_ab_le25','loan_ab_gt25','loan_pg','loan_sole','loan_person_de','loan_person_ab'];
    public static function allProfiles():array{return array_merge(array_keys(self::SECURITIES['09000']),self::LOAN_PROFILES,array_keys(self::BOOK_TARGETS_R69),['pension_reinsurance_unoffset']);}
    public static function profiles(string $code):array{return isset(self::PENSION_ASSETS[$code])?array_keys(self::PENSION_ASSETS[$code]):($code==='08300'?array_keys(self::BOOK_TARGETS_R69):(isset(self::LOANS[$code])?array_keys(self::LOANS[$code]):(isset(self::SECURITIES[$code])?array_keys(self::SECURITIES[$code]):self::PROFILES)));}
    public const PROFILES=['corp_domestic','corp_foreign'];
    public static function target(string $code,mixed $profile):string
    {
        if(!in_array($code,self::CODES,true)||!is_string($profile)||!in_array($profile,self::profiles($code),true))throw new DomainException('Ursprüngliche Beteiligungs- oder Wertpapierart und Sitz des Emittenten anhand des Anschaffungsbelegs bestätigen.');
        if(isset(self::PENSION_ASSETS[$code]))return self::PENSION_ASSETS[$code][$profile];
        if($code==='08300')return 'bs.ass.fixAss.fin.otherLoans.other';
        if(isset(self::LOANS[$code]))return self::LOANS[$code][$profile];
        if(isset(self::SECURITIES[$code]))return self::SECURITIES[$code][$profile];
        $affiliated=in_array($code,['08000','08090','08040','08080'],true);
        return 'bs.ass.fixAss.fin.'.($affiliated?'sharesInAffil.corporations':'particip.corporations');
    }
    public static function pensionRule(array $rule):void
    {
        $codes=$rule['codes']??null;
        if(!is_array($codes)||!array_intersect($codes,array_keys(self::PENSION_ASSETS)))return;
        $code=$codes[0]??'';$target='bs.ass.fixAss.fin.otherFinAss.reInsurClaim';
        if(count($codes)!==1||!isset(self::PENSION_ASSETS[$code])||($rule['group']??null)!=='fixed_assets'||($rule['condition']??null)!=='cost_schedule'||($rule['target']??null)!==$target||($rule['allowed_targets']??null)!==[$target]||($rule['source_years']??null)!==[2025,2026]||($rule['financial_profile_contract']??null)!==$code.'@1'||($rule['pension_asset_contract']??null)!=='original-unnetted-reinsurance-r74-'.$code.'@1'||!empty($rule['disclosures'])||array_intersect_key($rule,array_flip(['book_only','commercial_contract','own_intangible_contract','financial_book_targets','negative_target'])))throw new DomainException('Ursprünglichen Rückdeckungsanspruch ohne Saldierung und jahresgebundene Quellenregel prüfen.');
    }
    public static function check(array $plan):void
    {
        $code=$plan['account']??'';
        if(!in_array($code,self::CODES,true)){
            if(array_key_exists('financial_profile',$plan))throw new DomainException('Beteiligungsprofil gehört nicht zur bestätigten ursprünglichen Anlagenart.');
            return;
        }
        $target=self::target($code,$plan['financial_profile']??null);
        if(($plan['kind']??$plan['valuation_kind']??null)!=='financial'||($plan['target']??null)!==$target)throw new DomainException('Anlagenart, bestätigter Emittentensitz und ursprüngliche Bilanzposition stimmen nicht überein.');
    }
    public static function key(string $code,?string $profile):string
    {return 'src_asset_valuation_'.$code.($profile===null?'':'_'.($profile==='pension_reinsurance_unoffset'?'rein_unoffset':$profile));}
    public static function counterTarget(string $code,string $profile):string
    {
        if(!in_array($profile,self::allProfiles(),true))throw new DomainException('Ursprüngliches Beteiligungsprofil prüfen.');
        if(in_array($profile,['loan_pg','loan_sole','loan_person_de','loan_person_ab'],true))return match($code){'49120'=>'is.netIncome.regular.operatingTC.otherOpRevenue.revalFixAss.fin.other','68970'=>'is.netIncome.regular.operatingTC.otherCost.disposFixAss.bookValue.fin.other','72000','72010','72070'=>'is.netIncome.regular.fin.netParticipation.amortFinanc.financialsExcept.other',default=>throw new DomainException('Gegenkonto gehört nicht zum ursprünglichen Darlehen an eine Personengesellschaft oder ein Einzelunternehmen.')};
        if(in_array($profile,self::LOAN_PROFILES,true)){
            $high=str_ends_with($profile,'gt25');$place=str_starts_with($profile,'loan_de_')?'domestic':'foreign';
            if($code===($high?'49130':'49120'))return 'is.netIncome.regular.operatingTC.otherOpRevenue.revalFixAss.fin.'.$place.'Loan.'.($high?'relatedParty':'unrelatedParty');
            if($code==='68970')return 'is.netIncome.regular.operatingTC.otherCost.disposFixAss.bookValue.fin.other';
            if($code!==($high?'72040':'72000')&&$code!=='72010'&&$code!=='72070')throw new DomainException('Darlehensart, Beteiligungsumfang und ursprüngliches Gegenkonto stimmen nicht überein.');
            return 'is.netIncome.regular.fin.netParticipation.amortFinanc.financialsExcept.'.($high?'receivCorp25pt.'.$place:'other');
        }
        if(in_array($profile,['fund_de','fund_ab'],true))return match($code){'49120'=>'is.netIncome.regular.operatingTC.otherOpRevenue.revalFixAss.fin.investmentShares','68970'=>'is.netIncome.regular.operatingTC.otherCost.disposFixAss.bookValue.fin.investmentShares','72000'=>'is.netIncome.regular.fin.netParticipation.amortFinanc.financialsExcept.investementShares','72010'=>'is.netIncome.regular.fin.netParticipation.amortFinanc.financialsExcept.investementShares',default=>throw new DomainException('Gegenkonto gehört nicht zum ursprünglichen Investmentfonds.')};
        if(!in_array($profile,self::CORPORATE_PROFILES,true))return match($code){'49120'=>'is.netIncome.regular.operatingTC.otherOpRevenue.revalFixAss.fin.other','68970'=>'is.netIncome.regular.operatingTC.otherCost.disposFixAss.bookValue.fin.other','72000','72010','72070'=>'is.netIncome.regular.fin.netParticipation.amortFinanc.financialsExcept.other',default=>throw new DomainException('Gegenkonto gehört nicht zur ursprünglichen Wertpapierart.')};
        $suffix=in_array($profile,['corp_domestic','cert_corp_de'],true)?'domesticCorp':'foreignCorp';
        return match($code){
            '49140'=>'is.netIncome.regular.operatingTC.otherOpRevenue.revalFixAss.fin.'.$suffix,
            '68980'=>'is.netIncome.regular.operatingTC.otherCost.disposFixAss.bookValue.fin.'.$suffix,
            '72040','72010','72070'=>'is.netIncome.regular.fin.netParticipation.amortFinanc.financialsExcept.participCorp.'.$suffix,
            default=>throw new DomainException('Bewertungsgegenkonto gehört nicht zur bestätigten Beteiligung.'),
        };
    }
    public static function counterCode(string $event,bool $permanent,string $profile,?string $original=null,?string $account=null):string
    {
        if(!in_array($profile,self::allProfiles(),true)||!in_array($event,['writeup','disposal','impairment'],true))throw new DomainException('Ursprüngliche Darlehens- oder Wertpapierbewertung prüfen.');
        if($original!==null){if($original!=='72070'||$event!=='impairment'||!in_array($account,['08040','08080','08100','08140'],true)||!in_array($profile,self::profiles($account),true))throw new DomainException('72070 benötigt die belegte Wertminderung einer ursprünglichen Finanzanlage an einem verbundenen Unternehmen.');return $original;}
        $high=in_array($profile,self::LOAN_PROFILES,true)&&str_ends_with($profile,'gt25');$corp=in_array($profile,self::CORPORATE_PROFILES,true);
        return match($event){'writeup'=>$high?'49130':($corp?'49140':'49120'),'disposal'=>$corp?'68980':'68970',default=>!$permanent?'72010':($high||$corp?'72040':'72000')};
    }

}
