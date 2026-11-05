<?php declare(strict_types=1);
namespace Kulturhus\Verein;

use DomainException;

/** Timed promises are separate from the physical quantity ledger. All writes use Inventory::change. */
trait InventoryLoanWindows
{
    private ?bool $loanWindowSchema=null;
    public function loanWindowsReady():bool
    {
        return $this->loanWindowSchema??=!array_diff(['window_start','window_end'],Database::columns($this->db,'inventory_loans'));
    }
    private function requireLoanWindows():void
    {
        if(!$this->loanWindowsReady())throw new DomainException('Für Zeitfenster zuerst das geprüfte Paketupgrade mit Datenbanksicherung ausführen.');
    }
    public static function windowUtc(mixed $value):string
    {
        if(!is_string($value)||!preg_match('/\A(18|19|20)[0-9]{2}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}\z/D',$value))throw new DomainException('Gültige lokale Uhrzeit für das Zeitfenster angeben.');
        $tz=new \DateTimeZone('Europe/Berlin');$day=\DateTimeImmutable::createFromFormat('!Y-m-d\TH:i',$value,$tz);
        if(!$day||$day->format('Y-m-d\TH:i')!==$value)throw new DomainException('Ungültige lokale Uhrzeit im Zeitfenster (gegebenenfalls Zeitumstellung).');
        $transitions=$tz->getTransitions($day->getTimestamp()-86400,$day->getTimestamp()+86400);
        for($i=1;$i<count($transitions);$i++){$delta=$transitions[$i-1]['offset']-$transitions[$i]['offset'];$at=$transitions[$i]['ts'];if($delta>0&&$day->getTimestamp()>=$at-$delta&&$day->getTimestamp()<$at+$delta)throw new DomainException('Diese Uhrzeit ist bei der Zeitumstellung doppeldeutig. Eine eindeutige Uhrzeit wählen.');}
        return $day->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }
    public static function windowLocal(?string $value,string $format='Y-m-d\TH:i'):string
    {
        return $value===null||$value===''?'':(new \DateTimeImmutable($value,new \DateTimeZone('UTC')))->setTimezone(new \DateTimeZone('Europe/Berlin'))->format($format);
    }
    private function windowPair(array $data):array
    {
        $this->requireLoanWindows();$start=self::windowUtc($data['window_start']??'');$end=self::windowUtc($data['window_end']??'');
        if($end<=$start)throw new DomainException('Das Ende des Zeitfensters muss nach dem Beginn liegen.');
        return [$start,$end];
    }
    private function windowCapacitySql():string
    {
        // Only issued timed quantities have a planned return. Legacy undated issues are not guessed back into stock.
        return "(s.shelf-s.reserved+COALESCE((SELECT SUM(p.quantity-p.returned-p.lost) FROM inventory_loan_lines p JOIN inventory_loans w ON w.club_id=p.club_id AND w.id=p.loan_id WHERE p.club_id=s.club_id AND p.item_id=s.item_id AND p.location_id=s.location_id AND w.state='issued' AND w.window_start IS NOT NULL),0))";
    }
    private function windowAvailability(string $club,string $item,string $location,string $start,string $end,string $exclude=''):array
    {
        $capacity=(int)$this->query('SELECT '.$this->windowCapacitySql().' FROM inventory_stock s WHERE s.club_id=? AND s.item_id=? AND s.location_id=?',[$club,$item,$location])->fetchColumn();
        $now=gmdate('Y-m-d H:i:s');
        // An actual overdue issue remains occupied until its physical return is confirmed.
        $until="CASE WHEN w.state='issued' AND w.window_end<=? THEN '9999-12-31 23:59:59' ELSE w.window_end END";
        $rows=$this->query("SELECT w.window_start,($until) AS busy_until,p.quantity-p.returned-p.lost AS quantity FROM inventory_loan_lines p JOIN inventory_loans w ON w.club_id=p.club_id AND w.id=p.loan_id
            WHERE p.club_id=? AND p.item_id=? AND p.location_id=? AND w.state IN ('reserved','issued') AND w.window_start IS NOT NULL AND w.id<>?
            AND w.window_start<? AND ($until)>? ORDER BY w.window_start,w.id LIMIT 10001",[$now,$club,$item,$location,$exclude,$end,$now,$start])->fetchAll();
        if(count($rows)>10000)throw new DomainException('Zu viele überlappende Zeitfenster. Zeitraum eingrenzen.');
        $events=[];foreach($rows as$row){$quantity=(int)$row['quantity'];if($quantity<=0)continue;$from=max($start,$row['window_start']);$to=min($end,$row['busy_until']);$events[$from]=($events[$from]??0)+$quantity;$events[$to]=($events[$to]??0)-$quantity;}
        ksort($events,SORT_STRING);$used=0;$peak=0;foreach($events as$delta){$used+=$delta;$peak=max($peak,$used);}
        return ['capacity'=>$capacity,'peak'=>$peak,'available'=>max(0,$capacity-$peak),'shortage'=>max(0,$peak-$capacity),'overlaps'=>count($rows)];
    }
    /** Read-only on-demand preview, using current permissions and current stock; never a binding reservation. */
    public function reviewLoanWindow(array $data):array
    {
        $actor=$this->actor(true,true);[$start,$end]=$this->windowPair($data);$lines=$data['lines']??null;
        if(!is_array($lines)||!count($lines)||count($lines)>50)throw new DomainException('Bitte 1 bis 50 Ausleihpositionen auswählen.');$rows=[];
        foreach($lines as$line){if(!is_array($line)||!is_string($line['stock']??null))throw new DomainException('Ausleihposition prüfen.');$selection=$this->loanStockSelection($line['stock'],true);if(!$selection)throw new DomainException('Bestand erneut auswählen.');
            $quantity=self::integer($line['quantity']??'',1);$availability=$this->windowAvailability($actor['club_id'],$selection['item_id'],$selection['location_id'],$start,$end);
            $rows[]=['name'=>$selection['item_name'],'requested'=>$quantity,'fits'=>$quantity<=$availability['available']]+$availability;
        }
        if($this->actor(true,true)['club_id']!==$actor['club_id'])throw new DomainException('Vereinszugang geändert.');
        return ['start'=>$start,'end'=>$end,'rows'=>$rows];
    }
    public function loanWindowStatus(string $id):array
    {
        $loan=$this->loan($id);if(empty($loan['window_start'])||!in_array($loan['state'],['reserved','issued'],true))return[];$rows=[];
        foreach($loan['lines']as$line){$quantity=(int)$line['quantity']-(int)$line['returned']-(int)$line['lost'];if($quantity<=0)continue;
            $availability=$this->windowAvailability($loan['club_id'],$line['item_id'],$line['location_id'],$loan['window_start'],$loan['window_end'],$loan['id']);
            $rows[]=['name'=>$line['item_name'],'requested'=>$quantity,'fits'=>$quantity<=$availability['available']]+$availability;
        }
        return $rows;
    }
    public static function loanOverdue(array $loan):bool
    {
        return $loan['state']==='issued'&&(!empty($loan['window_end'])?$loan['window_end']<=gmdate('Y-m-d H:i:s'):$loan['due_on']<self::today());
    }
    private function assertWindowQuantity(string $club,string $item,string $location,string $start,string $end,int $quantity,string $exclude=''):void
    {
        $availability=$this->windowAvailability($club,$item,$location,$start,$end,$exclude);
        if($quantity>$availability['available'])throw new DomainException('Nicht genügend Menge im gewählten Zeitfenster. Überschneidungen, tatsächliche Rückgaben und aktuellen Bestand prüfen.');
    }
    private function assertFutureWindowStock(string $club,array $pairs):void
    {
        if(!$this->loanWindowsReady())return;
        foreach($pairs as[$item,$location]){$availability=$this->windowAvailability($club,$item,$location,gmdate('Y-m-d H:i:s'),'2100-01-01 00:00:00');if($availability['shortage'])throw new DomainException('Die sofortige Reservierung würde bestätigte Zeitfenster unterschreiten. Ein passendes Zeitfenster planen oder bestehende Planung bewusst ändern.');}
    }
    private function changeLoanWindow(array $loan,array $data,string $action):void
    {
        $club=$loan['club_id'];$start=$action==='reschedule'?self::windowUtc($data['window_start']??''):$loan['window_start'];$end=self::windowUtc($data['window_end']??'');
        if($end<=$start||($loan['state']==='issued'&&$end<=gmdate('Y-m-d H:i:s')))throw new DomainException('Zeitfenster und tatsächliche laufende Ausgabe prüfen.');
        foreach($this->query('SELECT * FROM inventory_loan_lines WHERE club_id=? AND loan_id=?',[$club,$loan['id']])->fetchAll()as$line){
            self::active($this->row('inventory_items',$club,$line['item_id']));self::active($this->row('inventory_locations',$club,$line['location_id']));
            $this->assertWindowQuantity($club,$line['item_id'],$line['location_id'],$start,$end,(int)$line['quantity']-(int)$line['returned']-(int)$line['lost'],$loan['id']);
        }
        self::text($data['reason']??'',1000,true);
        $this->query('UPDATE inventory_loans SET window_start=?,window_end=?,starts_on=?,due_on=?,version=version+1 WHERE club_id=? AND id=?',[$start,$end,self::windowLocal($start,'Y-m-d'),self::windowLocal($end,'Y-m-d'),$club,$loan['id']]);
    }
}
