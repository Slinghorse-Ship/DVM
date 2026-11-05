<?php declare(strict_types=1);
namespace Kulturhus\Verein;
use DomainException;

/** Account identities retained with the source, not inferred from names or current balances. */
final class BalanceDisposalAccounts
{
    public static function codes(array $disposal):array
    {
        if(in_array($disposal['kind']??null,['scrap','partial_scrap'],true))return ['68950'=>'expense'];
        if(($disposal['kind']??null)==='software_end')return ['68960'=>'expense'];
        if(($disposal['kind']??null)!=='sale'||!in_array($disposal['rate']??null,[0,19],true))throw new DomainException('Kontennachweis des Anlagenabgangs prüfen.');
        [$gain,$loss]=AssetSale::saleCodes($disposal['rate'],$disposal['vat_kind']??'domestic');
        return [$gain=>'revenue','48550'=>'revenue',$loss=>'expense','68950'=>'expense'];
    }
    public static function retained(array $chart,array $item):?array
    {
        if(!array_key_exists('disposal_accounts',$item))return null;
        $map=$item['disposal_accounts'];$disposal=$item['disposal']??null;
        if(!is_array($map)||!is_array($disposal))throw new DomainException('Gespeicherten Abgangskontennachweis prüfen.');
        $codes=self::codes($disposal);
        if(count($map)!==count($codes)||array_diff_key($map,$codes)||array_diff_key($codes,$map))throw new DomainException('Abgangskontennachweis ist unvollständig.');
        foreach($codes as $code=>$group){
            $id=$map[$code];
            if(!is_string($id)||!isset($chart[$id])||($chart[$id]['group']??null)!==$group||($chart[$id]['skr_code']??null)!==(string)$code)
                throw new DomainException('Gespeichertes Abgangskonto passt nicht zum festgeschriebenen Kontenplan.');
        }
        return $map;
    }
    public static function choose(array $chart,array $item,string $code,string $group):string
    {
        $map=self::retained($chart,$item);
        if($map!==null){
            if(!isset($map[$code])||$chart[$map[$code]]['group']!==$group)throw new DomainException('Abgangskonto ist nicht durch den Beleg nachgewiesen.');
            return $map[$code];
        }
        // Preserve old unique-account cases, but never guess between duplicate codes.
        $candidates=array_keys(array_filter($chart,static fn($a)=>$a['group']===$group&&$a['skr_code']===$code));
        if(count($candidates)!==1)throw new DomainException('Eindeutiges Abgangskonto '.$code.' im festgeschriebenen Kontenplan erforderlich. Buchabschluss zuerst fachlich klären.');
        return $candidates[0];
    }
    public static function checkMovements(array $accounts,array $items,int $year):void
    {
        $codes=[];
        foreach($items as $item){$map=self::retained($accounts,$item);if($map!==null)foreach($map as $code=>$_)$codes[$code]=true;}
        foreach($codes as $code=>$_){
            $code=(string)$code;$peers=array_filter($accounts,static fn($a)=>$a['skr_code']===$code);$expected=array_fill_keys(array_keys($peers),0);
            foreach($items as $item){
                if(($item['method']??null)==='cost'){
                    $amount=BalanceCostAssetSources::movement($item,$code);if($amount===null)continue;
                    $id=$item['valuation_accounts'][$code]??null;
                    if($id===null)throw new DomainException('Gespeichertes Bewertungsgegenkonto fehlt.');
                    $expected[$id]+=$amount;continue;
                }
                $amount=AssetSale::movement($item,$code,$year);if($amount===null)continue;
                $map=self::retained($accounts,$item);
                if($map===null){
                    if(count($peers)!==1)throw new DomainException('Altem Anlagenabgang fehlt ein eindeutiger Kontennachweis.');
                    $id=array_key_first($peers);
                }else $id=$map[$code]??null;
                if($id===null)throw new DomainException('Beleggebundenes Anlagenabgangskonto fehlt.');
                $expected[$id]+=$amount;
            }
            foreach($peers as $id=>$a)if(($a['amount_cents']??null)!==$expected[$id])throw new DomainException('Anlagenabgang und Bewegung auf dem gespeicherten Gegenkonto stimmen nicht überein.');
        }
    }
}
