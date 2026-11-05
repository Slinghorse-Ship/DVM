<?php declare(strict_types=1);
namespace Kulturhus\Verein\Settings;
use DomainException;
use PDO;
use Kulturhus\Native\Shared\Db;
use Kulturhus\Verein\{AssignmentPrices,Permissions};

/** Current financial policy and genuine canonical personal-price history. */
final class PersonalPriceRepository
{
    public function __construct(private PDO $db,private string $actorId){}
    public function read(callable $operation):mixed{return(new AssignmentRepository($this->db,$this->actorId))->read($operation);}
    public function actor(bool $write=false):array
    {
        if(!$this->db->inTransaction())return$this->read(fn():array=>$this->actor($write));
        $actor=Permissions::actor($this->db,$this->actorId);
        if(!in_array($actor['finance_access'],$write?['write']:['read','write'],true)||!in_array($actor['members_access'],['read','write'],true))throw new DomainException('Aktuelle Finanz- und Mitgliederrechte für persönliche Preise erforderlich.',403);
        return$actor;
    }
    public function state(string $id,string $tab):array
    {
        CatalogInput::id($id);CatalogRepository::kinds($tab);
        return$this->read(function()use($id,$tab):array{
            $this->actor();$assignment=(new AssignmentRepository($this->db,$this->actorId))->state($id,$tab);$source=['assignment'=>array_intersect_key($assignment,array_flip(['id','tab','version','data']))]+$assignment['source'];$canonical=(new AssignmentPrices($this->db,$this->actorId))->get($id);
            return['id'=>$id,'tab'=>$tab,'version'=>(int)($canonical['version']??0),'data'=>['mode'=>$canonical['mode']??'none','value_units'=>(int)($canonical['value_units']??0)],'canonical'=>$canonical,'source'=>$source];
        });
    }
    public function contact(string $id):array{return(new AssignmentRepository($this->db,$this->actorId))->contact($id);}
    public function revisions(string $id,string $tab,mixed $page='1'):array
    {
        if((!is_string($page)&&!is_int($page))||!preg_match('/\A[1-9][0-9]{0,8}\z/D',(string)$page))throw new DomainException('Vollständige persönliche Preisverlaufsseite prüfen.',422);
        return$this->read(function()use($id,$tab,$page):array{
            $this->state($id,$tab);$result=(new AssignmentPrices($this->db,$this->actorId))->history($id,(int)$page);
            return$result+['page_input'=>(string)$page,'size'=>6];
        });
    }
    public function revision(string $id,string $tab,mixed $version):array
    {
        $version=GeneralInput::version($version);if($version<1)throw new DomainException('Ursprüngliche persönliche Preisfassung nicht verfügbar.',404);
        return$this->read(function()use($id,$tab,$version):array{
            $this->state($id,$tab);$actor=$this->actor();
            $row=Db::run($this->db,'SELECT * FROM native_assignment_price_changes WHERE club_id=? AND assignment_id=? AND version=? FOR SHARE NOWAIT',[$actor['club_id'],$id,$version])->fetch();
            if(!$row)throw new DomainException('Ursprüngliche persönliche Preisfassung nicht verfügbar.',404);
            return['id'=>$id,'tab'=>$tab,'version'=>$version,'actor_id'=>$row['actor_id'],'request_key'=>$row['request_key'],'happened_at'=>$row['created_at'],'data'=>json_decode($row['state_json'],true,64,JSON_THROW_ON_ERROR),'payload_json'=>$row['state_json'],'sha256'=>hash('sha256',$row['state_json'])];
        });
    }
}
