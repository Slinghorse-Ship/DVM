<?php declare(strict_types=1);
namespace Kulturhus\Verein;

use DomainException;

/** Private server dependency. HTTP input can never select or construct a policy. */
interface BalanceFilingRuntime
{
    public function proof(array $filing,bool $sending=false):array;
    public function validate(array $request):array;
    public function transmit(#[\SensitiveParameter] array $request):array;
}

/** Filing environment is fixed when a draft is created; legacy originals stay test originals. */
final class BalanceFilingEnvironment
{
    public static function check(string $environment):string
    {
        if(!in_array($environment,['test','production'],true))throw new DomainException('Abgabeumgebung ungültig.');
        return $environment;
    }
    public static function enabled(string $environment='test'):bool
    {
        self::check($environment);
        if(!BalanceLedger::enabled())return false;
        return $environment==='test'
            ?getenv('VEREIN_MODE')!=='production'&&getenv('VEREIN_TAX_AUTO_TEST')==='synthetic-only'
            :getenv('VEREIN_MODE')==='production';
    }
    public static function submitted(array $filing):bool
    {return in_array($filing['environment']??null,['test','production'],true)&&($filing['state']??null)==='sent_'.$filing['environment'];}
    public static function binding(array $filing,array $preparation):void
    {
        $environment=self::check((string)($filing['environment']??''));
        if(!array_key_exists('binding_version',$preparation)){
            if($environment!=='test'||array_key_exists('environment',$preparation)||array_key_exists('release_binding',$preparation))throw new DomainException('E-Bilanz-Umgebung und ursprüngliche Bindung stimmen nicht überein.');
            return; // Keep every historical test payload and its original approval hash unchanged.
        }
        if($preparation['binding_version']!==2||($preparation['environment']??null)!==$environment||!is_array($preparation['release_binding']??null))throw new DomainException('E-Bilanz-Umgebung und ursprüngliche Bindung stimmen nicht überein.');
        if(($preparation['release_binding']['year']??null)!==(int)$filing['tax_year']||($preparation['release_binding']['data_version']??null)!==$filing['data_version'])throw new DomainException('E-Bilanz-Jahr und ursprüngliches Jahrespaket stimmen nicht überein.');
        if(self::submitted($filing))return; // Historical release evidence is protected by its original approval, not a newly installed runtime.
        $release=TaxBalanceRelease::software((int)$filing['tax_year'],(string)$filing['data_version']);
        if($release!==$preparation['release_binding'])throw new DomainException('Das E-Bilanz-Jahrespaket wurde geändert. Einen neuen Entwurf anlegen.');
    }
}
