<?php declare(strict_types=1);
namespace Kulturhus\Verein\Web;

/** Bind this receipt action to its opened source; never collect other posted fields. */
final class ContributionInvoiceForm
{
    public static function original(array $body):array
    {
        return array_intersect_key($body,['entry_id'=>true]);
    }
    public static function target(array $body,array $query):string
    {
        $entry=$body['entry_id']??null;
        if(!is_string($entry)||$entry===''||strlen($entry)>150||preg_match('//u',$entry)!==1||preg_match('/[\x00-\x1f\x7f]/',$entry))throw new \DomainException('Ursprüngliche Beitragsrechnungsquelle vollständig prüfen. Diese Eingabeform wird nicht übernommen.',422);
        if(!in_array($query['view']??null,['finance','donations'],true)||!is_string($query['entry']??null)||$query['entry']!==$entry)throw new \DomainException('Beitragsrechnung am ursprünglich geöffneten Beleg erneut prüfen.',409);
        return $entry;
    }
    public static function blocked(array $body,array $query,?string $actualOpened=null):bool
    {
        if(($body['action']??null)!=='contribution-invoice')return false;
        try{$source=self::target($body,$query);return $actualOpened!==null&&$source!==$actualOpened;}catch(\DomainException){return true;}
    }
}
