<?php declare(strict_types=1);
namespace Kulturhus\Native\Cache;
use Kulturhus\Native\Shared\Access;

/** Every operation checks the current website manager and current tenant again. */
final class Administration
{
    public function __construct(private Access $access,private string $root){}
    public function requireAccess():void{$this->actor();}
    private function actor():array{try{return $this->access->require('website','manage');}catch(\DomainException $error){throw new \DomainException('Keine Berechtigung für die Cacheverwaltung.',403,$error);}}
    private function store():Store {
        $actor=$this->actor();$club=$actor['club_id'];
        return new Store($this->root,$club,guard:function()use($club):void{$current=$this->actor();if($current['club_id']!==$club)throw new \DomainException('Vereinszuordnung geändert. Bitte neu laden.',403);});
    }
    public function listing(int $page=1,?string $namespace=null):array{return $this->store()->listing($page,$namespace);}
    public function action(string $action,array $input):int {
        $store=$this->store();
        return match($action){
            'cache-cleanup'=>$store->cleanup(),
            'cache-clear'=>$store->clear(self::optional($input,'namespace')),
            'cache-invalidate-tag'=>$store->invalidate(self::scalar($input,'tag')),
            'cache-delete'=>(int)$store->deleteId(self::scalar($input,'entry')),
            default=>throw new \DomainException('Unbekannte Cacheaktion.'),
        };
    }
    public static function scalar(array $input,string $key,string $default=''):string{if(!is_string($input[$key]??$default))throw new \DomainException('Cacheanfrage prüfen.');return $input[$key]??$default;}
    public static function optional(array $input,string $key):?string{$value=self::scalar($input,$key);return $value===''?null:$value;}
}
