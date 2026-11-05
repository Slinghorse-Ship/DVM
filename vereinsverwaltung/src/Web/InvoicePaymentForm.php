<?php declare(strict_types=1);
namespace Kulturhus\Verein\Web;
/** Present the original native payment-report intent; never replace its versions or request key. */
final class InvoicePaymentForm
{
    public const ACTIONS=['invoice-payment-report','invoice-payment-withdraw','invoice-payment-allocate'];
    public static function prepare(array $entry,array $report,string $document,array $post,bool $canWrite,array $query):array
    {
        $action=$post['action']??null;$submitted=in_array($action,self::ACTIONS,true)&&($post['doc']??null)===$document&&($post['entry_id']??null)===$entry['id'];
        $out=['submitted'=>$submitted,'action'=>$submitted?$action:null,'raw'=>[],'first'=>null,'blocked'=>false,'report_allowed'=>$canWrite&&$entry['status']==='open'&&$entry['remaining_cents']>0&&!in_array($entry['payment_state'],['cancelled','reversed'],true),'withdraw_allowed'=>$canWrite&&($report['latest']['operation']??null)==='reported','report_draft'=>[],'withdraw_draft'=>[],'refresh'=>['view'=>'finance','tab'=>'inbox','inbox_doc'=>$document]+array_intersect_key($query,array_flip(['status','inbox_q','inbox_year','inbox_page']))];
        if(!$submitted)return$out;
        foreach(['doc','entry_id','entry_version','report_version','request_key','paid_on','note','reason','transaction_id','transaction_version','amount']as$key)if(array_key_exists($key,$post))$out['raw'][$key]=$post[$key];
        if($action==='invoice-payment-allocate'){$out['blocked']=true;return$out;}
        $key=$post['request_key']??null;
        if(is_string($key))foreach($report['history']as$event)if(is_string($event['request_key']??null)&&hash_equals($event['request_key'],$key)){$out['first']=$event;break;}
        $out['blocked']=($post['entry_version']??null)!==(string)$entry['version']||($post['report_version']??null)!==(string)$report['version']||!is_string($key)||!preg_match('/\A[a-f0-9]{32}\z/D',$key)||$out['first']!==null||!($action==='invoice-payment-report'?$out['report_allowed']:$out['withdraw_allowed']);
        if(!$out['blocked'])$out[$action==='invoice-payment-report'?'report_draft':'withdraw_draft']=$post;
        return$out;
    }
}
