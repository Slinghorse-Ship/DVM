<?php declare(strict_types=1);
namespace Kulturhus\Native\Api;
use Kulturhus\Native\Integration\ManagerBridge;

/** Server-resolved owner. No HTTP body or URL chooses the calling club or actor. */
final class Context
{
    public readonly \Kulturhus\Native\Shared\Access $access;
    public function __construct(private readonly \PDO $db,private readonly ManagerBridge $bridge,public readonly string $accountId,public readonly string $mode,private readonly ?array $scopes=null)
    {
        if(!in_array($mode,['session','bearer'],true))throw Problem::unauthorized();
        // This verified account already has its native identity. Ordinary API reads
        // must not rebuild every user/grant in the club as a login projection does.
        $this->access=new \Kulturhus\Native\Integration\ManagerAccess($db,$bridge);
    }
    public function actor():string{return $this->bridge->managerActor();}
    public function club():string{return $this->bridge->managerClub();}
    public function active():void{try{$this->bridge->user();}catch(\Throwable){throw Problem::unauthorized();}}
    public function require(string $scope):void
    {
        $this->active();if(!isset(Scopes::ALL[$scope])||($this->scopes!==null&&!in_array($scope,$this->scopes,true)))throw Problem::forbidden();
        [, $module,$capability]=Scopes::ALL[$scope];
        try{
            if($module==='identity')return;
            if($module==='settings'){
                if(!(new \Kulturhus\Verein\Settings($this->db,$this->actor()))->canManage())throw Problem::forbidden();
                // Locking native/manager reads occur through the bridge inside writes.
                $this->bridge->user();return;
            }
            $this->access->require($module,$capability);
        }catch(Problem $error){throw $error;}catch(\Throwable){throw Problem::forbidden();}
    }
    public function allowedScopes():array{$allowed=[];foreach(array_keys(Scopes::ALL) as $scope)try{$this->require($scope);$allowed[]=$scope;}catch(Problem){}return $allowed;}
}
