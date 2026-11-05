<?php declare(strict_types=1);
namespace Kulturhus\Verein\Web;
/** A malformed read selection never becomes page one without an explicit reopen. */
final class InventoryLinkForm
{
    public const PAGE='inventory_link_page';
    public static function state(array $query):array
    {
        $present=array_key_exists(self::PAGE,$query);$value=$present?$query[self::PAGE]:'1';
        $valid=(!$present||is_string($value))&&is_string($value)&&preg_match('/\A[1-9][0-9]{0,6}\z/D',$value)===1&&(int)$value<=1000000;
        return ['blocked'=>!$valid,'page'=>$valid?$value:null,'original'=>$valid?null:[self::PAGE=>$query[self::PAGE]],'error'=>$valid?'':'Zuordnungsseite prüfen. Die bekannten ursprünglichen Seitenangaben bleiben erhalten.'];
    }
}
