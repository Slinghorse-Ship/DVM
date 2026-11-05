<?php declare(strict_types=1);
namespace Kulturhus\Verein;
use DomainException;

trait Accounting
{
    public function taxAccounts(int $year=TaxCatalog::YEAR):array
    {
        $a=$this->actor();if(!TaxCatalog::hasYear($year))return [];$rows=TaxCatalog::accounts($year);$q=$this->db->prepare('SELECT * FROM tax_account_settings WHERE club_id=? AND year=?'.($this->db->inTransaction()?' FOR SHARE NOWAIT':''));
        try{$q->execute([$a['club_id'],$year]);}catch(\PDOException $failure){if(in_array((int)($failure->errorInfo[1]??0),[1205,3572],true))throw new DomainException('Steuerkonten werden gerade bearbeitet. Bitte erneut prüfen.',503,$failure);throw$failure;}
        foreach($q->fetchAll() as $r){$rows[$r['code']]=array_replace($rows[$r['code']]??[],$r);if($rows[$r['code']]['blocked']??false)$rows[$r['code']]['active']=0;}ksort($rows,SORT_STRING);return $rows;
    }
    public function inputTaxDefault():string
    {
        $this->actor();$data=(new Settings($this->db,$this->actorId))->section('tax')['data'];
        if(($data['input_tax_eligibility']??'')==='no')return 'none';
        return InputTaxTreatment::validate($data['input_tax_default']??'unknown','expense');
    }
    /** The club-wide eligibility decision is separate from each invoice position. */
    public function inputTaxEligibility():string
    {
        $this->actor();$value=(new Settings($this->db,$this->actorId))->section('tax')['data']['input_tax_eligibility']??'';
        if(!in_array($value,['','no','yes'],true))throw new DomainException('Vorsteuerabzugsberechtigung in den Vereinseinstellungen prüfen.');
        return $value;
    }
    public function saveTaxAccount(string $code,string $name,bool $active,int $version,bool $createOnly=false):void
    {
        $this->transaction(function()use($code,$name,$active,$version,$createOnly){$a=$this->actor();$name=trim($name);
            if(!preg_match('/^[0-79][0-9]{4}$/D',$code)||$name===''||strlen($name)>200)throw new DomainException('Fünfstellige Kontonummer und Bezeichnung prüfen.');
            $category=TaxCatalog::group($code);$current=$this->taxAccounts()[$code]??null;
            if($createOnly&&$current)throw new DomainException('Kontonummer bereits vorhanden. Zum Ändern den bestehenden Eintrag öffnen.');
            if($active&&($current['blocked']??false))throw new DomainException('Reserviertes oder widersprüchliches Quellkonto kann nicht aktiviert werden.');
            if(!$current&&TaxCatalog::reserved($code))throw new DomainException('Unterkonten eines reservierten Quellbereichs benötigen erst eine geprüfte Kontenfunktion.');
            if((int)($current['version']??0)!==$version)throw new DomainException('Steuerkonto inzwischen geändert. Bitte neu laden.');
            $this->db->prepare('INSERT INTO tax_account_settings(club_id,year,code,name,category,active,custom,version) VALUES(?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE name=VALUES(name),active=VALUES(active),version=VALUES(version)')->execute([$a['club_id'],TaxCatalog::YEAR,$code,$name,$category,(int)$active,isset(TaxCatalog::accounts()[$code])?0:1,$version+1]);
            $this->audit($a,'finance.tax-account.saved',$code,$version+1);
        });
    }
    private static function positionColumn(string $type):string{return match($type){'entry'=>'entry_id','goods'=>'goods_id',default=>throw new DomainException('Unbekannte Positionsart.')};}
    public function positions(string $type,string $id,bool $current=false):array
    {
        if($current&&!$this->db->inTransaction())throw new DomainException('Aktuelle Rechnungspositionen benötigen einen offenen Speichervorgang.');$a=$this->actor();$column=self::positionColumn($type);if($type==='entry')$current?$this->currentEntry($id):$this->entry($id);elseif(!$current)$this->goodsEntry($id);if($current&&$type==='goods'){$source=$this->db->prepare('SELECT id FROM goods_donations WHERE club_id=? AND id=? FOR SHARE NOWAIT');$source->execute([$a['club_id'],$id]);if(!$source->fetchColumn())throw new DomainException('Sachspende nicht verfügbar.');}
        $q=$this->db->prepare("SELECT ordinal,description,gross_cents,tax_rate,tax_cents,sphere,account_code,account_name,account_category,catalog_year,booking_key,booking_key_snapshot,input_tax FROM accounting_positions WHERE club_id=? AND $column=? ORDER BY ordinal".($current?' FOR SHARE NOWAIT':''));$q->execute([$a['club_id'],$id]);return $q->fetchAll();
    }
    /** Read-only manual-form totals from the same normalizer used when saving. */
    public function previewEntryPositions(array $positions,string $kind,string $date):array
    {
        $actor=$this->actor();if(!isset(self::KINDS[$kind]))throw new DomainException('Belegart und Kategorie prüfen.');
        $rows=$this->normalizePositions($positions,$kind,$date);$gross=0;$tax=0;foreach($rows as$row){$gross+=(int)$row['gross_cents'];$tax+=(int)$row['tax_cents'];}
        if($this->actor()['club_id']!==$actor['club_id'])throw new DomainException('Vereinszugang geändert.',409);
        return ['gross_cents'=>$gross,'tax_cents'=>$tax,'net_cents'=>$gross-$tax];
    }
    public static function positionsFromForm(array $data):array
    {
        $keys=['position_description','position_amount','position_sphere','position_account','position_tax'];$columns=[];
        foreach($keys as $key){if(!isset($data[$key])||!is_array($data[$key])||!array_is_list($data[$key]))throw new DomainException('Positionsformular unvollständig.');$columns[$key]=$data[$key];}
        $columns['position_booking_key']=$data['position_booking_key']??array_fill(0,count($columns[$keys[0]]),'');if(!is_array($columns['position_booking_key'])||!array_is_list($columns['position_booking_key']))throw new DomainException('Schlüsselfelder prüfen.');$count=count($columns[$keys[0]]);if($count<1||$count>50)throw new DomainException('Bitte 1 bis 50 Positionen erfassen.');
        if(isset($data['position_input_tax'])){if(!is_array($data['position_input_tax'])||!array_is_list($data['position_input_tax']))throw new DomainException('Vorsteuerfelder prüfen.');$columns['position_input_tax']=$data['position_input_tax'];}
        foreach($columns as $values){if(count($values)!==$count||count(array_filter($values,'is_string'))!==$count)throw new DomainException('Positionsfelder passen nicht zusammen.');}
        $out=[];for($i=0;$i<$count;$i++){$p=['description'=>$columns['position_description'][$i],'amount'=>$columns['position_amount'][$i],'sphere'=>$columns['position_sphere'][$i],'account'=>$columns['position_account'][$i],'tax_rate'=>$columns['position_tax'][$i],'booking_key'=>$columns['position_booking_key'][$i]];if(isset($columns['position_input_tax']))$p['input_tax']=$columns['position_input_tax'][$i];$out[]=$p;}return $out;
    }
    /** Current original and revision comparison; a caller's transaction remains theirs. */
    public function currentPositionState(string $type,string $id):array
    {
        return $this->positionRead(fn()=>$this->readCurrentPositionState($type,$id));
    }
    /** Preview uses the same normalizer and the actual previous historical snapshots as saving. */
    public function previewPositionUpdate(string $type,string $id,array $input):array
    {
        return $this->positionRead(function()use($type,$id,$input){$state=$this->readCurrentPositionState($type,$id);$source=$state['source'];$rows=$this->normalizePositions($input,$type==='entry'?$source['kind']:'goods',$type==='entry'?$source['document_date']:$source['received_on'],$state['positions']);InvoiceRecipientTax::assertExisting($this->db,$this->actorId,$type,$id,$rows);$gross=(int)array_sum(array_column($rows,'gross_cents'));$tax=(int)array_sum(array_column($rows,'tax_cents'));return ['state'=>$state,'preview'=>['gross_cents'=>$gross,'tax_cents'=>$tax,'net_cents'=>$gross-$tax]];});
    }
    private function positionRead(callable $read):mixed
    {
        $own=!$this->db->inTransaction();try{if($own)$this->db->beginTransaction();$result=$read();if($own)$this->db->commit();return$result;}catch(\Throwable $failure){if($own&&$this->db->inTransaction())$this->db->rollBack();if($failure instanceof \PDOException&&in_array((int)($failure->errorInfo[1]??0),[1205,3572],true))throw new DomainException('Beleg wird gerade bearbeitet. Bitte erneut prüfen.',503,$failure);throw$failure;}
    }
    private function readCurrentPositionState(string $type,string $id):array
    {
        self::positionColumn($type);$a=$this->actor();$table=$type==='entry'?'finance_entries':'goods_donations';$q=$this->db->prepare('SELECT * FROM '.$table.' WHERE club_id=? AND id=? FOR SHARE NOWAIT');$q->execute([$a['club_id'],$id]);$source=$q->fetch();if(!$source)throw new DomainException($type==='entry'?'Beleg nicht verfügbar.':'Sachspende nicht verfügbar.',404);if($type==='entry')$source=$this->currentEntry($id);$positions=$this->positions($type,$id,true);$q=$this->db->prepare('SELECT * FROM accounting_revisions WHERE club_id=? AND object_type=? AND object_id=? ORDER BY version DESC FOR SHARE NOWAIT');$q->execute([$a['club_id'],$type,$id]);$state=['source'=>$source,'positions'=>$positions,'history'=>$q->fetchAll()];return$state+['hash'=>hash('sha256',json_encode($state,JSON_THROW_ON_ERROR))];
    }
    private function normalizePositions(array $input,string $kind,string $date,array $previous=[],?array $rounding=null):array
    {
        self::date($date);
        if(!array_is_list($input)||count($input)<1||count($input)>50)throw new DomainException('Bitte 1 bis 50 Positionen erfassen.');
        $documentYear=TaxCatalog::documentYear($date);$catalogYear=TaxCatalog::assignmentYear($documentYear);$catalog=$this->taxAccounts($catalogYear);$rows=[];$sum=0;$eligibility=$kind==='expense'?$this->inputTaxEligibility():'';$default=$kind==='expense'&&$previous===[]?$this->inputTaxDefault():'unknown';
        foreach($input as $i=>$p){if(!is_array($p))throw new DomainException('Ungültige Position.');
            $description=self::field($p,'description',300,true);$gross=self::cents(self::field($p,'amount',30,true));$sphere=self::field($p,'sphere',2);$code=self::field($p,'account',5);$rate=self::field($p,'tax_rate',2,true);
            if(!$gross||!in_array($rate,['0','7','19'],true)||(in_array($kind,['donation','goods'],true)&&$rate!=='0'))throw new DomainException('Spenden mit 0 % und alle Positionen mit positivem Betrag erfassen.');
            if(($code!==''&&$sphere==='')||($sphere!==''&&TaxCatalog::sphereOf($sphere)===''))throw new DomainException('Eine gueltige Sphaere auswaehlen. Ein Steuerkonto braucht eine Sphaere; das Konto darf offen bleiben.');
            $old=$previous[$i]??null;$requestedInputTax=array_key_exists('input_tax',$p)?$p['input_tax']:($old['input_tax']??$default);
            if($kind==='expense'&&$eligibility==='no'){
                if($requestedInputTax==='deductible')throw new DomainException('Der Verein ist laut Vereinseinstellung nicht vorsteuerabzugsberechtigt. Diese Position mit Bruttobetrag und ohne BU-Schlüssel erfassen.');
                $requestedInputTax='none';
            }
            $inputTax=InputTaxTreatment::validate($requestedInputTax,$kind,self::field($p,'booking_key',4));
            $accountName='';$accountGroup='';$year=null;
            if($code!==''){
                // Preserve an unchanged historical snapshot honestly; never relabel it as a different year's catalog.
                $account=$catalog[$code]??null;
                $unchanged=$old&&$old['account_code']===$code&&$old['sphere']===$sphere&&(int)$old['gross_cents']===$gross&&(int)$old['tax_rate']===(int)$rate&&($old['booking_key']??'')===($p['booking_key']??'')&&($old['input_tax']??'unknown')===$inputTax;
                if(!$account&&!$unchanged)throw new DomainException('Steuerkonto nicht im verwendeten SKR42-Katalog. Bitte ein aktives Konto auswählen oder die Zuordnung offen lassen.');
                if($account&&!$account['active']&&!$unchanged)throw new DomainException('Deaktiviertes Steuerkonto kann keiner neuen oder geänderten Position zugewiesen werden.');
                $accountName=$unchanged?$old['account_name']:$account['name'];$accountGroup=$unchanged?$old['account_category']:$account['category'];$year=$unchanged?$old['catalog_year']:$catalogYear;
            }
            $bookingKey=self::field($p,'booking_key',4);$bookingSnapshot='';
            if($bookingKey!==''){
                if($code===''||in_array($kind,['donation','goods','contribution'],true))throw new DomainException('Für diesen Beleg keinen Umsatzsteuer-/Vorsteuerschlüssel verwenden.');
                $rule=TaxCatalog::supportedKeys($catalogYear)[$bookingKey]??null;
                if(!$rule&&!empty($unchanged)&&$old['booking_key_snapshot']!=='')$rule=json_decode($old['booking_key_snapshot'],true,64,JSON_THROW_ON_ERROR);
                if(!$rule)throw new DomainException('Dieser Schlüssel benötigt einen noch nicht umgesetzten Steuerablauf. Die vollständige Referenzliste steht unter Buchungsschlüssel.');
                if(($kind==='expense'?'expense':'income')!==$rule['direction']||(int)$rate!==$rule['local_rate'])throw new DomainException('Steuerschlüssel, Belegart und Steuersatz passen nicht zusammen.');
                if(str_contains($catalog[$code]['function']??'','AM')||str_contains($catalog[$code]['function']??'','AV'))throw new DomainException('Ein gesonderter Steuerschlüssel darf nicht mit einem Automatikkonto kombiniert werden.');
                $bookingSnapshot=json_encode($rule,JSON_THROW_ON_ERROR);
            }
            // Gross input, commercial rounding per position; int64 range checked by cents().
            $tax=(int)$rate===0?0:intdiv($gross*(int)$rate+intdiv(100+(int)$rate,2),100+(int)$rate);
            $prior=$previous[$i]??null;if($rounding===null&&$prior&&(int)$prior['gross_cents']===$gross&&(int)$prior['tax_rate']===(int)$rate&&abs((int)$prior['tax_cents']-$tax)<=1)$tax=(int)$prior['tax_cents'];
            $rows[]=['ordinal'=>$i+1,'description'=>$description,'gross_cents'=>$gross,'tax_rate'=>(int)$rate,'tax_cents'=>$tax,'sphere'=>$sphere,'account_code'=>$code,'account_name'=>$accountName,'account_category'=>$accountGroup,'catalog_year'=>$year,'booking_key'=>$bookingKey,'booking_key_snapshot'=>$bookingSnapshot,'input_tax'=>$inputTax];$sum+=$gross;
            if($sum>100000000000)throw new DomainException('Positionssumme ist zu groß.');
        }
        if($rounding!==null){if(!is_int($rounding['total_cents']??null)||($rounding['confirmed']??false)!==true)throw new DomainException('Steuerrundung ausdrücklich am Original bestätigen.');$rows=self::roundInvoiceTax($rows,$rounding['total_cents'],true);}
        return $rows;
    }
    /** Explicit invoice-total rounding only: no gross/position amount mutation. */
    public static function roundInvoiceTax(array $rows,int $target,bool $confirmed):array
    {
        $sum=array_sum(array_column($rows,'tax_cents'));$delta=$target-$sum;if($delta===0)return $rows;
        $rates=array_unique(array_column($rows,'tax_rate'));
        if(!$confirmed||count($rates)!==1||(int)reset($rates)<=0||$target<0||abs($delta)>intdiv(count($rows)+1,2))throw new DomainException('Steuersumme prüfen. Eine kleine Rundungsverteilung ist nur nach ausdrücklicher Bestätigung bei einem einheitlichen Steuersatz möglich.');
        $order=array_keys($rows);usort($order,static function($a,$b)use($rows,$delta){$fraction=static fn($r)=>$r['gross_cents']*$r['tax_rate']-$r['tax_cents']*(100+$r['tax_rate']);$cmp=$fraction($rows[$a])<=>$fraction($rows[$b]);return ($delta>0?-$cmp:$cmp)?:($a<=>$b);});
        foreach(array_slice($order,0,abs($delta)) as $index){$rows[$index]['tax_cents']+=$delta>0?1:-1;if($rows[$index]['tax_cents']<0||$rows[$index]['tax_cents']>$rows[$index]['gross_cents'])throw new DomainException('Steuerrundung außerhalb des Positionsbetrags.');}
        return $rows;
    }
    private function insertPositions(array $actor,string $type,string $id,array $rows,bool $newEntry=false):void
    {
        // A freshly inserted entry has no earlier reimbursement recipient. Later edits always check it.
        if(!$newEntry)InvoiceRecipientTax::assertExisting($this->db,(string)$actor['id'],$type,$id,$rows);$column=self::positionColumn($type);$fields=['ordinal','description','gross_cents','tax_rate','tax_cents','sphere','account_code','account_name','account_category','catalog_year','booking_key','booking_key_snapshot','input_tax'];$q=$this->db->prepare("INSERT INTO accounting_positions(club_id,$column,".implode(',',$fields).") VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
        foreach($rows as $r){$r+=['input_tax'=>'unknown'];$q->execute([$actor['club_id'],$id,...array_map(static fn($key)=>$r[$key],$fields)]);}
    }
    public function updatePositions(string $type,string $id,int $version,array $input,string $reason):void
    {
        $this->transaction(fn()=>$this->updatePositionsLocked($type,$id,$version,$input,$reason));
    }
    public function updatePositionsLocked(string $type,string $id,int $version,array $input,string $reason):void
    {
        if(!$this->db->inTransaction())throw new DomainException('Kontierungsänderung benötigt einen Speichervorgang.');
        $a=$this->actor(true);$column=self::positionColumn($type);$table=$type==='entry'?'finance_entries':'goods_donations';try{$source=$this->db->prepare('SELECT * FROM '.$table.' WHERE club_id=? AND id=? FOR UPDATE NOWAIT');$source->execute([$a['club_id'],$id]);$original=$source->fetch();}catch(\PDOException $failure){if(in_array((int)($failure->errorInfo[1]??0),[1205,3572],true))throw new DomainException('Beleg wird gerade bearbeitet. Bitte erneut prüfen.',503,$failure);throw $failure;}if(!$original)throw new DomainException($type==='entry'?'Beleg nicht verfügbar.':'Sachspende nicht verfügbar.',404);$e=$type==='entry'?$this->currentEntry($id):$original;$reason=trim($reason);
            if((int)$e['version']!==$version)throw new DomainException('Beleg inzwischen geändert. Bitte neu laden.',409);
            if($reason===''||!mb_check_encoding($reason,'UTF-8')||mb_strlen($reason,'UTF-8')>500)throw new DomainException('Bitte einen Änderungsgrund angeben (höchstens 500 Zeichen).');
            if($type==='entry'){
                if(in_array($e['payment_state'],['cancelled','reversed'],true))throw new DomainException('Verworfene oder gegengebuchte Belege sind unveränderlich.');
                foreach($this->allocations($id,false,true) as $l){if($l['voided_at'])continue;$t=$this->transactionDetail($l['transaction_id'],true);$this->assertUnclosed($t['account_id'],$t['booked_on']);}
            }
            $old=$this->positions($type,$id,true);$rows=$this->normalizePositions($input,$type==='entry'?$e['kind']:'goods',$type==='entry'?$e['document_date']:$e['received_on'],$old);
            InvoiceRecipientTax::assertExisting($this->db,$this->actorId,$type,$id,$rows);
            if($type==='entry'&&Database::tableExists($this->db,'outgoing_invoices')){
                $issued=$this->db->prepare("SELECT 1 FROM outgoing_invoices WHERE club_id=? AND entry_id=? AND state IN ('issued','cancelled') FOR SHARE NOWAIT");$issued->execute([$a['club_id'],$id]);
                if($issued->fetchColumn()){$immutable=static fn(array $p)=>array_intersect_key($p,array_flip(['ordinal','description','gross_cents','tax_rate','tax_cents']));if(array_map($immutable,$old)!==array_map($immutable,$rows))throw new DomainException('Eine ausgegebene Rechnung bleibt unveränderlich. Nur die Kontierung darf angepasst werden; für Preise, Steuern oder Artikel eine Rechnungskorrektur erstellen.');}
            }
            if(array_sum(array_column($rows,'gross_cents'))!==(int)($e['amount_cents']??$e['value_cents']))throw new DomainException('Die Positionssumme muss dem gespeicherten Belegbetrag entsprechen.');
            $table=$type==='entry'?'finance_entries':'goods_donations';
            $this->db->prepare("DELETE FROM accounting_positions WHERE club_id=? AND $column=?")->execute([$a['club_id'],$id]);$this->insertPositions($a,$type,$id,$rows);
            $updated=$this->db->prepare("UPDATE $table SET version=version+1 WHERE club_id=? AND id=? AND version=?");$updated->execute([$a['club_id'],$id,$version]);if($updated->rowCount()!==1)throw new DomainException('Beleg inzwischen geändert. Bitte neu laden.',409);
            $this->db->prepare('INSERT INTO accounting_revisions(club_id,object_type,object_id,version,previous_positions,next_positions,reason,actor_id,happened_at) VALUES(?,?,?,?,?,?,?,?,?)')->execute([$a['club_id'],$type,$id,$version+1,json_encode($old,JSON_THROW_ON_ERROR),json_encode($rows,JSON_THROW_ON_ERROR),$reason,$a['id'],gmdate('Y-m-d\TH:i:s\Z')]);$this->audit($a,'finance.positions.updated',$id,$version+1);
    }
    public function positionHistory(string $type,string $id):array
    {
        $a=$this->actor();$this->positions($type,$id);$q=$this->db->prepare('SELECT * FROM accounting_revisions WHERE club_id=? AND object_type=? AND object_id=? ORDER BY version DESC');$q->execute([$a['club_id'],$type,$id]);return $q->fetchAll();
    }
}
