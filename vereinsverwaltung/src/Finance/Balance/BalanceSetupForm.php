<?php declare(strict_types=1);
namespace Kulturhus\Verein;
use DomainException;

/** Preserve ordinary year/chart/opening requests; the ledger owns every decision. */
final class BalanceSetupForm
{
    public const ACTIONS=['balance-create','balance-account','balance-opening','balance-opening-reconcile'];
    public const COMPLETE='annual_setup_form_complete';
    public static function route(array $post):array
    {
        return ['view'=>'finance','tab'=>'tax','balance'=>'1','balance_year'=>is_string($post['balance_year']??null)?$post['balance_year']:''];
    }
    public static function metadata(array $post):array
    {
        foreach(['action','balance_year','version']as$key)if(!is_string($post[$key]??null))throw new DomainException('Ursprüngliche Einrichtungsangaben vollständig prüfen.',422);
        if(!in_array($post['action'],self::ACTIONS,true)||!preg_match('/\A[0-9]{4}\z/D',$post['balance_year'])||!preg_match('/\A(?:0|[1-9][0-9]{0,9})\z/D',$post['version'])||(int)$post['version']>4294967295||($post['action']==='balance-create'? $post['version']!=='0':$post['version']==='0'))throw new DomainException('Ursprüngliche Einrichtungsangaben vollständig prüfen.',422);
        BalanceCalculation::year((int)$post['balance_year']);return ['action'=>$post['action'],'year'=>(int)$post['balance_year'],'version'=>(int)$post['version']];
    }
    public static function read(array $post):array
    {
        $meta=self::metadata($post);if(($post[self::COMPLETE]??null)!=='1')throw new DomainException('Ursprüngliches Einrichtungsformular vollständig prüfen.',422);
        if($meta['action']==='balance-account')foreach(['account_id','account_name','account_group','skr_code']as$key)if(!is_string($post[$key]??null))throw new DomainException('Ursprüngliche Kontoangaben vollständig prüfen.',422);
        if(in_array($meta['action'],['balance-opening','balance-opening-reconcile'],true)){
            if(!is_string($post['evidence']??null)||(array_key_exists('reviewed',$post)&&$post['reviewed']!=='1'))throw new DomainException('Ursprüngliche Anfangsbestände und Bestätigung vollständig prüfen.',422);
            $meta['reviewed']=($post['reviewed']??null)==='1';
        }
        if($meta['action']==='balance-opening'){
            if(array_key_exists('opening_amounts',$post)){
                if(!is_array($post['opening_amounts'])||!$post['opening_amounts']||count($post['opening_amounts'])>500||array_key_exists('opening',$post))throw new DomainException('Ursprüngliche Anfangsbestände vollständig prüfen.',422);
                foreach($post['opening_amounts']as$value)if(!is_string($value))throw new DomainException('Ursprüngliche Anfangsbestände vollständig prüfen.',422);
            }else{
                if(!is_array($post['opening']??null)||!$post['opening']||count($post['opening'])>500)throw new DomainException('Ursprüngliche Anfangsbestände vollständig prüfen.',422);
                foreach($post['opening']as$row)if(!is_array($row)||array_diff(array_keys($row),['debit','credit'])||!is_string($row['debit']??null)||!is_string($row['credit']??null))throw new DomainException('Ursprüngliche Anfangsbestände vollständig prüfen.',422);
            }
        }
        if($meta['action']==='balance-opening-reconcile'&&(!is_string($post['prior_hash']??null)||!preg_match('/\A[a-f0-9]{64}\z/D',$post['prior_hash'])))throw new DomainException('Ursprünglichen Vorjahresbezug vollständig prüfen.',422);
        return$meta;
    }
    public static function status(DomainException $failure,array $post=[]):int
    {
        if(in_array($failure->getCode(),[403,404,409,422,503],true))return$failure->getCode();
        if($failure->getMessage()==='Aktuelles offenes Folgejahr und Vorjahresabgleich bestätigen.'&&($post['reviewed']??null)==='1')return 409;
        if(in_array($failure->getMessage(),['Finanzberechtigung erforderlich.','Zugang nicht aktiv.','Dieser Bereich ist nur für den aktuellen Vorstand und Superuser freigegeben.','Das Bilanzjournal ist noch nicht für den Produktivbetrieb freigegeben.','Für diesen Verein ist die EÜR eingestellt. Keine Bilanzbuchung vorgenommen.','Abweichende Wirtschaftsjahre sind für das Bilanzjournal noch nicht freigegeben.'],true))return 403;
        if(in_array($failure->getMessage(),['Bilanzjahr bereits vorhanden.','Jahresstand inzwischen geändert. Bitte neu laden.','Dieser Jahresstand kann hier nicht geändert werden.','Konto kann diesem Jahresstand nicht hinzugefügt werden. Bitte neu laden.','Berichtigtes Vorjahr zuerst prüfen und die Anfangsbestände übernehmen.','Geprüften berichtigten Vorjahresabschluss auswählen.'],true))return 409;
        return 422;
    }
}
