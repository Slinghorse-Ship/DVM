<?php declare(strict_types=1);
namespace Kulturhus\Verein\Settings;
use DomainException;
use PDO;
use Kulturhus\Verein\{Permissions,Finance,CatalogBilling,TaxCatalog};
use Kulturhus\Native\Shared\Db;

/** Current permission, source, accounting and actual earlier financial records. */
final class BillingRepository
{
    public function __construct(private PDO $db,private string $actorId){}
    public function read(callable $operation):mixed{return(new AssignmentRepository($this->db,$this->actorId))->read($operation);}
    public function actor(bool $write=false):array
    {
        if(!$this->db->inTransaction())return$this->read(fn():array=>$this->actor($write));$actor=Permissions::actor($this->db,$this->actorId);
        if(!in_array($actor['finance_access'],$write?['write']:['read','write'],true)||!in_array($actor['members_access'],['read','write'],true))throw new DomainException('Aktuelle Finanz- und Mitgliederrechte für Tarifabrechnungen erforderlich.',403);return$actor;
    }
    public function sources(array $input):array
    {
        return$this->read(function()use($input):array{
            $this->actor();$normalized=BillingInput::normalize($input);$repo=new PersonalPriceRepository($this->db,$this->actorId);$sources=[];
            $accounts=(new Finance($this->db,$this->actorId))->taxAccounts(TaxCatalog::assignmentYear(TaxCatalog::documentYear($normalized['date'])));
            foreach($input['assignments']as$id){$state=$repo->state($id,$input['tab']);$code=(string)$state['source']['catalog']['data']['tax_account'];$account=$code===''?null:($accounts[$code]??null);if($code!==''&&(!$account||!($account['active']??false)||($account['blocked']??false)))throw new DomainException('Aktives ursprüngliches Steuerkonto vor der Abrechnung prüfen.',422);$sources[]=$state['source']+['personal_price'=>$state,'tax_account'=>$account];}
            $quotes=(new CatalogBilling($this->db,$this->actorId))->review($input['assignments'],$normalized['date'],$normalized['mode'],$normalized['price_day']);return['sources'=>$sources,'quotes'=>$quotes];
        });
    }
    public function contact(string $id):array{return(new AssignmentRepository($this->db,$this->actorId))->contact($id);}
    public function actual(string $id):array
    {
        return$this->read(function()use($id):array{
            $actor=$this->actor();CatalogInput::id($id);
            $entry=Db::run($this->db,'SELECT * FROM finance_entries WHERE club_id=? AND id=? FOR SHARE NOWAIT',[$actor['club_id'],$id])->fetch();if(!$entry)throw new DomainException('Tatsächlichen eigenen Tarifbeleg auswählen.',404);
            $charge=Db::run($this->db,'SELECT * FROM native_catalog_charges WHERE club_id=? AND entry_id=? FOR SHARE NOWAIT',[$actor['club_id'],$id])->fetch();if(!$charge)throw new DomainException('Tatsächlichen eigenen Tarifbeleg auswählen.',404);
            $positions=Db::run($this->db,'SELECT * FROM accounting_positions WHERE club_id=? AND entry_id=? ORDER BY ordinal FOR SHARE NOWAIT',[$actor['club_id'],$id])->fetchAll();return['entry'=>$entry,'positions'=>$positions,'charge'=>$charge];
        });
    }
}
