<?php declare(strict_types=1);
namespace Kulturhus\Verein;
use DomainException;

/** Canonical client selection of actual dated originals; never authorizes a write. */
final class BalancePeriodAreaForm
{
    public static function source(array $post):array
    {
        foreach(['period_id','area_version','closed_hash','area_prior_period','area_prior_revision','area_prior_sha256','area_request_key','area_form_complete'] as $key)
            if(!is_string($post[$key]??null))throw new DomainException('Ursprüngliche Angaben zur Bereichsaufteilung vollständig prüfen.',422);
        if(!preg_match('/\A[0-9]{8}-[0-9]{8}\z/D',$post['period_id'])||!preg_match('/\A(?:0|[1-9][0-9]{0,2})\z/D',$post['area_version'])||!preg_match('/\A[a-f0-9]{64}\z/D',$post['closed_hash'])||!preg_match('/\A[a-f0-9]{32}\z/D',$post['area_request_key'])||$post['area_form_complete']!=='1')throw new DomainException('Ursprüngliche Angaben zur Bereichsaufteilung vollständig prüfen.',422);
        $prior=null;$parts=[$post['area_prior_period'],$post['area_prior_revision'],$post['area_prior_sha256']];
        if($parts!==['','','']){
            if(!preg_match('/\A(?:[0-9]{8}-[0-9]{8}|calendar:[0-9]{4}:(?:segments|clearing))\z/D',$parts[0])||!preg_match('/\A(?:[1-9][0-9]{0,2}|1000)\z/D',$parts[1])||!preg_match('/\A[a-f0-9]{64}\z/D',$parts[2]))throw new DomainException('Ursprünglichen Bereichsvortrag vollständig prüfen.',422);
            $prior=['period_id'=>$parts[0],'revision'=>(int)$parts[1],'sha256'=>$parts[2]];
        }
        return ['period_id'=>$post['period_id'],'version'=>(int)$post['area_version'],'closed_hash'=>$post['closed_hash'],'prior_source'=>$prior,'request_key'=>$post['area_request_key']];
    }
    public static function cents(string $value):int
    {
        if(!preg_match('/\A-?(?:0|[1-9][0-9]*)(?:[,.][0-9]{1,2})?\z/D',$value))throw new DomainException('Betrag in Euro mit höchstens zwei Nachkommastellen ohne Tausendertrennzeichen angeben.',422);
        return Finance::cents($value);
    }
    /** Selectable saved advances while other allocations are still incomplete.
     * These are form choices, never a calculated balance or an approval.
     */
    public static function advanceChoices(array $state):array
    {
        $input=$state['input'];if($input===null)return [];$units=$input['units'];$entries=array_column($state['source']['entries'],null,'id');
        $choices=[];
        foreach($state['prior']['proof']['clearing']['cases']??[]as$id=>$case)
            if($case['closing_cents']>0&&isset($units[$case['from']],$units[$case['to']]))$choices[$id]=['from'=>$case['from'],'to'=>$case['to'],'closing_cents'=>$case['closing_cents'],'pending'=>true];
        foreach($input['instructions']['advances']as$id=>$row){
            if(!is_string($id)||!preg_match('/\A[a-f0-9]{32}\z/D',$id)||!is_array($row)||!is_string($row['from']??null)||!is_string($row['to']??null)||!isset($units[$row['from']],$units[$row['to']])||$row['from']===$row['to']||!is_string($row['voucher']??null)||!isset($entries[$row['voucher']])||!is_int($row['cents']??null)||$row['cents']<=0||$row['cents']>BalanceCalculation::LIMIT||isset($choices[$id]))continue;
            $choices[$id]=['from'=>$row['from'],'to'=>$row['to'],'closing_cents'=>$row['cents'],'pending'=>true];
        }
        foreach($input['instructions']['repayments']as$row){
            if(!is_array($row)||!is_string($row['advance']??null)||!isset($choices[$row['advance']])||!is_string($row['voucher']??null)||!isset($entries[$row['voucher']])||!is_int($row['cents']??null)||$row['cents']<=0)continue;
            $choices[$row['advance']]['closing_cents']-=$row['cents'];
        }
        foreach($state['proof']['clearing']['cases']??[]as$id=>$case)
            $choices[$id]=['from'=>$case['from'],'to'=>$case['to'],'closing_cents'=>$case['closing_cents'],'pending'=>false];
        return array_filter($choices,static fn(array $row):bool=>$row['closing_cents']>0);
    }

}
