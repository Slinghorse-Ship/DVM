<?php declare(strict_types=1);
namespace Kulturhus\Verein;
use DomainException;

/** Original scalar form metadata; never silently replace a failed source. */
final class BalancePeriodJournalForm
{
    public const ACTIONS=['balance-period-create','balance-period-opening','balance-period-account','balance-period-post','balance-period-reverse','balance-period-close'];
    private static function bad():never{throw new DomainException('Ursprüngliche Angaben zum Abschlusszeitraum vollständig prüfen.',422);}
    private static function field(array$p,string$key):string{if(!is_string($p[$key]??null))self::bad();return$p[$key];}
    public static function read(array$p):array
    {
        $action=self::field($p,'action');if(!in_array($action,self::ACTIONS,true)||self::field($p,'period_journal_form_complete')!=='1')self::bad();
        $fields=match($action){
            'balance-period-create'=>['starts_on','ends_on','evidence'],
            'balance-period-opening'=>['evidence'],
            'balance-period-account'=>['account_id','account_name','account_group','skr_code'],
            'balance-period-post'=>['request_key','booked_on','description','evidence'],
            'balance-period-reverse'=>['request_key','entry','booked_on','evidence'],
            'balance-period-close'=>['evidence'],
        };
        foreach($fields as$key)self::field($p,$key);
        foreach(['reviewed','day_allocation_reviewed','complete','valuation','cutoff','tax']as$key)if(array_key_exists($key,$p)&&(!is_string($p[$key])||$p[$key]!=='1'))self::bad();
        if($action==='balance-period-opening'){if(!is_array($p['opening_amounts']??null)||array_is_list($p['opening_amounts']))self::bad();foreach($p['opening_amounts']as$value)if(!is_string($value))self::bad();}
        if($action==='balance-period-post'){if(!is_array($p['lines']??null)||!array_is_list($p['lines'])||count($p['lines'])>50)self::bad();foreach($p['lines']as$row)if(!is_array($row)||array_diff(array_keys($row),['account','debit','credit','sphere'])||count(array_filter($row,'is_string'))!==count($row))self::bad();}
        if($action==='balance-period-create'){
            $period=BalancePeriod::check(['start'=>self::field($p,'starts_on'),'end'=>self::field($p,'ends_on')]);
            $kind=self::field($p,'period_create_prior_kind');$id=self::field($p,'period_create_prior_id');$sha=self::field($p,'period_create_prior_sha256');
            if($kind===''&&$id===''&&$sha==='')$prior=null;
            else{if(!in_array($kind,['calendar','period'],true)||!preg_match('/\A[a-f0-9]{64}\z/D',$sha))self::bad();if($kind==='calendar'){if(!preg_match('/\A[0-9]{4}\z/D',$id))self::bad();BalanceCalculation::year((int)$id);}else self::periodId($id);$prior=['kind'=>$kind,'id'=>$id,'sha256'=>$sha];}
            return ['action'=>$action,'period'=>$period,'expectation'=>['previous'=>$prior]];
        }
        $id=self::field($p,'period_id');self::periodId($id);$version=self::field($p,'version');$hash=self::field($p,'journal_hash');
        if(!preg_match('/\A[1-9][0-9]{0,4}\z/D',$version)||(int)$version>=20000||!preg_match('/\A[a-f0-9]{64}\z/D',$hash))self::bad();
        if(in_array($action,['balance-period-post','balance-period-reverse'],true)&&!preg_match('/\A[a-f0-9]{32}\z/D',self::field($p,'request_key')))self::bad();
        if($action==='balance-period-reverse'&&!preg_match('/\A[a-f0-9]{32}\z/D',self::field($p,'entry')))self::bad();
        return ['action'=>$action,'period_id'=>$id,'version'=>(int)$version,'journal_hash'=>$hash];
    }
    private static function periodId(string$id):void
    {
        if(!preg_match('/\A[0-9]{8}-[0-9]{8}\z/D',$id))self::bad();
        $p=['start'=>substr($id,0,4).'-'.substr($id,4,2).'-'.substr($id,6,2),'end'=>substr($id,9,4).'-'.substr($id,13,2).'-'.substr($id,15,2)];if(BalancePeriod::key(BalancePeriod::check($p))!==$id)self::bad();
    }
}
