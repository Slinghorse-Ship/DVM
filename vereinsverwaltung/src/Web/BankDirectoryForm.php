<?php declare(strict_types=1);
namespace Kulturhus\Verein\Web;

/** Nonsecret directory form originals; file bytes and credentials are never collected. */
final class BankDirectoryForm
{
    public const FIELDS=['directory_month','directory_id','directory_confirm'];
    public static function original(array $body):array{return array_intersect_key($body,array_fill_keys(self::FIELDS,true));}
    public static function assertInput(array $body,string $action):void
    {
        $fields=$action==='bank-directory-preview'?['directory_month']:['directory_id'];
        foreach($fields as$field)if(!array_key_exists($field,$body)||!is_string($body[$field]))throw new \DomainException('Ursprüngliche Bankenlisten-Eingaben vollständig prüfen. Diese Eingabeform wird nicht übernommen.');
        if(array_key_exists('directory_confirm',$body)&&(!is_string($body['directory_confirm'])||!in_array($body['directory_confirm'],['','1'],true)))throw new \DomainException('Die ursprüngliche Bestätigung zur Bankenliste prüfen.');
    }
    public static function monthType(string $value):string{return preg_match('/\A20[0-9]{2}-(?:0[1-9]|1[0-2])\z/D',$value)?'month':'text';}
}
