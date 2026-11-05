<?php declare(strict_types=1);
namespace Kulturhus\Verein;

use DomainException;

/** Immutable portions of existing money, independently of its receipt allocations. */
trait BankPaymentShares
{
    public static function amounts(array $amounts):array
    {
        $ids=self::ids(array_map('strval',array_keys($amounts)));if(!$ids)throw new DomainException('Mindestens eine vorhandene Bewegung mit ihrem Bankteilbetrag auswählen.',422);$out=[];
        foreach($ids as$id){$value=$amounts[$id];if(!is_string($value)||strlen($value)>32||preg_match('/[\x00-\x1f\x7f]/',$value)||Finance::cents($value)<=0)throw new DomainException('Jeden Bankteilbetrag als positiven Eurobetrag eingeben. Die Bewegungsrichtung bleibt erhalten.',422);$out[$id]=$value;}return $out;
    }
    public function previewShares(string $record,array $amounts,string $reason,string $reuse='',string $duplicate=''):array
    {
        return $this->read(fn()=>$this->reviewSharesLocked($record,$amounts,$reason,$reuse,$duplicate));
    }
    private function portionSourceLocked(string $record,array $amounts,string $reason,string $reuse='',string $duplicate=''):array
    {
        if(!$this->db->inTransaction())throw new DomainException('Bankteilbeträge gemeinsam mit dem aktuellen Bankstand prüfen.');$amounts=self::amounts($amounts);self::reason($reason);$a=$this->access();$context=$this->reviewContextLocked($record,$duplicate);$r=$context['record'];$m=$context['mapping'];$f=$context['finance'];
        $prior=null;$priorParts=[];
        if($reuse!==''){
            if(!preg_match('/\A[a-f0-9]{32}\z/D',$reuse))throw new DomainException('Frühere eigene Zusammenfassung erneut auswählen.',422);
            $prior=$this->group($reuse,true);$bindings=$this->q('SELECT * FROM bank_payment_group_links WHERE club_id=? AND group_id=? AND released_at IS NULL FOR SHARE NOWAIT',[$a['club_id'],$reuse])->fetchAll();if(!$bindings)throw new DomainException('Früheren Sammelbanknachweis zuerst erneut bewusst zuordnen.',409);foreach($bindings as$binding)self::verified($binding);$priorParts=array_column($prior['items'],'cents','transaction_id');
            if(array_keys($amounts)!==array_keys($priorParts)||$prior['group']['account_id']!==$m['account_id']||(int)$prior['group']['total_cents']!==(int)$r['cents'])throw new DomainException('Nur dieselben ursprünglichen Bankteilbeträge mit demselben Nettobetrag wiederverwenden.',409);
        }
        $money=[];$parts=[];$proofs=[];$total=0;
        foreach($amounts as$id=>$raw){
            if($id===$duplicate)throw new DomainException('Die doppelte Bankübernahme darf nicht selbst Teil ihrer tatsächlichen Originalzahlungen sein.',422);
            $this->q('SELECT id FROM finance_transactions WHERE club_id=? AND id=? FOR SHARE NOWAIT',[$a['club_id'],$id]);$t=$f->transactionDetail((string)$id,true);
            if($t['account_id']!==$m['account_id']||!BankCounterpayments::actualMovement($this->db,$this->actor,(string)$id,true))throw new DomainException('Nur tatsächliche vorhandene Bewegungen auf genau diesem Bankkonto auswählen.',422);
            $part=Finance::cents($raw);$signed=$t['cents']>0?$part:-$part;$allowance=$prior?abs((int)$priorParts[$id]):0;
            if($prior&&$signed!==(int)$priorParts[$id])throw new DomainException('Bankreferenzen desselben Zusammenhangs müssen jeden ursprünglichen Teilbetrag unverändert übernehmen.',409);
            if($part>(int)$t['bank_unproved_cents']+$allowance)throw new DomainException('Ein Bankteilbetrag überschreitet den noch nicht belegten Betrag dieser ursprünglichen Bewegung.',409);
            $current=BankEvidence::proofs($this->db,$a['club_id'],[(string)$id],true)[(string)$id]??[];foreach($current as$proof)if($proof['bank_state']==='conflict')throw new DomainException('Zuerst den abweichenden bisherigen Banknachweis dieser Bewegung klären.',409);
            $money[$id]=$t;$parts[$id]=$signed;$proofs[$id]=$current;$total+=$signed;if(!is_int($total))throw new DomainException('Die ausgewählten Teilbeträge überschreiten den prüfbaren Bankbetrag.',422);
        }
        $source=['record'=>$r,'mapping'=>$m,'transactions'=>$money,'portions'=>$parts,'proofs'=>$proofs,'total_cents'=>$total,'reuse'=>$prior?['id'=>$prior['group']['id'],'original_sha256'=>$prior['group']['original_sha256']]:null,'duplicate'=>$context['duplicate']];$input=['record'=>$record,'transactions'=>array_map('strval',array_keys($amounts)),'reason'=>$reason,'reuse'=>$reuse,'duplicate'=>$duplicate,'amounts'=>$amounts];
        return ['input'=>$input,'source'=>$source];
    }
    public function reviewSharesLocked(string $record,array $amounts,string $reason,string $reuse='',string $duplicate=''):array
    {
        $parts=$this->portionSourceLocked($record,$amounts,$reason,$reuse,$duplicate);$source=$parts['source'];$input=$parts['input'];$a=$this->access();
        if($source['total_cents']!==(int)$source['record']['cents'])throw new DomainException('Die vorzeichenrichtige Summe aller Bankteilbeträge muss genau dem Banknettobetrag entsprechen.',422);
        return ['schema'=>2,'input'=>$input,'source'=>$source,'hash'=>hash('sha256',self::json(['bank-portion-proof-v2',$a['club_id'],$a['id'],$input,$source]))];
    }
}
