<?php declare(strict_types=1);
namespace Kulturhus\Verein;

use DomainException;

/** Complete read-only catalogues; only the requested page loads whole originals. */
trait BankCatalogue
{
    /** Display projection only; closing and CSV retain the complete original cashbook contract. */
    public function cashbookPage(string $id,string $from,string $to,mixed $page=1):array
    {
        $page=self::cataloguePage($page);
        return $this->bankCatalogueRead(function()use($id,$from,$to,$page){
            $account=$this->account($id);if($account['kind']!=='cash')throw new DomainException('Dieses Konto ist keine Barkasse.');
            self::date($from);self::date($to);if($from>$to||$from<$account['opened_on'])throw new DomainException('Kassenzeitraum prüfen: Beginn frühestens bei Kontoeröffnung.');
            $a=$this->actor();
            $q=$this->db->prepare('SELECT COALESCE(SUM(CASE WHEN booked_on<? THEN cents ELSE 0 END),0) AS prior,COALESCE(SUM(CASE WHEN booked_on>=? THEN GREATEST(cents,0) ELSE 0 END),0) AS income,COALESCE(SUM(CASE WHEN booked_on>=? THEN GREATEST(-cents,0) ELSE 0 END),0) AS expense,COUNT(CASE WHEN booked_on>=? THEN 1 END) AS total FROM finance_transactions WHERE club_id=? AND account_id=? AND booked_on<=?');
            $q->execute([$from,$from,$from,$from,$a['club_id'],$id,$to]);$totals=$q->fetch();
            $opening=(int)$account['opening_cents']+(int)$totals['prior'];$income=(int)$totals['income'];$expense=(int)$totals['expense'];$closing=$opening+$income-$expense;
            $total=(int)$totals['total'];$size=40;$pages=max(1,(int)ceil($total/$size));$page=min($page,$pages);$rows=[];
            if($total){
                // Only IDs, cents and running balances cross the complete period; whole originals load for this page.
                $q=$this->db->prepare('SELECT id,cents,SUM(cents) OVER (ORDER BY booked_on,(cents<0),id ROWS UNBOUNDED PRECEDING) AS running_cents FROM finance_transactions WHERE club_id=? AND account_id=? AND booked_on BETWEEN ? AND ? ORDER BY booked_on,(cents<0),id LIMIT '.$size.' OFFSET '.(($page-1)*$size));
                $q->execute([$a['club_id'],$id,$from,$to]);$positions=$q->fetchAll();$ids=array_column($positions,'id');$marks=implode(',',array_fill(0,count($ids),'?'));
                $q=$this->db->prepare('SELECT t.id,t.id AS transaction_id,t.booked_on AS day,t.counterparty,t.description,t.cents,t.reversal_of IS NOT NULL AS reversal,r.booked_on AS reversed_on,'.$this->transactionAllocatedSql().' AS allocated_cents,'.self::INTERNAL_ALLOCATED.' AS internal_cents,'.self::excessReturnAllocatedSql($this->db).' AS excess_return_cents FROM finance_transactions t LEFT JOIN finance_transactions r ON r.club_id=t.club_id AND r.reversal_of=t.id WHERE t.club_id=? AND t.account_id=? AND t.id IN ('.$marks.')');
                $q->execute([$a['club_id'],$id,...$ids]);$byId=[];foreach($q as $row){$row['remaining_cents']=abs((int)$row['cents'])-(int)$row['allocated_cents'];$row['receipts']=[];$byId[$row['id']]=$row;}
                // Typed original receipt identities; never parse, translate or truncate GROUP_CONCAT output.
                $q=$this->db->prepare('SELECT DISTINCT t.id AS transaction_id,e.id,e.display_number FROM finance_transactions t JOIN '.PaymentFunding::rows($this->db).' l ON l.club_id=t.club_id AND l.transaction_id=COALESCE(t.reversal_of,t.id) AND l.voided_at IS NULL JOIN finance_entries e ON e.club_id=l.club_id AND e.id=l.entry_id WHERE t.club_id=? AND t.account_id=? AND t.id IN ('.$marks.') ORDER BY t.id,e.display_number,e.id');
                $q->execute([$a['club_id'],$id,...$ids]);foreach($q as $receipt)$byId[$receipt['transaction_id']]['receipts'][]=['id'=>$receipt['id'],'number'=>$receipt['display_number']];
                foreach($positions as $position){$row=$byId[$position['id']];$row['balance']=$opening+(int)$position['running_cents'];$rows[]=$row;}
            }
            $pageOpening=$rows?$rows[0]['balance']-(int)$rows[0]['cents']:$opening;$pageClosing=$rows?$rows[array_key_last($rows)]['balance']:$opening;
            $q=$this->db->prepare('SELECT * FROM cash_closures WHERE club_id=? AND account_id=? ORDER BY closed_through DESC');$q->execute([$a['club_id'],$id]);$closures=$q->fetchAll();
            return ['account'=>$account,'from'=>$from,'to'=>$to,'opening'=>$opening,'closing'=>$closing,'income'=>$income,'expense'=>$expense,'rows'=>$rows,'closures'=>$closures,'page'=>$page,'pages'=>$pages,'total'=>$total,'size'=>$size,'page_opening'=>$pageOpening,'page_closing'=>$pageClosing];
        });
    }

    private static function cataloguePage(mixed $page):int
    {
        if((!is_int($page)&&!is_string($page))||!preg_match('/^[1-9][0-9]{0,6}$/D',(string)$page)||(int)$page>1000000)throw new DomainException('Seite als ganze Zahl zwischen 1 und 1000000 eingeben.');
        return (int)$page;
    }

    private function bankCatalogueRead(callable $read):array
    {
        $own=!$this->db->inTransaction();
        if($own){$this->db->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');$this->db->beginTransaction();}
        try{$club=$this->actor()['club_id'];$result=$read();if($own)$this->db->commit();if($this->actor()['club_id']!==$club)throw new DomainException('Vereinszugang geändert.');return $result;}
        catch(\Throwable $failure){if($own&&$this->db->inTransaction())$this->db->rollBack();throw $failure;}
    }

    /** Facets share the search/account/date scope, independently of the chosen status. */
    public function transactionsPage(array $filter=[],mixed $page=1,int $size=40):array
    {
        $page=self::cataloguePage($page);if(!in_array($size,[6,20,40,100],true))throw new DomainException('Seitengröße prüfen.');
        foreach(['account','exclude_account','q','from','to','direction','assignment','amount','preferred'] as $key){
            if(isset($filter[$key])&&!is_string($filter[$key]))throw new DomainException('Umsatzfilter prüfen.');
            $filter[$key]=self::field($filter,$key,in_array($key,['q'],true)?150:128);
        }
        if(!in_array($filter['direction'],['','income','expense'],true)||!in_array($filter['assignment'],['','pending','open','partial','assigned','reversal'],true))throw new DomainException('Zahlungsrichtung oder Zuordnungsstatus prüfen.');
        foreach(['from','to'] as $date)if($filter[$date]!=='')self::date($filter[$date]);
        if($filter['from']!==''&&$filter['to']!==''&&$filter['from']>$filter['to'])throw new DomainException('Von-Datum liegt nach Bis-Datum.');
        $amount=$filter['amount']===''?null:self::cents($filter['amount']);
        return $this->bankCatalogueRead(function()use($filter,$page,$size,$amount){
            $a=$this->actor();$where=['t.club_id=?'];$args=[$a['club_id']];
            foreach(['account'=>'=','exclude_account'=>'<>'] as $key=>$operator)if($filter[$key]!==''){$this->account($filter[$key]);$where[]='t.account_id'.$operator.'?';$args[]=$filter[$key];}
            if($filter['direction']!=='')$where[]=$filter['direction']==='income'?'t.cents>0':'t.cents<0';
            foreach(['from'=>'>=','to'=>'<='] as $key=>$operator)if($filter[$key]!==''){$where[]='t.booked_on'.$operator.'?';$args[]=$filter[$key];}
            if($amount!==null){$where[]='ABS(t.cents)=?';$args[]=$amount;}
            if($filter['q']!==''){$where[]='(INSTR(LOWER(t.description),LOWER(?))>0 OR INSTR(LOWER(t.counterparty),LOWER(?))>0 OR INSTR(LOWER(a.name),LOWER(?))>0 OR t.id=?)';array_push($args,$filter['q'],$filter['q'],$filter['q'],$filter['q']);}
            $where=implode(' AND ',$where);
            $joins=' FROM finance_transactions t JOIN finance_accounts a ON a.club_id=t.club_id AND a.id=t.account_id LEFT JOIN finance_transactions r ON r.club_id=t.club_id AND r.reversal_of=t.id WHERE '.$where;
            $facts='SELECT t.id,t.booked_on,t.cents,t.reversal_of,r.booked_on AS reversed_on,'.$this->transactionAllocatedSql().' AS allocated_cents'.$joins;
            $facts="SELECT f.*,CASE WHEN reversal_of IS NOT NULL OR reversed_on IS NOT NULL THEN 'reversal' WHEN ABS(cents)=allocated_cents THEN 'assigned' WHEN allocated_cents>0 THEN 'partial' ELSE 'open' END AS assignment FROM (".$facts.') f';
            $available=!empty($filter['available']);$scope=$available?" WHERE assignment IN ('open','partial')":'';
            $q=$this->db->prepare('SELECT assignment,COUNT(*) AS total FROM ('.$facts.') catalogue'.$scope.' GROUP BY assignment');$q->execute($args);
            $counts=['open'=>0,'partial'=>0,'assigned'=>0,'reversal'=>0];foreach($q as $row)$counts[$row['assignment']]=(int)$row['total'];
            $states=match($filter['assignment']){'pending'=>['open','partial'],''=>array_keys($counts),default=>[$filter['assignment']]};
            if($available)$states=array_values(array_intersect($states,['open','partial']));
            $total=0;foreach($states as $state)$total+=$counts[$state];
            $pages=max(1,(int)ceil($total/$size));$page=min($page,$pages);$ids=[];$rows=[];
            if($total){
                $stateWhere=' WHERE assignment IN ('.implode(',',array_fill(0,count($states),'?')).')';
                $order=$filter['preferred']!==''?'(id=?) DESC,':'';$sortArgs=$filter['preferred']!==''?[$filter['preferred']]:[];
                $q=$this->db->prepare('SELECT id FROM ('.$facts.') catalogue'.$stateWhere.' ORDER BY '.$order.'booked_on DESC,id DESC LIMIT '.$size.' OFFSET '.(($page-1)*$size));
                $q->execute([...$args,...$states,...$sortArgs]);$ids=$q->fetchAll(\PDO::FETCH_COLUMN);
                $q=$this->db->prepare('SELECT t.*,a.name AS account_name,a.kind AS account_kind,r.booked_on AS reversed_on,'.$this->transactionAllocatedSql().' AS allocated_cents,'.self::INTERNAL_ALLOCATED.' AS internal_cents,'.self::excessReturnAllocatedSql($this->db).' AS excess_return_cents'.$this->transactionSourceColumns().$joins.' AND t.id IN ('.implode(',',array_fill(0,count($ids),'?')).')');
                $q->execute([...$args,...$ids]);$byId=[];foreach($q as $row){$row['allocated_cents']=(int)$row['allocated_cents'];$row['remaining_cents']=abs((int)$row['cents'])-$row['allocated_cents'];$row['transaction_id']=$row['id'];$row['day']=$row['booked_on'];$row['reversal']=$row['reversal_of']!==null;$byId[$row['id']]=$row;}
                foreach($ids as $id)$rows[]=$byId[$id];$rows=$this->bankProofRows($rows,$a['club_id']);
            }
            if($this->actor()['club_id']!==$a['club_id'])throw new DomainException('Vereinszugang geändert.');
            return compact('rows','total','counts','page','pages','size','filter');
        });
    }

    public function ownMovementCandidatesPage(string $source,mixed $page=1,mixed $search='',mixed $account=''):array
    {
        $t=$this->transactionDetail($source);
        return $this->transactionsPage(['available'=>true,'direction'=>$t['cents']<0?'income':'expense','exclude_account'=>$t['account_id'],'q'=>$search,'account'=>$account],$page,20);
    }

    public function paymentCandidatesPage(string $source,mixed $page=1,mixed $search=''):array
    {
        $page=self::cataloguePage($page);if(!is_string($search))throw new DomainException('Belegsuche prüfen.');
        return $this->bankCatalogueRead(function()use($source,$page,$search){
            $t=$this->transactionDetail($source);$filter=['status'=>'open','direction'=>$t['cents']<0?'expense':'income','q'=>$search];
            [$where,$args]=$this->entryWhere($filter);$where.=' AND r.entry_id IS NULL AND e.amount_cents>'.$this->allocatedSql();
            $joins=' FROM finance_entries e LEFT JOIN finance_reversals r ON r.club_id=e.club_id AND r.entry_id=e.id WHERE '.$where;
            $q=$this->db->prepare('SELECT COUNT(*)'.$joins);$q->execute($args);$total=(int)$q->fetchColumn();$size=20;$pages=max(1,(int)ceil($total/$size));$page=min($page,$pages);
            $q=$this->db->prepare($this->entrySelect().$where.' ORDER BY e.document_date DESC,e.number DESC,e.id DESC LIMIT '.$size.' OFFSET '.(($page-1)*$size));$q->execute($args);$rows=array_map($this->paymentState(...),$q->fetchAll());
            return compact('rows','total','page','pages','size','filter');
        });
    }
}
