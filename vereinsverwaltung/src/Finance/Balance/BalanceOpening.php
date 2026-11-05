<?php declare(strict_types=1);
namespace Kulturhus\Verein;
use DomainException;

/** Signed reviewed balances, translated into the existing debit/credit contract. */
final class BalanceOpening
{
    public static function label(string $group):string
    {
        return match($group){
            'fixed_assets'=>'Gegenstände nach bisherigen Abschreibungen',
            'inventory'=>'Vorräte und Waren',
            'receivables'=>'Was dem Verein noch zustand · Forderungen',
            'cash'=>'Bank und Kasse',
            'other_assets'=>'Weiteres Vermögen',
            'prepaid'=>'Schon bezahlte Kosten für das neue Jahr',
            'equity'=>'Vereinskapital aus dem letzten Abschluss',
            'reserves'=>'Gebildete Rücklagen',
            'provisions'=>'Rückstellungen für noch ungewisse Verpflichtungen',
            'liabilities'=>'Was der Verein noch bezahlen musste · Schulden',
            'member_payables'=>'Noch offene Erstattungen an Mitglieder',
            'deferred'=>'Schon erhaltenes Geld für Leistungen im neuen Jahr',
            default=>BalanceCalculation::GROUPS[$group][0]??$group,
        };
    }
    public static function lines(array $accounts,array $amounts):array
    {
        $accounts=BalanceCalculation::chart($accounts);$wanted=array_filter($accounts,static fn($a)=>!in_array($a['group'],['revenue','expense'],true));
        if(array_diff_key($amounts,$wanted)||array_diff_key($wanted,$amounts))throw new DomainException('Alle Anfangsbestände ausdrücklich angeben. Unbekannte Werte zuerst klären.');
        $lines=[];
        foreach($wanted as $id=>$a){
            $value=$amounts[$id];if(!is_string($value)||trim($value)==='')throw new DomainException(self::label($a['group']).': Anfangsbestand fehlt. Null nur nach Prüfung eintragen.');
            $value=trim($value);if(strlen($value)>30)throw new DomainException('Anfangsbestand ist zu lang.');$negative=str_starts_with($value,'-');$unsigned=$negative?substr($value,1):$value;
            if(preg_match('/^[0-9]{1,3}(?:\.[0-9]{3})+(?:,[0-9]{1,2})?$/D',$unsigned))$unsigned=str_replace('.','',$unsigned);
            $amount=Finance::cents($unsigned);
            $debit=BalanceCalculation::GROUPS[$a['group']][1]==='asset';if($negative)$debit=!$debit;
            if($amount!==0)$lines[]=['account'=>$id,'debit_cents'=>$debit?$amount:0,'credit_cents'=>$debit?0:$amount,'sphere'=>''];
        }
        if(array_sum(array_column($lines,'debit_cents'))!==array_sum(array_column($lines,'credit_cents')))throw new DomainException('Vermögen, Kapital und Schulden passen noch nicht zusammen. Anfangsbestände mit dem letzten geprüften Abschluss abgleichen.');
        return BalanceCalculation::lines($lines,$accounts,true);
    }
}
