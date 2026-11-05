<?php declare(strict_types=1);
namespace Kulturhus\Verein\Web;

/** Explicit bank/account binding and only nonsecret original scheduling choices. */
final class BankScheduleForm
{
    public const FIELDS=['mapping_id','account_id','schedule_version','enabled'];
    public static function original(array $body):array{return array_intersect_key($body,array_fill_keys(self::FIELDS,true));}
    public static function parse(array $body,string $openedAccount):array
    {
        foreach(self::FIELDS as$field)if(!array_key_exists($field,$body)||!is_string($body[$field]))throw new \DomainException('Ursprünglichen Abrufplan vollständig prüfen. Diese Eingabeform wird nicht übernommen.',422);
        foreach(['mapping_id','account_id']as$field)if($body[$field]===''||strlen($body[$field])>150||preg_match('/[\x00-\x1f\x7f]/',$body[$field]))throw new \DomainException('Ursprüngliche Bankverknüpfung des Abrufplans prüfen.',422);
        if($openedAccount===''||$body['account_id']!==$openedAccount)throw new \DomainException('Abrufplan am ursprünglich geöffneten Bankkonto erneut prüfen.',409);
        if(!in_array($body['enabled'],['0','1'],true))throw new \DomainException('Automatischen Abruf ausdrücklich aktivieren oder pausieren.',422);
        try{$version=BankDialogFields::version(['dialog_version'=>$body['schedule_version']]);}catch(\DomainException){throw new \DomainException('Ursprünglichen Abrufplanstand prüfen. Formular bewusst neu öffnen.',422);}
        return ['mapping'=>$body['mapping_id'],'account'=>$body['account_id'],'version'=>$version,'enabled'=>$body['enabled']==='1'];
    }
    public static function blocked(array $body,string $account):bool
    {
        try{self::parse($body,$account);return false;}catch(\DomainException $failure){return $failure->getMessage()!=='Automatischen Abruf ausdrücklich aktivieren oder pausieren.';}
    }
}
