<?php declare(strict_types=1);
namespace Kulturhus\Verein;
require_once __DIR__.'/BalanceXmlOverview.php';

/** Presentation only. All original amounts and the complete export remain intact. */
final class BalanceScreenReport
{
    public static function partition(array $calculation):array
    {
        $out=['assets'=>[],'capital'=>[],'liabilities'=>[],'unused'=>[]];
        foreach(['assets','capital','liabilities'] as $group){
            foreach($calculation[$group] as $key=>$account){
                if(BalanceXmlOverview::unused($account))$out['unused'][]=$account;
                else $out[$group][$key]=$account;
            }
        }
        return $out;
    }
}
