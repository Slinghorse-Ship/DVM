<?php declare(strict_types=1);
namespace Kulturhus\Verein;

/** Classifies recorded money only. Never fetches or submits bank transactions. */
trait AutomaticMatching
{
    private function autoMatchLocked(string $id):bool
    {
        $a=$this->actor(true);$t=$this->transactionDetail($id);
        if($t['reversal_of']||$t['reversed_on']||$t['allocated_cents']||$t['remaining_cents']<=0)return false;
        // A manually released association must not be silently restored.
        if($this->allocations($id,true))return false;
        $q=$this->db->prepare('SELECT id,display_number FROM finance_entries WHERE club_id=? LIMIT 10001');$q->execute([$a['club_id']]);$rows=$q->fetchAll();
        if(count($rows)>10000)return false;
        $matches=[];
        foreach($rows as $row){$ref=$row['display_number'];if($ref!==''&&preg_match('/(?<![\pL\pN_-])'.preg_quote($ref,'/').'(?![\pL\pN_-])/iu',$t['description']))$matches[]=$row['id'];}
        // A supplier reference is only meaningful together with the explicitly linked company.
        // No fuzzy name matching and no guessed counterparty IBAN from free-form text.
        if(!$matches&&$t['cents']<0){
            $normaliseSupplier=static fn(string $v):string=>mb_strtolower(trim(preg_replace('/[^\pL\pN]+/u',' ',$v)),'UTF-8');
            $payer=$normaliseSupplier((string)$t['counterparty']);
            $q=$this->db->prepare("SELECT e.id,e.notes,s.supplier_name,s.contact_id FROM finance_entries e JOIN finance_supplier_contacts s ON s.club_id=e.club_id AND s.entry_id=e.id WHERE e.club_id=? AND e.kind='expense' AND e.status='open' LIMIT 10001");$q->execute([$a['club_id']]);$supplierEntries=$q->fetchAll();if(count($supplierEntries)>10000)return false;
            foreach($supplierEntries as $row){
                if($payer===''||$payer!==$normaliseSupplier($row['supplier_name']))continue;
                if(preg_match_all('/^Lieferanten-Rechnungsnummer: ([^\r\n]+)$/m',$row['notes'],$references)!==1)continue;
                $reference=trim($references[1][0]);if(mb_strlen($reference)<3||strlen($reference)>120||!preg_match('/(?<![\pL\pN_-])'.preg_quote($reference,'/').'(?![\pL\pN_-])/iu',$t['description']))continue;
                $candidate=$this->entry($row['id']);if($candidate['payment_state']!=='open'||$candidate['allocated_cents']||$candidate['remaining_cents']!==$t['remaining_cents'])continue;
                $matches[]=$row['id'];
            }
        }
        {
            $q=$this->db->prepare('SELECT DISTINCT m.member_number,m.contact_id,c.legacy_member_number,c.name FROM memberships m JOIN contacts c ON c.club_id=m.club_id AND c.id=m.contact_id WHERE m.club_id=? LIMIT 10001');$q->execute([$a['club_id']]);$members=$q->fetchAll();if(count($members)>10000)return false;$people=[];
            foreach($members as $member)foreach([$member['member_number'],$member['legacy_member_number']] as $number)
                if($number!==''&&preg_match('/(?<![\pL\pN_-])'.preg_quote($number,'/').'(?![\pL\pN_-])/iu',$t['description']))$people[$member['contact_id']]=true;
            // Names are a fallback only: explicit invoice/member references take precedence.
            // Check all members, including those without open dues, to detect namesakes.
            if(!$people&&!$matches){
                $normalise=static fn(string $v):string=>mb_strtolower(trim(preg_replace('/[^\pL\pN]+/u',' ',$v)),'UTF-8');
                $payer=$normalise((string)$t['counterparty']);$purpose=$normalise($t['description']);
                foreach($members as $member){
                    $name=$normalise($member['name']);$parts=explode(' ',$name);
                    if(count($parts)<2)continue;
                    $payerParts=explode(' ',$payer);sort($parts);sort($payerParts);
                    if(($payer!==''&&$parts===$payerParts)||str_contains(' '.$purpose.' ',' '.$name.' '))$people[$member['contact_id']]=true;
                }
            }
            if(count($people)>1)return false;
            if($people){
                $q=$this->db->prepare('SELECT DISTINCT entry_id FROM member_dues_people WHERE club_id=? AND contact_id=?');$q->execute([$a['club_id'],array_key_first($people)]);$duesIds=$q->fetchAll(\PDO::FETCH_COLUMN);
                if($matches&&array_diff($matches,$duesIds))return false;
                if(!$matches)foreach($duesIds as $duesId){$candidate=$this->entry($duesId);if($candidate['payment_state']==='open'&&!$candidate['allocated_cents']&&$candidate['remaining_cents']===$t['remaining_cents'])$matches[]=$candidate['id'];}
            }
        }
        if(count($matches)!==1)return false;
        $e=$this->entry($matches[0]);
        if($e['payment_state']!=='open'||$e['allocated_cents']||$e['remaining_cents']!==$t['remaining_cents']||(($t['cents']<0)!==($e['kind']==='expense')))return false;
        $key=substr(hash('sha256','auto-allocation:'.$a['club_id'].':'.$id.':'.$e['id']),0,32);
        $allocation=$this->insertAllocation($id,(int)$t['version'],$e['id'],(int)$e['version'],self::decimal($t['remaining_cents']),$key);
        $this->audit($a,'finance.allocation.automatic',$allocation,1);return true;
    }
    public function matchRecordedTransactions(string $account='',?array $transactions=null):int
    {
        $this->actor(true);$count=0;
        if($transactions!==null){
            if(!$transactions||count($transactions)>40)throw new \DomainException('Eine Seite mit höchstens 40 Umsätzen auswählen.');
            if($account!=='')$this->account($account);$rows=[];$seen=[];
            // Validate the entire explicit selection before classifying any money.
            foreach($transactions as $id){
                if(!is_string($id)||$id===''||strlen($id)>100||isset($seen[$id]))throw new \DomainException('Umsatzauswahl prüfen.');
                $row=$this->transactionDetail($id);if($account!==''&&$row['account_id']!==$account)throw new \DomainException('Umsatz gehört nicht zum ausgewählten Konto.');
                $seen[$id]=true;$rows[]=['id'=>$id];
            }
        }else $rows=$this->transactions(['available'=>true,'account'=>$account]);
        foreach($rows as $row){$count+=(int)$this->transaction(fn()=>$this->autoMatchLocked($row['id']));DonationPreparation::afterTransaction($this->db,$this->actorId,$row['id']);}
        return $count;
    }
}
