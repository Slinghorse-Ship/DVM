<?php declare(strict_types=1);
namespace Kulturhus\Verein\Settings;
use DomainException;
use PDO;
use Kulturhus\Native\Shared\Db;
use Kulturhus\Verein\{Permissions,Finance,TaxCatalog,Database,CatalogBillingAutomation};

/** Current actual plan, source, raw canonical changes and genuine bounded worker history. */
final class AutomationRepository
{
    public function __construct(private PDO $db,private string $actorId){}
    public function read(callable $operation):mixed{return(new AssignmentRepository($this->db,$this->actorId))->read($operation);}
    public function actor(bool $write=false):array
    {
        if(!$this->db->inTransaction())return$this->read(fn():array=>$this->actor($write));$a=Permissions::actor($this->db,$this->actorId);if(!in_array($a['finance_access'],$write?['write']:['read','write'],true)||!in_array($a['members_access'],['read','write'],true))throw new DomainException('Aktuelle Finanz- und Mitgliederrechte für Tarifautomatiken erforderlich.',403);return$a;
    }
    public function state(string $id,string $tab):array
    {
        CatalogInput::id($id);CatalogRepository::kinds($tab);return$this->read(function()use($id,$tab):array{
            $this->actor();$price=(new PersonalPriceRepository($this->db,$this->actorId))->state($id,$tab);$plan=(new CatalogBillingAutomation($this->db,$this->actorId))->get($id);return['id'=>$id,'tab'=>$tab,'version'=>(int)($plan['version']??0),'canonical'=>$plan,'source'=>$price['source']+['personal_price'=>$price]];
        });
    }
    public static function initial(array $state,string $operation):array
    {
        $plan=$state['canonical'];$first=$plan['next_on']??CatalogBillingAutomation::today();if($operation==='activate')$first=max($first,CatalogBillingAutomation::today());return['operation'=>$operation,'tab'=>$state['tab'],'id'=>$state['id'],'version'=>(string)$state['version'],'data'=>['first'=>$first,'mode'=>$plan['mode']??'full','due_days'=>(string)($plan['due_days']??30)]];
    }
    public function contact(string $id):array{return(new AssignmentRepository($this->db,$this->actorId))->contact($id);}
    public function taxAccount(string $day,string $code):?array
    {
        if($code==='')return null;return$this->read(function()use($day,$code):?array{$this->actor();$accounts=(new Finance($this->db,$this->actorId))->taxAccounts(TaxCatalog::assignmentYear(TaxCatalog::documentYear($day)));return$accounts[$code]??null;});
    }
    private static function page(mixed $page):int
    {
        if((!is_string($page)&&!is_int($page))||!preg_match('/\A[1-9][0-9]{0,8}\z/D',(string)$page))throw new DomainException('Vollständige Automatikverlaufsseite prüfen.',422);return(int)$page;
    }
    public function revisions(string $id,string $tab,mixed $page='1'):array
    {
        $page=self::page($page);return$this->read(function()use($id,$tab,$page):array{
            $this->state($id,$tab);$a=$this->actor();if(!Database::tableExists($this->db,'native_catalog_automation_changes')){if($page!==1)throw new DomainException('Automatikverlaufsseite ist nicht verfügbar.',422);return['rows'=>[],'total'=>0,'page'=>1,'pages'=>1,'size'=>6];}
            $total=(int)Db::run($this->db,'SELECT COUNT(*) FROM native_catalog_automation_changes WHERE club_id=? AND assignment_id=? FOR SHARE NOWAIT',[$a['club_id'],$id])->fetchColumn();$pages=max(1,(int)ceil($total/6));if($page>$pages)throw new DomainException('Automatikverlaufsseite ist nicht verfügbar.',422);$rows=Db::run($this->db,'SELECT version,actor_id,request_key,created_at FROM native_catalog_automation_changes WHERE club_id=? AND assignment_id=? ORDER BY version DESC LIMIT 6 OFFSET '.(($page-1)*6).' FOR SHARE NOWAIT',[$a['club_id'],$id])->fetchAll();return['rows'=>$rows,'total'=>$total,'page'=>$page,'pages'=>$pages,'page_input'=>(string)$page,'size'=>6];
        });
    }
    public function revision(string $id,string $tab,mixed $version):array
    {
        $version=GeneralInput::version($version);if($version<1)throw new DomainException('Ursprüngliche Automatikfassung nicht verfügbar.',404);return$this->read(function()use($id,$tab,$version):array{
            $this->state($id,$tab);$a=$this->actor();if(!Database::tableExists($this->db,'native_catalog_automation_changes'))throw new DomainException('Ursprüngliche Automatikfassung nicht verfügbar.',404);$row=Db::run($this->db,'SELECT * FROM native_catalog_automation_changes WHERE club_id=? AND assignment_id=? AND version=? FOR SHARE NOWAIT',[$a['club_id'],$id,$version])->fetch();if(!$row)throw new DomainException('Ursprüngliche Automatikfassung nicht verfügbar.',404);return['id'=>$id,'tab'=>$tab,'version'=>$version,'actor_id'=>$row['actor_id'],'request_key'=>$row['request_key'],'happened_at'=>$row['created_at'],'data'=>json_decode($row['state_json'],true,64,JSON_THROW_ON_ERROR),'payload_json'=>$row['state_json'],'sha256'=>hash('sha256',$row['state_json'])];
        });
    }
    public function runs(string $id,string $tab,mixed $page='1'):array
    {
        $page=self::page($page);return$this->read(function()use($id,$tab,$page):array{$this->state($id,$tab);return(new CatalogBillingAutomation($this->db,$this->actorId))->history($id,$page)+['page_input'=>(string)$page,'size'=>6];});
    }
    public function scheduledRun(string $id,string $tab,string $day):?array
    {
        return$this->read(function()use($id,$tab,$day):?array{
            $this->state($id,$tab);$a=$this->actor();if(!Database::tableExists($this->db,'native_catalog_automation_runs'))return null;$row=Db::run($this->db,'SELECT * FROM native_catalog_automation_runs WHERE club_id=? AND assignment_id=? AND scheduled_on=? FOR SHARE NOWAIT',[$a['club_id'],$id,$day])->fetch();return$row?($row+['quote'=>json_decode($row['quote_json'],true,64,JSON_THROW_ON_ERROR),'sha256'=>hash('sha256',$row['quote_json'])]):null;
        });
    }
    public function run(string $id,string $tab,mixed $key):array
    {
        if(!is_string($key)||!preg_match('/\A[a-f0-9]{32}\z/D',$key))throw new DomainException('Ursprünglicher Automatiklauf nicht verfügbar.',404);return$this->read(function()use($id,$tab,$key):array{
            $this->state($id,$tab);$a=$this->actor();if(!Database::tableExists($this->db,'native_catalog_automation_runs'))throw new DomainException('Ursprünglicher Automatiklauf nicht verfügbar.',404);$row=Db::run($this->db,'SELECT * FROM native_catalog_automation_runs WHERE club_id=? AND assignment_id=? AND id=? FOR SHARE NOWAIT',[$a['club_id'],$id,$key])->fetch();if(!$row)throw new DomainException('Ursprünglicher Automatiklauf nicht verfügbar.',404);return$row+['quote'=>json_decode($row['quote_json'],true,64,JSON_THROW_ON_ERROR),'sha256'=>hash('sha256',$row['quote_json'])];
        });
    }
}
