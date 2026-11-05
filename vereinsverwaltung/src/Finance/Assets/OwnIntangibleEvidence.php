<?php declare(strict_types=1);
namespace Kulturhus\Verein;
use PDO;use DomainException;
require_once __DIR__.'/OwnIntangibleOriginalSources.php';require_once __DIR__.'/OwnIntangibleBookBasis.php';require_once __DIR__.'/OwnIntangibleTaxExclusion.php';require_once __DIR__.'/OwnIntangibleJournals.php';require_once __DIR__.'/OwnIntangibleBookJournals.php';require_once __DIR__.'/AssetValuationEvidence.php';
/** Original byte/revision/club checks and live complete journal readback. No writes. */
final class OwnIntangibleEvidence {
 public function __construct(private PDO $db,private string $actor){}
 private function need(bool $ok):void{if(!$ok)throw new DomainException('Gespeicherte Originalquellen selbst geschaffener Werte fehlen oder wurden verändert.');}
 private function q(string $sql,array $args=[]):\PDOStatement{return DocumentCatalog::query($this->db,$sql,$args);}
 public function checkSources(array $proof,bool $lock=false,int $depth=0):void{
  $this->need($depth>=0&&$depth<2&&OwnIntangibleOriginalSources::fingerprint(OwnIntangibleOriginalSources::calculate($proof['source'],$proof['previous']))===OwnIntangibleOriginalSources::fingerprint($proof));$s=$proof['source'];$a=Permissions::actor($this->db,$this->actor);$this->need($a['club_id']===$s['club_id']&&in_array($a['finance_access'],$lock?['write']:['read','write'],true)&&(!$lock||$this->db->inTransaction()));
  $i=$this->q('SELECT ownership,cash_account FROM inventory_items WHERE club_id=? AND id=?'.($lock?' FOR UPDATE':''),[$a['club_id'],$s['item_id']])->fetch();$this->need($i&&$i['ownership']==='club'&&$i['cash_account']===null);
  $e=new AssetValuationEvidence($this->db,$this->actor);$e->check($s['book_document'],$lock);
  foreach($s['costs'] as $cost){
   $e->check($cost['document'],$lock);$year=(int)substr($s['period']['start'],0,4);
   $row=$this->q('SELECT booked_on,posting_lines,reversal_of FROM balance_entries WHERE club_id=? AND tax_year=? AND id=?'.($lock?' FOR UPDATE':''),[$a['club_id'],$year,$cost['journal_id']])->fetch();
   $this->need((bool)$row&&$row['reversal_of']===null&&!$this->q('SELECT 1 FROM balance_entries WHERE club_id=? AND tax_year=? AND reversal_of=?'.($lock?' FOR UPDATE':''),[$a['club_id'],$year,$cost['journal_id']])->fetchColumn());
   $lines=json_decode($row['posting_lines'],true,512,JSON_THROW_ON_ERROR);$line=$lines[$cost['line_ordinal']]??null;
   $yr=$this->q('SELECT accounts FROM balance_years WHERE club_id=? AND tax_year=?'.($lock?' FOR UPDATE':''),[$a['club_id'],$year])->fetchColumn();$this->need(is_string($yr));$chart=json_decode($yr,true,512,JSON_THROW_ON_ERROR);
   $this->need($row['booked_on']===$cost['on']&&is_array($line)&&$line['debit_cents']===$cost['booked_cents']&&$line['credit_cents']===0&&($chart[$line['account']]['group']??null)==='expense'&&!in_array($chart[$line['account']]['skr_code']??null,['62010','62110','68960'],true));
  }
  if($proof['previous']!==null)$this->checkSources($proof['previous'],$lock,$depth+1);
 }
 public function checkBook(array $proof,bool $lock=false,int $depth=0):void{
  $this->need($depth>=0&&$depth<2&&OwnIntangibleBookBasis::fingerprint(OwnIntangibleBookBasis::calculate($proof['source_proof'],$proof['book_source'],$proof['previous']))===OwnIntangibleBookBasis::fingerprint($proof));$this->checkSources($proof['source_proof'],$lock);$e=new AssetValuationEvidence($this->db,$this->actor);$e->check($proof['book_source']['document'],$lock);foreach($proof['events'] as $event)$e->check($event['original']['document'],$lock);
  if($proof['previous']!==null)$this->checkBook($proof['previous'],$lock,$depth+1);
 }
 public function checkJournals(array $proof,bool $lock=false,int $depth=0):void{
  $this->need($depth>=0&&$depth<2&&OwnIntangibleJournals::fingerprint(OwnIntangibleJournals::verify($proof['book_proof'],$proof['journal'],$proof['previous']))===OwnIntangibleJournals::fingerprint($proof));$this->checkBook($proof['book_proof'],$lock);$j=$proof['journal'];$e=new AssetValuationEvidence($this->db,$this->actor);$e->check($j['document'],$lock);$e->check($j['fiscal_source']['document'],$lock);
  $this->actualJournal($proof,$lock,$depth,false);
 }
 public function checkBookJournals(array $proof,bool $lock=false,int $depth=0):void{
  $this->need($depth>=0&&$depth<2&&OwnIntangibleBookJournals::fingerprint(OwnIntangibleBookJournals::verify($proof['book_proof'],$proof['journal'],$proof['previous']))===OwnIntangibleBookJournals::fingerprint($proof));$this->checkBook($proof['book_proof'],$lock);(new AssetValuationEvidence($this->db,$this->actor))->check($proof['journal']['document'],$lock);
  $this->actualJournal($proof,$lock,$depth,true);
 }
 private function actualJournal(array $proof,bool $lock,int $depth,bool $bookOnly):void{
  $j=$proof['journal'];
  $row=$this->q('SELECT state,accounts,opening,result_account,previous_sha256,closed_snapshot,closed_sha256 FROM balance_years WHERE club_id=? AND tax_year=?'.($lock?' FOR UPDATE':''),[$j['club_id'],$j['year']])->fetch();$this->need($row&&$row['state']==='closed'&&is_string($row['closed_snapshot'])&&hash_equals($row['closed_sha256'],hash('sha256',$row['closed_snapshot'])));
  $closed=json_decode($row['closed_snapshot'],true,512,JSON_THROW_ON_ERROR);$annual=$proof['book_calculation'];unset($annual['period']);$this->need(BalanceCalculation::chart(json_decode($row['accounts'],true,512,JSON_THROW_ON_ERROR))===BalanceCalculation::chart($j['chart'])&&json_decode($row['opening'],true,512,JSON_THROW_ON_ERROR)===$j['book_opening']&&$row['result_account']===$j['result_account']&&($closed['calculation']??null)===$annual);
  $actual=$this->q('SELECT id,booked_on,description,evidence,posting_lines,reversal_of FROM balance_entries WHERE club_id=? AND tax_year=? ORDER BY booked_on,id'.($lock?' FOR UPDATE':''),[$j['club_id'],$j['year']])->fetchAll();$given=[];foreach($j['book_entries'] as $entry)$given[$entry['id']]=$entry;$closedEntries=[];foreach($closed['entries']??[] as $entry)$closedEntries[$entry['id']]=$entry;$this->need(count($actual)===count($given)&&count($actual)===count($closedEntries));
  foreach($actual as $entry){$g=$given[$entry['id']]??null;$c=$closedEntries[$entry['id']]??null;$lines=json_decode($entry['posting_lines'],true,512,JSON_THROW_ON_ERROR);$this->need(is_array($g)&&is_array($c)&&$g['date']===$entry['booked_on']&&$g['lines']===$lines&&$c['date']===$entry['booked_on']&&$c['lines']===$lines&&($c['description']??null)===$entry['description']&&($c['evidence']??null)===$entry['evidence']&&($c['reversal_of']??null)===$entry['reversal_of']);}
  if($proof['previous']!==null){if($bookOnly)$this->checkBookJournals($proof['previous'],$lock,$depth+1);else $this->checkJournals($proof['previous'],$lock,$depth+1);$prior=$this->q('SELECT closed_sha256 FROM balance_years WHERE club_id=? AND tax_year=?'.($lock?' FOR UPDATE':''),[$j['club_id'],$proof['previous']['journal']['year']])->fetchColumn();$this->need($row['previous_sha256']===$prior&&($closed['previous_sha256']??null)===$prior);}
 }
}
