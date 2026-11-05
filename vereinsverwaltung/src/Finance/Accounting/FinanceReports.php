<?php declare(strict_types=1);
namespace Kulturhus\Verein;

use DateTimeImmutable;
use DomainException;
use PDO;
use Throwable;

/** Read-only reports of recorded cash movements, not a DATEV posting/export adapter. */
final class FinanceReports
{
    private const ZERO=['income_cents'=>0,'expense_cents'=>0,'result_cents'=>0];
    private const DETAIL_LIMIT=10000;

    public function __construct(private PDO $db,private string $actorId){}

    private function actor():array
    {
        $a=Permissions::actor($this->db,$this->actorId);
        if(!in_array($a['finance_access'],['read','write'],true))throw new DomainException('Finanzberechtigung für Auswertungen erforderlich.');
        return $a;
    }

    private function snapshot(callable $read):array
    {
        $own=!$this->db->inTransaction();if($own){$this->db->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');$this->db->beginTransaction();}
        try{$result=$read();if($own)$this->db->commit();return $result;}
        catch(Throwable $e){if($own&&$this->db->inTransaction())$this->db->rollBack();throw $e;}
    }

    private function query(string $sql,array $params=[]):\PDOStatement{$q=$this->db->prepare($sql);$q->execute($params);return $q;}
    private static function text(array $input,string $key,string $default=''):string
    {
        if(!isset($input[$key]))return $default;
        if(!is_string($input[$key])&&!is_int($input[$key]))throw new DomainException('Auswertungsfilter prüfen.');
        return trim((string)$input[$key]);
    }
    private static function date(string $value):DateTimeImmutable
    {
        $d=DateTimeImmutable::createFromFormat('!Y-m-d',$value);
        if(!preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/D',$value)||!$d||$d->format('Y-m-d')!==$value||(int)$d->format('Y')<1)throw new DomainException('Gültigen Auswertungszeitraum angeben.');
        return $d;
    }
    private function filters(array $input):array
    {
        $from=self::text($input,'from');$to=self::text($input,'to');$start=self::date($from);$end=self::date($to);
        if($from<'1901-01-01'||$from>$to||(int)$start->diff($end)->days>3660)throw new DomainException('Zeitraum ab 1901 in richtiger Reihenfolge mit höchstens zehn Jahren wählen.');
        $resolution=self::text($input,'resolution','month');if(!in_array($resolution,['week','month','year'],true))throw new DomainException('Auflösung Woche, Monat oder Jahr wählen.');
        $compare=$input['compare']??false;if(!in_array($compare,[false,true,0,1,'0','1'],true))throw new DomainException('Vorjahresvergleich prüfen.');
        if((bool)$compare&&(int)$start->format('Y')<2)throw new DomainException('Für diesen Zeitraum gibt es kein gültiges Vorjahr.');
        $account=self::text($input,'account');if(strlen($account)>100)throw new DomainException('Kontoauswahl prüfen.');
        if($account!=='')(new Finance($this->db,$this->actorId))->account($account);
        $tags=array_key_exists('tags',$input)?$input['tags']:[];if($tags==='')$tags=[];
        if(!is_array($tags)||!array_is_list($tags)||count($tags)>PaymentTags::MAX_SELECTION)throw new DomainException('Ursprüngliche Zahlungstag-Auswahl prüfen.',422);
        $known=array_column($this->availableTags(),null,'id');$selected=[];
        foreach($tags as$tag){if(!is_string($tag)||!preg_match('/\A[a-f0-9]{32}\z/D',$tag)||!isset($known[$tag]))throw new DomainException('Gewählter Zahlungstag nicht verfügbar.',422);$selected[$tag]=true;}
        $tags=array_keys($selected);sort($tags,SORT_STRING);
        return ['from'=>$from,'to'=>$to,'resolution'=>$resolution,'compare'=>(bool)$compare,'account'=>$account,'tags'=>$tags];
    }

    public function availableTags():array{$this->actor();return (new PaymentTags($this->db,$this->actorId))->catalogue();}

    /** Labels and figures share one read snapshot; raw accounting report contracts stay stable. */
    public function interfaceRead(string$type,array$input):array
    {
        return $this->snapshot(function()use($type,$input):array{
            $actor=$this->actor();$catalogue=$this->availableTags();$report=$type==='journal'?$this->journal($input):$this->income($input);
            if($type==='journal')$report=$this->interfaceBankAccounts($report,$actor);
            if(!$catalogue)return $report;
            $report['tag_catalogue']=$catalogue;$names=array_column($catalogue,'name','id');$report['selected_tag_names']=array_map(static fn(string$id):string=>$names[$id],$report['filters']['tags']);
            [$where,$params]=$this->condition($actor,$report['filters']);$labels=[];
            $sql='SELECT t.id,t.booked_on,g.id AS tag_id,g.name FROM finance_transactions t JOIN finance_payment_tag_assignments x ON x.club_id=t.club_id AND x.transaction_id=CASE WHEN t.source=\'reversal\' THEN COALESCE(t.reversal_of,t.id) ELSE t.id END JOIN finance_payment_tags g ON g.club_id=x.club_id AND g.id=x.tag_id WHERE '.$where.' ORDER BY t.booked_on,t.id,g.name,g.id';
            foreach($this->query($sql,$params)as$row){$key=$type==='journal'?$row['id']:$row['booked_on'];$labels[$key][$row['tag_id']]=$row['name'];}
            foreach($report['rows']as&$row){$rowLabels=[];if($type==='journal')$rowLabels=$labels[$row['transaction_id']]??[];else foreach($labels as$day=>$items)if($day>=$row['from']&&$day<=$row['to'])$rowLabels+=$items;asort($rowLabels,SORT_STRING);$row['tags']=array_values($rowLabels);}unset($row);
            return $report;
        });
    }

    /** Current display coding only. Raw journal() remains the DATEV counteraccount contract. */
    private function interfaceBankAccounts(array $report,array $actor):array
    {
        if(!Database::tableExists($this->db,'finance_account_settings'))return $report;
        $settings=[];foreach($this->query('SELECT a.id,s.tax_account FROM finance_accounts a LEFT JOIN finance_account_settings s ON s.club_id=a.club_id AND s.account_id=a.id WHERE a.club_id=?',[$actor['club_id']])as$row)$settings[$row['id']]=(string)($row['tax_account']??'');
        $catalogues=[];$finance=new Finance($this->db,$this->actorId);$report['bank_coding_source']='current-account-settings';
        foreach($report['rows']as&$row){
            $code=$settings[$row['account_id']]??'';if($code==='')continue;
            $year=(int)substr($row['payment_date'],0,4);$notes=explode(' · ',$row['info']);$notes=array_values(array_filter($notes,static fn(string$note):bool=>$note!=='SKR-Kontonummer des Geldkontos fehlt'));
            $notes[]='Aktuelle Geldkontierung';$notes[]=$code;$notes[]='Kein ursprünglicher Buchungsnachweis';
            if(!TaxCatalog::hasYear($year))$notes[]='Kontenplan des Buchungsjahres nicht verfügbar';
            else{
                if(!array_key_exists($year,$catalogues))$catalogues[$year]=$finance->taxAccounts($year);
                $account=$catalogues[$year][$code]??null;
                if(!preg_match('/\A[0-9]{5}\z/D',$code)||!$account||empty($account['active'])||($account['category']??'')!=='current'||preg_match('/AM|AV/',$account['function']??''))$notes[]='Aktuelle Geldkontierung für das Buchungsjahr nicht als aktives Geldkonto verwendbar';
                elseif($row['signed_cents']>0)$row['debit_account_number']=$code;
                elseif($row['signed_cents']<0)$row['credit_account_number']=$code;
            }
            $row['info']=implode(' · ',array_unique($notes));
        }unset($row);
        return $report;
    }

    /** The joined original determines the direction of a later correction. */
    private function condition(array $actor,array $filters):array
    {
        $where='t.club_id=? AND t.booked_on>=? AND t.booked_on<=?';$params=[$actor['club_id'],$filters['from'],$filters['to']];
        if($filters['account']!==''){$where.=' AND t.account_id=?';$params[]=$filters['account'];}
        if($filters['tags']){$where.=' AND EXISTS(SELECT 1 FROM finance_payment_tag_assignments tag_filter WHERE tag_filter.club_id=t.club_id AND tag_filter.transaction_id=CASE WHEN t.source=\'reversal\' THEN COALESCE(t.reversal_of,t.id) ELSE t.id END AND tag_filter.tag_id IN('.implode(',',array_fill(0,count($filters['tags']),'?')).'))';array_push($params,...$filters['tags']);}
        return [$where,$params];
    }
    private function daily(array $actor,array $filters):array
    {
        [$where,$params]=$this->condition($actor,$filters);
        $neutral=Finance::neutralAllocatedSql($this->db);$returned=PaymentFunding::returnedSql($this->db,'t');
        $sql="SELECT t.booked_on AS day,SUM(CASE WHEN COALESCE(o.cents,t.cents)>0 THEN t.cents-SIGN(t.cents)*$neutral ELSE 0 END-$returned) AS income_cents,SUM(CASE WHEN COALESCE(o.cents,t.cents)<0 THEN -t.cents+SIGN(t.cents)*$neutral ELSE 0 END-$returned) AS expense_cents,SUM(t.cents-SIGN(t.cents)*$neutral) AS result_cents FROM finance_transactions t LEFT JOIN finance_transactions o ON o.club_id=t.club_id AND o.id=t.reversal_of WHERE $where GROUP BY t.booked_on ORDER BY t.booked_on";
        $out=[];foreach($this->query($sql,$params) as $r)$out[$r['day']]=array_map('intval',array_intersect_key($r,self::ZERO));return $out;
    }
    private static function sum(array $daily,?string $from=null,?string $to=null):array
    {
        $sum=self::ZERO;foreach($daily as $day=>$values)if(($from===null||$day>=$from)&&($to===null||$day<=$to))foreach($sum as $key=>$value)$sum[$key]+=$values[$key];return $sum;
    }
    private static function previousDate(string $date):string
    {
        $d=self::date($date);$year=(int)$d->format('Y')-1;$month=(int)$d->format('m');$day=(int)$d->format('d');
        while(!checkdate($month,$day,$year))--$day;
        return sprintf('%04d-%02d-%02d',$year,$month,$day);
    }
    private static function buckets(array $f):array
    {
        $start=self::date($f['from']);$end=self::date($f['to']);
        $cursor=match($f['resolution']){'week'=>$start->modify('monday this week'),'month'=>$start->modify('first day of this month'),'year'=>$start->setDate((int)$start->format('Y'),1,1)};
        $rows=[];
        while($cursor<=$end){
            $next=$cursor->modify(match($f['resolution']){'week'=>'+1 week','month'=>'+1 month','year'=>'+1 year'});
            $from=max($f['from'],$cursor->format('Y-m-d'));$to=min($f['to'],$next->modify('-1 day')->format('Y-m-d'));
            $key=match($f['resolution']){'week'=>$cursor->format('o-\WW'),'month'=>$cursor->format('Y-m'),'year'=>$cursor->format('Y')};
            $label=match($f['resolution']){'week'=>'KW '.$cursor->format('W').' · '.$cursor->format('o'),'month'=>$cursor->format('m/Y'),'year'=>$cursor->format('Y')};
            $rows[]=['key'=>$key,'label'=>$label,'from'=>$from,'to'=>$to];$cursor=$next;
        }
        return $rows;
    }

    public function income(array $input):array
    {
        return $this->snapshot(function()use($input){
            $actor=$this->actor();$f=$this->filters($input);$daily=$this->daily($actor,$f);$totals=self::sum($daily);$rows=self::buckets($f);
            $previousFrom=null;$previousTo=null;$previousTotals=null;$previous=[];
            if($f['compare']){$previousFrom=self::previousDate($f['from']);$previousTo=self::previousDate($f['to']);$previous=$this->daily($actor,array_replace($f,['from'=>$previousFrom,'to'=>$previousTo]));$previousTotals=self::sum($previous);}
            // Adjacent comparison buckets partition the same prior-year range, even around leap days.
            $previousCursor=$previousFrom;$previousEnd=$previousTo===null?null:self::date($previousTo)->modify('+1 day')->format('Y-m-d');
            foreach($rows as $i=>&$row){
                $row+=self::sum($daily,$row['from'],$row['to']);$row['previous']=null;
                if($f['compare']){
                    $next=isset($rows[$i+1])?min($previousEnd,max($previousCursor,self::previousDate($rows[$i+1]['from']))):$previousEnd;
                    $hasDays=$next>$previousCursor;$pTo=$hasDays?self::date($next)->modify('-1 day')->format('Y-m-d'):null;
                    $row['previous']=['from'=>$hasDays?$previousCursor:null,'to'=>$pTo]+($hasDays?self::sum($previous,$previousCursor,$pTo):self::ZERO);$previousCursor=$next;
                }
            }unset($row);
            return ['type'=>'income','filters'=>$f,'totals'=>$totals,'previous_totals'=>$previousTotals,'previous_from'=>$previousFrom,'previous_to'=>$previousTo,'rows'=>array_reverse($rows),'breakdowns'=>$this->breakdowns($actor,$f,$totals)];
        });
    }

    private function breakdowns(array $actor,array $f,array $totals):array
    {
        [$where,$params]=$this->condition($actor,$f);
        $sql="SELECT e.kind,CASE WHEN e.kind='expense' THEN 'expense' ELSE 'income' END AS direction,SUM(CASE WHEN t.reversal_of IS NULL THEN l.cents ELSE -l.cents END) AS amount_cents FROM finance_transactions t LEFT JOIN finance_transactions o ON o.club_id=t.club_id AND o.id=t.reversal_of JOIN ".PaymentFunding::rows($this->db)." l ON l.club_id=t.club_id AND l.transaction_id=COALESCE(t.reversal_of,t.id) AND l.voided_at IS NULL JOIN finance_entries e ON e.club_id=l.club_id AND e.id=l.entry_id WHERE $where GROUP BY direction,e.kind ORDER BY direction,e.kind";
        $out=['income'=>[],'expense'=>[]];$assigned=['income'=>0,'expense'=>0];
        foreach($this->query($sql,$params) as $r){$amount=(int)$r['amount_cents'];$assigned[$r['direction']]+=$amount;if($amount!==0)$out[$r['direction']][]=['key'=>$r['kind'],'label'=>Finance::KINDS[$r['kind']],'amount_cents'=>$amount];}
        foreach($out as $direction=>&$rows){$rest=$totals[$direction.'_cents']-$assigned[$direction];if($rest!==0)$rows[]=['key'=>'unallocated','label'=>'Nicht zugeordnete Zahlungen','amount_cents'=>$rest];}unset($rows);
        return $out;
    }

    private function movements(array $actor,array $f):array
    {
        [$where,$params]=$this->condition($actor,$f);
        $sql="SELECT t.*,".Finance::neutralAllocatedSql($this->db)." AS neutral_cents,".PaymentFunding::returnedSql($this->db,'t')." AS returned_cents,a.name AS account_name,a.kind AS account_kind,COALESCE(o.cents,t.cents) AS original_cents FROM finance_transactions t JOIN finance_accounts a ON a.club_id=t.club_id AND a.id=t.account_id LEFT JOIN finance_transactions o ON o.club_id=t.club_id AND o.id=t.reversal_of WHERE $where ORDER BY t.booked_on DESC,t.id DESC LIMIT ".(self::DETAIL_LIMIT+1);
        $rows=$this->query($sql,$params)->fetchAll();if(count($rows)>self::DETAIL_LIMIT)throw new DomainException('Mehr als 10.000 Zahlungen. Für die Detailansicht bitte Zeitraum oder Konto eingrenzen.');return $rows;
    }

    public function drilldown(array $input):array
    {
        return $this->snapshot(function()use($input){
            $actor=$this->actor();$f=$this->filters($input);$rows=[];
            foreach($this->movements($actor,$f) as $t){$t['cents']=(int)$t['cents']-((int)$t['cents']<0?-1:1)*(int)$t['neutral_cents'];if(!$t['cents'])continue;$rows[]=['transaction_id'=>$t['id'],'payment_date'=>$t['booked_on'],'transaction_number'=>$t['id'],'account_id'=>$t['account_id'],'account_name'=>$t['account_name'],'description'=>$t['description'],'counterparty'=>$t['counterparty'],'signed_cents'=>(int)$t['cents'],'income_cents'=>($t['original_cents']>0?(int)$t['cents']:0)-(int)$t['returned_cents'],'expense_cents'=>($t['original_cents']<0?-(int)$t['cents']:0)-(int)$t['returned_cents'],'result_cents'=>(int)$t['cents'],'reversal_of'=>$t['reversal_of']];}
            return ['type'=>'transactions','filters'=>$f,'rows'=>$rows,'count'=>count($rows),'totals'=>self::sum($this->daily($actor,$f))];
        });
    }

    /** Exact product division without overflowing int64 for supported 100-billion-cent amounts. */
    private static function productDivision(int $a,int $b,int $divisor):array
    {
        $quotient=0;$remainder=0;$partQ=intdiv($b,$divisor);$partR=$b%$divisor;
        while($a>0){
            if($a%2===1){$quotient+=$partQ;$remainder+=$partR;if($remainder>=$divisor){++$quotient;$remainder-=$divisor;}}
            $a=intdiv($a,2);if($a===0)break;
            $partQ*=2;$partR*=2;if($partR>=$divisor){++$partQ;$partR-=$divisor;}
        }
        return [$quotient,$remainder];
    }
    private static function positionAmounts(int $amount,int $total,array $positions):array
    {
        if($total<=0||$amount<=0||$amount>$total||array_sum(array_column($positions,'gross_cents'))!==$total)throw new DomainException('Belegpositionen und Zahlungszuordnung stimmen nicht überein.');
        $parts=[];$used=0;
        foreach($positions as $i=>$p){[$part]=self::productDivision($amount,(int)$p['gross_cents'],$total);$parts[$i]=$part;$used+=$part;}
        // D'Hondt is monotone as cumulative paid cents increase; lower quotas leave <50 seats.
        // Largest-remainder rounding would allow an earlier position to lose a cent later.
        for($left=$amount-$used;$left>0;--$left){
            $best=0;foreach($positions as $i=>$p)if(self::fractionCompare((int)$p['gross_cents'],$parts[$i]+1,(int)$positions[$best]['gross_cents'],$parts[$best]+1)>0)$best=$i;
            ++$parts[$best];
        }
        return $parts;
    }
    private static function fractionCompare(int $a,int $b,int $c,int $d):int
    {
        $direction=1;
        while(true){$qa=intdiv($a,$b);$qc=intdiv($c,$d);if($qa!==$qc)return ($qa<=>$qc)*$direction;$a%=$b;$c%=$d;if($a===0||$c===0)return ($a<=>$c)*$direction;[$a,$b]=[$b,$a];[$c,$d]=[$d,$c];$direction=-$direction;}
    }

    private function journalFunding(string $club,array $entries):array
    {
        if(!$entries||!PaymentFunding::available($this->db))return [];
        $marks=implode(',',array_fill(0,count($entries),'?'));
        $sql='SELECT f.*,a.id AS actual_id,a.club_id AS actual_club,a.transaction_id AS actual_source,a.entry_id AS actual_entry,a.cents AS actual_cents,a.request_key AS actual_key,a.voided_at AS actual_voided_at,a.void_reason AS actual_void_reason FROM finance_allocation_funding f JOIN finance_allocations a ON a.club_id=f.club_id AND a.id=f.allocation_id WHERE f.club_id=? AND f.entry_id IN('.$marks.') ORDER BY f.allocation_id';$out=[];
        foreach($this->query($sql,[$club,...$entries])as$row){$actual=['id'=>$row['actual_id'],'club_id'=>$row['actual_club'],'transaction_id'=>$row['actual_source'],'entry_id'=>$row['actual_entry'],'cents'=>(int)$row['actual_cents'],'request_key'=>$row['actual_key'],'voided_at'=>$row['actual_voided_at'],'void_reason'=>$row['actual_void_reason']];$out[$row['allocation_id']]=PaymentFundingOriginals::verify($row,$actual);}
        return $out;
    }

    /** Keep each distinct interval's before/after tax basis, including fragmented recollections. */
    private function journalFundingSlices(string $club,array $allocation,array $positions,array $funding,array &$firsts):?array
    {
        $id=$allocation['funding_allocation_id'];$record=$funding[$id]??null;
        if($allocation['refund_id']!==null){
            $refund=$allocation['refund_id'];if(!isset($firsts[$refund]))$firsts[$refund]=PartialReturnOriginals::read($this->db,$club,$refund);
            $first=$firsts[$refund];if(!$first['active'])throw new DomainException('Der Zahlungsstand wurde während der Auswertung geändert. Auswertung erneut öffnen.',409);
            $item=null;foreach($first['items']as$part)if($part['id']===$allocation['refund_item_id'])$item=$part;
            if(!$item||$item['allocation_id']!==$id||$item['entry_id']!==$allocation['entry_id']||$first['counter_id']!==$allocation['transaction_id']||(int)$item['cents']!==abs((int)$allocation['cents']))throw new DomainException('Teilrückzahlung und ursprünglicher Journalnachweis stimmen nicht überein.',503);
            $record=$item['funding'];$ranges=$item['ranges'];
        }else{
            if($record===null)return null;
            if($record['source_id']!==$allocation['transaction_id']||$record['entry_id']!==$allocation['entry_id'])throw new DomainException('Ursprüngliche Zahlungszuordnung und Journalnachweis stimmen nicht überein.',503);$ranges=$record['ranges'];
        }
        $face=(int)$allocation['amount_cents'];if($face!==(int)$record['face_cents']||PaymentFundingRanges::cents($ranges,$face)!==abs((int)$allocation['cents']))throw new DomainException('Ursprünglicher Belegbetrag und Journalcentbereiche stimmen nicht überein.',503);
        if(!$positions)return [];$out=[];
        foreach($ranges as[$start,$end]){
            $before=$start===0?array_fill(0,count($positions),0):self::positionAmounts($start,$face,$positions);$after=self::positionAmounts($end,$face,$positions);$amounts=[];$paid=[];
            foreach($after as$i=>$amount){$part=$amount-$before[$i];if($part<0)throw new DomainException('Ursprüngliche Positionscents konnten nicht fortgeführt werden.',503);$amounts[$i]=$part;$paid[$i]=['before'=>$before[$i],'after'=>$amount];}
            if(array_sum($amounts)!==$end-$start)throw new DomainException('Journalcentbereiche stimmen nicht mit der ursprünglichen Zahlung überein.',503);$out[]=compact('amounts','paid');
        }
        return $out;
    }

    public function journal(array $input):array
    {
        return $this->snapshot(function()use($input){
            $actor=$this->actor();$f=$this->filters($input);$transactions=$this->movements($actor,$f);$allocations=[];$positions=[];
            // Fetch only data belonging to selected transactions, including their original correction chain.
            [$where,$params]=$this->condition($actor,$f);
            $allocSql="SELECT DISTINCT l.id,l.transaction_id,l.entry_id,l.cents,l.funding_allocation_id,l.refund_id,l.refund_item_id,e.amount_cents,e.number,e.display_number,e.description AS entry_description,e.kind FROM finance_transactions t JOIN ".PaymentFunding::rows($this->db)." l ON l.club_id=t.club_id AND l.transaction_id=COALESCE(t.reversal_of,t.id) AND l.voided_at IS NULL JOIN finance_entries e ON e.club_id=l.club_id AND e.id=l.entry_id WHERE $where ORDER BY l.transaction_id,l.id";
            foreach($this->query($allocSql,$params) as $l)$allocations[$l['transaction_id']][]=$l;
            $positionSql="SELECT DISTINCT p.* FROM finance_transactions t JOIN ".PaymentFunding::rows($this->db)." l ON l.club_id=t.club_id AND l.transaction_id=COALESCE(t.reversal_of,t.id) AND l.voided_at IS NULL JOIN accounting_positions p ON p.club_id=l.club_id AND p.entry_id=l.entry_id WHERE $where ORDER BY p.entry_id,p.ordinal";
            foreach($this->query($positionSql,$params) as $p)$positions[$p['entry_id']][]=$p;
            // A filtered period must use the same position cents as the complete payment history.
            $allSql="SELECT whole.id,whole.entry_id,whole.transaction_id,whole.cents,e.amount_cents,EXISTS(SELECT 1 FROM finance_transactions returned WHERE returned.club_id=payment.club_id AND returned.reversal_of=payment.id) AS returned FROM finance_allocations whole JOIN finance_transactions payment ON payment.club_id=whole.club_id AND payment.id=whole.transaction_id JOIN finance_entries e ON e.club_id=whole.club_id AND e.id=whole.entry_id WHERE whole.club_id=? AND whole.voided_at IS NULL AND whole.entry_id IN(SELECT l.entry_id FROM finance_transactions t JOIN ".PaymentFunding::rows($this->db)." l ON l.club_id=t.club_id AND l.transaction_id=COALESCE(t.reversal_of,t.id) AND l.voided_at IS NULL WHERE $where) ORDER BY whole.entry_id,payment.booked_on,payment.id,whole.id";
            $selected=[];foreach($allocations as $items)foreach($items as $l)$selected[$l['id']]=true;
            $cumulative=[];$returnedCumulative=[];$distribution=[];$positionPaid=[];
            $fundingEntries=[];foreach($allocations as$items)foreach($items as$item)$fundingEntries[$item['entry_id']]=true;$funding=$this->journalFunding($actor['club_id'],array_keys($fundingEntries));$fundedEntries=[];foreach($funding as$record)$fundedEntries[$record['entry_id']]=true;$refundFirsts=[];
            foreach($this->query($allSql,[$actor['club_id'],...$params]) as $l){
                $entry=$l['entry_id'];if(isset($funding[$l['id']]))continue;if(isset($fundedEntries[$entry])&&!$l['returned'])throw new DomainException('Eine spätere Zahlung hat keinen vollständigen ursprünglichen Centnachweis. Den gespeicherten Stand prüfen lassen.',503);$before=$l['returned']?($returnedCumulative[$entry][$l['transaction_id']]??0):($cumulative[$entry]??0);$after=$before+(int)$l['cents'];
                // All allocations of one returned movement retain their original
                // cumulative position slices. A later collection starts anew.
                if($l['returned'])$returnedCumulative[$entry][$l['transaction_id']]=$after;else $cumulative[$entry]=$after;
                if($after>(int)$l['amount_cents'])throw new DomainException('Beleg ist über seinen Betrag hinaus zugeordnet.');
                if(!isset($selected[$l['id']])||empty($positions[$entry]))continue;
                $prior=$before===0?array_fill(0,count($positions[$entry]),0):self::positionAmounts($before,(int)$l['amount_cents'],$positions[$entry]);
                $next=self::positionAmounts($after,(int)$l['amount_cents'],$positions[$entry]);
                foreach($next as $i=>$part){$delta=$part-$prior[$i];if($delta<0)throw new DomainException('Positionsverteilung konnte nicht fortgeführt werden.');$distribution[$l['id']][$i]=$delta;$positionPaid[$l['id']][$i]=['before'=>$prior[$i],'after'=>$part];}
            }
            $rows=[];$signedTotal=0;
            foreach($transactions as $t){
                $used=(int)$t['neutral_cents'];$source=$t['reversal_of']?:$t['id'];
                foreach($allocations[$source]??[] as $l){
                    $amount=abs((int)$l['cents']);$used+=$amount;$entryPositions=$positions[$l['entry_id']]??[];
                    $slices=$this->journalFundingSlices($actor['club_id'],$l,$entryPositions,$funding,$refundFirsts);
                    if(!$entryPositions)$rows[]=$this->journalRow($t,$amount,$l,null,'Beleg ohne Kontierungspositionen');
                    elseif($slices!==null){foreach($slices as$slice)foreach($slice['amounts']as$i=>$part)if($part>0)$rows[]=$this->journalRow($t,$part,$l,$entryPositions[$i],$l['refund_id']!==null?'Teilrückzahlung':'',$slice['paid'][$i]);}
                    else foreach($distribution[$l['id']] as $i=>$part)if($part>0)$rows[]=$this->journalRow($t,$part,$l,$entryPositions[$i],'',$positionPaid[$l['id']][$i]);
                }
                $rest=abs((int)$t['cents'])-$used;if($rest<0)throw new DomainException('Zahlung ist über ihren Betrag hinaus zugeordnet.');
                if($rest>0)$rows[]=$this->journalRow($t,$rest,null,null,'Nicht zugeordnete Zahlung');
                $signedTotal+=(int)$t['cents']-((int)$t['cents']<0?-1:1)*(int)$t['neutral_cents'];
                if(count($rows)>50000)throw new DomainException('Mehr als 50.000 Journalpositionen. Bitte Zeitraum oder Konto eingrenzen.');
            }
            if(array_sum(array_column($rows,'signed_cents'))!==$signedTotal)throw new DomainException('Journal konnte nicht mit den Zahlungen abgestimmt werden.');
            return ['type'=>'journal','filters'=>$f,'rows'=>$rows,'signed_total_cents'=>$signedTotal,'count'=>count($rows)];
        });
    }

    private function journalRow(array $transaction,int $amount,?array $allocation,?array $position,string $note='',?array $paid=null):array
    {
        $incoming=(int)$transaction['cents']>0;$code=$position['account_code']??'';$positionName=$position['account_name']??'';
        if($positionName==='')$positionName=$allocation?'Nicht kontierter Beleg':'Nicht zugeordnet';
        $notes=[];if($transaction['reversal_of'])$notes[]='Gegenbuchung';if($note!=='')$notes[]=$note;
        if($allocation&&$code==='')$notes[]='Sachkontierung fehlt';$notes[]='SKR-Kontonummer des Geldkontos fehlt';
        if(($allocation['kind']??'')==='expense'&&$position!==null)$notes[]='Vorsteuer: '.(InputTaxTreatment::OPTIONS[$position['input_tax']??'unknown']??'Ungültig');
        $row=[
            'account_id'=>$transaction['account_id'],'catalog_year'=>$position['catalog_year']??null,'transaction_id'=>$transaction['id'],'payment_date'=>$transaction['booked_on'],'transaction_number'=>$transaction['id'],
            'entry_id'=>$allocation['entry_id']??null,'receipt_number'=>$allocation?($allocation['display_number']?:sprintf('KH-%05d',$allocation['number'])):'',
            'position_id'=>$position&&$allocation?$allocation['entry_id'].':'.$position['ordinal']:null,'position_ordinal'=>$position?(int)$position['ordinal']:null,
            'position_paid_before_cents'=>$paid['before']??null,'position_paid_after_cents'=>$paid['after']??null,
            'amount_cents'=>$amount,'signed_cents'=>$incoming?$amount:-$amount,
            'debit_account_number'=>$incoming?'':$code,'debit_account_name'=>$incoming?$transaction['account_name']:$positionName,
            'credit_account_number'=>$incoming?$code:'','credit_account_name'=>$incoming?$positionName:$transaction['account_name'],
            'sphere'=>$position['sphere']??'','booking_key'=>$position['booking_key']??'','tax_rate'=>$position===null?null:(int)$position['tax_rate'],
            'entry_kind'=>$allocation['kind']??'','input_tax'=>$position['input_tax']??'unknown',
            'description'=>$position['description']??$allocation['entry_description']??$transaction['description'],'counterparty'=>$transaction['counterparty'],
            'info'=>implode(' · ',array_unique($notes)),'reversal_of'=>$transaction['reversal_of'],
        ];
        if(($allocation['refund_id']??null)!==null)$row['partial_return_id']=$allocation['refund_id'];
        return $row;
    }
}
