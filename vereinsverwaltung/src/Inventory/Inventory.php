<?php declare(strict_types=1);
namespace Kulturhus\Verein;
require_once __DIR__.'/InventoryDeletion.php';
require_once __DIR__.'/InventoryAcquisitions.php';
require_once __DIR__.'/InventoryLoanWindows.php';

use PDO;
use DomainException;

/** Stock is a quantity ledger. No inventory operation creates a financial transaction. */
final class Inventory
{
    use InventoryAssignments;
    use InventoryDeletion;
    use InventoryPurchaseUnits;
    use InventoryAccessories;
    use InventoryAcquisitions;
    use InventoryLoanWindows;
    public const STATES=['reserved'=>'Reserviert','issued'=>'Ausgegeben','returned'=>'Abgeschlossen','cancelled'=>'Storniert'];
    public const UNITS=['Stück'=>'Stück','Set'=>'Set','Satz'=>'Satz','Paar'=>'Paar','Packung'=>'Packung','Karton'=>'Karton','Kiste'=>'Kiste','Rolle'=>'Rolle','Flasche'=>'Flasche','Meter'=>'Meter (m)','Zentimeter'=>'Zentimeter (cm)','Liter'=>'Liter (l)','Milliliter'=>'Milliliter (ml)','Kilogramm'=>'Kilogramm (kg)','Gramm'=>'Gramm (g)'];
    public const ACTIONS=['accessories'=>'Zubehörzuordnung gepflegt','archive'=>'Gegenstand entfernt und archiviert','restore'=>'Gegenstand wiederhergestellt','location'=>'Lagerort gepflegt','item'=>'Inventar gepflegt','individual-create'=>'Einzelstücke angelegt','receive'=>'Zugang','remove'=>'Teilentfernung aus freiem Lagerbestand','consume'=>'Verbrauch','transfer'=>'Umlagerung','block'=>'Gesperrt / defekt','repair'=>'Freigegeben / repariert','discard'=>'Ausgesondert','count'=>'Inventurkorrektur','reserve'=>'Reserviert','plan'=>'Zeitfenster geplant','reschedule'=>'Zeitfenster geändert','issue'=>'Ausgegeben','cancel'=>'Reservierung storniert','return'=>'Rückgabe / Verlust','extend'=>'Frist geändert','relocate'=>'Aufenthaltsort bestätigt'];
    public function __construct(private PDO $db, private string $actorId) {}

    private function actor(bool $write=false, bool $own=false): array
    {
        $a=Permissions::actor($this->db,$this->actorId);
        if($write?($a[$own?'loans_access':'inventory_access']!=='write'):($a['inventory_access']==='none'&&$a['loans_access']==='none'))
            throw new DomainException('Keine Berechtigung für Material, Inventar und Ausleihen.');
        return $a;
    }
    /** Existing free labels, including archived items, scoped to the authorized club. */
    public function categories():array
    {
        $a=$this->actor();return $this->query("SELECT DISTINCT category FROM inventory_items WHERE club_id=? AND trim(category)<>'' ORDER BY category,category",[$a['club_id']])->fetchAll(PDO::FETCH_COLUMN);
    }
    public function canRead(): bool {try{$this->actor();return true;}catch(DomainException){return false;}}
    public function canWrite(): bool {try{$this->actor(true);return true;}catch(DomainException){return false;}}
    public function canWriteLoans(): bool {try{$this->actor(true,true);return true;}catch(DomainException){return false;}}
    public function canReadLoans(): bool {try{$this->actor(false,true);return true;}catch(DomainException){return false;}}
    /** Compact quantities for authorized inventory/loan roles, without private details. */
    public function memberItems(string $search=''):array
    {
        $a=$this->actor();$search=self::text($search,150);
        return $this->query("SELECT i.id,i.name,i.category,i.unit,i.content_quantity,i.content_unit,CAST(COALESCE(SUM(s.shelf-s.reserved),0) AS SIGNED) available,CAST(COALESCE(SUM(s.reserved),0) AS SIGNED) reserved,CAST(COALESCE(SUM(s.lent),0) AS SIGNED) lent FROM inventory_items i LEFT JOIN inventory_stock s ON s.club_id=i.club_id AND s.item_id=i.id WHERE i.club_id=? AND i.deleted_at IS NULL AND i.active=1 AND i.cash_account IS NULL AND instr(lower(CONCAT(i.name,' ',i.category)),lower(?))>0 GROUP BY i.club_id,i.id ORDER BY i.name",[$a['club_id'],$search])->fetchAll();
    }
    private function query(string $sql,array $args=[]): \PDOStatement {$q=$this->db->prepare($sql);$q->execute($args);return $q;}
    private function row(string $table,string $club,string $id): array
    {
        $r=$this->query("SELECT * FROM $table WHERE club_id=? AND id=?",[$club,$id])->fetch();
        if(!$r||($table==='inventory_items'&&!empty($r['deleted_at'])))throw new DomainException('Eintrag nicht verfügbar.');return $r;
    }
    private static function text(mixed $value,int $max=160,bool $required=false): string
    {
        if(!is_string($value)||strlen($value)>$max||preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f]/',$value))throw new DomainException('Text ist ungültig oder zu lang.');
        $value=trim($value);if($required&&$value==='')throw new DomainException('Bitte alle Pflichtfelder ausfüllen.');return $value;
    }
    private static function integer(mixed $n,int $min=0): int
    {
        if((!is_string($n)&&!is_int($n))||!preg_match('/^\d{1,7}$/',(string)$n)||(int)$n<$min||(int)$n>1000000)throw new DomainException('Mengen müssen ganze Zahlen zwischen '.$min.' und 1.000.000 sein.');return (int)$n;
    }
    private static function date(mixed $value): string
    {
        $v=self::text($value,10,true);$d=\DateTimeImmutable::createFromFormat('!Y-m-d',$v);
        if(!$d||$d->format('Y-m-d')!==$v)throw new DomainException('Bitte ein gültiges Datum eingeben.');return $v;
    }
    public static function today(): string {return (new \DateTimeImmutable('now',new \DateTimeZone('Europe/Berlin')))->format('Y-m-d');}
    private static function version(array $row,int $version,string $field='version'): void {if((int)$row[$field]!==$version)throw new DomainException('Der Eintrag wurde inzwischen geändert. Bitte neu laden und erneut prüfen.');}
    private static function active(array $r): void {if(!$r['active'])throw new DomainException('Archivierte Einträge können nicht verwendet werden.');}

    /** Permission and replay checks remain inside the serialized write transaction. */
    private function change(string $action,string $key,string $id,array $data,callable $fn,bool $withinInvoice=false): string
    {
        if(!preg_match('/^[a-f0-9]{32}$/D',$key))throw new DomainException('Ungültige Vorgangskennung. Bitte Formular neu öffnen.');
        $savepoint='';
        if($withinInvoice){if(!$this->db->inTransaction()||$action!=='item'||$id!=='')throw new DomainException('Neue Rechnungsgegenstände müssen zusammen mit der Rechnung gespeichert werden.');$savepoint='invoice_inventory_'.bin2hex(random_bytes(8));$this->db->exec('SAVEPOINT '.$savepoint);}
        else $this->db->beginTransaction();
        try{
            // Keep quantity and time-window decisions current even with a native PDO connection.
            $this->query('SELECT c.id FROM clubs c JOIN actors a ON a.club_id=c.id WHERE a.id=? FOR UPDATE',[$this->actorId])->fetchColumn();
            $a=$this->actor(true,in_array($action,['reserve','plan','reschedule','issue','cancel','return','extend','relocate'],true));$json=json_encode($data,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
            $hash=hash('sha256',$this->actorId.'|'.$action.'|'.$id.'|'.$json);
            if($action!=='purge'&&in_array($action,['item','archive','restore','receive','remove','consume','transfer','block','repair','discard','count'],true)&&$this->query('SELECT 1 FROM inventory_items WHERE club_id=? AND id=? AND deleted_at IS NOT NULL',[$a['club_id'],$id])->fetchColumn())throw new DomainException('Gelöschter Gegenstand kann nicht geändert werden.');
            $previous=$this->query('SELECT * FROM inventory_events WHERE club_id=? AND request_key=?',[$a['club_id'],$key])->fetch();
            if($previous){if(!hash_equals($previous['request_hash'],$hash))throw new DomainException('Diese Vorgangskennung wurde bereits mit anderen Eingaben verwendet.');if($savepoint!=='')$this->db->exec('RELEASE SAVEPOINT '.$savepoint);else $this->db->commit();return $previous['object_id'];}
            $id=$id?:bin2hex(random_bytes(16));$now=gmdate('Y-m-d\TH:i:s\Z');
            if(in_array($action,['receive','transfer'],true)){
                $item=$this->row('inventory_items',$a['club_id'],$id);
                if($item['cash_account']){$d=$data[1];$from=$action==='transfer'?$this->row('inventory_locations',$a['club_id'],self::text($d['location']??'',80)):null;$to=$this->row('inventory_locations',$a['club_id'],self::text($action==='transfer'?($d['destination']??''):($d['location']??''),80));$data['handover']=['from_place'=>$from['name']??'Erster Zugang','from_holder'=>$from['holder_id']??null,'to_place'=>$to['name'],'to_holder'=>$to['holder_id']];$json=json_encode($data,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);}
            }
            $this->query('INSERT INTO inventory_events(club_id,actor_id,action,object_id,happened_at,details,request_key,request_hash) VALUES(?,?,?,?,?,?,?,?)',[$a['club_id'],$a['id'],$action,$id,$now,$json,$key,$hash]);
            $event=(int)$this->db->lastInsertId();$version=$fn($a,$id,$event);
            if($action==='item')DocumentCatalog::register($this->db,$a['club_id'],'item',$id);
            elseif(in_array($action,['reserve','plan','reschedule','issue','cancel','return','extend','relocate'],true))DocumentCatalog::register($this->db,$a['club_id'],'loan',$id);
            $this->query('INSERT INTO audit_events(club_id,actor_id,action,object_id,object_version,happened_at) VALUES(?,?,?,?,?,?)',[$a['club_id'],$a['id'],'inventory.'.$action,$id,$version,$now]);
            if($savepoint!=='')$this->db->exec('RELEASE SAVEPOINT '.$savepoint);else $this->db->commit();return $id;
        }catch(\Throwable $e){if($savepoint!==''){$this->db->exec('ROLLBACK TO SAVEPOINT '.$savepoint);$this->db->exec('RELEASE SAVEPOINT '.$savepoint);}elseif($this->db->inTransaction())$this->db->rollBack();throw $e;}
    }

    public function locations(): array {return $this->query('SELECT * FROM inventory_locations WHERE club_id=? ORDER BY active DESC,name',[$this->actor()['club_id']])->fetchAll();}
    public function location(string $id):array
    {
        return $this->row('inventory_locations',$this->actor()['club_id'],$id);
    }
    public function saveLocation(string $id,int $version,array $data,string $key): string
    {
        return $this->change('location',$key,$id,[$version,$data],function($a,$target)use($id,$version,$data){
            $club=$a['club_id'];$name=self::text($data['name']??'',120,true);$notes=self::text($data['notes']??'',2000);$active=self::integer($data['active']??'1');if($active>1)throw new DomainException('Ungültiger Status.');
            if($id){$old=$this->row('inventory_locations',$club,$id);self::version($old,$version);if(!$active&&$this->query('SELECT 1 FROM inventory_stock WHERE club_id=? AND location_id=? AND shelf+lent+blocked>0',[$club,$id])->fetchColumn())throw new DomainException('Lagerort ist noch belegt. Bestände und offene Ausleihen zuerst auflösen.');}
            elseif($version!==0)throw new DomainException('Ungültige Version.');
            if($this->query('SELECT 1 FROM inventory_locations WHERE club_id=? AND name=? AND id<>?',[$club,$name,$target])->fetchColumn())throw new DomainException('Der Lagerort existiert bereits.');
            if($id)$this->query('UPDATE inventory_locations SET name=?,notes=?,active=?,version=version+1 WHERE club_id=? AND id=?',[$name,$notes,$active,$club,$id]);
            else $this->query('INSERT INTO inventory_locations(club_id,id,name,notes,active) VALUES(?,?,?,?,?)',[$club,$target,$name,$notes,$active]);
            $this->saveLocationAssignments($club,$target,$data,$id?$old:null);
            return $version+1;
        });
    }
    public function items(array $filters=[]): array
    {
        $club=$this->actor()['club_id'];$search=self::text($filters['q']??'',150);$group=self::text($filters['group']??'',80);
        $rows=$this->query("SELECT i.*,COALESCE((SELECT GROUP_CONCAT(u.serial_number ORDER BY u.created_event,u.sequence SEPARATOR ' ') FROM inventory_purchase_units u WHERE u.club_id=i.club_id AND u.item_id=i.id AND u.retired_event IS NULL),'') piece_serials,CAST(COALESCE(SUM(s.shelf),0) AS SIGNED) shelf,CAST(COALESCE(SUM(s.reserved),0) AS SIGNED) reserved,CAST(COALESCE(SUM(s.lent),0) AS SIGNED) lent,CAST(COALESCE(SUM(s.blocked),0) AS SIGNED) blocked FROM inventory_items i LEFT JOIN inventory_stock s ON s.club_id=i.club_id AND s.item_id=i.id WHERE i.club_id=? AND i.deleted_at IS NULL AND (?='' OR i.inventory_group_id=?) GROUP BY i.club_id,i.id ORDER BY i.active DESC,i.name,i.code",[$club,$group,$group])->fetchAll();
        return array_values(array_filter(array_map(function($r){$r['available']=$r['shelf']-$r['reserved'];$r['total']=$r['shelf']+$r['lent']+$r['blocked'];return $this->hideValues($r);},$rows),function($r)use($filters,$search){
            return ($search===''||mb_stripos($r['name'].' '.$r['code'].' '.$r['serial_number'].' '.$r['piece_serials'],$search)!==false)&&(!isset($filters['category'])||$filters['category']===''||$r['category']===$filters['category'])&&match($filters['status']??'active'){'all'=>true,'archived'=>!$r['active'],'low'=>$r['active']&&$r['available']<$r['minimum'],'blocked'=>$r['active']&&$r['blocked']>0,default=>(bool)$r['active']};
        }));
    }
    public function item(string $id): array
    {
        $club=$this->actor()['club_id'];$row=$this->row('inventory_items',$club,$id);$row['stock']=$this->stock($id);$row['purchase_units']=$this->purchaseUnits($id);$row['has_purchase_units']=$this->hasPurchaseUnits($club,$id);return $this->hideValues($row);
    }
    /** Exact legacy filters/order over compact stock facts, then selected originals. */
    public function itemsPage(array $filters=[],mixed $page=1):array
    {
        $club=$this->actor()['club_id'];$search=self::text($filters['q']??'',150);$group=self::text($filters['group']??'',80);
        if((!is_int($page)&&!is_string($page))||!preg_match('/^[1-9][0-9]{0,6}$/D',(string)$page)||(int)$page>1000000)throw new DomainException('Gegenstandsseite prüfen.');
        $owned=!$this->db->inTransaction();if($owned){$this->db->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');$this->db->beginTransaction();}
        $select=static function(bool$compact):string{return 'SELECT '.($compact?'i.id,i.name,i.code,i.serial_number,i.active,i.category,i.minimum':'i.*').",COALESCE((SELECT GROUP_CONCAT(u.serial_number ORDER BY u.created_event,u.sequence SEPARATOR ' ') FROM inventory_purchase_units u WHERE u.club_id=i.club_id AND u.item_id=i.id AND u.retired_event IS NULL),'') piece_serials,CAST(COALESCE(SUM(s.shelf),0) AS SIGNED) shelf,CAST(COALESCE(SUM(s.reserved),0) AS SIGNED) reserved,CAST(COALESCE(SUM(s.lent),0) AS SIGNED) lent,CAST(COALESCE(SUM(s.blocked),0) AS SIGNED) blocked FROM inventory_items i LEFT JOIN inventory_stock s ON s.club_id=i.club_id AND s.item_id=i.id WHERE i.club_id=? AND i.deleted_at IS NULL AND (?='' OR i.inventory_group_id=?)";};
        $matches=static fn(array$r):bool=>($search===''||mb_stripos($r['name'].' '.$r['code'].' '.$r['serial_number'].' '.$r['piece_serials'],$search)!==false)&&(!isset($filters['category'])||$filters['category']===''||$r['category']===$filters['category'])&&match($filters['status']??'active'){'all'=>true,'archived'=>!$r['active'],'low'=>$r['active']&&$r['available']<$r['minimum'],'blocked'=>$r['active']&&$r['blocked']>0,default=>(bool)$r['active']};
        try{
            $q=$this->query($select(true).' GROUP BY i.club_id,i.id ORDER BY i.active DESC,i.name,i.code',[$club,$group,$group]);$ids=[];while($r=$q->fetch()){$r['available']=$r['shelf']-$r['reserved'];if($matches($r))$ids[]=$r['id'];}$q->closeCursor();$total=count($ids);$pages=max(1,(int)ceil($total/6));$page=min((int)$page,$pages);$ids=array_slice($ids,($page-1)*6,6);$rows=[];
            if($ids){$selected=$this->query($select(false).' AND i.id IN ('.implode(',',array_fill(0,count($ids),'?')).') GROUP BY i.club_id,i.id',[$club,$group,$group,...$ids]);$byId=[];while($r=$selected->fetch()){$r['available']=$r['shelf']-$r['reserved'];$r['total']=$r['shelf']+$r['lent']+$r['blocked'];$byId[$r['id']]=$r;}foreach($ids as$id)$rows[]=$byId[$id];}
            if($owned)$this->db->commit();if($this->actor()['club_id']!==$club)throw new DomainException('Vereinszugang geändert.');$rows=array_map($this->hideValues(...),$rows);return compact('rows','total','page','pages');
        }catch(\Throwable$e){if($owned&&$this->db->inTransaction())$this->db->rollBack();throw$e;}
    }
    public function saveItem(string $id,int $version,array $data,string $key): string
    {
        return $this->saveItemOperation($id,$version,$data,$key);
    }
    /** Explicit composition point; never commits its caller's invoice transaction. */
    public function createFromInvoiceInTransaction(array $data):string
    {
        if(!$this->db->inTransaction()||!isset($data['invoice_position'],$data['home_location_version']))throw new DomainException('Rechnungsposition und Lagerort zusammen mit der Rechnung speichern.');
        $draft=InvoiceInventoryDraft::validate($this->db,$this->actorId,$data);
        return $this->saveItemOperation('',0,$data,$draft['key'],true);
    }
    private function saveItemOperation(string $id,int $version,array $data,string $key,bool $withinInvoice=false): string
    {
        if(isset($data['invoice_position'])){if($id!=='')throw new DomainException('Rechnungspositionsentwurf nur für neue Gegenstände verwenden.');$draft=InvoiceInventoryDraft::validate($this->db,$this->actorId,$data);$key=$draft['key'];}
        return $this->change('item',$key,$id,[$version,$data],function($a,$target,$event)use($id,$version,$data){
            $invoiceDraft=isset($data['invoice_position'])?InvoiceInventoryDraft::validate($this->db,$this->actorId,$data):null;
            $initial=0;$location='';
            if(array_key_exists('initial_quantity',$data)){
                if($id!=='')throw new DomainException('Den Anfangsbestand kann man nur bei einer neuen Anlage eingeben. Bestandsänderungen bitte über „Bestand bewegen“ erfassen.');
                $initial=self::integer($data['initial_quantity']);
                if($initial>0){$location=self::text($data['home_location']??'',80,true);$locationRow=$this->row('inventory_locations',$a['club_id'],$location);self::active($locationRow);if(isset($data['home_location_version']))self::version($locationRow,self::integer($data['home_location_version'],1));if(self::integer($data['active']??'1')!==1)throw new DomainException('Gegenstände mit Anfangsbestand müssen aktiv angelegt werden.');}
            }
            if(array_key_exists('purchase_units',$data)&&($id!==''||$initial<1))throw new DomainException('Stückdetails bei der Neuanlage zusammen mit dem Anfangsbestand erfassen; spätere Zugänge über Bestand bewegen.');
            $itemData=$data;if(array_key_exists('purchase_units',$data)){if($a['finance_access']==='none')unset($itemData['unit_value']);else $itemData['unit_value']='';}$nextVersion=$this->writeItem($a,$target,$id,$version,$itemData);if(array_key_exists('purchase_unit_updates',$data))$this->updatePurchaseDetails($a,$target,$data);
            if($initial>0){
                $item=$this->row('inventory_items',$a['club_id'],$target);$receipt=['kind'=>'receive','location'=>$location,'quantity'=>(string)$initial,'reason'=>'Anfangsbestand bei der Anlage geprüft und dem Lagerort zugeordnet.'];
                if($item['cash_account']){$this->checkCashHandover($a,$item,'receive',$data);$receipt['counted']=$data['counted'];}
                $event=$this->individualEvent($a,$target,$target,'receive',[0,$receipt]);$this->delta($a['club_id'],$target,$location,$event,$initial);if(array_key_exists('purchase_units',$data))$this->addPurchaseUnits($a,$target,$event,$data['purchase_units'],$initial,self::text($data['value_note']??'',1000));
            }
            if($invoiceDraft)InvoiceInventoryDraft::attach($this->db,$this->actorId,$target,$invoiceDraft);
            return $nextVersion;
        },$withinInvoice);
    }
    /** Validated metadata write, called only inside change()'s single transaction. */
    private function writeItem(array $a,string $target,string $id,int $version,array $data,string $group=''): int
    {
            $club=$a['club_id'];$old=$id?$this->row('inventory_items',$club,$id):null;if($old)self::version($old,$version);elseif($version!==0)throw new DomainException('Ungültige Version.');
            $tracking=$group!==''?'individual':($old['tracking_mode']??'quantity');
            if(isset($data['tracking_mode'])&&$data['tracking_mode']!==$tracking)throw new DomainException('Die Bestandsführung kann nicht nachträglich geändert werden. Neue Einzelstücke bitte über „Einzelstücke anlegen“ erfassen.');
            if(isset($data['inventory_group_id'])&&$data['inventory_group_id']!==($old['inventory_group_id']??$group))throw new DomainException('Die Gruppe der Einzelstücke kann hier nicht geändert werden.');
            if(!$old&&self::text($data['code']??'',60)==='')$data['code']=$this->nextIndividualCode($club);
            $code=self::text($data['code']??'',60,true);$name=self::text($data['name']??'',160,true);$category=self::text($data['category']??'',100);$unit=self::text($data['unit']??$old['unit']??'Stück',30,true);$kind=$data['kind']??'reusable';
            if(!isset(self::UNITS[$unit])&&(!$old||$unit!==$old['unit']))throw new DomainException('Bitte eine Einheit aus der Liste auswählen.');
            if(!in_array($kind,['reusable','consumable'],true))throw new DomainException('Ungültige Inventarart.');
            if($tracking==='individual'&&$kind!=='reusable')throw new DomainException('Einzelstücke sind wiederverwendbare Gegenstände. Verbrauchsmaterial bitte als Mengenbestand anlegen.');
            $minimum=self::integer($data['minimum']??'0');$serial=self::text($data['serial_number']??$old['serial_number']??'',100);$notes=self::text($data['notes']??'',4000);$active=self::integer($data['active']??'1');if($active>1)throw new DomainException('Ungültiger Status.');
            if($tracking==='individual'&&$minimum>1)throw new DomainException('Der Mindestbestand eines Einzelstücks kann höchstens 1 sein.');
            // Correcting a unit label does not move stock or rewrite existing loan snapshots.
            if($old&&(!$active||$kind!==$old['kind'])&&$this->query('SELECT 1 FROM inventory_stock WHERE club_id=? AND item_id=? AND shelf+lent+blocked>0',[$club,$id])->fetchColumn())throw new DomainException('Archivieren oder Art ändern ist erst ohne Bestand und offene Ausleihen möglich.');
            if($this->query('SELECT 1 FROM inventory_items WHERE club_id=? AND code=? AND id<>?',[$club,$code,$target])->fetchColumn())throw new DomainException('Diese Inventarnummer ist bereits vergeben.');
            if($tracking==='individual'&&$serial!==''&&$this->query("SELECT 1 FROM inventory_items WHERE club_id=? AND tracking_mode='individual' AND serial_number=? AND id<>?",[$club,$serial,$target])->fetchColumn())throw new DomainException('Diese Seriennummer ist bereits einem anderen Einzelstück zugeordnet.');
            $entry=$old['source_entry']??null;$goods=$old['source_goods']??null;
            if(($data['source_kind']??'keep')!=='keep'){
                if($a['finance_access']==='none'&&($old!==null||$data['source_kind']!=='none'))throw new DomainException('Belegverknüpfungen benötigen eine eigene Finanzberechtigung.');
                $entry=$goods=null;$source=self::text($data['source_id']??'',80);
                if(($data['source_kind']??'')==='expense'){$r=$this->row('finance_entries',$club,$source);if($r['kind']!=='expense')throw new DomainException('Bitte einen Ausgabenbeleg auswählen.');if(($old['source_entry']??null)!==$source&&($r['status']==='cancelled'||$this->query('SELECT 1 FROM finance_reversals WHERE club_id=? AND entry_id=?',[$club,$source])->fetchColumn()))throw new DomainException('Stornierte Ausgaben können nicht neu als Anschaffung zugeordnet werden.');$entry=$source;}
                elseif(($data['source_kind']??'')==='goods'){$this->row('goods_donations',$club,$source);$goods=$source;}
                elseif(($data['source_kind']??'')!=='none')throw new DomainException('Ungültige Herkunft.');
            }
            $values=[$code,$name,$category,$unit,$kind,$minimum,$serial,$notes,$active,$entry,$goods];
            if($id)$this->query('UPDATE inventory_items SET code=?,name=?,category=?,unit=?,kind=?,minimum=?,serial_number=?,notes=?,active=?,source_entry=?,source_goods=?,version=version+1 WHERE club_id=? AND id=?',[...$values,$club,$id]);
            else $this->query('INSERT INTO inventory_items(code,name,category,unit,kind,minimum,serial_number,notes,active,source_entry,source_goods,club_id,id,tracking_mode,inventory_group_id) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',[...$values,$club,$target,$tracking,$group]);
            $this->saveItemAssignments($a,$target,$data,$old);
            return $version+1;
    }
    public function stock(string $item='',string $location=''): array
    {
        return $this->query("SELECT s.*,s.shelf-s.reserved available,i.name item_name,i.code,i.unit,i.content_quantity,i.content_unit,i.kind,i.active,i.stock_version,i.cash_account,l.name location_name FROM inventory_stock s JOIN inventory_items i ON i.club_id=s.club_id AND i.id=s.item_id JOIN inventory_locations l ON l.club_id=s.club_id AND l.id=s.location_id WHERE s.club_id=? AND i.deleted_at IS NULL AND (?='' OR s.item_id=?) AND (?='' OR s.location_id=?) ORDER BY l.name,i.name",[$this->actor()['club_id'],$item,$item,$location,$location])->fetchAll();
    }
    public function loanStockPage(mixed $search='',mixed $page=1,mixed $move='',bool $timed=false):array
    {
        $a=$this->actor(true,true);if(!is_string($search))throw new DomainException('Bestandssuche prüfen.');$search=self::text($search,150);
        if((!is_int($page)&&!is_string($page))||!preg_match('/^[1-9][0-9]{0,6}$/D',(string)$page)||(int)$page>1000000||!is_string($move)||!in_array($move,['','previous','next','search'],true))throw new DomainException('Bestandsauswahlseite prüfen.');
        $page=$move==='search'?1:(int)$page+match($move){'previous'=>-1,'next'=>1,default=>0};
        if($timed)$this->requireLoanWindows();$capacity=$timed?$this->windowCapacitySql():'s.shelf-s.reserved';
        $where="s.club_id=? AND i.deleted_at IS NULL AND i.active=1 AND i.kind='reusable' AND COALESCE(i.cash_account,'')='' AND l.active=1 AND ".$capacity.">0 AND instr(lower(CONCAT(i.name,' ',i.code,' ',i.serial_number,' ',l.name)),lower(?))>0";
        $join=' FROM inventory_stock s JOIN inventory_items i ON i.club_id=s.club_id AND i.id=s.item_id JOIN inventory_locations l ON l.club_id=s.club_id AND l.id=s.location_id WHERE ';$params=[$a['club_id'],$search];$owned=!$this->db->inTransaction();
        if($owned){$this->db->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');$this->db->beginTransaction();}
        try{$total=(int)$this->query('SELECT COUNT(*)'.$join.$where,$params)->fetchColumn();$pages=max(1,(int)ceil($total/6));$page=max(1,min($page,$pages));$rows=$this->query('SELECT s.item_id,s.location_id,'.$capacity.' available,i.name item_name,i.code,i.unit,i.content_quantity,i.content_unit,i.stock_version,l.name location_name'.$join.$where.' ORDER BY l.name,i.name,i.id,l.id LIMIT 6 OFFSET '.(($page-1)*6),$params)->fetchAll();if($owned)$this->db->commit();if($this->actor(true,true)['club_id']!==$a['club_id'])throw new DomainException('Vereinszugang geändert.');return compact('rows','total','page','pages');}catch(\Throwable$e){if($owned&&$this->db->inTransaction())$this->db->rollBack();throw$e;}
    }
    public function loanStockSelection(string $value,bool $timed=false):?array
    {
        $a=$this->actor(true,true);if(!preg_match('/^([a-f0-9]{32}):([a-f0-9]{32}):([1-9][0-9]*)$/D',$value,$parts))return null;
        if($timed)$this->requireLoanWindows();$capacity=$timed?$this->windowCapacitySql():'s.shelf-s.reserved';
        $row=$this->query('SELECT s.item_id,s.location_id,'.$capacity.' available,i.name item_name,i.code,i.unit,i.content_quantity,i.content_unit,i.stock_version,l.name location_name FROM inventory_stock s JOIN inventory_items i ON i.club_id=s.club_id AND i.id=s.item_id JOIN inventory_locations l ON l.club_id=s.club_id AND l.id=s.location_id WHERE s.club_id=? AND s.item_id=? AND s.location_id=? AND i.deleted_at IS NULL',[$a['club_id'],$parts[1],$parts[2]])->fetch();if($this->actor(true,true)['club_id']!==$a['club_id'])throw new DomainException('Vereinszugang geändert.');return $row?:null;
    }
    private function delta(string $club,string $item,string $location,int $event,int $shelf=0,int $reserved=0,int $lent=0,int $blocked=0): void
    {
        $this->query('INSERT IGNORE INTO inventory_stock(club_id,item_id,location_id) VALUES(?,?,?)',[$club,$item,$location]);
        $s=$this->query('SELECT * FROM inventory_stock WHERE club_id=? AND item_id=? AND location_id=?',[$club,$item,$location])->fetch();
        $next=[$s['shelf']+$shelf,$s['reserved']+$reserved,$s['lent']+$lent,$s['blocked']+$blocked];
        if(min($next)<0||$next[1]>$next[0]||max($next)>1000000000)throw new DomainException('Nicht genügend freier Bestand. Reservierte, ausgegebene oder gesperrte Mengen sind nicht verfügbar.');
        $this->validateIndividualQuantity($club,$item,$shelf+$lent+$blocked);
        $this->query('UPDATE inventory_stock SET shelf=?,reserved=?,lent=?,blocked=? WHERE club_id=? AND item_id=? AND location_id=?',[...$next,$club,$item,$location]);
        if($shelf!==0||$lent!==0||$blocked!==0)$this->query('UPDATE inventory_stock SET confirmed_at=?,confirmed_by=? WHERE club_id=? AND item_id=? AND location_id=?',[gmdate('Y-m-d\TH:i:s\Z'),$this->actorId,$club,$item,$location]);
        $this->validateCashQuantity($club,$item);
        $this->query('INSERT INTO inventory_movements(club_id,item_id,location_id,event_sequence,shelf_delta,reserved_delta,lent_delta,blocked_delta) VALUES(?,?,?,?,?,?,?,?)',[$club,$item,$location,$event,$shelf,$reserved,$lent,$blocked]);
        $this->query('UPDATE inventory_items SET stock_version=stock_version+1 WHERE club_id=? AND id=?',[$club,$item]);
    }
    public function move(string $item,int $version,array $data,string $key): string
    {
        $kind=self::text($data['kind']??'',20);if(!in_array($kind,['receive','remove','consume','transfer','block','repair','discard','count'],true))throw new DomainException('Unbekannte Lagerbewegung.');
        return $this->change($kind,$key,$item,[$version,$data],function($a,$id,$event)use($version,$data,$kind){
            $club=$a['club_id'];$i=$this->row('inventory_items',$club,$id);self::active($i);self::version($i,$version,'stock_version');
            $location=self::text($data['location']??'',80,true);self::active($this->row('inventory_locations',$club,$location));$n=self::integer($data['quantity']??'', $kind==='count'?0:1);self::text($data['reason']??'',1000,true);
            if($i['cash_account'])$this->checkCashHandover($a,$i,$kind,$data);
            switch($kind){
                case 'receive':$this->purchaseStockChange($a,$id,$event,$n,$data);$this->delta($club,$id,$location,$event,$n);break;
                case 'remove':if($i['tracking_mode']!=='quantity'||$i['cash_account'])throw new DomainException('Teilentfernung ist nur für Mengenartikel ohne Handkasse möglich.');$this->purchaseStockChange($a,$id,$event,-$n,$data);$this->delta($club,$id,$location,$event,-$n);break;
                case 'consume':if($i['kind']!=='consumable')throw new DomainException('Verbrauch ist nur für Verbrauchsmaterial vorgesehen.');$this->purchaseStockChange($a,$id,$event,-$n,$data);$this->delta($club,$id,$location,$event,-$n);break;
                case 'transfer':$to=self::text($data['destination']??'',80,true);if($to===$location)throw new DomainException('Bitte einen anderen Zielort auswählen.');self::active($this->row('inventory_locations',$club,$to));$this->delta($club,$id,$location,$event,-$n);$this->delta($club,$id,$to,$event,$n);break;
                case 'block':$this->delta($club,$id,$location,$event,-$n,0,0,$n);break;
                case 'repair':$this->delta($club,$id,$location,$event,$n,0,0,-$n);break;
                case 'discard':$this->purchaseStockChange($a,$id,$event,-$n,$data);$this->delta($club,$id,$location,$event,0,0,0,-$n);break;
                case 'count':$old=$this->query('SELECT shelf FROM inventory_stock WHERE club_id=? AND item_id=? AND location_id=?',[$club,$id,$location])->fetchColumn();$diff=$n-(int)$old;if($diff===0)throw new DomainException('Zählbestand entspricht dem Lagerbestand. Keine Korrektur nötig.');$this->purchaseStockChange($a,$id,$event,$diff,$data);$this->delta($club,$id,$location,$event,$diff);break;
            }
            return $version+1;
        });
    }
    public static function contentSummary(array $item,int $quantity,?callable $text=null):string
    {
        $parts=(int)($item['content_quantity']??0);if($parts<=1)return '';
        $text??=static fn(string $source):string=>$source;
        $content=$item['content_unit']??$text('Teile');$unit=$item['unit'];
        if($unit===$content)$unit=$text($quantity===1?'Einheit':'Einheiten');
        elseif($quantity!==1&&$unit==='Satz')$unit=$text('Sätze');
        return strtr($text('{quantity} {unit} (je {parts} {content}) = {total} {content}'),['{quantity}'=>(string)$quantity,'{unit}'=>$unit,'{parts}'=>(string)$parts,'{content}'=>$content,'{total}'=>(string)($quantity*$parts)]);
    }
    /** Prepare human-entered positions without reserving stock or replacing versions. */
    public function loanDraft(array $data,mixed $remove=null):array
    {
        $this->actor(true,true);$lines=$data['lines']??[['stock'=>'','quantity'=>'1']];
        if(!is_array($lines)||count($lines)<1||count($lines)>50)throw new DomainException('Bitte 1 bis 50 Ausleihpositionen vorbereiten.');
        $lines=array_values($lines);foreach($lines as$line){if(!is_array($line))throw new DomainException('Ausleihposition prüfen.');foreach(['stock','quantity']as$field)if(isset($line[$field])&&(!is_scalar($line[$field])||strlen((string)$line[$field])>200))throw new DomainException('Ausleihposition prüfen.');}
        if($remove!==null){if((!is_int($remove)&&!is_string($remove))||!preg_match('/^(0|[1-9][0-9]?)$/D',(string)$remove)||(int)$remove>=count($lines)||count($lines)===1)throw new DomainException('Die letzte Ausleihposition bleibt erhalten.');array_splice($lines,(int)$remove,1);}
        else{if(count($lines)===50)throw new DomainException('Höchstens 50 Ausleihpositionen vorbereiten.');$lines[]=['stock'=>'','quantity'=>'1'];}
        $data['lines']=$lines;return $data;
    }
    public function reserve(array $data,string $key): string
    {
        $mode=$data['reservation_mode']??'immediate';if(!in_array($mode,['immediate','window'],true))throw new DomainException('Reservierungsart prüfen.');$timed=$mode==='window';
        return $this->change($timed?'plan':'reserve',$key,'',$data,function($a,$id,$event)use($data,$timed){
            $club=$a['club_id'];$contact=self::text($data['contact']??'',80,true);$person=$this->row('contacts',$club,$contact);if($person['archived_at']!==null)throw new DomainException('Geschütztes Archivprofil kann nicht für eine Ausleihe gewählt werden.');
            if($timed){[$windowStart,$windowEnd]=$this->windowPair($data);$start=self::windowLocal($windowStart,'Y-m-d');$due=self::windowLocal($windowEnd,'Y-m-d');}
            else{$start=self::date($data['starts_on']??'');$due=self::date($data['due_on']??'');}
            if($due<$start)throw new DomainException('Rückgabe darf nicht vor Beginn liegen.');$purpose=self::text($data['purpose']??'',200,true);$notes=self::text($data['notes']??'',3000);
            $lines=$data['lines']??null;if(!is_array($lines)||!count($lines)||count($lines)>50)throw new DomainException('Bitte 1 bis 50 Ausleihpositionen erfassen.');
            $number=1+(int)$this->query('SELECT COALESCE(MAX(number),0) FROM inventory_loans WHERE club_id=?',[$club])->fetchColumn();
            $this->query("INSERT INTO inventory_loans(club_id,id,number,contact_id,starts_on,due_on,purpose,notes,state) VALUES(?,?,?,?,?,?,?,?,'reserved')",[$club,$id,$number,$contact,$start,$due,$purpose,$notes]);
            if($timed)$this->query('UPDATE inventory_loans SET window_start=?,window_end=? WHERE club_id=? AND id=?',[$windowStart,$windowEnd,$club,$id]);
            $seen=[];$versions=[];
            foreach($lines as $line){
                if(!is_array($line))throw new DomainException('Ungültige Position.');$item=self::text($line['item']??'',80,true);$location=self::text($line['location']??'',80,true);$quantity=self::integer($line['quantity']??'',1);
                if(isset($seen[$item.'|'.$location]))throw new DomainException('Artikel am selben Lagerort bitte in einer Position zusammenfassen.');$seen[$item.'|'.$location]=true;
                $i=$this->row('inventory_items',$club,$item);self::active($i);if($i['kind']!=='reusable')throw new DomainException('Verbrauchsmaterial wird über Lager → Verbrauch ausgegeben.');if($i['cash_account'])throw new DomainException('Handkassen über Lager → Umlagerung mit bestätigtem Zählbestand übergeben.');
                $versions[$item]??=$i['stock_version'];self::version(['stock_version'=>$versions[$item]],self::integer($line['stock_version']??''),'stock_version');self::active($this->row('inventory_locations',$club,$location));
                $this->query('INSERT INTO inventory_loan_lines(club_id,id,loan_id,item_id,location_id,quantity,item_name,item_code,unit,content_quantity,content_unit) VALUES(?,?,?,?,?,?,?,?,?,?,?)',[$club,bin2hex(random_bytes(16)),$id,$item,$location,$quantity,$i['name'],$i['code'],$i['unit'],$i['content_quantity'],$i['content_unit']]);
                if($timed)$this->assertWindowQuantity($club,$item,$location,$windowStart,$windowEnd,$quantity,$id);
                else $this->delta($club,$item,$location,$event,0,$quantity);
            }
            if(!$timed)$this->assertFutureWindowStock($club,array_map(static fn($pair)=>explode('|',$pair),array_keys($seen)));
            return 1;
        });
    }
    private function loanListWhere(string $state,string $contact,string $search):array
    {
        $a=$this->actor(false,true);if($a['inventory_access']==='none'&&$a['loans_access']==='none')$contact=$a['contact_id'];$search=self::text($search,150);
        if($state==='planned'){$this->requireLoanWindows();return["l.club_id=? AND (?='' OR l.contact_id=?) AND instr(lower(CONCAT(l.purpose,' ',c.name,' ','AUS-',LPAD(l.number,GREATEST(6,CHAR_LENGTH(l.number)),'0'))),lower(?))>0 AND l.state='reserved' AND l.window_start IS NOT NULL",[$a['club_id'],$contact,$contact,$search]];}
        $where="l.club_id=? AND (?='' OR l.contact_id=?) AND (instr(lower(CONCAT(l.purpose,' ',c.name,' ','AUS-',LPAD(l.number,GREATEST(6,CHAR_LENGTH(l.number)),'0'))),lower(?))>0) AND (?='' OR l.state=? OR (?='open' AND l.state IN ('reserved','issued')) OR (?='overdue' AND l.state='issued' AND l.due_on<?))";
        if($this->loanWindowsReady())$where=str_replace('l.due_on<?',"((l.window_end IS NULL AND l.due_on<?) OR l.window_end<=UTC_TIMESTAMP())",$where);
        return[$where,[$a['club_id'],$contact,$contact,$search,$state,$state,$state,$state,self::today()]];
    }
    public static function loanStateLabel(array $loan):string
    {
        return $loan['state']==='reserved'&&!empty($loan['window_start'])?'Geplant':self::STATES[$loan['state']];
    }
    private function loanListSelect():string{return "SELECT l.*,c.name contact_name,CAST(COALESCE(SUM(p.quantity-p.returned-p.lost),0) AS SIGNED) outstanding FROM inventory_loans l JOIN contacts c ON c.club_id=l.club_id AND c.id=l.contact_id LEFT JOIN inventory_loan_lines p ON p.club_id=l.club_id AND p.loan_id=l.id WHERE ";}
    private const LOAN_LIST_ORDER=" GROUP BY l.club_id,l.id,c.name ORDER BY CASE l.state WHEN 'issued' THEN 0 WHEN 'reserved' THEN 1 ELSE 2 END,l.due_on,l.number DESC";
    public function loans(string $state='',string $contact='',string $search=''): array
    {
        [$where,$params]=$this->loanListWhere($state,$contact,$search);return$this->query($this->loanListSelect().$where.self::LOAN_LIST_ORDER,$params)->fetchAll();
    }
    public function loansPage(string $state='',string $contact='',string $search='',mixed $page=1):array
    {
        if((!is_int($page)&&!is_string($page))||!preg_match('/^[1-9][0-9]{0,6}$/D',(string)$page)||(int)$page>1000000)throw new DomainException('Ausleihseite prüfen.');[$where,$params]=$this->loanListWhere($state,$contact,$search);$owned=!$this->db->inTransaction();if($owned){$this->db->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');$this->db->beginTransaction();}
        try{$total=(int)$this->query('SELECT COUNT(*) FROM inventory_loans l JOIN contacts c ON c.club_id=l.club_id AND c.id=l.contact_id WHERE '.$where,$params)->fetchColumn();$pages=max(1,(int)ceil($total/6));$page=min((int)$page,$pages);$offset=($page-1)*6;$rows=$this->query($this->loanListSelect().$where.self::LOAN_LIST_ORDER.' LIMIT 6 OFFSET '.$offset,$params)->fetchAll();if($owned)$this->db->commit();if($this->actor(false,true)['club_id']!==$params[0])throw new DomainException('Vereinszugang geändert.');return compact('rows','total','page','pages');}
        catch(\Throwable$e){if($owned&&$this->db->inTransaction())$this->db->rollBack();throw$e;}
    }
    public function loan(string $id): array
    {
        $a=$this->actor(false,true);$l=$this->row('inventory_loans',$a['club_id'],$id);
        if($a['inventory_access']==='none'&&$a['loans_access']==='none'&&$l['contact_id']!==$a['contact_id'])throw new DomainException('Ausleihe nicht verfügbar.');
        $c=$this->row('contacts',$a['club_id'],$l['contact_id']);$l['contact_name']=$c['name'];
        $l['lines']=$this->query('SELECT p.*,l.name location_name FROM inventory_loan_lines p JOIN inventory_locations l ON l.club_id=p.club_id AND l.id=p.location_id WHERE p.club_id=? AND p.loan_id=? ORDER BY p.item_code',[$a['club_id'],$id])->fetchAll();
        $l['history']=$this->query('SELECT action,happened_at,details,actor_id FROM inventory_events WHERE club_id=? AND object_id=? ORDER BY sequence',[$a['club_id'],$id])->fetchAll();return $l;
    }
    public function transition(string $id,int $version,string $action,array $data,string $key): string
    {
        if(!in_array($action,['issue','cancel','extend','reschedule','relocate'],true))throw new DomainException('Ungültige Ausleihaktion.');
        return $this->change($action,$key,$id,[$version,$data],function($a,$id,$event)use($version,$action,$data){
            $club=$a['club_id'];$l=$this->row('inventory_loans',$club,$id);self::version($l,$version);
            if(!in_array($l['state'],['reserved','issued'],true)||(in_array($action,['issue','cancel'],true)&&$l['state']!=='reserved')||($action==='relocate'&&$l['state']!=='issued'))throw new DomainException('Diese Aktion ist im aktuellen Ausleihstatus nicht möglich.');
            $timed=!empty($l['window_start']);
            if($action==='reschedule'||($action==='extend'&&$timed)){
                if(!$timed||($action==='reschedule'&&$l['state']!=='reserved'))throw new DomainException('Nur geplante, noch nicht ausgegebene Zeitfenster verschieben.');
                $this->changeLoanWindow($l,$data,$action);return $version+1;
            }
            if($action==='relocate'){$place=self::text($data['current_place']??'',200,true);self::text($data['reason']??'',1000,true);$this->query('UPDATE inventory_loans SET current_place=?,version=version+1 WHERE club_id=? AND id=?',[$place,$club,$id]);}
            elseif($action==='extend'){$due=self::date($data['due_on']??'');if($due<max($l['starts_on'],$l['issued_on']??''))throw new DomainException('Frist darf nicht vor Beginn oder Ausgabe liegen.');self::text($data['reason']??'',1000,true);$this->query('UPDATE inventory_loans SET due_on=?,version=version+1 WHERE club_id=? AND id=?',[$due,$club,$id]);}
            else{
                $date=self::date($data['date']??'');if($date>self::today()||($action==='issue'&&($date<$l['starts_on']||$date>$l['due_on'])))throw new DomainException('Ausgabedatum muss zwischen Beginn und Frist liegen und darf nicht in der Zukunft liegen.');
                if($timed&&$action==='issue'&&(gmdate('Y-m-d H:i:s')<$l['window_start']||gmdate('Y-m-d H:i:s')>=$l['window_end']))throw new DomainException('Ausgabe nur im bestätigten Zeitfenster. Bei geändertem Bedarf zuerst die Planung anpassen.');
                self::text($data['reason']??'',1000,true);
                if($action==='issue'){$place=self::text($data['current_place']??'',200,true);$this->query('UPDATE inventory_loans SET current_place=? WHERE club_id=? AND id=?',[$place,$club,$id]);}
                $lines=$this->query('SELECT * FROM inventory_loan_lines WHERE club_id=? AND loan_id=?',[$club,$id])->fetchAll();
                foreach($lines as $p)if(!$timed||$action==='issue')$this->delta($club,$p['item_id'],$p['location_id'],$event,$action==='issue'?-$p['quantity']:0,$timed?0:-$p['quantity'],$action==='issue'?$p['quantity']:0);
                $this->query('UPDATE inventory_loans SET state=?,issued_on=?,closed_on=?,version=version+1 WHERE club_id=? AND id=?',[$action==='issue'?'issued':'cancelled',$action==='issue'?$date:null,$action==='cancel'?$date:null,$club,$id]);
            }
            return $version+1;
        });
    }
    public function returnLoan(string $id,int $version,array $data,string $key): string
    {
        return $this->change('return',$key,$id,[$version,$data],function($a,$id,$event)use($version,$data){
            $club=$a['club_id'];$l=$this->row('inventory_loans',$club,$id);self::version($l,$version);if($l['state']!=='issued')throw new DomainException('Nur ausgegebene Ausleihen können zurückgenommen werden.');
            $date=self::date($data['date']??'');if($date<$l['issued_on']||$date>self::today())throw new DomainException('Rückgabedatum muss zwischen Ausgabe und heute liegen.');self::text($data['reason']??'',1000,true);
            $lines=$data['lines']??null;if(!is_array($lines)||!count($lines)||count($lines)>50)throw new DomainException('Rückgabepositionen fehlen.');$changed=0;$seen=[];
            foreach($lines as $line){
                if(!is_array($line))throw new DomainException('Ungültige Rückgabe.');$lineId=self::text($line['line']??'',80,true);if(isset($seen[$lineId]))throw new DomainException('Position doppelt angegeben.');$seen[$lineId]=true;
                $p=$this->row('inventory_loan_lines',$club,$lineId);if($p['loan_id']!==$id)throw new DomainException('Position gehört nicht zu dieser Ausleihe.');
                $good=self::integer($line['good']??'0');$damaged=self::integer($line['damaged']??'0');$lost=self::integer($line['lost']??'0');$n=$good+$damaged+$lost;if(!$n)continue;
                if($n>$p['quantity']-$p['returned']-$p['lost'])throw new DomainException('Rückgabe und Verlust überschreiten die noch offene Menge.');
                $location=self::text($line['location']??'',80,$good+$damaged>0);if($good+$damaged)self::active($this->row('inventory_locations',$club,$location));
                $this->purchaseStockChange($a,$p['item_id'],$event,-$lost,$line);$this->delta($club,$p['item_id'],$p['location_id'],$event,0,0,-$n);
                if($good+$damaged)$this->delta($club,$p['item_id'],$location,$event,$good,0,0,$damaged);
                $this->query('UPDATE inventory_loan_lines SET returned=returned+?,lost=lost+? WHERE club_id=? AND id=?',[$good+$damaged,$lost,$club,$lineId]);$changed+=$n;
            }
            if(!$changed)throw new DomainException('Bitte mindestens eine Rückgabe- oder Verlustmenge eingeben.');
            $remaining=(int)$this->query('SELECT SUM(quantity-returned-lost) FROM inventory_loan_lines WHERE club_id=? AND loan_id=?',[$club,$id])->fetchColumn();
            $this->query('UPDATE inventory_loans SET state=?,closed_on=?,version=version+1 WHERE club_id=? AND id=?',[$remaining?'issued':'returned',$remaining?null:$date,$club,$id]);return $version+1;
        });
    }
    public function movements(string $item='',string $location=''): array
    {
        return $this->movementRows($item,$location,1000);
    }
    private const MOVEMENT_FROM=' FROM inventory_movements m JOIN inventory_events e ON e.sequence=m.event_sequence JOIN inventory_items i ON i.club_id=m.club_id AND i.id=m.item_id JOIN inventory_locations l ON l.club_id=m.club_id AND l.id=m.location_id WHERE m.club_id=? AND (?=\'\' OR m.item_id=?) AND (?=\'\' OR m.location_id=?)';
    private function movementRows(string $item,string $location,int $limit,int $offset=0,?int $before=null):array
    {
        $a=$this->actor();$args=[$a['club_id'],$item,$item,$location,$location];if($before!==null)$args[]=$before;
        $rows=$this->query('SELECT m.*,e.action,e.happened_at,e.actor_id,e.details,e.object_id,i.name item_name,i.code,l.name location_name'.self::MOVEMENT_FROM.($before!==null?' AND m.sequence<?':'').' ORDER BY m.sequence DESC LIMIT '.$limit.' OFFSET '.$offset,$args)->fetchAll();
        if($a['finance_access']==='none')foreach($rows as &$r){$d=json_decode($r['details'],true);$d=self::withoutInventoryPrices($d);$r['details']=json_encode($d,JSON_THROW_ON_ERROR);}unset($r);return $rows;
    }
    /** Complete original count and six exact rows; the old limited service remains compatible. */
    public function movementsPage(string $item='',string $location='',mixed $page=1):array
    {
        if((!is_string($page)&&!is_int($page))||!preg_match('/\A[1-9][0-9]{0,6}\z/D',(string)$page)||(int)$page>1000000)throw new DomainException('Bewegungsseite prüfen.');
        $a=$this->actor();$total=(int)$this->query('SELECT COUNT(*)'.self::MOVEMENT_FROM,[$a['club_id'],$item,$item,$location,$location])->fetchColumn();
        $pages=max(1,(int)ceil($total/6));$page=min((int)$page,$pages);
        return ['rows'=>$this->movementRows($item,$location,6,($page-1)*6),'total'=>$total,'page'=>$page,'pages'=>$pages,'size'=>6];
    }
    private function exportMovements(string $item,string $location):\Generator
    {
        $before=null;
        do{
            $rows=$this->movementRows($item,$location,200,0,$before);
            foreach($rows as$row)yield$row;
            if($rows)$before=(int)end($rows)['sequence'];
        }while(count($rows)===200);
    }
    public function summary(): array
    {
        $a=$this->actor();$stock=$this->query('SELECT COUNT(*) items,CAST(COALESCE(SUM(available<minimum),0) AS SIGNED) low,CAST(COALESCE(SUM(blocked),0) AS SIGNED) blocked FROM (SELECT i.id,i.minimum,COALESCE(SUM(s.shelf-s.reserved),0) available,COALESCE(SUM(s.blocked),0) blocked FROM inventory_items i LEFT JOIN inventory_stock s ON s.club_id=i.club_id AND s.item_id=i.id WHERE i.club_id=? AND i.deleted_at IS NULL AND i.active=1 GROUP BY i.club_id,i.id) facts',[$a['club_id']])->fetch();
        $overdue=$this->loanWindowsReady()?"((l.window_end IS NULL AND l.due_on<?) OR l.window_end<=UTC_TIMESTAMP())":"l.due_on<?";
        $loans=$this->query("SELECT CAST(COALESCE(SUM(l.state='reserved'),0) AS SIGNED) reserved,CAST(COALESCE(SUM(l.state='issued'),0) AS SIGNED) issued,CAST(COALESCE(SUM(l.state='issued' AND ".$overdue."),0) AS SIGNED) overdue FROM inventory_loans l JOIN contacts c ON c.club_id=l.club_id AND c.id=l.contact_id WHERE l.club_id=?",[self::today(),$a['club_id']])->fetch();if($this->actor()['club_id']!==$a['club_id'])throw new DomainException('Vereinszugang geändert.');return array_map(static fn($v)=>(int)$v,$stock+$loans);
    }
    public static function number(int $n): string {return 'AUS-'.str_pad((string)$n,6,'0',STR_PAD_LEFT);}
    public function export(string $kind,array $filters=[]): string
    {
        $this->actor();$f=fopen('php://temp','r+');fwrite($f,"\xEF\xBB\xBF");
        $rows=match($kind){'items'=>$this->items($filters),'stock'=>$this->stock($filters['item']??'',$filters['location']??''),'loans'=>$this->loans($filters['state']??'',$filters['contact']??'',$filters['q']??''),'movements'=>$this->exportMovements($filters['item']??'',$filters['location']??''),default=>throw new DomainException('Unbekannter Export.')};
        $columns=match($kind){'items'=>['code'=>'Inventarnummer','name'=>'Bezeichnung','category'=>'Kategorie','unit'=>'Einheit','kind'=>'Art','total'=>'Gesamtbestand','available'=>'Verfügbar','reserved'=>'Reserviert','lent'=>'Ausgegeben','blocked'=>'Gesperrt','minimum'=>'Mindestbestand','serial_number'=>'Seriennummer'], 'stock'=>['code'=>'Inventarnummer','item_name'=>'Bezeichnung','location_name'=>'Lagerort','unit'=>'Einheit','shelf'=>'Im Lager (nutzbar)','available'=>'Verfügbar','reserved'=>'Reserviert','lent'=>'Ausgegeben','blocked'=>'Gesperrt'], 'loans'=>['number'=>'Ausleihe','contact_name'=>'Entleiher','purpose'=>'Zweck','starts_on'=>'Beginn','due_on'=>'Rückgabefrist','issued_on'=>'Ausgabe','closed_on'=>'Abschluss','state'=>'Status','outstanding'=>'Offene Menge'], 'movements'=>['happened_at'=>'Zeitpunkt UTC','code'=>'Inventarnummer','item_name'=>'Bezeichnung','location_name'=>'Lagerort','action'=>'Vorgang','shelf_delta'=>'Lager +/-','reserved_delta'=>'Reserviert +/-','lent_delta'=>'Ausgegeben +/-','blocked_delta'=>'Gesperrt +/-','actor_id'=>'Bearbeiter']};
        if($kind==='loans'&&array_filter($rows,static fn($row)=>!empty($row['window_start'])))$columns+=['window_start'=>'Zeitfenster Beginn (Europe/Berlin)','window_end'=>'Zeitfenster Ende (Europe/Berlin)'];
        fputcsv($f,array_values($columns),';','"','');foreach($rows as $row){$line=[];foreach($columns as $key=>$label){$v=(string)($row[$key]??'');if($key==='number')$v=self::number((int)$v);if($key==='state')$v=$kind==='loans'?self::loanStateLabel($row):(self::STATES[$v]??$v);if(in_array($key,['window_start','window_end'],true))$v=self::windowLocal($row[$key]??null,'d.m.Y H:i');if($key==='action')$v=self::ACTIONS[$v]??$v;if(preg_match('/^[\s]*[=+@\-]/u',$v))$v="'".$v;$line[]=$v;}fputcsv($f,$line,';','"','');}rewind($f);$csv=stream_get_contents($f);fclose($f);return $csv;
    }
}
