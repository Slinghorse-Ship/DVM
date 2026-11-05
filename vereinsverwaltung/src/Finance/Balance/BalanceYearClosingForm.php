<?php declare(strict_types=1);
namespace Kulturhus\Verein;
use DomainException;

/** Original ordinary annual-closing form; financial approval stays in the ledger. */
final class BalanceYearClosingForm
{
    public const ACTIONS=['balance-close'];
    public const COMPLETE='annual_closing_form_complete';
    public static function route(array $post):array
    {
        return ['view'=>'finance','tab'=>'tax','balance'=>'1','balance_year'=>is_string($post['balance_year']??null)?$post['balance_year']:''];
    }
    public static function metadata(array $post):array
    {
        foreach(['action','balance_year','version']as$key)if(!is_string($post[$key]??null))throw new DomainException('Ursprüngliche Abschlussangaben vollständig prüfen.',422);
        if($post['action']!=='balance-close'||!preg_match('/\A[0-9]{4}\z/D',$post['balance_year'])||!preg_match('/\A[1-9][0-9]{0,9}\z/D',$post['version'])||(int)$post['version']>4294967295)throw new DomainException('Ursprüngliche Abschlussangaben vollständig prüfen.',422);BalanceCalculation::year((int)$post['balance_year']);return ['year'=>(int)$post['balance_year'],'version'=>(int)$post['version']];
    }
    public static function read(array $post):array
    {
        $metadata=self::metadata($post);if(($post[self::COMPLETE]??null)!=='1'||!is_string($post['evidence']??null))throw new DomainException('Ursprüngliches Abschlussformular vollständig prüfen.',422);$checks=[];
        foreach(['complete','valuation','cutoff','tax']as$key){if(array_key_exists($key,$post)&&$post[$key]!=='1')throw new DomainException('Ursprüngliche Abschlussbestätigungen vollständig prüfen.',422);$checks[$key]=($post[$key]??null)==='1';}
        return $metadata+['checks'=>$checks,'evidence'=>$post['evidence']];
    }
    public static function status(DomainException $failure):int
    {
        if(in_array($failure->getCode(),[403,404,409,422,503],true))return $failure->getCode();
        if(in_array($failure->getMessage(),['Finanzberechtigung erforderlich.','Zugang nicht aktiv.','Dieser Bereich ist nur für den aktuellen Vorstand und Superuser freigegeben.','Das Bilanzjournal ist noch nicht für den Produktivbetrieb freigegeben.','Für diesen Verein ist die EÜR eingestellt. Keine Bilanzbuchung vorgenommen.','Abweichende Wirtschaftsjahre sind für das Bilanzjournal noch nicht freigegeben.'],true))return 403;
        if(in_array($failure->getMessage(),['Jahresstand inzwischen geändert. Bitte neu laden.','Dieser Jahresstand kann hier nicht geändert werden.'],true))return 409;
        return 422;
    }
}
