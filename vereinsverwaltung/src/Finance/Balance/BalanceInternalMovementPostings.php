<?php declare(strict_types=1);
namespace Kulturhus\Verein;
use PDO;use DomainException;

/** Actual two-legged own-account classification, never a payment instruction. */
final class BalanceInternalMovementPostings
{
    public const ACCOUNT='src_internal_transit';
    public static function movement(PDO$db,string$actorId,string$transaction,bool$current=false):array
    {
        if(!preg_match('/\A[a-f0-9]{32}\z/D',$transaction))throw new DomainException('Ursprünglichen eigenen Kontoumsatz prüfen.');
        if($current&&!$db->inTransaction())throw new DomainException('Aktuelle eigene Umbuchung gemeinsam prüfen.',409);
        $actor=Permissions::actor($db,$actorId);if(!in_array($actor['finance_access'],['read','write'],true))throw new DomainException('Finanzberechtigung erforderlich.');
        $lock=$current?' FOR SHARE NOWAIT':'';$q=$db->prepare('SELECT * FROM finance_internal_movements WHERE club_id=? AND(outgoing_id=? OR incoming_id=?)AND voided_at IS NULL ORDER BY id'.$lock);$q->execute([$actor['club_id'],$transaction,$transaction]);$movements=$q->fetchAll(PDO::FETCH_ASSOC);$empty=['amount'=>0,'accounts'=>[],'lines'=>[],'snapshot'=>[]];if(!$movements)return$empty;
        $ids=[];foreach($movements as$m){$ids[]=$m['outgoing_id'];$ids[]=$m['incoming_id'];}$ids=array_values(array_unique($ids));sort($ids,SORT_STRING);$facts=[];
        foreach($ids as$id){$q=$db->prepare('SELECT id,account_id,booked_on,cents,reversal_of FROM finance_transactions WHERE club_id=? AND id=?'.$lock);$q->execute([$actor['club_id'],$id]);$t=$q->fetch(PDO::FETCH_ASSOC);if(!$t)throw new DomainException('Beide ursprünglichen Umsätze der eigenen Umbuchung müssen vorhanden sein.');$q=$db->prepare('SELECT id,name,kind FROM finance_accounts WHERE club_id=? AND id=?'.$lock);$q->execute([$actor['club_id'],$t['account_id']]);$account=$q->fetch(PDO::FETCH_ASSOC);if(!$account)throw new DomainException('Umbuchung nur zwischen zwei eigenen Konten übernehmen.');$q=$db->prepare('SELECT id FROM finance_transactions WHERE club_id=? AND reversal_of=?'.$lock);$q->execute([$actor['club_id'],$id]);if($t['reversal_of']!==null||$q->fetchColumn()!==false)throw new DomainException('Gegengebuchte eigene Umbuchung zuerst abstimmen.');$q=$db->prepare('SELECT id,cents FROM finance_internal_movements WHERE club_id=? AND(outgoing_id=? OR incoming_id=?)AND voided_at IS NULL ORDER BY id'.$lock);$q->execute([$actor['club_id'],$id,$id]);$consumed=0;foreach($q->fetchAll(PDO::FETCH_ASSOC)as$link){$part=(int)$link['cents'];if($part<=0||$part>BalanceCalculation::LIMIT-$consumed)throw new DomainException('Ursprüngliche eigene Umbuchungsbeträge prüfen.');$consumed+=$part;}if($consumed>abs((int)$t['cents']))throw new DomainException('Eigene Umbuchungen überschreiten einen ursprünglichen Kontoumsatz.');$facts[$id]=['transaction'=>$t,'account'=>$account];}
        $amount=0;$snapshot=[];
        foreach($movements as$m){$out=$facts[$m['outgoing_id']];$in=$facts[$m['incoming_id']];$cents=(int)$m['cents'];if($out['transaction']['account_id']===$in['transaction']['account_id']||(int)$out['transaction']['cents']>=0||(int)$in['transaction']['cents']<=0||$cents<=0||$cents>min(abs((int)$out['transaction']['cents']),(int)$in['transaction']['cents'])||$cents>BalanceCalculation::LIMIT-$amount)throw new DomainException('Ursprünglichen Abgang und Zugang auf zwei eigenen Konten abstimmen.');$amount+=$cents;$snapshot[]=['movement'=>$m,'outgoing'=>$out,'incoming'=>$in];}
        $t=$facts[$transaction]['transaction'];if($amount>abs((int)$t['cents']))throw new DomainException('Eigene Umbuchung überschreitet den ursprünglichen Kontoumsatz.');
        // SKR42 13720 already has source-qualified debit/credit presentation through
        // BalanceDirectionalClosing; a later counter date remains genuine transit.
        $account=['name'=>'Geldtransit zwischen eigenen Konten','group'=>'other_assets','skr_code'=>'13720'];$line=['account'=>self::ACCOUNT,'debit_cents'=>(int)$t['cents']<0?$amount:0,'credit_cents'=>(int)$t['cents']>0?$amount:0,'sphere'=>''];return['amount'=>$amount,'accounts'=>[self::ACCOUNT=>$account],'lines'=>[$line],'snapshot'=>$snapshot];
    }
}
