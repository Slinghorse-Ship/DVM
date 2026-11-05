<?php declare(strict_types=1);
namespace Kulturhus\Verein\Finance\Banking\NativeSepa;

use DomainException;
use Kulturhus\Verein\BankImport;

/** Whole local bank-order decisions; no transport or assumed payment. */
final class SepaInput
{
    public const OPERATIONS=['prepare','approve','export','submitted','cancel','return','fints_submitted','fints_not_sent','return_attach','return_existing','return_undo'];
    public const FIELDS=['account','collection_date','notice_date','bank_reference','item','transaction','document','reason','evidence'];
    public static function initial(string $order=''):array
    {
        return ['order'=>$order,'operation'=>$order===''?'prepare':'','data'=>['entries'=>[]]+array_fill_keys(self::FIELDS,'')];
    }
    public static function capture(mixed $raw):array
    {
        $financial=is_array($raw)&&is_array($raw['data']??null)&&array_key_exists('return_financials',$raw['data']);
        $extended=is_array($raw)&&is_array($raw['data']??null)&&array_key_exists('return_allocations',$raw['data']);
        if(!is_array($raw)||count($raw)!==3||array_diff(array_keys($raw),['order','operation','data'])||!is_array($raw['data']??null)||count($raw['data'])!==count(self::FIELDS)+1+($extended?1:0)+($financial?1:0)||array_diff(array_keys($raw['data']),['entries',...self::FIELDS,...($extended?['return_allocations']:[]),...($financial?['return_financials']:[])])||!is_array($raw['data']['entries']??null)||!array_is_list($raw['data']['entries'])||count($raw['data']['entries'])>500)throw new DomainException('Ganze Angaben zum Lastschriftauftrag übermitteln.',422);
        foreach([$raw['order'],$raw['operation'],...$raw['data']['entries'],...array_map(static fn(string $field):mixed=>$raw['data'][$field],self::FIELDS)]as$value)if(!is_string($value)||preg_match('//u',$value)!==1||strlen($value)>40000)throw new DomainException('Ganze gültige Textangaben zum Lastschriftauftrag übermitteln.',422);
        $data=['entries'=>$raw['data']['entries']];foreach(self::FIELDS as$field)$data[$field]=$raw['data'][$field];
        if($extended){$ids=$raw['data']['return_allocations'];if(!is_array($ids)||!array_is_list($ids)||count($ids)>40)throw new DomainException('Bis zu vierzig ursprüngliche Einzugszuordnungen auswählen.',422);foreach($ids as$id)if(!is_string($id)||$id===''||strlen($id)>128||preg_match('//u',$id)!==1)throw new DomainException('Ganze ursprüngliche Zahlungszuordnungen übermitteln.',422);if(count(array_unique($ids))!==count($ids))throw new DomainException('Jede ursprüngliche Einzugszuordnung nur einmal auswählen.',422);$data['return_allocations']=$ids;}
        if($financial){$ids=$raw['data']['return_financials'];if(!is_array($ids)||!array_is_list($ids)||count($ids)>40)throw new DomainException('Bis zu vierzig ursprüngliche Einzugszuordnungen auswählen.',422);foreach($ids as$id)if(!is_string($id)||$id===''||strlen($id)>128||preg_match('//u',$id)!==1)throw new DomainException('Ganze ursprüngliche Zahlungszuordnungen übermitteln.',422);if(count(array_unique($ids))!==count($ids))throw new DomainException('Jede ursprüngliche Einzugszuordnung nur einmal auswählen.',422);$data['return_financials']=$ids;}
        $input=['order'=>$raw['order'],'operation'=>$raw['operation'],'data'=>$data];if(strlen(json_encode($input,JSON_THROW_ON_ERROR))>1000000)throw new DomainException('Ganze Lastschriftauswahl mit höchstens 500 Rechnungen übermitteln.',422);return $input;
    }
    public static function normalize(array $raw):array
    {
        $raw=self::capture($raw);$operation=$raw['operation'];$data=$raw['data'];
        if(!in_array($operation,self::OPERATIONS,true)||($operation==='prepare'?$raw['order']!=='':!preg_match('/\A[a-f0-9]{32}\z/D',$raw['order'])))throw new DomainException('Genau einen vorhandenen Lastschriftauftrag und eine angebotene Aktion auswählen.',422);
        foreach(['reason','evidence']as$field)if(mb_strlen($data[$field],'UTF-8')>2000||preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/',$data[$field]))throw new DomainException('Grund und Nachweis mit jeweils höchstens 2.000 vollständigen Zeichen prüfen.',422);
        $data['reason']=trim($data['reason']);$data['evidence']=trim($data['evidence']);if($data['reason']==='')throw new DomainException('Einen eigenen Grund für genau diese Lastschriftentscheidung angeben.',422);
        $used=['reason','evidence'];
        if($operation==='prepare'){
            array_push($used,'account','collection_date','notice_date');
            if(!$data['entries']||count(array_unique($data['entries']))!==count($data['entries']))throw new DomainException('1 bis 500 verschiedene Beitragsrechnungen auswählen.',422);
            foreach($data['entries']as$id)if(!preg_match('/\A[a-zA-Z0-9_-]{1,128}\z/D',$id))throw new DomainException('Ganze Beitragsrechnungsauswahl prüfen.',422);
            if(!preg_match('/\A[a-zA-Z0-9_-]{1,128}\z/D',$data['account']))throw new DomainException('Ein eigenes aktives Vereinskonto auswählen.',422);
            BankImport::date($data['collection_date']);BankImport::date($data['notice_date']);
            if($data['evidence']==='')throw new DomainException('Die tatsächlich bereits mitgeteilte Vorabinformation anhand eines Nachweises dokumentieren.',422);
        }elseif($data['entries']!==[])throw new DomainException('Für diese Auftragsaktion keine neue Rechnungsauswahl übermitteln.',422);
        if(in_array($operation,['submitted','fints_submitted','fints_not_sent'],true)){
            $used[]='bank_reference';$data['bank_reference']=trim($data['bank_reference']);
            if($data['bank_reference']===''||strlen($data['bank_reference'])>200||preg_match('/[\x00-\x1f\x7f]/',$data['bank_reference'])||$data['evidence']==='')throw new DomainException($operation==='fints_not_sent'?'Referenz der bestätigten Nichtannahme und geprüften Nachweis vollständig angeben.':'Tatsächliche Bankbestätigung mit Auftragsnummer und geprüftem Nachweis vollständig angeben.',422);
        }
        if($operation==='return'){
            array_push($used,'item','transaction','document');foreach(['item','transaction']as$field)if(!preg_match('/\A[a-f0-9]{32}\z/D',$data[$field]))throw new DomainException('Genau einen Einzug und einen tatsächlich gebuchten Rücklastschriftumsatz auswählen.',422);
            if($data['document']!==''&&!preg_match('/\A[a-zA-Z0-9_-]{1,128}\z/D',$data['document']))throw new DomainException('Ursprüngliches Rücklastschriftdokument prüfen.',422);
            if(mb_strlen($data['reason'],'UTF-8')>500)throw new DomainException('Rücklastschriftgrund mit höchstens 500 Zeichen angeben.',422);
        }
        if(array_key_exists('return_allocations',$data)&&(!in_array($operation,['return','return_attach'],true)||$data['return_allocations']===[]))throw new DomainException('Für die Rücklastschrift die tatsächlichen ursprünglichen Zahlungszuordnungen auswählen.',422);
        if(in_array($operation,['return_attach','return_existing','return_undo'],true)){
            $used[]='item';if(!preg_match('/\A[a-f0-9]{32}\z/D',$data['item'])||$data['evidence']===''||mb_strlen($data['reason'],'UTF-8')>500)throw new DomainException('Dokumentierte Rücklastschrift, tatsächlichen Banknachweis und Grund mit höchstens 500 Zeichen angeben.',422);
        }
        if(array_key_exists('return_financials',$data)&&($operation!=='return_existing'||$data['return_financials']===[]))throw new DomainException('Vorhandene Finanznachweise ausschließlich für ihre bewusste Verknüpfung auswählen.',422);
        foreach(self::FIELDS as$field)if(!in_array($field,$used,true)&&$data[$field]!=='')throw new DomainException('Für diese Auftragsaktion nur die zugehörigen Angaben übermitteln.',422);
        return ['order'=>$raw['order'],'operation'=>$operation,'data'=>$data];
    }
}
