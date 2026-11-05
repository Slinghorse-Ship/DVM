<?php declare(strict_types=1);
namespace Kulturhus\Verein;
use DomainException;
use PDO;
require_once __DIR__.'/PartnershipHoldingSources.php';
require_once __DIR__.'/PartnershipHoldingJournals.php';
require_once __DIR__.'/PartnershipHoldingMatrix.php';
require_once __DIR__.'/AssetValuationEvidence.php';
/** Read original bytes, revisions and live permissions; never writes or exports. */
final class PartnershipHoldingEvidence
{
 public function __construct(private PDO $db,private string $actor){}
 public function checkMatrix(array $proof,bool $lock=false,int $depth=0):void{
  if($depth>100||PartnershipHoldingMatrix::reconcile($proof['journal_proof'],$proof['source'],$proof['previous']??null)!==$proof)throw new DomainException('Steuerlichen Original-Anlagenspiegel unverändert nachrechnen.');
  $this->checkJournals($proof['journal_proof'],$lock);(new AssetValuationEvidence($this->db,$this->actor))->check($proof['source']['document'],$lock);
  if($proof['previous']!==null)$this->checkMatrix($proof['previous'],$lock,$depth+1);
 }
 public function checkJournals(array $proof,bool $lock=false,int $depth=0):void{
  if($depth>100||PartnershipHoldingJournals::verify($proof['sources'],$proof['journal'],$proof['previous']??null)!==$proof)throw new DomainException('Ursprünglichen Beteiligungsjournalnachweis unverändert nachrechnen.');
  $this->check($proof['sources'],$lock);(new AssetValuationEvidence($this->db,$this->actor))->check($proof['journal']['document'],$lock);
  if($proof['previous']!==null)$this->checkJournals($proof['previous'],$lock,$depth+1);
 }
 public function check(array $sources,bool $lock=false):void{
  if(PartnershipHoldingSources::reconcile($sources['book_basis'],$sources['capital_basis'],$sources['relationship'])!==$sources)throw new DomainException('Ursprüngliche Beteiligungsquellen wurden verändert.');
  $actor=Permissions::actor($this->db,$this->actor);
  if($actor['club_id']!==$sources['club_id']||!in_array($actor['finance_access'],$lock?['write']:['read','write'],true))throw new DomainException('Finanzberechtigung für genau diesen Verein erforderlich.');
  if($lock&&!$this->db->inTransaction())throw new DomainException('Gesperrte Originalprüfung benötigt einen Speichervorgang.');
  $e=new AssetValuationEvidence($this->db,$this->actor);$e->check($sources['relationship']['document'],$lock);
  $book=$sources['book_basis'];$depth=0;
  do{
   if(++$depth>101)throw new DomainException('Beteiligungsvorträge sind zu umfangreich.');
   $s=$book['source'];$e->check($s['plan']['document'],$lock);
   foreach($s['events'] as $event){$e->check($event['document'],$lock);if(isset($event['allocation_document']))$e->check($event['allocation_document'],$lock);}
   $book=$book['previous'];
  }while($book!==null);
  $capital=$sources['capital_basis'];$depth=0;
  do{
   if(++$depth>101)throw new DomainException('Beteiligungsvorträge sind zu umfangreich.');
   $s=$capital['source'];$e->check($s['document'],$lock);if(isset($s['negative_basis']))$e->check($s['negative_basis']['document'],$lock);foreach($s['movements'] as $event)$e->check($event['document'],$lock);
   $capital=$capital['previous'];
  }while($capital!==null);
 }
}
