<?php declare(strict_types=1);
namespace Kulturhus\Verein\Settings;
/** A separate private draft per chosen assignment and actual activate/pause intention. */
final class AutomationDraft
{
    private static function scope(array $input,string $tab):string
    {
        CatalogRepository::kinds($tab);$id=CatalogInput::id($input['id']??null);if(($input['tab']??null)!==$tab||!in_array($input['operation']??null,['activate','pause'],true))throw new \DomainException('Eigene Automatik im passenden Bereich öffnen.',422);return'catalog-automation:'.$tab.':'.$id.':'.$input['operation'];
    }
    public static function open(array &$session,array $actor,string $tab,array $input,bool $fresh=false):array{return GeneralDraft::open($session,$actor,self::scope($input,$tab),$input,$fresh);}
    public static function get(array $session,array $actor,string $tab,mixed $id):array
    {
        $stored=is_string($id)&&preg_match('/\A[a-f0-9]{32}\z/D',$id)?($session['general_settings_drafts'][$id]??null):null;if(!is_array($stored)||!is_array($stored['input']??null))throw new \DomainException('Eigenen ursprünglichen Automatikentwurf öffnen.',404);return GeneralDraft::get($session,$actor,self::scope($stored['input'],$tab),$id);
    }
}
