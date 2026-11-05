<?php declare(strict_types=1);
namespace Kulturhus\Verein\Settings;
/** A separate private tab-bound draft, with independently retained selected assignments. */
final class BillingDraft
{
    private static function scope(string $tab):string{CatalogRepository::kinds($tab);return'catalog-billing:'.$tab;}
    public static function open(array &$session,array $actor,string $tab,array $input,bool $fresh=false):array{return GeneralDraft::open($session,$actor,self::scope($tab),$input,$fresh);}
    public static function get(array $session,array $actor,string $tab,mixed $id):array{return GeneralDraft::get($session,$actor,self::scope($tab),$id);}
}
