<?php declare(strict_types=1);
namespace Kulturhus\Verein;

use DomainException;
use PDO;
use Throwable;

/** Read-only preparation from recorded payments, not a tax return or tax calculation. */
final class TaxAnnualReport
{
    private const ZERO=['income_cents'=>0,'expense_cents'=>0,'result_cents'=>0];

    public function __construct(private PDO $db,private string $actor){}

    public function build(int $year):array
    {
        $own=!$this->db->inTransaction();
        if($own)$this->db->beginTransaction();
        try{
            $actor=Permissions::actor($this->db,$this->actor);
            if(!in_array($actor['finance_access'],['read','write'],true))throw new DomainException('Finanz-Leserecht für die Jahresauswertung erforderlich.');
            if($year<2000||$year>(int)date('Y'))throw new DomainException('Kalenderjahr zwischen 2000 und dem laufenden Jahr auswählen.');
            $result=$this->read($year);
            if($own)$this->db->commit();
            return $result;
        }catch(Throwable $e){
            if($own&&$this->db->inTransaction())$this->db->rollBack();
            throw $e;
        }
    }

    private static function group(array $identity):array
    {
        return $identity+self::ZERO+['row_count'=>0,'transaction_count'=>0,'row_indexes'=>[]];
    }

    private static function add(array &$group,array $row,int $index):void
    {
        foreach(self::ZERO as $key=>$unused)$group[$key]+=$row[$key];
        ++$group['row_count'];
        $group['row_indexes'][]=$index;
    }

    private static function issue(array &$issues,string $code,string $severity,string $message,int $index):void
    {
        if(!isset($issues[$code]))$issues[$code]=['code'=>$code,'severity'=>$severity,'message'=>$message,'row_indexes'=>[],'count'=>0];
        $issues[$code]['row_indexes'][]=$index;
        ++$issues[$code]['count'];
    }

    private function read(int $year):array
    {
        $period=['from'=>$year.'-01-01','to'=>$year.'-12-31'];
        $reports=new FinanceReports($this->db,$this->actor);
        // One shared read snapshot preserves both journal rounding and reversal direction.
        $journal=$reports->journal($period);
        $detail=$reports->drilldown($period);
        return self::fromRecordedPayments($year,$journal,$detail);
    }

    /** Aggregate already recorded immutable payments; this does not authorize a database read. */
    public static function fromRecordedPayments(int $year,array $journal,array $detail):array
    {
        $period=['from'=>$year.'-01-01','to'=>$year.'-12-31'];
        $transactions=[];
        foreach($detail['rows'] as $transaction){
            $transactions[$transaction['transaction_id']]=$transaction;
        }
        $spheres=[];
        foreach(TaxCatalog::SPHERES as $key=>$label)$spheres[(string)$key]=self::group(['key'=>(string)$key,'label'=>$label]);
        $spheres['unassigned']=self::group(['key'=>'unassigned','label'=>'Noch nicht zugeordnet']);
        $accounts=[];$rows=[];$issues=[];$totals=self::ZERO;
        $sphereTransactions=[];$accountTransactions=[];$transactionSums=[];$transactionDirections=[];
        foreach($journal['rows'] as $index=>$source){
            $transaction=$transactions[$source['transaction_id']]??null;
            if($transaction===null)throw new DomainException('Journalposition ohne zugehörige Zahlung.');
            $signed=(int)$source['signed_cents'];
            // One actual movement may contain returned income and an ordinary expense.
            // Each assigned row retains its original receipt direction, even across years.
            if($source['entry_id']!==null){
                if(!array_key_exists($source['entry_kind'],Finance::KINDS))throw new DomainException('Zahlungsrichtung der Jahresauswertung ist nicht eindeutig.');
                $direction=$source['entry_kind']==='expense'?'expense':'income';
            }elseif(($transaction['income_cents']!==0)!==($transaction['expense_cents']!==0))$direction=$transaction['income_cents']!==0?'income':'expense';
            elseif($source['reversal_of']===null)$direction=$signed>0?'income':'expense';
            else throw new DomainException('Zahlungsrichtung der Jahresauswertung ist nicht eindeutig.');
            $sphere=(string)$source['sphere'];
            $sphereKey=TaxCatalog::sphereOf($sphere)?:'unassigned';
            // The actual debit/credit side follows this payment's sign, not its original direction.
            $side=$signed>0?'credit':'debit';
            $code=(string)$source[$side.'_account_number'];
            $name=(string)$source[$side.'_account_name'];
            $catalogYear=$source['catalog_year']===null?null:(int)$source['catalog_year'];
            $row=$source+[
                'sphere_key'=>$sphereKey,'account_code'=>$code,'account_name'=>$name,'direction'=>$direction,
                'income_cents'=>$direction==='income'?$signed:0,
                'expense_cents'=>$direction==='expense'?-$signed:0,'result_cents'=>$signed,
            ];
            $rows[]=$row;
            self::add($spheres[$sphereKey],$row,$index);
            $sphereTransactions[$sphereKey][$source['transaction_id']]=true;
            // Never substitute today's account name or relabel a saved catalog as the payment year.
            $accountKey=json_encode([$sphereKey,$code,$name,$catalogYear],JSON_THROW_ON_ERROR);
            if(!isset($accounts[$accountKey]))$accounts[$accountKey]=self::group([
                'sphere'=>$sphereKey,'account_code'=>$code,'account_name'=>$name,'catalog_year'=>$catalogYear,
            ]);
            self::add($accounts[$accountKey],$row,$index);
            $accountTransactions[$accountKey][$source['transaction_id']]=true;
            foreach(self::ZERO as $key=>$unused)$totals[$key]+=$row[$key];
            $transactionSums[$source['transaction_id']]=($transactionSums[$source['transaction_id']]??0)+$signed;
            foreach(['income_cents','expense_cents']as$key)$transactionDirections[$source['transaction_id']][$key]=($transactionDirections[$source['transaction_id']][$key]??0)+$row[$key];

            if($source['entry_id']===null)self::issue($issues,'unallocated_payment','warning','Zahlungen sind noch keinem Beleg zugeordnet.',$index);
            if($sphere==='')self::issue($issues,'missing_sphere','warning','Für diese Zahlungen oder Belegpositionen fehlt die Steuersphäre.',$index);
            elseif($sphereKey==='unassigned')self::issue($issues,'unknown_sphere','warning','Eine gespeicherte Steuersphäre ist unbekannt. Sie wird als ungeklärt ausgewiesen.',$index);
            elseif($sphere==='9')self::issue($issues,'technical_sphere','warning','Sammelposten 9 ist ein technischer Bereich. Die sachliche Zuordnung vor der Steuererklärung prüfen.',$index);
            if($code==='')self::issue($issues,'missing_account','warning','Für diese Zahlungen oder Belegpositionen fehlt das Steuerkonto.',$index);
            elseif($catalogYear===null||$catalogYear<1)self::issue($issues,'missing_catalog_year','warning','Das Bezugsjahr des gespeicherten Steuerkontos fehlt. Die ursprüngliche Kontierung prüfen.',$index);
            // An empty BU key is valid in many cases; no blanket warning or inferred replacement.
        }
        foreach($transactions as $id=>$transaction){
            if(($transactionSums[$id]??0)!==(int)$transaction['signed_cents'])throw new DomainException('Journalpositionen stimmen nicht mit ihrer Zahlung überein.');
            foreach(['income_cents','expense_cents']as$key)if(($transactionDirections[$id][$key]??0)!==(int)$transaction[$key])throw new DomainException('Zahlungsrichtung der Jahresauswertung ist nicht eindeutig.');
        }
        if($totals['result_cents']!==$journal['signed_total_cents']||$totals!==$detail['totals']||$totals['income_cents']-$totals['expense_cents']!==$totals['result_cents'])throw new DomainException('Jahresauswertung konnte nicht mit dem Zahlungsjournal abgestimmt werden.');
        foreach($spheres as $key=>&$sphere)$sphere['transaction_count']=count($sphereTransactions[$key]??[]);
        unset($sphere);
        foreach($accounts as $key=>&$account)$account['transaction_count']=count($accountTransactions[$key]??[]);
        unset($account);
        $accounts=array_values($accounts);
        usort($accounts,static fn(array $a,array $b):int=>[$a['sphere'],$a['account_code'],$a['catalog_year'],$a['account_name']]<=>[$b['sphere'],$b['account_code'],$b['catalog_year'],$b['account_name']]);
        return [
            'year'=>$year,'basis'=>'recorded_cash_movements','period'=>$period,
            'spheres'=>array_values($spheres),'accounts'=>$accounts,'totals'=>$totals,
            'reconciliation'=>['journal_signed_total_cents'=>$journal['signed_total_cents'],'transaction_totals'=>$detail['totals'],'balanced'=>true],
            'issues'=>array_values($issues),'rows'=>$rows,
        ];
    }
}
