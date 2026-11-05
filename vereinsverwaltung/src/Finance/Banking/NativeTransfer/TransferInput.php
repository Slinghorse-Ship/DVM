<?php declare(strict_types=1);
namespace Kulturhus\Verein\Finance\Banking\NativeTransfer;

use DomainException;
use Kulturhus\Verein\BankImport;
use Kulturhus\Banking\PaymentRules;

/** Whole local transfer decisions. This form never sends to a bank or records money. */
final class TransferInput
{
    public const OPERATIONS=['prepare','pay','approve','export','submitted','cancel','fints_submitted','fints_not_sent'];
    public const FIELDS=['account','execution_date','purpose','transfer_mode','bank_reference','reason','evidence'];
    public static function initial(string $entry,string $order=''):array
    {
        return ['entry'=>$entry,'order'=>$order,'operation'=>$order===''?'prepare':'','data'=>array_replace(array_fill_keys(self::FIELDS,''),['transfer_mode'=>$order===''?'standard':''])];
    }
    public static function capture(mixed $raw):array
    {
        if(!is_array($raw)||count($raw)!==4||array_diff(array_keys($raw),['entry','order','operation','data'])||!is_array($raw['data']??null)||count($raw['data'])!==count(self::FIELDS)||array_diff(array_keys($raw['data']),self::FIELDS))throw new DomainException('Ganze Angaben zum Überweisungsauftrag übermitteln.',422);
        foreach([$raw['entry'],$raw['order'],$raw['operation'],...array_map(static fn(string $field):mixed=>$raw['data'][$field],self::FIELDS)]as$value)if(!is_string($value)||preg_match('//u',$value)!==1||strlen($value)>40000)throw new DomainException('Ganze gültige Textangaben zum Überweisungsauftrag übermitteln.',422);
        $data=[];foreach(self::FIELDS as$field)$data[$field]=$raw['data'][$field];return ['entry'=>$raw['entry'],'order'=>$raw['order'],'operation'=>$raw['operation'],'data'=>$data];
    }
    public static function normalize(array $raw):array
    {
        $raw=self::capture($raw);$operation=$raw['operation'];$data=$raw['data'];
        if(!preg_match('/\A[a-zA-Z0-9_-]{1,128}\z/D',$raw['entry'])||!in_array($operation,self::OPERATIONS,true)||(in_array($operation,['prepare','pay'],true)?$raw['order']!=='':!preg_match('/\A[a-f0-9]{32}\z/D',$raw['order'])))throw new DomainException('Genau eine eigene Rechnung und eine angebotene Überweisungsaktion auswählen.',422);
        foreach(['reason','evidence']as$field)if(mb_strlen($data[$field],'UTF-8')>2000||preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/',$data[$field]))throw new DomainException('Grund und Nachweis mit jeweils höchstens 2.000 vollständigen Zeichen prüfen.',422);
        $data['reason']=trim($data['reason']);$data['evidence']=trim($data['evidence']);if($data['reason']==='')throw new DomainException('Einen eigenen Grund für genau diese Überweisungsentscheidung angeben.',422);$used=['reason','evidence'];
        if(in_array($operation,['prepare','pay'],true)){
            array_push($used,'account','execution_date','purpose','transfer_mode');if(!preg_match('/\A[a-zA-Z0-9_-]{1,128}\z/D',$data['account']))throw new DomainException('Ein eigenes aktives Vereinskonto auswählen.',422);
            try{BankImport::date($data['execution_date']);PaymentRules::transferMode($data['transfer_mode']);}catch(DomainException $failure){throw new DomainException($failure->getMessage(),422,$failure);}$data['purpose']=trim($data['purpose']);if($data['purpose']===''||mb_strlen($data['purpose'],'UTF-8')>140||preg_match('/[\x00-\x1f\x7f]/',$data['purpose']))throw new DomainException('Verwendungszweck mit höchstens 140 Zeichen eingeben.',422);
        }
        if(in_array($operation,['submitted','fints_submitted','fints_not_sent'],true)){
            $used[]='bank_reference';$data['bank_reference']=trim($data['bank_reference']);if($data['bank_reference']===''||mb_strlen($data['bank_reference'],'UTF-8')>200||preg_match('/[\x00-\x1f\x7f]/',$data['bank_reference'])||$data['evidence']==='')throw new DomainException($operation==='fints_not_sent'?'Referenz der bestätigten Nichtannahme und geprüften Nachweis vollständig angeben.':'Tatsächliche Bankbestätigung mit Auftragsnummer und geprüftem Nachweis vollständig angeben.',422);
        }
        foreach(self::FIELDS as$field)if(!in_array($field,$used,true)&&$data[$field]!=='')throw new DomainException('Für diese Überweisungsaktion nur die zugehörigen Angaben übermitteln.',422);
        return ['entry'=>$raw['entry'],'order'=>$raw['order'],'operation'=>$operation,'data'=>$data];
    }
}
