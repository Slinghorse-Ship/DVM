<?php declare(strict_types=1);
namespace Kulturhus\Verein;
use DomainException;
use PDO;

/** Local, consciously reviewed tariff billing. Never sends mail or submits a payment. */
final class CatalogBilling
{
    public function __construct(private PDO $db,private string $actorId){}
    public function actor(bool $write=false):array
    {
        $a=Permissions::actor($this->db,$this->actorId);
        if(!in_array($a['finance_access'],$write?['write']:['read','write'],true)||$a['members_access']==='none')throw new DomainException('Finanz- und Mitgliederberechtigung für die Tarifabrechnung erforderlich.');
        if(!Database::tableExists($this->db,'native_catalog_charges'))throw new DomainException('Tarifabrechnung benötigt das aktuelle installierte Paket.');
        return $a;
    }
    public static function items(array $items,bool $fingerprints=false):array
    {
        if(!array_is_list($items)||!$items||count($items)>6)throw new DomainException('Eine bis sechs eigene Zuweisungen auswählen.');
        $out=[];$seen=[];
        foreach($items as$item){if($fingerprints&&!is_array($item))throw new DomainException('Geprüfte Zuweisung auswählen.');$id=$fingerprints?($item['assignment_id']??null):$item;$hash=$fingerprints?($item['fingerprint']??null):null;
            if(!is_string($id)||!preg_match('/\A[a-f0-9]{32}\z/D',$id)||isset($seen[$id])||($fingerprints&&(!is_string($hash)||!preg_match('/\A[a-f0-9]{64}\z/D',$hash))))throw new DomainException('Zuweisung und geprüften Originalpreis auswählen.');
            $seen[$id]=true;$out[]=$fingerprints?['assignment_id'=>$id,'fingerprint'=>$hash]:$id;
        }
        return $out;
    }
    public function review(array $assignments,string $day,string $mode='full',?string $priceDay=null,bool $lock=false):array
    {
        try{return Infrastructure\Database\Transactions::run($this->db,function()use($assignments,$day,$mode,$priceDay,$lock){
            $a=$this->actor();$ids=self::items($assignments);$out=[];
            $linkedIds=null;if($lock){$q=$this->db->prepare('SELECT catalog_id FROM membership_contribution_rates WHERE club_id=? FOR SHARE NOWAIT');$q->execute([$a['club_id']]);$linkedIds=$q->fetchAll(PDO::FETCH_COLUMN);}
            foreach($ids as$id){$quote=(new CatalogPricing($this->db,$this->actorId))->quote($id,$day,$mode,$priceDay,$lock);
                if($linkedIds!==null?in_array($quote['catalog_id'],$linkedIds,true):ContributionRates::linked($this->db,$a['club_id'],$quote['catalog_id']))throw new DomainException('Verknüpfte Einzel- und Familienbeiträge über den Jahresbeitragslauf abrechnen.');
                if($quote['amount_cents']<=0)throw new DomainException('Ein ausgewählter Tarif hat keinen zahlbaren Betrag.');
                $q=$this->db->prepare('SELECT entry_id,period_start,period_end,quote_json FROM native_catalog_charges WHERE club_id=? AND assignment_id=? AND period_start<=? AND period_end>=? ORDER BY period_start,id'.($lock?' FOR UPDATE NOWAIT':' FOR SHARE NOWAIT'));$q->execute([$a['club_id'],$id,$quote['period_end'],$quote['period_start']]);$quote['charge']=$q->fetch(PDO::FETCH_ASSOC)?:null;$out[]=$quote;
            }
            return $out;
        });}catch(\PDOException $failure){if(in_array((int)($failure->errorInfo[1]??0),[1205,3572],true))throw new DomainException('Tarifabrechnung wird gerade bearbeitet. Bitte erneut prüfen.',503,$failure);throw$failure;}
    }
}
