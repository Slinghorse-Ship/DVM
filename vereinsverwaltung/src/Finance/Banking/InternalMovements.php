<?php declare(strict_types=1);
namespace Kulturhus\Verein;

use DomainException;

/** Classifies actual own-account movements. Never submits a bank payment. */
trait InternalMovements
{
    public const INTERNAL_ALLOCATED="COALESCE((SELECT SUM(m.cents) FROM finance_internal_movements m WHERE m.club_id=t.club_id AND (m.outgoing_id=t.id OR m.incoming_id=t.id) AND m.voided_at IS NULL),0)";

    private function lockMovements(array $ids):void
    {
        $a=$this->actor(true);sort($ids,SORT_STRING);
        foreach(array_unique($ids) as $id){
            $q=$this->db->prepare('SELECT account_id FROM finance_transactions WHERE club_id=? AND id=? FOR UPDATE NOWAIT');
            $q->execute([$a['club_id'],$id]);
            $account=$q->fetchColumn();if(!$account)throw new DomainException('Kontoumsatz nicht verfügbar.');
            $this->lockMovementAccount($account);
        }
    }

    private function lockMovementAccount(string $id):void
    {
        $a=$this->actor(true);$q=$this->db->prepare('SELECT id FROM finance_accounts WHERE club_id=? AND id=? FOR UPDATE NOWAIT');$q->execute([$a['club_id'],$id]);
        if(!$q->fetchColumn())throw new DomainException('Eigenes Konto nicht verfügbar.');
    }

    public function internalMovements(string $transaction):array
    {
        $a=$this->actor();$this->transactionDetail($transaction);
        $q=$this->db->prepare('SELECT m.*,o.account_id AS outgoing_account,i.account_id AS incoming_account,oa.name AS outgoing_name,ia.name AS incoming_name FROM finance_internal_movements m JOIN finance_transactions o ON o.club_id=m.club_id AND o.id=m.outgoing_id JOIN finance_transactions i ON i.club_id=m.club_id AND i.id=m.incoming_id JOIN finance_accounts oa ON oa.club_id=o.club_id AND oa.id=o.account_id JOIN finance_accounts ia ON ia.club_id=i.club_id AND ia.id=i.account_id WHERE m.club_id=? AND (m.outgoing_id=? OR m.incoming_id=?) ORDER BY m.created_at,m.id');
        $q->execute([$a['club_id'],$transaction,$transaction]);return $q->fetchAll();
    }

    /** Partial transfers allow a withdrawal fee to remain assigned to its own expense. */
    public function linkInternalMovement(string $source,int $version,string $other,int $otherVersion,string $amount,string $reason,string $key):string
    {
        return $this->transaction(function()use($source,$version,$other,$otherVersion,$amount,$reason,$key){
            $this->lockMovements([$source,$other]);
            return $this->insertInternalMovement($source,$version,$other,$otherVersion,$amount,$reason,$key);
        });
    }

    private function insertInternalMovement(string $source,int $version,string $other,int $otherVersion,string $amount,string $reason,string $key):string
    {
        $a=$this->actor(true);self::requestKey($key);$cents=self::cents($amount);
        $reason=self::field(['reason'=>$reason],'reason',500,true);
        $t=$this->transactionDetail($source,true);$u=$this->transactionDetail($other,true);
        $out=$t['cents']<0?$t:$u;$in=$t['cents']<0?$u:$t;
        $q=$this->db->prepare('SELECT * FROM finance_internal_movements WHERE club_id=? AND request_key=? FOR UPDATE NOWAIT');$q->execute([$a['club_id'],$key]);
        if($old=$q->fetch()){
            if($old['outgoing_id']!==$out['id']||$old['incoming_id']!==$in['id']||(int)$old['cents']!==$cents||$old['reason']!==$reason||$old['voided_at'])throw new DomainException('Diese Transferkennung wurde bereits verwendet. Gespeicherten Stand prüfen.');
            return $old['id'];
        }
        if($source===$other||$t['account_id']===$u['account_id']||$out['cents']>=0||$in['cents']<=0)throw new DomainException('Abgang und Zugang auf zwei verschiedenen eigenen Konten wählen.');
        foreach([$t,$u] as $row){
            if($row['reversal_of']||$row['reversed_on'])throw new DomainException('Gegengebuchte Umsätze können nicht als Geldtransfer zugeordnet werden.');
            if($row['account_kind']==='cash')$this->assertUnclosed($row['account_id'],$row['booked_on']);
        }
        if((int)$t['version']!==$version||(int)$u['version']!==$otherVersion)throw new DomainException('Ein Umsatz wurde inzwischen geändert. Aktuellen Stand neu prüfen.');
        if($cents<=0||$cents>$t['remaining_cents']||$cents>$u['remaining_cents'])throw new DomainException('Transferbetrag darf die offenen Beträge beider Umsätze nicht übersteigen.');
        $id=bin2hex(random_bytes(16));
        $this->db->prepare('INSERT INTO finance_internal_movements(club_id,id,outgoing_id,incoming_id,cents,reason,request_key,created_by,created_at) VALUES(?,?,?,?,?,?,?,?,?)')->execute([$a['club_id'],$id,$out['id'],$in['id'],$cents,$reason,$key,$a['id'],gmdate('c')]);
        $this->db->prepare('UPDATE finance_transactions SET version=version+1 WHERE club_id=? AND id IN (?,?)')->execute([$a['club_id'],$source,$other]);
        $this->audit($a,'finance.internal.created',$id,1);return $id;
    }

    /** Explicitly record cash actually received/handed over; the bank movement already exists. */
    public function recordCashMovement(string $source,int $version,string $cashAccount,string $date,string $amount,string $reason,string $key):string
    {
        return $this->transaction(function()use($source,$version,$cashAccount,$date,$amount,$reason,$key){
            self::requestKey($key);$this->lockMovements([$source]);$t=$this->transactionDetail($source,true);
            if($t['account_kind']==='cash'||$this->account($cashAccount)['kind']!=='cash')throw new DomainException('Vorhandenen Bankumsatz und tatsächliche Handkasse wählen.');
            $reason=self::field(['reason'=>$reason],'reason',500,true);
            // insertTransaction is idempotent and deliberately does not auto-match a cash transfer to an invoice.
            $other=$this->insertTransaction(['account'=>$cashAccount,'booked_on'=>$date,'direction'=>$t['cents']<0?'income':'expense','amount'=>$amount,'description'=>$reason,'counterparty'=>$t['account_name']],substr(hash('sha256','internal-cash:'.$key),0,32));
            $u=$this->transactionDetail($other,true);
            return $this->insertInternalMovement($source,$version,$other,(int)$u['version'],$amount,$reason,$key);
        });
    }

    public function releaseInternalMovement(string $id,string $reason):void
    {
        $this->transaction(function()use($id,$reason){
            $a=$this->actor(true);$reason=self::field(['reason'=>$reason],'reason',500,true);
            $q=$this->db->prepare('SELECT * FROM finance_internal_movements WHERE club_id=? AND id=? FOR UPDATE NOWAIT');$q->execute([$a['club_id'],$id]);$m=$q->fetch();
            if(!$m)throw new DomainException('Geldtransfer nicht verfügbar.');
            if($m['voided_at'])return;
            $this->lockMovements([$m['outgoing_id'],$m['incoming_id']]);
            foreach([$m['outgoing_id'],$m['incoming_id']] as $tx){$t=$this->transactionDetail($tx,true);if($t['account_kind']==='cash')$this->assertUnclosed($t['account_id'],$t['booked_on']);}
            $this->db->prepare('UPDATE finance_internal_movements SET voided_at=?,void_reason=? WHERE club_id=? AND id=?')->execute([gmdate('c'),$reason,$a['club_id'],$id]);
            $this->db->prepare('UPDATE finance_transactions SET version=version+1 WHERE club_id=? AND id IN (?,?)')->execute([$a['club_id'],$m['outgoing_id'],$m['incoming_id']]);
            $this->audit($a,'finance.internal.released',$id,2);
        });
    }
}
