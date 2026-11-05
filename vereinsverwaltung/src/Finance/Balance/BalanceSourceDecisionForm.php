<?php declare(strict_types=1);
namespace Kulturhus\Verein;
use DomainException;

/** Original annual source metadata, independent of competing view parameters. */
final class BalanceSourceDecisionForm
{
    public const ACTIONS=['balance-source-import','balance-source-correct'];
    private static function field(array $post,string $key):string{if(!is_string($post[$key]??null))BalanceSourceDecisionSchema::bad();return $post[$key];}
    public static function route(array $post):array{return ['view'=>'finance','tab'=>'tax','balance'=>'1','balance_year'=>is_string($post['balance_year']??null)?$post['balance_year']:''];}
    public static function read(array $post):array
    {
        $action=self::field($post,'action');if(!in_array($action,self::ACTIONS,true)||self::field($post,'annual_source_form_complete')!=='1')BalanceSourceDecisionSchema::bad();
        $year=self::field($post,'balance_year');$version=self::field($post,'version');if(!preg_match('/\A[0-9]{4}\z/D',$year)||!preg_match('/\A[1-9][0-9]{0,9}\z/D',$version)||(int)$version>4294967295)BalanceSourceDecisionSchema::bad();
        $type=self::field($post,'source_type');$id=self::field($post,'source_id');BalanceSourceDecisionSchema::identity($type,$id);
        foreach(['reviewed']as$key)if(array_key_exists($key,$post)&&(!is_string($post[$key])||$post[$key]!=='1'))BalanceSourceDecisionSchema::bad();
        if($type==='invoice'){self::field($post,'recognised_on');self::field($post,'private_paid_on');if(self::field($post,'purchase_form')!=='1')BalanceSourceDecisionSchema::bad();self::field($post,'classify_purchases');}
        elseif($type==='transaction')self::field($post,'donation_use');
        $hash=self::field($post,$action==='balance-source-correct'?'correction_hash':'source_hash');$reason=$action==='balance-source-correct'?self::field($post,'correction_reason'):'';
        return BalanceSourceDecisionSchema::input(['operation'=>$action==='balance-source-import'?'import':'correct','request_key'=>self::field($post,'source_request_key'),'year'=>(int)$year,'version'=>(int)$version,'before_sha256'=>self::field($post,'source_before_sha256'),'source_type'=>$type,'source_id'=>$id,'options'=>BalanceSources::formOptions($type,$post),'expected_hash'=>$hash,'reviewed'=>($post['reviewed']??'')==='1','reason'=>$reason]);
    }
}
