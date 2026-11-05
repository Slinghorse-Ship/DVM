<?php declare(strict_types=1);
namespace Kulturhus\Verein;
use DomainException;

/** Preserve the submitted annual journal identity across an error response. */
final class BalancePostingForm
{
    public const ACTIONS=['balance-post','balance-reverse'];
    public const COMPLETE='annual_posting_form_complete';
    public static function route(array $post):array
    {
        return ['view'=>'finance','tab'=>'tax','balance'=>'1','balance_year'=>is_string($post['balance_year']??null)?$post['balance_year']:''];
    }
    public static function metadata(array $post):array
    {
        foreach(['action','balance_year','version','request_key']as$key)if(!is_string($post[$key]??null))throw new DomainException('Ursprüngliche Buchungsangaben vollständig prüfen.',422);
        if(!in_array($post['action'],self::ACTIONS,true)||!preg_match('/\A[0-9]{4}\z/D',$post['balance_year'])||!preg_match('/\A[1-9][0-9]{0,9}\z/D',$post['version'])||(int)$post['version']>4294967295||!preg_match('/\A[a-f0-9]{32}\z/D',$post['request_key']))throw new DomainException('Ursprüngliche Buchungsangaben vollständig prüfen.',422);
        BalanceCalculation::year((int)$post['balance_year']);
        return ['action'=>$post['action'],'year'=>(int)$post['balance_year'],'version'=>(int)$post['version'],'request_key'=>$post['request_key']];
    }
    /** Validate the complete field shape without approving amounts or accounts. */
    public static function complete(array $post):bool
    {
        try{self::metadata($post);}catch(DomainException){return false;}
        if(($post[self::COMPLETE]??null)!=='1')return false;
        foreach(['booked_on','evidence']as$key)if(!is_string($post[$key]??null))return false;
        if($post['action']==='balance-reverse')return is_string($post['entry']??null)&&preg_match('/\A[a-f0-9]{32}\z/D',$post['entry'])===1;
        if(!is_string($post['description']??null)||!is_array($post['lines']??null)||!array_is_list($post['lines'])||count($post['lines'])>50||count($post['lines'])<1)return false;
        foreach($post['lines']as$row)if(!is_array($row)||count($row)!==4||array_diff(array_keys($row),['account','debit','credit','sphere'])||count(array_filter($row,'is_string'))!==4)return false;
        return true;
    }
    public static function status(DomainException $failure):int
    {
        if(in_array($failure->getCode(),[403,404,409,422,503],true))return $failure->getCode();
        if(in_array($failure->getMessage(),['Finanzberechtigung erforderlich.','Zugang nicht aktiv.','Das Bilanzjournal ist noch nicht für den Produktivbetrieb freigegeben.','Für diesen Verein ist die EÜR eingestellt. Keine Bilanzbuchung vorgenommen.','Abweichende Wirtschaftsjahre sind für das Bilanzjournal noch nicht freigegeben.'],true))return 403;
        if(in_array($failure->getMessage(),['Jahresstand inzwischen geändert. Bitte neu laden.','Dieser Jahresstand kann hier nicht geändert werden.','Dieser Vorgang wurde bereits gebucht.'],true))return 409;
        return 422;
    }
}
