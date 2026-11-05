<?php declare(strict_types=1);
namespace Kulturhus\Verein;

use DomainException;
require_once __DIR__.'/FinancialReserveCalculation.php';
require_once __DIR__.'/FinancialReserveYear.php';

/** Read-only calendar review of a saved, unqualified source; never a tax value. */
final class FinancialReserveReview
{
    private const KEYS=['schema','status','year','journal_sha256','account','skr_code','original_taxpayer',
        'original','receiver_legal_status','transfer_kind','transfer_value','transferred_on',
        'transferred_reserve_cents','confirmations','documents','calculation','recorded_by','recorded_at',
        'activation_ready','native_xml_qualified'];
    private const FLAGS=['original_personal_scope_reviewed','whole_business_transfer_reviewed',
        'reserve_continuity_reviewed','no_deadline_restart_reviewed','no_earlier_reinvestment_reviewed'];
    private const DOCUMENTS=['original','transfer','continuity','election'];

    private static function need(bool $ok):void
    {
        if(!$ok)throw new DomainException('Fristen können mit diesen Angaben nicht geprüft werden.');
    }

    private static function keys(array $value,array $expected):void
    {
        $keys=array_keys($value);sort($keys);sort($expected);self::need($keys===$expected);
    }

    private static function normal(array $value):array
    {
        if(!array_is_list($value))ksort($value,SORT_STRING);
        foreach($value as $key=>$item)if(is_array($item))$value[$key]=self::normal($item);
        return $value;
    }

    public static function fromIntake(array $intake,?int $reviewYear=null):array
    {
        self::keys($intake,self::KEYS);
        self::need($intake['schema']===1&&$intake['status']==='pending_tax_review'
            &&$intake['activation_ready']===false&&$intake['native_xml_qualified']===false
            &&is_array($intake['original'])&&is_array($intake['calculation']));
        $source=$intake['original'];$proof=FinancialReserveCalculation::derive($source);
        self::need($intake['year']===$source['year']&&in_array($intake['skr_code'],['72500','72550'],true)
            &&is_string($intake['account'])&&$intake['account']!==''
            &&in_array($intake['receiver_legal_status'],['GMBH','gGMBH'],true)
            &&$intake['transfer_kind']==='whole_business_contribution_UmwStG20'&&$intake['transfer_value']==='book_value'
            &&$intake['transferred_reserve_cents']===$source['available_cents']);
        $on=$intake['transferred_on'];
        self::need(is_string($on)&&preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/D',$on)===1);
        $date=\DateTimeImmutable::createFromFormat('!Y-m-d',$on);
        self::need($date!==false&&$date->format('Y-m-d')===$on&&$source['sold_on']<=$on&&$on<=$source['acquired_on']);
        self::need(is_array($intake['confirmations'])&&is_array($intake['documents']));
        self::keys($intake['confirmations'],self::FLAGS);self::keys($intake['documents'],self::DOCUMENTS);
        foreach(self::FLAGS as $flag)self::need($intake['confirmations'][$flag]===true);
        $ids=[];
        foreach(self::DOCUMENTS as $purpose){
            $document=$intake['documents'][$purpose];self::need(is_array($document));
            self::keys($document,['document_id','revision','sha256']);
            self::need(is_string($document['document_id'])&&preg_match('/^[a-f0-9]{32}$/D',$document['document_id'])===1
                &&is_int($document['revision'])&&$document['revision']>=1
                &&is_string($document['sha256'])&&preg_match('/^[a-f0-9]{64}$/D',$document['sha256'])===1);
            $ids[]=$document['document_id'];
        }
        self::need(count(array_unique($ids))===4);
        $expected=$proof+['original_share_reinvestment_deadline'=>($source['sale_year']+2).'-12-31'];
        self::need(self::normal($intake['calculation'])===self::normal($expected));
        $calendar=FinancialReserveYear::calendar($source['sale_year'],$reviewYear??$source['year']);$rows=[];
        for($year=$source['sale_year'];$year<=$source['sale_year']+4;$year++)
            $rows[]=FinancialReserveYear::calendar($source['sale_year'],$year);
        if($calendar['year']>$source['sale_year']+4)$rows[]=$calendar;
        $purchaseScope=$source['acquired_on']>$on
            ?'excluded_corporate_purchase_after_transfer':'chronology_review_required';
        return ['schema'=>1,'contract'=>'financial-reserve-source-calendar@1',
            'source_sha256'=>hash('sha256',json_encode(self::normal($intake),JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)),
            // This intake describes a NEW replacement purchase at/after contribution.
            // It does not classify shares whose basis was already reduced beforehand.
            'intended_purchase_scope'=>$purchaseScope,
            'calendar'=>$calendar,'calendar_rows'=>$rows,'intended_reduction_cents'=>$source['reduction_cents'],
            'original_intended_basis_cents'=>$proof['tax_basis_cents'],
            'remaining_if_intended_use_cents'=>$proof['reserve_closing_cents'],
            // This is captured acquisition cost, not a confirmed closing tax basis.
            'purchase_cost_without_intended_reduction_cents'=>$purchaseScope==='excluded_corporate_purchase_after_transfer'?$source['cost_cents']:null,
            'excluded_intended_reduction_cents'=>$purchaseScope==='excluded_corporate_purchase_after_transfer'?$source['reduction_cents']:null,
            // An intake has no complete annual movement history or reviewed tax fraction.
            'year_end_balance_cents'=>null,'surcharge_cents'=>null,
            'corporate_transfer_qualified'=>false,'activation_ready'=>false,'native_xml_qualified'=>false];
    }
}
