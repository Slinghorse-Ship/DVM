<?php declare(strict_types=1);
namespace Kulturhus\Verein;
use DomainException;

trait InventoryAssignments
{
    /** Called inside the serialized create transaction; archived numbers remain reserved. */
    private function nextIndividualCode(string $club): string
    {
        $next=1;
        foreach($this->query("SELECT code FROM inventory_items WHERE club_id=? AND code LIKE 'INV-%'",[$club])->fetchAll() as $row)if(preg_match('/^INV-(\d{1,9})$/iD',$row['code'],$match))$next=max($next,(int)$match[1]+1);
        if($next>999999999)throw new DomainException('Der automatische Inventarnummernbereich ist ausgeschöpft.');
        return 'INV-'.str_pad((string)$next,6,'0',STR_PAD_LEFT);
    }
    private function archiveBlocker(array $item,array $stocks): string
    {
        if($item['cash_account'])return 'Eine verknüpfte Handkasse kann hier nicht entfernt werden. Bitte zuerst die Kassenführung gesondert klären.';
        $open=$this->query("SELECT 1 FROM inventory_loan_lines p JOIN inventory_loans l ON l.club_id=p.club_id AND l.id=p.loan_id WHERE p.club_id=? AND p.item_id=? AND l.state IN ('reserved','issued') AND p.quantity>p.returned+p.lost LIMIT 1",[$item['club_id'],$item['id']])->fetchColumn();
        if($open||array_filter($stocks,fn($row)=>$row['reserved']>0||$row['lent']>0))return 'Der Gegenstand ist noch reserviert oder ausgegeben. Bitte die Ausleihe zuerst abschließen oder die Reservierung aufheben.';
        return '';
    }
    public function archiveStatus(string $item): array
    {
        $a=$this->actor();$row=$this->row('inventory_items',$a['club_id'],$item);$stocks=$this->stock($item);
        return ['blocker'=>$this->archiveBlocker($row,$stocks),'stock'=>$stocks];
    }
    /** Remove from active inventory while retaining identities, invoices and the full movement trail. */
    public function archiveItem(string $item,int $version,int $stockVersion,array $data,string $key): string
    {
        $reason=self::text($data['reason']??'',1000,true);
        return $this->change('archive',$key,$item,[$version,['reason'=>$reason,'stock_version'=>$stockVersion,'active'=>0]],function($a,$id,$event)use($version,$stockVersion){
            $club=$a['club_id'];$old=$this->row('inventory_items',$club,$id);self::version($old,$version);self::version($old,$stockVersion,'stock_version');self::active($old);
            $stocks=$this->query('SELECT * FROM inventory_stock WHERE club_id=? AND item_id=? ORDER BY location_id',[$club,$id])->fetchAll();
            $blocker=$this->archiveBlocker($old,$stocks);if($blocker!=='')throw new DomainException($blocker);
            $remainingUnits=(int)$this->query('SELECT COUNT(*) FROM inventory_purchase_units WHERE club_id=? AND item_id=? AND retired_event IS NULL',[$club,$id])->fetchColumn();if($remainingUnits)$this->retirePurchaseUnits($club,$id,$event,$remainingUnits);
            foreach($stocks as $stock)if($stock['shelf']||$stock['blocked'])$this->delta($club,$id,$stock['location_id'],$event,-(int)$stock['shelf'],0,0,-(int)$stock['blocked']);
            $this->query('UPDATE inventory_items SET active=0,version=version+1,stock_version=stock_version+1 WHERE club_id=? AND id=?',[$club,$id]);
            DocumentCatalog::register($this->db,$club,'item',$id);return $version+1;
        });
    }
    /** Restoring the record never recreates physical stock. */
    public function restoreItem(string $item,int $version,string $key): string
    {
        return $this->change('restore',$key,$item,[$version,['reason'=>'Gegenstand wiederhergestellt; Bestand nicht verändert.','active'=>1]],function($a,$id)use($version){
            $old=$this->row('inventory_items',$a['club_id'],$id);self::version($old,$version);if($old['active'])throw new DomainException('Der Gegenstand ist bereits aktiv.');
            $this->query('UPDATE inventory_items SET active=1,version=version+1,stock_version=stock_version+1 WHERE club_id=? AND id=?',[$a['club_id'],$id]);DocumentCatalog::register($this->db,$a['club_id'],'item',$id);return $version+1;
        });
    }
    /** Archive lifecycle only; details are [itemVersion, {reason, stock_version?, active}]. */
    public function archiveHistory(string $item): array
    {
        $a=$this->actor();$this->row('inventory_items',$a['club_id'],$item);
        $rows=$this->query("SELECT action,happened_at,actor_id,details FROM inventory_events WHERE club_id=? AND object_id=? AND action IN ('archive','restore') ORDER BY sequence DESC",[$a['club_id'],$item])->fetchAll();
        foreach($rows as &$row)$row['details']=json_decode($row['details'],true,512,JSON_THROW_ON_ERROR);unset($row);return $rows;
    }
    /**
     * Create separately identifiable pieces in one confirmed batch, not an averaged quantity.
     * Rows override common defaults, including explicit empty values. The returned group ID
     * is also the replay result. All metadata, stock, documents and history share one transaction.
     */
    public function createIndividualItems(array $common,array $rows,string $requestKey): string
    {
        if(count($rows)<1||count($rows)>50)throw new DomainException('Bitte 1 bis 50 Einzelstücke erfassen.');
        return $this->change('individual-create',$requestKey,'',[$common,$rows],function($a,$group)use($common,$rows){
            foreach(array_values($rows) as $index=>$row){
                try{
                    if(!is_array($row))throw new DomainException('Ungültige Eingabe.');
                    $data=array_replace($common,$row);$location=self::text($data['location']??'',80);
                    if(self::text($data['code']??'',60)==='')$data['code']=$this->nextIndividualCode($a['club_id']);
                    if($location==='')throw new DomainException('Bitte den tatsächlichen Lagerort auswählen.');
                    self::active($this->row('inventory_locations',$a['club_id'],$location));
                    $data['home_location']??=$location;
                    if(self::integer($data['active']??'1')!==1)throw new DomainException('Neue Einzelstücke werden aktiv angelegt.');
                    $id=bin2hex(random_bytes(16));$this->writeItem($a,$id,'',0,$data,$group);
                    DocumentCatalog::register($this->db,$a['club_id'],'item',$id);
                    $this->individualEvent($a,$group,$id,'item',[0,$data]);
                    $receipt=['kind'=>'receive','location'=>$location,'quantity'=>'1','reason'=>'Einzelstück bei der Anlage vollständig erfasst und dem Lagerort zugeordnet.'];
                    $item=$this->row('inventory_items',$a['club_id'],$id);
                    if($item['cash_account']){
                        $this->checkCashHandover($a,$item,'receive',$data);$receipt['counted']=$data['counted'];
                    }
                    $event=$this->individualEvent($a,$group,$id,'receive',[0,$receipt]);
                    $this->delta($a['club_id'],$id,$location,$event,1);
                }catch(DomainException $e){throw new DomainException('Einzelstück '.($index+1).': '.$e->getMessage(),0,$e);}
            }
            return 1;
        });
    }
    /** Child events retain the same item/receipt history format as single-item operations. */
    private function individualEvent(array $a,string $group,string $item,string $action,array $details): int
    {
        $now=gmdate('Y-m-d\TH:i:s\Z');$json=json_encode($details,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
        $key=substr(hash('sha256','individual|'.$group.'|'.$item.'|'.$action),0,32);
        $hash=hash('sha256',$a['id'].'|'.$action.'|'.$item.'|'.$json);
        $this->query('INSERT INTO inventory_events(club_id,actor_id,action,object_id,happened_at,details,request_key,request_hash) VALUES(?,?,?,?,?,?,?,?)',[$a['club_id'],$a['id'],$action,$item,$now,$json,$key,$hash]);
        $event=(int)$this->db->lastInsertId();
        $this->query('INSERT INTO audit_events(club_id,actor_id,action,object_id,object_version,happened_at) VALUES(?,?,?,?,?,?)',[$a['club_id'],$a['id'],'inventory.'.$action,$item,1,$now]);
        return $event;
    }
    /** Physical stock includes usable, lent and blocked pieces; reservations are a subset. */
    private function validateIndividualQuantity(string $club,string $item,int $physicalDelta): void
    {
        if($physicalDelta<=0)return;
        $row=$this->row('inventory_items',$club,$item);if($row['tracking_mode']!=='individual')return;
        $quantity=(int)$this->query('SELECT CAST(COALESCE(SUM(shelf+lent+blocked),0) AS SIGNED) FROM inventory_stock WHERE club_id=? AND item_id=?',[$club,$item])->fetchColumn();
        if($quantity+$physicalDelta>1)throw new DomainException('Dieses Einzelstück ist bereits im Bestand oder ausgeliehen. Ein weiteres Stück bitte mit eigener Inventarnummer anlegen.');
    }
    private function contactReference(string $club,mixed $id): ?string
    {
        $id=self::text($id??'',80);if($id!=='')$this->row('contacts',$club,$id);return $id?:null;
    }
    private function saveLocationAssignments(string $club,string $id,array $data,?array $old): void
    {
        $holder=$this->contactReference($club,$data['holder_id']??$old['holder_id']??'');
        if($old&&$holder!==$old['holder_id']&&$this->query('SELECT 1 FROM inventory_stock WHERE club_id=? AND location_id=? AND shelf+blocked+lent>0',[$club,$id])->fetchColumn())throw new DomainException('Bei belegten Lagerorten den Aufbewahrer über einen neuen Lagerort und eine bestätigte Umlagerung wechseln.');
        $values=[$holder];foreach(['building','room','shelf'] as $field)$values[]=self::text($data[$field]??$old[$field]??'',120);
        $this->query('UPDATE inventory_locations SET holder_id=?,building=?,room=?,shelf=? WHERE club_id=? AND id=?',[...$values,$club,$id]);
    }
    private function saveItemAssignments(array $a,string $id,array $data,?array $old): void
    {
        $club=$a['club_id'];$contentQuantity=self::integer($data['content_quantity']??$old['content_quantity']??'1',1);$contentUnit=self::text($data['content_unit']??$old['content_unit']??'Stück',40,true);$this->query('UPDATE inventory_items SET content_quantity=?,content_unit=? WHERE club_id=? AND id=?',[$contentQuantity,$contentUnit,$club,$id]);$ownership=$data['ownership']??$old['ownership']??'club';if(!in_array($ownership,['club','third_party'],true))throw new DomainException('Bitte Vereinseigentum oder fremde Leihgabe auswählen.');
        $owner=$this->contactReference($club,$data['owner_id']??$old['owner_id']??'');$responsible=$this->contactReference($club,$data['responsible_id']??$old['responsible_id']??'');
        if($ownership==='third_party'&&!$owner)throw new DomainException('Bitte den Eigentümer der fremden Leihgabe zuordnen.');if($ownership==='club')$owner=null;
        $home=self::text($data['home_location']??$old['home_location']??'',80);if($home)$this->row('inventory_locations',$club,$home);
        $date=$data['acquired_on']??$old['acquired_on']??'';if($date)$date=self::date($date);
        $department=self::text($data['department']??$old['department']??'',120);$condition=self::text($data['condition_note']??$old['condition_note']??'',1000);
        $value=$old['unit_value_cents']??null;$valueNote=$old['value_note']??'';$cash=$old['cash_account']??null;
        if(array_key_exists('unit_value',$data)||array_key_exists('value_note',$data)||array_key_exists('cash_account',$data)){
            if($a['finance_access']==='none')throw new DomainException('Werte und Kassenverknüpfungen benötigen Finanzberechtigung.');
            if(array_key_exists('unit_value',$data)){$v=self::text($data['unit_value'],40);$value=$v===''?null:($v==='0'?0:Finance::cents($v));$valueNote=self::text($data['value_note']??'',1000);}
            elseif(array_key_exists('value_note',$data))$valueNote=self::text($data['value_note'],1000);
            if((array_key_exists('unit_value',$data)||array_key_exists('value_note',$data))&&$value!==null&&$valueNote==='')throw new DomainException('Bitte die Herkunft des dokumentierten Stückwerts angeben.');
            if(array_key_exists('cash_account',$data))$cash=self::text($data['cash_account'],80)?:null;
        }
        if($cash){
            $account=$this->row('finance_accounts',$club,$cash);if($account['kind']!=='cash'||$ownership!=='club')throw new DomainException('Nur eine vereinseigene Handkasse kann mit einem Kassenkonto verknüpft werden.');
            if($this->query('SELECT 1 FROM inventory_items WHERE club_id=? AND cash_account=? AND id<>?',[$club,$cash,$id])->fetchColumn())throw new DomainException('Dieses Kassenkonto ist bereits mit einer Handkasse verknüpft.');
            if(($data['kind']??$old['kind']??'reusable')!=='reusable')throw new DomainException('Eine Handkasse ist kein Verbrauchsmaterial.');$value=null;$valueNote='Geldbestand ausschließlich aus dem Kassenbuch.';
        }
        if($old&&$old['cash_account']!==$cash&&$this->query('SELECT 1 FROM inventory_stock WHERE club_id=? AND item_id=? AND shelf+blocked+lent>0',[$club,$id])->fetchColumn())throw new DomainException('Kassenverknüpfung vor dem ersten Zugang festlegen; Änderungen bei vorhandenem Bestand sind gesperrt.');
        $this->query('UPDATE inventory_items SET ownership=?,owner_id=?,responsible_id=?,department=?,home_location=?,acquired_on=?,condition_note=?,unit_value_cents=?,value_note=?,cash_account=? WHERE club_id=? AND id=?',[$ownership,$owner,$responsible,$department,$home?:null,$date?:null,$condition,$value,$valueNote,$cash,$club,$id]);
        $this->validateCashQuantity($club,$id);
    }
    private function validateCashQuantity(string $club,string $item): void
    {
        $i=$this->row('inventory_items',$club,$item);if(!$i['cash_account'])return;
        $n=(int)$this->query('SELECT CAST(COALESCE(SUM(shelf+blocked+lent),0) AS SIGNED) FROM inventory_stock WHERE club_id=? AND item_id=?',[$club,$item])->fetchColumn();
        if($n>1)throw new DomainException('Eine verknüpfte Handkasse darf nur einmal im Bestand vorhanden sein.');
    }
    private function checkCashHandover(array $a,array $item,string $kind,array $data): void
    {
        if($a['finance_access']==='none')throw new DomainException('Die Übergabe einer Handkasse benötigt zusätzlich Finanz-Leserechte für den Zählabgleich.');
        if(!in_array($kind,['receive','transfer'],true))throw new DomainException('Verknüpfte Handkassen über Zugang oder Umlagerung mit Zählabgleich verwalten.');
        $balance=(int)$this->query('SELECT a.opening_cents+COALESCE((SELECT SUM(t.cents) FROM finance_transactions t WHERE t.club_id=a.club_id AND t.account_id=a.id),0) FROM finance_accounts a WHERE a.club_id=? AND a.id=?',[$a['club_id'],$item['cash_account']])->fetchColumn();
        $counted=self::text($data['counted']??'',40,true);$cents=$counted==='0'?0:Finance::cents($counted);if($cents!==$balance)throw new DomainException('Zählbestand stimmt nicht mit dem Kassenbuch überein. Differenz zuerst im Kassenbuch klären.');
    }
    private function hideValues(array $row): array
    {
        if(Permissions::actor($this->db,$this->actorId)['finance_access']==='none'){unset($row['unit_value_cents'],$row['value_note'],$row['source_entry'],$row['source_goods']);}return $row;
    }
    public function people(bool $activeOnly=false): array
    {
        $club=$this->actor()['club_id'];return $this->query("SELECT c.id,c.name,c.kind,(SELECT GROUP_CONCAT(m.member_number ORDER BY m.member_number SEPARATOR ', ') FROM memberships m WHERE m.club_id=c.club_id AND m.contact_id=c.id) member_numbers FROM contacts c WHERE c.club_id=?".($activeOnly?' AND c.archived_at IS NULL':'')." ORDER BY c.name",[$club])->fetchAll();
    }
    /** One projection for list, profile and warehouse; shelf excludes lent quantities. */
    public function whereabouts(array $filters=[],bool $own=false): array
    {
        $a=$this->actor(false,$own);$club=$a['club_id'];$member=$filters['member']??'';
        if($a['inventory_access']==='none'&&$a['loans_access']==='none')$member=$a['contact_id'];
        $rows=$this->query("SELECT s.*,i.name item_name,i.code,i.serial_number,COALESCE((SELECT GROUP_CONCAT(u.serial_number ORDER BY u.created_event,u.sequence SEPARATOR ' ') FROM inventory_purchase_units u WHERE u.club_id=i.club_id AND u.item_id=i.id AND u.retired_event IS NULL),'') piece_serials,i.unit,i.content_quantity,i.content_unit,i.responsible_id,i.category,i.home_location,i.cash_account,i.department,l.name location_name,l.holder_id,l.building,l.room,l.shelf location_shelf,c.name holder_name,r.name responsible_name,h.name home_name FROM inventory_stock s JOIN inventory_items i ON i.club_id=s.club_id AND i.id=s.item_id JOIN inventory_locations l ON l.club_id=s.club_id AND l.id=s.location_id LEFT JOIN contacts c ON c.club_id=l.club_id AND c.id=l.holder_id LEFT JOIN contacts r ON r.club_id=i.club_id AND r.id=i.responsible_id LEFT JOIN inventory_locations h ON h.club_id=i.club_id AND h.id=i.home_location WHERE s.club_id=? AND i.deleted_at IS NULL AND s.shelf+s.blocked>0 ORDER BY i.name,l.name",[$club])->fetchAll();
        $out=[];foreach($rows as $row){foreach(['available'=>$row['shelf']-$row['reserved'],'reserved'=>$row['reserved'],'blocked'=>$row['blocked']] as $state=>$n)if($n){$r=$row;$r['quantity']=$n;$r['state']=$state;$r['loan_id']='';$r['due_on']='';$r['purpose']='';$out[]=$r;}}
        $lent=$this->query("SELECT p.item_id,p.location_id,p.quantity-p.returned-p.lost quantity,i.name item_name,i.code,i.serial_number,COALESCE((SELECT GROUP_CONCAT(u.serial_number ORDER BY u.created_event,u.sequence SEPARATOR ' ') FROM inventory_purchase_units u WHERE u.club_id=i.club_id AND u.item_id=i.id AND u.retired_event IS NULL),'') piece_serials,p.unit,p.content_quantity,p.content_unit,i.responsible_id,i.category,i.home_location,i.cash_account,i.department,l.current_place location_name,l.contact_id holder_id,c.name holder_name,r.name responsible_name,h.name home_name,l.id loan_id,l.due_on,l.purpose,(SELECT happened_at FROM inventory_events e WHERE e.club_id=l.club_id AND e.object_id=l.id AND e.action IN ('issue','relocate') ORDER BY sequence DESC LIMIT 1) confirmed_at,(SELECT actor_id FROM inventory_events e WHERE e.club_id=l.club_id AND e.object_id=l.id AND e.action IN ('issue','relocate') ORDER BY sequence DESC LIMIT 1) confirmed_by FROM inventory_loan_lines p JOIN inventory_loans l ON l.club_id=p.club_id AND l.id=p.loan_id JOIN inventory_items i ON i.club_id=p.club_id AND i.id=p.item_id JOIN contacts c ON c.club_id=l.club_id AND c.id=l.contact_id LEFT JOIN contacts r ON r.club_id=i.club_id AND r.id=i.responsible_id LEFT JOIN inventory_locations h ON h.club_id=i.club_id AND h.id=i.home_location WHERE p.club_id=? AND i.deleted_at IS NULL AND l.state='issued' AND p.quantity-p.returned-p.lost>0",[$club])->fetchAll();
        foreach($lent as $r){$r['state']='issued';$r['building']=$r['room']=$r['location_shelf']='';$out[]=$r;}
        $search=self::text($filters['q']??'',150);return array_values(array_filter($out,function($r)use($filters,$member,$search){
            return ($member===''||$r['holder_id']===$member||$r['responsible_id']===$member)&&(($filters['item']??'')===''||$r['item_id']===$filters['item'])&&(($filters['location']??'')===''||($r['state']!=='issued'&&$r['location_id']===$filters['location']))&&(($filters['state']??'')===''||$r['state']===$filters['state'])&&($search===''||mb_stripos(implode(' ',[$r['item_name'],$r['code'],$r['serial_number'],$r['piece_serials']]),$search)!==false);
        }));
    }
    public function assetsOverview(array $filters=[]): array{return $this->assetsOverviewData($filters,false);}
    /** Page original whereabouts facts without changing state projections or quantity meaning. */
    public function whereaboutsPage(array $filters=[],mixed $page=1,bool $own=false):array
    {
        $a=$this->actor(false,$own);if((!is_int($page)&&!is_string($page))||!preg_match('/^[1-9][0-9]{0,6}$/D',(string)$page)||(int)$page>1000000)throw new DomainException('Bestandsseite prüfen.');$rows=$this->whereabouts($filters,$own);$total=count($rows);$pages=max(1,(int)ceil($total/6));$page=min((int)$page,$pages);$rows=array_slice($rows,($page-1)*6,6);if($this->actor(false,$own)['club_id']!==$a['club_id'])throw new DomainException('Vereinszugang geändert.');return compact('rows','total','page','pages');
    }
    private function assetsOverviewData(array $filters,bool $compact): array
    {
        $a=Permissions::actor($this->db,$this->actorId);if($a['finance_access']==='none')throw new DomainException('Finanzberechtigung für die Vermögensübersicht erforderlich.');
        $fields=$compact?'i.id,i.name,i.code,i.category,i.unit,i.content_quantity,i.content_unit,i.ownership,i.cash_account,i.unit_value_cents,i.responsible_id':'i.*';
        $rows=$this->query("SELECT ".$fields.",CAST(COALESCE(SUM(s.shelf+s.lent+s.blocked),0) AS SIGNED) quantity,c.name responsible_name FROM inventory_items i LEFT JOIN inventory_stock s ON s.club_id=i.club_id AND s.item_id=i.id LEFT JOIN contacts c ON c.club_id=i.club_id AND c.id=i.responsible_id WHERE i.club_id=? AND i.deleted_at IS NULL AND i.active=1 GROUP BY i.club_id,i.id,c.name ORDER BY i.category,i.name",[$a['club_id']])->fetchAll();
        $out=[];$total=0;$unvalued=0;foreach($rows as $r){
            if(($filters['category']??'')!==''&&$r['category']!==$filters['category'])continue;
            if(($filters['member']??'')!==''&&$r['responsible_id']!==$filters['member'])continue;
            if(($filters['location']??'')!==''&&!$this->query('SELECT 1 FROM inventory_stock WHERE club_id=? AND item_id=? AND location_id=? AND shelf+blocked>0',[$a['club_id'],$r['id'],$filters['location']])->fetchColumn())continue;
            $r['documented_total']=null;if($r['ownership']==='club'&&!$r['cash_account']&&$r['quantity']>0){if($this->hasPurchaseUnits($a['club_id'],$r['id'])){$detail=$this->query('SELECT COUNT(*) quantity,COUNT(unit_value_cents) valued,CAST(COALESCE(SUM(unit_value_cents),0) AS SIGNED) total FROM inventory_purchase_units WHERE club_id=? AND item_id=? AND retired_event IS NULL',[$a['club_id'],$r['id']])->fetch();$r['has_purchase_units']=true;if((int)$detail['quantity']!==(int)$r['quantity']||(int)$detail['valued']!==(int)$detail['quantity'])$unvalued++;else{$r['documented_total']=(int)$detail['total'];$total+=$r['documented_total'];}}elseif($r['unit_value_cents']===null)$unvalued++;else{if($r['unit_value_cents']>0&&$r['quantity']>intdiv(PHP_INT_MAX,$r['unit_value_cents']))throw new DomainException('Dokumentierter Gesamtwert überschreitet den Zahlenbereich. Werte bitte prüfen.');$r['documented_total']=$r['quantity']*$r['unit_value_cents'];if($total>PHP_INT_MAX-$r['documented_total'])throw new DomainException('Inventarwertsumme überschreitet den Zahlenbereich.');$total+=$r['documented_total'];}}$out[]=$r;
        }
        require_once __DIR__.'/InventoryAcquisitionValues.php';
        return InventoryAcquisitionValues::includeInOverview(['rows'=>$out,'total'=>$total,'unvalued'=>$unvalued],InventoryAcquisitionValues::pools($this->db,$this->actorId));
    }
    public function linkedItems(string $source,string $id): array
    {
        $a=Permissions::actor($this->db,$this->actorId);if($a['finance_access']==='none')throw new DomainException('Finanzberechtigung erforderlich.');
        $field=match($source){'expense'=>'source_entry','goods'=>'source_goods','cash'=>'cash_account',default=>throw new DomainException('Unbekannte Verknüpfung.')};
        return $this->query("SELECT id,name,code FROM inventory_items WHERE club_id=? AND deleted_at IS NULL AND $field=?",[$a['club_id'],$id])->fetchAll();
    }
    /** Six reverse links from the actual readable financial original, including archived items. */
    public function linkedItemsPage(string $source,string $id,mixed $page=1):array
    {
        $a=Permissions::actor($this->db,$this->actorId);
        if(!in_array($a['finance_access'],['read','write'],true))throw new DomainException('Finanzberechtigung für die Inventarzuordnung erforderlich.',403);
        if((!is_int($page)&&!is_string($page))||!preg_match('/\A[1-9][0-9]{0,6}\z/D',(string)$page)||(int)$page>1000000)throw new DomainException('Zuordnungsseite prüfen. Die bekannten ursprünglichen Seitenangaben bleiben erhalten.',422);
        $field=match($source){'expense'=>'source_entry','goods'=>'source_goods','cash'=>'cash_account',default=>throw new DomainException('Finanzquelle für die Inventarzuordnung prüfen.',422)};
        $owned=!$this->db->inTransaction();
        try{
            if($owned){$this->db->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');$this->db->beginTransaction();}
            $fresh=Permissions::actor($this->db,$this->actorId);if($fresh['club_id']!==$a['club_id']||!in_array($fresh['finance_access'],['read','write'],true))throw new DomainException('Finanzzugang geändert. Inventarzuordnung neu öffnen.',403);$a=$fresh;
            $finance=new Finance($this->db,$this->actorId);
            if($source==='expense'&&$finance->entry($id)['kind']!=='expense')throw new DomainException('Finanzquelle für die Inventarzuordnung prüfen.',422);
            if($source==='goods')$finance->goodsEntry($id);
            if($source==='cash'&&$finance->account($id)['kind']!=='cash')throw new DomainException('Finanzquelle für die Inventarzuordnung prüfen.',422);
            $where=' FROM inventory_items WHERE club_id=? AND deleted_at IS NULL AND '.$field.'=?';$args=[$a['club_id'],$id];
            $count=(int)$this->query('SELECT COUNT(*)'.$where,$args)->fetchColumn();$pages=max(1,(int)ceil($count/6));$page=min((int)$page,$pages);
            $rows=$this->query('SELECT id,name,code,active'.$where.' ORDER BY name,code,id LIMIT 6 OFFSET '.(($page-1)*6),$args)->fetchAll();
            if($owned)$this->db->commit();
            $now=Permissions::actor($this->db,$this->actorId);
            if($now['club_id']!==$a['club_id']||!in_array($now['finance_access'],['read','write'],true))throw new DomainException('Finanzzugang geändert. Inventarzuordnung neu öffnen.',403);
            return ['rows'=>$rows,'count'=>$count,'page'=>$page,'pages'=>$pages,'size'=>6,'source'=>$source,'source_id'=>$id];
        }catch(\Throwable $failure){if($owned&&$this->db->inTransaction())$this->db->rollBack();throw $failure;}
    }
    /** Preserve complete valuation rules; only selected item bodies are materialized. */
    public function assetsPage(array $filters=[],mixed $page=1):array
    {
        $a=Permissions::actor($this->db,$this->actorId);if($a['finance_access']==='none')throw new DomainException('Finanzberechtigung für die Vermögensübersicht erforderlich.');
        if((!is_int($page)&&!is_string($page))||!preg_match('/^[1-9][0-9]{0,6}$/D',(string)$page)||(int)$page>1000000)throw new DomainException('Vermögensseite prüfen.');$owned=!$this->db->inTransaction();if($owned){$this->db->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');$this->db->beginTransaction();}
        try{$overview=$this->assetsOverviewData($filters,true);$count=count($overview['rows']);$pages=max(1,(int)ceil($count/6));$page=min((int)$page,$pages);$selected=array_slice($overview['rows'],($page-1)*6,6);$ids=array_column($selected,'id');$rows=[];
            if($ids){$q=$this->query('SELECT * FROM inventory_items WHERE club_id=? AND id IN ('.implode(',',array_fill(0,count($ids),'?')).')',[$a['club_id'],...$ids]);$originals=[];while($r=$q->fetch())$originals[$r['id']]=$r;foreach($selected as$r)$rows[]=array_replace($originals[$r['id']],$r);}
            $overview['rows']=$rows;$overview['count']=$count;$overview['page']=$page;$overview['pages']=$pages;if($owned)$this->db->commit();$now=Permissions::actor($this->db,$this->actorId);if($now['club_id']!==$a['club_id']||$now['finance_access']==='none')throw new DomainException('Finanzzugang geändert.');return$overview;
        }catch(\Throwable$e){if($owned&&$this->db->inTransaction())$this->db->rollBack();throw$e;}
    }
    public function itemHistory(string $item): array
    {
        $a=$this->actor();$this->row('inventory_items',$a['club_id'],$item);$rows=$this->query("SELECT happened_at,actor_id,details FROM inventory_events WHERE club_id=? AND object_id=? AND action='item' ORDER BY sequence DESC",[$a['club_id'],$item])->fetchAll();
        foreach($rows as &$r){$d=json_decode($r['details'],true);if($a['finance_access']==='none'){$d=self::withoutInventoryPrices($d);foreach(['source','source_kind','source_id'] as $k)unset($d[1][$k]);}$r['details']=$d;}unset($r);return $rows;
    }
    /** Six actual metadata snapshots; the earlier itemHistory API remains unchanged. */
    public function itemHistoryPage(string $item,mixed $page=1):array
    {
        if((!is_int($page)&&!is_string($page))||!preg_match('/\A[1-9][0-9]{0,6}\z/D',(string)$page)||(int)$page>1000000)throw new DomainException('Angabenverlaufsseite prüfen.');
        $a=$this->actor();$this->row('inventory_items',$a['club_id'],$item);$total=(int)$this->query("SELECT COUNT(*) FROM inventory_events WHERE club_id=? AND object_id=? AND action='item'",[$a['club_id'],$item])->fetchColumn();$pages=max(1,(int)ceil($total/6));$page=min((int)$page,$pages);
        $rows=$this->query("SELECT sequence,happened_at,actor_id,details FROM inventory_events WHERE club_id=? AND object_id=? AND action='item' ORDER BY sequence DESC LIMIT 6 OFFSET ".(($page-1)*6),[$a['club_id'],$item])->fetchAll();
        foreach($rows as&$r){$d=json_decode($r['details'],true);if($a['finance_access']==='none'){$d=self::withoutInventoryPrices($d);foreach(['source','source_kind','source_id']as$k)unset($d[1][$k]);}$r['details']=$d;}unset($r);
        return ['rows'=>$rows,'total'=>$total,'page'=>$page,'pages'=>$pages,'size'=>6];
    }
    public function files(string $item): array
    {
        $this->item($item);return (new Documents($this->db,$this->actorId))->listing(['source_type'=>'item','source_id'=>$item]);
    }
    public function file(string $id): array
    {
        $this->actor();$docs=new Documents($this->db,$this->actorId);$d=$docs->document($id);
        if(!array_filter($d['links'],fn($l)=>$l['source_type']==='item'))throw new DomainException('Inventardatei nicht verfügbar.');return $docs->file($id);
    }
    public function upload(string $item,string $name,string $bytes,string $key,?CameraDocumentUploads $prepared=null): string
    {
        if(!$this->canWrite())throw new DomainException('Keine Berechtigung zum Hochladen von Inventardateien.');$this->item($item);
        [, $mime]=Documents::inspectFile($name,$bytes);if(!in_array($mime,['image/png','image/jpeg','application/pdf'],true))throw new DomainException('Bitte PNG, JPEG oder PDF auswählen.');
        return (new Documents($this->db,$this->actorId))->upload($name,$bytes,['title'=>$name,'kind'=>str_starts_with($mime,'image/')?'photo':'inventory','links'=>['item:'.$item]],$key,null,$prepared);
    }
}
