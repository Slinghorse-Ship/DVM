<?php declare(strict_types=1);
namespace Kulturhus\Verein;
use DomainException;

/** Complete original source decision, independent of catalogue search metadata. */
final class BalancePeriodSourceForm
{
    public const ACTIONS=['balance-period-source-import','balance-period-source-correct'];
    public static function route(array $form):array
    {
        return ['view'=>'finance','tab'=>'tax','balance'=>'1','periods'=>'1','period_id'=>is_string($form['period_id']??null)?$form['period_id']:''];
    }
    private static function bad():never{throw new DomainException('Ursprüngliche Angaben zur Belegübernahme vollständig prüfen.',422);}
    private static function field(array $form,string $name):string
    {
        if(!is_string($form[$name]??null))self::bad();return $form[$name];
    }
    public static function read(array $form):array
    {
        $action=self::field($form,'action');if(!in_array($action,self::ACTIONS,true)||self::field($form,'period_source_form_complete')!=='1')self::bad();
        $id=self::field($form,'period_id');$version=self::field($form,'version');$journal=self::field($form,'journal_hash');$type=self::field($form,'source_type');$source=self::field($form,'source_id');$hash=self::field($form,'source_hash');
        if(!preg_match('/\A[0-9]{8}-[0-9]{8}\z/D',$id)||!preg_match('/\A[1-9][0-9]{0,4}\z/D',$version)||(int)$version>=20000||!preg_match('/\A[a-f0-9]{64}\z/D',$journal)||!preg_match('/\A[a-f0-9]{64}\z/D',$hash))self::bad();
        $period=['start'=>substr($id,0,4).'-'.substr($id,4,2).'-'.substr($id,6,2),'end'=>substr($id,9,4).'-'.substr($id,13,2).'-'.substr($id,15,2)];if(BalancePeriod::key(BalancePeriod::check($period))!==$id)self::bad();
        BalancePeriodSources::identity($type,$source);
        if(array_key_exists('reviewed',$form)&&(!is_string($form['reviewed'])||$form['reviewed']!=='1'))self::bad();
        if($type==='invoice'){self::field($form,'recognised_on');self::field($form,'private_paid_on');if(self::field($form,'purchase_form')!=='1')self::bad();self::field($form,'classify_purchases');}
        elseif($type==='transaction')self::field($form,'donation_use');
        else self::field($form,'asset_options');
        $previous=null;$reason='';
        if($action==='balance-period-source-correct'){
            $previous=self::field($form,'previous_source_hash');$reason=self::field($form,'reason');if(!preg_match('/\A[a-f0-9]{64}\z/D',$previous))self::bad();
        }
        return ['action'=>$action,'period_id'=>$id,'version'=>(int)$version,'journal_hash'=>$journal,'source_type'=>$type,'source_id'=>$source,'source_hash'=>$hash,'options'=>BalancePeriodSources::formOptions($type,$form),'reviewed'=>($form['reviewed']??'')==='1','previous_source_hash'=>$previous,'reason'=>$reason];
    }
}
