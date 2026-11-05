<?php declare(strict_types=1);
namespace Kulturhus\Verein\Settings;
use DomainException;
/** Row actions retain the entire unsaved proposal, including invalid corrections. */
final class TariffDraft
{
    private static function scope(array $input,string $tab):string
    {
        if(!is_string($input['kind']??null)||!in_array($input['kind'],CatalogRepository::kinds($tab),true))throw new DomainException('Eigene Tarifart auswählen.',422);
        return'catalog:'.$input['kind'].':'.CatalogInput::id($input['id']??null,true);
    }
    public static function open(array &$session,array $actor,string $tab,array $input,bool $fresh=false):array{return GeneralDraft::open($session,$actor,self::scope($input,$tab),$input,$fresh);}
    public static function get(array $session,array $actor,string $tab,mixed $id):array
    {
        $stored=is_string($id)&&preg_match('/\A[a-f0-9]{32}\z/D',$id)?($session['general_settings_drafts'][$id]??null):null;
        if(!is_array($stored)||!is_array($stored['input']??null))throw new DomainException('Eigenen ursprünglichen Tarifentwurf öffnen.',404);
        return GeneralDraft::get($session,$actor,self::scope($stored['input'],$tab),$id);
    }
    public static function row(array $input,string $action):array
    {
        if(!is_array($input['data']['tiers']??null)||count($input['data']['tiers'])>100)throw new DomainException('Vollständige Staffelzeilen prüfen.',422);$rows=array_values($input['data']['tiers']);
        if($action==='add'){if(count($rows)>=20)throw new DomainException('Höchstens 20 Staffelzeilen verwenden.',422);$rows[]=['from'=>'','amount'=>''];}
        else{if(!preg_match('/\A(remove|up|down):([0-9]{1,2})\z/D',$action,$parts)||!array_key_exists((int)$parts[2],$rows))throw new DomainException('Gültige ungespeicherte Staffelaktion auswählen.',422);$index=(int)$parts[2];if($parts[1]==='remove')array_splice($rows,$index,1);else{$other=$index+($parts[1]==='up'?-1:1);if(!array_key_exists($other,$rows))throw new DomainException('Gültige ungespeicherte Staffelaktion auswählen.',422);[$rows[$index],$rows[$other]]=[$rows[$other],$rows[$index]];}}
        $input['data']['tiers']=$rows;return$input;
    }
}
