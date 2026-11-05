<?php declare(strict_types=1);
namespace Kulturhus\Verein;
use DomainException;use PDO;
use Kulturhus\Verein\Infrastructure\Database\Transactions;

/** Source-bound commands classify actual payments; they never submit a transfer. */
final class ReimbursementCommands
{
    public function __construct(private PDO $db,private string $actor){}
    private function query(string $sql,array $args=[]):\PDOStatement{$q=$this->db->prepare($sql);$q->execute($args);return$q;}
    private static function pick(array $row,array $keys):array{return array_intersect_key($row,array_flip($keys));}
    public static function claim(array $row):array{return self::pick($row,['id','entry_id','contact_id','contact_name','amount_cents','invoice_recipient','state','version','request_key','created_by','created_at','notes','approved_by','approved_at','paid_transaction_id']);}
    private function member(array $who,array $fields):array
    {
        $contact=ReimbursementActionOriginals::id($fields['reimbursement_contact']??null);
        $member=$this->query("SELECT c.id,c.name,c.version FROM contacts c WHERE c.club_id=? AND c.id=? AND c.kind='person' AND c.archived_at IS NULL AND EXISTS(SELECT 1 FROM memberships m WHERE m.club_id=c.club_id AND m.contact_id=c.id) FOR SHARE NOWAIT",[$who['club_id'],$contact])->fetch();
        if(!$member||$member['id']!==$contact)throw new DomainException('Ein Mitglied für die private Vorleistung auswählen.',422);
        if(!isset(ExpenseReimbursements::RECIPIENTS[$fields['invoice_recipient']??'']))throw new DomainException('Rechnungsempfänger prüfen.',422);
        if(!is_string($fields['notes']??null)||!mb_check_encoding($fields['notes'],'UTF-8')||strlen($fields['notes'])>2000)throw new DomainException('Erstattungsnotiz vollständig prüfen (höchstens 2000 Byte).',422);
        return$member;
    }
    private function current(string $entry,string $operation,array $fields,bool $versions=true):array
    {
        $who=Permissions::finance($this->db,$this->actor,true);ReimbursementActionOriginals::requireOperation($this->db,$operation);$finance=new Finance($this->db,$this->actor);
        $this->query('SELECT id FROM finance_entries WHERE club_id=? AND id=? FOR UPDATE NOWAIT',[$who['club_id'],$entry]);$e=$finance->currentEntry($entry);
        if($e['id']!==$entry||($versions&&(string)$e['version']!==($fields['entry_version']??null)))throw new DomainException('Ausgabe, Mitglied oder Zahlung inzwischen geändert. Angaben erneut prüfen.',409);
        $service=new ExpenseReimbursements($this->db,$this->actor);
        if($e['kind']!=='expense'||($e['status']==='cancelled'&&$operation!=='cancel')||$e['reversed_on']||$service->isCredit($entry))throw new DomainException('Aktive ursprüngliche Ausgabe für die Erstattung öffnen.',409);
        $this->query('SELECT id FROM finance_reimbursement_claims WHERE club_id=? AND entry_id=? FOR UPDATE NOWAIT',[$who['club_id'],$entry]);$claim=$service->forEntry($entry);
        $review=['version'=>1,'entry'=>self::pick($e,['id','version','description','counterparty','document_date','amount_cents','allocated_cents','remaining_cents','payment_state','status']),'claim'=>$claim?self::claim($claim):null];
        if($operation==='create'){
            if($claim||!in_array($e['payment_state'],['open','partial'],true))throw new DomainException('Für diese Ausgabe kann keine weitere private Vorleistung angelegt werden.',409);
            $review['payment_boundary']=UnpaidExpenseChanges::assertIdle($this->db,$this->actor,$entry,false);
            $review['member']=$this->member($who,$fields);
            InvoiceRecipientTax::assertEntry($this->db,$this->actor,$entry,$fields['invoice_recipient']);
        }elseif(in_array($operation,['correct','cancel','reopen'],true)){
            if(!$claim||$claim['id']!==($fields['claim_id']??null)||($versions&&(string)$claim['version']!==($fields['claim_version']??null)))throw new DomainException('Ausgabe, Mitglied oder Zahlung inzwischen geändert. Angaben erneut prüfen.',409);
            if($claim['state']!==($operation==='reopen'?'cancelled':'open'))throw new DomainException('Nur einen offenen, unbezahlten Erstattungsfall berichtigen oder verwerfen. Eine Freigabe zuerst im Rechnungsprüfschritt zurücknehmen.',409);
            $review['payment_boundary']=UnpaidExpenseChanges::assertIdle($this->db,$this->actor,$entry);
            $reason=$fields['reason']??null;
            if(!is_string($reason)||!mb_check_encoding($reason,'UTF-8')||trim($reason)===''||mb_strlen($reason,'UTF-8')>500||preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/',$reason))throw new DomainException('Begründung vollständig angeben (höchstens 500 Zeichen).',422);
            if($operation==='cancel'){
                if(($fields['reimbursement_mistake']??null)!=='1')throw new DomainException('Die irrtümliche Erfassung ausdrücklich bestätigen. Ein Aufwandsverzicht ist ein eigener Fachweg.',422);
            }else{
                $review['member']=$this->member($who,$fields);InvoiceRecipientTax::assertEntry($this->db,$this->actor,$entry,$fields['invoice_recipient']);$review['new_amount_cents']=(int)$e['amount_cents'];
                if($operation==='correct'&&$claim['contact_id']===$fields['reimbursement_contact']&&$claim['invoice_recipient']===$fields['invoice_recipient']&&$claim['notes']===trim($fields['notes'])&&(int)$claim['amount_cents']===(int)$e['amount_cents'])throw new DomainException('Es liegen keine geänderten Erstattungsangaben vor.',422);
            }
        }elseif($operation==='associate'){
            if(!$claim||$claim['id']!==($fields['claim_id']??null)||$claim['state']!=='approved'||(int)$claim['amount_cents']!==(int)$e['amount_cents'])throw new DomainException('Aktuell freigegebene ursprüngliche Erstattung prüfen.',409);
            $transaction=ReimbursementActionOriginals::id($fields['transaction_id']??null);$t=$finance->transactionDetail($transaction,true);
            if($t['id']!==$transaction||($versions&&((string)$t['version']!==($fields['transaction_version']??null)||(string)$claim['version']!==($fields['claim_version']??null))))throw new DomainException('Ausgabe, Mitglied oder Zahlung inzwischen geändert. Angaben erneut prüfen.',409);
            if((int)$t['cents']>=0||$t['reversal_of']||$t['reversed_on'])throw new DomainException('Aktuellen ausgehenden Originalumsatz auswählen.',409);
            $amount=Finance::cents($fields['amount']);$linked=0;
            $allocations=$finance->allocations($entry,false,true);$funding=$finance->allocationFundingHistory($entry);
            foreach($allocations as$l)if(!$l['voided_at']&&!$l['reversed_on']&&$l['transaction_id']===$transaction)$linked+=(int)($funding[$l['id']]['paid_cents']??$l['cents']);
            if($amount<0||$amount>(int)$t['remaining_cents']||$amount>(int)$e['remaining_cents']||($amount===0&&($linked===0||(int)$e['remaining_cents']!==0)))throw new DomainException('Zuordnungsbetrag und offenen Zahlungsstand prüfen.',422);
            $review['transaction']=self::pick($t,['id','version','booked_on','counterparty','description','cents','remaining_cents','account_id','account_name','account_kind','source','recorded_source']);
            $review['amount_cents']=$amount;$review['already_assigned_cents']=$linked;
            $returnedFunding=array_filter($funding,static fn(array$f):bool=>($f['returned_cents']??0)>0);if($returnedFunding)$review['funding']=$returnedFunding;
            $review['allocations']=array_map(static fn(array$l):array=>self::pick($l,['id','transaction_id','entry_id','cents','voided_at','void_reason','booked_on','transaction_version','reversal_of','reversed_on']),$allocations);
        }else throw new DomainException('Erstattungsart prüfen.',422);
        return$review;
    }
    public function review(string $entry,string $operation,array $fields,bool $refresh=false):array
    {
        return Transactions::run($this->db,fn():array=>$this->current($entry,$operation,$fields,!$refresh));
    }
    public function execute(string $entry,string $operation,string $key,array $fields,string $expectedHash):array
    {
        return Transactions::run($this->db,function()use($entry,$operation,$key,$fields,$expectedHash):array{
            $who=Permissions::finance($this->db,$this->actor,true);
            // Lock the original first. A competing command cannot create a second claim or allocation.
            $this->query('SELECT id FROM finance_entries WHERE club_id=? AND id=? FOR UPDATE NOWAIT',[$who['club_id'],$entry]);
            $first=ReimbursementActionOriginals::read($this->db,$this->actor,$entry,$key);
            if($first){if($first['actor']!==$this->actor||$first['operation']!==$operation||$first['request']['fields']!==$fields)throw new DomainException('Diese ursprüngliche Kennung wurde mit anderen Angaben gespeichert. Gespeicherten Ausgang lesen.',409);return$first;}
            $review=$this->current($entry,$operation,$fields);
            if(!hash_equals(hash('sha256',ReimbursementActionOriginals::json($review)),$expectedHash))throw new DomainException('Ausgabe, Mitglied oder Zahlung inzwischen geändert. Angaben erneut prüfen.',409);
            $service=new ExpenseReimbursements($this->db,$this->actor);$finance=new Finance($this->db,$this->actor);
            if($operation==='create')$id=$service->createForEntry($entry,$fields['reimbursement_contact'],$fields['invoice_recipient'],$key,$fields['notes']);
            elseif(in_array($operation,['correct','cancel','reopen'],true)){
                $id=$review['claim']['id'];
                if($operation==='cancel')$changed=$this->query("UPDATE finance_reimbursement_claims SET state='cancelled',approved_by=NULL,approved_at=NULL,paid_transaction_id=NULL,version=version+1 WHERE club_id=? AND id=? AND version=?",[$who['club_id'],$id,$review['claim']['version']]);
                else $changed=$this->query("UPDATE finance_reimbursement_claims SET contact_id=?,amount_cents=?,invoice_recipient=?,notes=?,state='open',approved_by=NULL,approved_at=NULL,paid_transaction_id=NULL,version=version+1 WHERE club_id=? AND id=? AND version=?",[$fields['reimbursement_contact'],(int)$review['entry']['amount_cents'],$fields['invoice_recipient'],trim($fields['notes']),$who['club_id'],$id,$review['claim']['version']]);
                if($changed->rowCount()!==1)throw new DomainException('Ausgabe, Mitglied oder Zahlung inzwischen geändert. Angaben erneut prüfen.',409);
                $this->query('INSERT INTO audit_events(club_id,actor_id,action,object_id,object_version,happened_at)VALUES(?,?,?,?,?,?)',[$who['club_id'],$who['id'],'finance.reimbursement.'.match($operation){'correct'=>'corrected','cancel'=>'cancelled','reopen'=>'reopened'},$id,(int)$review['claim']['version']+1,gmdate('c')]);
            }else{
                $id=$review['claim']['id'];$amount=$review['amount_cents'];
                if($amount>0)$finance->allocateWithinTransaction($review['transaction']['id'],(int)$review['transaction']['version'],$entry,(int)$review['entry']['version'],Finance::decimal($amount),$key);
                $e=$finance->currentEntry($entry);$paid=(int)$e['remaining_cents']===0;
                $paidFunding=$finance->allocationFundingHistory($entry);$active=array_values(array_filter($finance->allocations($entry,false,true),static fn(array$l):bool=>!$l['voided_at']&&!$l['reversed_on']&&(int)($paidFunding[$l['id']]['paid_cents']??$l['cents'])>0));
                // As for finance_entries.paid_on, the legacy pointer denotes the latest
                // completing movement. The immutable result retains every allocation.
                usort($active,static fn(array$a,array$b):int=>[$a['booked_on'],$a['transaction_id'],$a['id']]<=>[$b['booked_on'],$b['transaction_id'],$b['id']]);$last=$active?end($active):null;
                if($paid&&!$last)throw new DomainException('Ursprüngliche Zahlungsnachweise fehlen.',409);
                $changed=$this->query('UPDATE finance_reimbursement_claims SET state=?,paid_transaction_id=?,version=version+1 WHERE club_id=? AND id=? AND version=?',[$paid?'paid':'approved',$paid?$last['transaction_id']:null,$who['club_id'],$id,$review['claim']['version']]);
                if($changed->rowCount()!==1)throw new DomainException('Ausgabe, Mitglied oder Zahlung inzwischen geändert. Angaben erneut prüfen.',409);
                $this->query('INSERT INTO audit_events(club_id,actor_id,action,object_id,object_version,happened_at)VALUES(?,?,?,?,?,?)',[$who['club_id'],$who['id'],$paid?'finance.reimbursement.paid':'finance.reimbursement.partially_assigned',$id,(int)$review['claim']['version']+1,gmdate('c')]);
            }
            if($operation!=='associate'){
                // A payee change invalidates old invoice reviews without changing their
                // snapshots or turning approval's own claim-state update into a change.
                $changed=$this->query('UPDATE finance_entries SET version=version+1 WHERE club_id=? AND id=? AND version=?',[$who['club_id'],$entry,$review['entry']['version']]);
                if($changed->rowCount()!==1)throw new DomainException('Ausgabe, Mitglied oder Zahlung inzwischen geändert. Angaben erneut prüfen.',409);
            }
            $result=['version'=>1,'claim'=>self::claim($service->forEntry($entry)),'entry'=>self::pick($finance->currentEntry($entry),['id','version','amount_cents','allocated_cents','remaining_cents','payment_state']),'allocations'=>array_map(static fn(array$l):array=>self::pick($l,['id','transaction_id','entry_id','cents','voided_at','booked_on','reversed_on']),$finance->allocations($entry,false,true))];
            $resultFunding=array_filter($finance->allocationFundingHistory($entry),static fn(array$f):bool=>($f['returned_cents']??0)>0);if($resultFunding)$result['funding']=$resultFunding;
            $request=['version'=>1,'actor_id'=>$who['id'],'source'=>['entry'=>$entry,'operation'=>$operation,'key'=>$key],'fields'=>$fields];
            return ReimbursementActionOriginals::store($this->db,$this->actor,$entry,$id,$operation,$key,$request,$review,$result);
        });
    }
    /** Search names and identifiers literally; current finance access is sufficient. */
    public function members(string $search='',int $page=1):array
    {
        $who=Permissions::finance($this->db,$this->actor);if(strlen($search)>200||!mb_check_encoding($search,'UTF-8')||$page<1||$page>1000000)throw new DomainException('Mitgliedersuche prüfen.',422);
        $where="c.club_id=? AND c.archived_at IS NULL AND c.kind='person' AND EXISTS(SELECT 1 FROM memberships m WHERE m.club_id=c.club_id AND m.contact_id=c.id) AND (INSTR(LOWER(c.name),LOWER(?))>0 OR INSTR(c.id,?)>0)";$args=[$who['club_id'],$search,$search];
        $count=(int)$this->query('SELECT COUNT(*) FROM contacts c WHERE '.$where,$args)->fetchColumn();$pages=max(1,(int)ceil($count/20));$page=min($page,$pages);
        $rows=$this->query('SELECT c.id,c.name,c.version FROM contacts c WHERE '.$where.' ORDER BY c.name,c.id LIMIT 20 OFFSET '.(($page-1)*20),$args)->fetchAll();return compact('rows','count','page','pages');
    }
    public function candidates(string $entry,string $search='',int $page=1):array
    {
        $who=Permissions::finance($this->db,$this->actor);$e=(new Finance($this->db,$this->actor))->entry($entry);if($e['kind']!=='expense'||strlen($search)>200||!mb_check_encoding($search,'UTF-8')||$page<1||$page>1000000)throw new DomainException('Umsatzsuche prüfen.',422);
        $consumed=PaymentFunding::consumedSql($this->db);$net=PaymentFunding::remainingSql($this->db,'l');$linked="EXISTS(SELECT 1 FROM finance_allocations l WHERE l.club_id=t.club_id AND l.transaction_id=t.id AND l.entry_id=? AND l.voided_at IS NULL AND $net>0)";
        $where="t.club_id=? AND t.cents<0 AND t.reversal_of IS NULL AND NOT EXISTS(SELECT 1 FROM finance_transactions r WHERE r.club_id=t.club_id AND r.reversal_of=t.id) AND (ABS(t.cents)-$consumed>0 OR (?=0 AND $linked)) AND (INSTR(LOWER(t.description),LOWER(?))>0 OR INSTR(LOWER(t.counterparty),LOWER(?))>0 OR INSTR(t.id,?)>0)";$args=[$who['club_id'],(int)$e['remaining_cents'],$entry,$search,$search,$search];
        $count=(int)$this->query('SELECT COUNT(*) FROM finance_transactions t WHERE '.$where,$args)->fetchColumn();$pages=max(1,(int)ceil($count/6));$page=min($page,$pages);
        $rows=$this->query('SELECT t.id,t.version,t.booked_on,t.counterparty,t.description,t.cents,a.name AS account_name,ABS(t.cents)-'.$consumed.' AS remaining_cents,(SELECT COALESCE(SUM('.$net.'),0) FROM finance_allocations l WHERE l.club_id=t.club_id AND l.transaction_id=t.id AND l.entry_id=? AND l.voided_at IS NULL) AS linked_cents FROM finance_transactions t JOIN finance_accounts a ON a.club_id=t.club_id AND a.id=t.account_id WHERE '.$where.' ORDER BY t.booked_on DESC,t.id LIMIT 6 OFFSET '.(($page-1)*6),array_merge([$entry],$args))->fetchAll();return compact('rows','count','page','pages');
    }
}
