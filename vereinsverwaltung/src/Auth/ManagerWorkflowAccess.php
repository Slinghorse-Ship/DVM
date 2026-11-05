<?php declare(strict_types=1);
namespace Kulturhus\Verein;
require_once dirname(__DIR__, 1).'/DocumentWorkflow/WorkflowSignatureAccess.php';
require_once __DIR__.'/Permissions.php';
require_once dirname(__DIR__, 1).'/Documents/DocumentCatalog.php';
require_once __DIR__.'/BoardAccess.php';
require_once dirname(__DIR__).'/Shared/ActorNames.php';
/** Optional manager boundary. The reusable workflow core does not load the CMS. */
final class ManagerWorkflowAccess implements \Kulturhus\Verein\DocumentWorkflow\WorkflowSignatureAccess
{
    private ?\Closure $guard;
    public function __construct(private \PDO $db,private string $actorId,?callable $guard=null) {$this->guard=$guard===null?null:\Closure::fromCallable($guard);}
    public function actor(): array
    {
        if($this->guard!==null)($this->guard)();
        $a=Permissions::actor($this->db,$this->actorId);
        return ['id'=>$a['id'],'club_id'=>$a['club_id'],'access'=>$a['role']==='editor'?'none':(BoardAccess::allowed($this->db,$a['id'])?'write':$a['documents_access'])];
    }
    public function participants(): array
    {
        $a=$this->actor();
        if(!in_array($a['access'],['read','write'],true)) throw new \DomainException('Dokumentenrecht erforderlich.');
        $q=$this->db->prepare('SELECT a.id,COALESCE(NULLIF(c.name,\'\'),a.id) name FROM actors a LEFT JOIN contacts c ON c.club_id=a.club_id AND c.id=a.contact_id WHERE a.club_id=? AND a.active=1 ORDER BY name,a.id');
        $q->execute([$a['club_id']]);$result=[];
        foreach(ActorNames::rows($this->db,$a['club_id'],$q->fetchAll(\PDO::FETCH_ASSOC)) as $person){
            try{$p=Permissions::actor($this->db,(string)$person['id']);}catch(\DomainException){continue;}
            $level=$p['role']==='editor'?'none':(BoardAccess::allowed($this->db,$p['id'])?'write':$p['documents_access']);
            if(!in_array($level,['read','write'],true))continue;
            $result[]=['id'=>$p['id'],'name'=>$person['name'],'access'=>$level];
        }
        return $result;
    }
    public function signatureMembers(): array
    {
        $actor=$this->actor();
        $q=$this->db->prepare("SELECT a.id,COALESCE(NULLIF(c.name,''),a.id) name FROM actors a LEFT JOIN contacts c ON c.club_id=a.club_id AND c.id=a.contact_id WHERE a.club_id=? AND a.active=1 ORDER BY name,a.id");
        $q->execute([$actor['club_id']]);$result=[];
        foreach(ActorNames::rows($this->db,$actor['club_id'],$q->fetchAll(\PDO::FETCH_ASSOC)) as $person){
            try{$current=Permissions::actor($this->db,(string)$person['id']);}catch(\DomainException){continue;}
            if($current['club_id']!==$actor['club_id']||$current['role']==='editor')continue;
            $result[]=['id'=>$current['id'],'name'=>$person['name']];
        }
        return $result;
    }
}
