<?php declare(strict_types=1);
namespace Kulturhus\Native\Pos;
use PDO;
use Kulturhus\Native\Shared\{Db,Identity};
use Kulturhus\Native\Communication\Content;
use Kulturhus\Native\Payments\Money;
use Kulturhus\Native\Integration\ManagerBridge;
use Kulturhus\Verein\{Permissions,Finance,EventVouchers};
/** Club ledger uses manager identities. Helpers get only their assigned event register. */
final class Register
{
    private ?\Closure $guard;
    public function __construct(private PDO $db,private string $actorId,?callable $identityGuard=null,private ?\Kulturhus\Verein\WebsiteCalendar $calendar=null,private ?string $mediaRoot=null)
    {$this->guard=$identityGuard===null?null:\Closure::fromCallable($identityGuard);}
    private function actor(?string $event=null,bool $manage=false):array
    {
        if($this->guard)($this->guard)();
        $lock=$this->db->inTransaction()?' FOR SHARE':'';
        $a=Db::run($this->db,'SELECT * FROM actors WHERE id=?'.$lock,[$this->actorId])->fetch();
        if(!$a||!$a['active']||$a['role']==='editor')throw new \DomainException('Kassenzugang gesperrt.');
        $assignment=Db::run($this->db,'SELECT role_id FROM settings_role_assignments WHERE club_id=? AND actor_id=?'.$lock,[$a['club_id'],$a['id']])->fetchColumn();
        $level=$assignment?Db::run($this->db,'SELECT finance_access FROM settings_roles WHERE club_id=? AND id=?'.$lock,[$a['club_id'],$assignment])->fetchColumn():($a['role']==='treasurer'?'write':'none');
        $finance=$a['is_superuser']||$level==='write';
        if(!$finance&&($manage||$event===null||!Db::run($this->db,'SELECT 1 FROM event_voucher_helpers WHERE club_id=? AND event_id=? AND actor_id=?'.$lock,[$a['club_id'],$event,$a['id']])->fetchColumn()))throw new \DomainException('Keine Kassenberechtigung für diese Veranstaltung.');
        $a['_finance']=$finance;return $a;
    }
    public function canManage():bool{try{$this->actor(null,true);return true;}catch(\DomainException){return false;}}
    public function canAccess():bool
    {try{if($this->canManage())return true;if($this->guard)($this->guard)();$a=Permissions::actor($this->db,$this->actorId);return $a['role']!=='editor'&&(bool)Db::run($this->db,'SELECT 1 FROM event_voucher_helpers WHERE club_id=? AND actor_id=?',[$a['club_id'],$a['id']])->fetchColumn();}catch(\DomainException){return false;}}
    public function editableProducts():array
    {$a=$this->actor(null,true);return Db::run($this->db,'SELECT * FROM pos_products WHERE club_id=? ORDER BY label,id',[$a['club_id']])->fetchAll();}
    public function products(string $event):array
    {
        $a=$this->actor($event);$products=Db::run($this->db,'SELECT * FROM pos_products WHERE club_id=? AND active=1 ORDER BY label,id',[$a['club_id']])->fetchAll();
        $source=Db::run($this->db,'SELECT source_id FROM event_voucher_events WHERE club_id=? AND id=?',[$a['club_id'],$event])->fetchColumn();
        if(is_string($source)&&preg_match('/^website:([a-f0-9]{32})$/D',$source,$match)){
            $offer=\Kulturhus\Native\Events\Tickets\Offers::publicOffer($this->db,ManagerBridge::clubKey($a['club_id']),$match[1]);
            if($offer&&$offer['remaining']>0){foreach($products as$p)if($p['id']===$match[1])throw new \DomainException('Ticket- und Artikelkennung kollidieren.');$products[]=['id'=>$match[1],'label'=>'Eintritt · '.$offer['event']['title'],'price_cents'=>$offer['price_cents'],'version'=>$offer['version'],'color'=>'#225544','kind'=>'admission','sphere'=>$offer['accounting_sphere'],'active'=>1];}
        }
        return $products;
    }
    private function ticketOrders(string $club):\Kulturhus\Native\Events\Tickets\Orders
    {
        $key=getenv('KH_NATIVE_PRIVATE_KEY_FILE')?:dirname(__DIR__,3).'/.local/native-cms-integration/private.key';
        return new \Kulturhus\Native\Events\Tickets\Orders($this->db,ManagerBridge::clubKey($club),\Kulturhus\Native\Shared\SecretBox::fromFile($key));
    }
    private static function admissions(array $sale):bool{return (bool)array_filter($sale['payload']['lines'],static fn($p)=>$p['kind']==='admission');}
    public function scannerActor(string $shift):array{$s=$this->shift($shift);return $this->actor($s['event_id']);}
    public function scan(string $shift,string $input):array
    {
        $s=$this->shift($shift);$input=trim($input);
        if(str_starts_with($input,'DVM:T:')){$qr=\Kulturhus\Native\Events\Tickets\Qr::parse($input);return array_merge($this->ticketOrders($s['club_id'])->inspectAtRegister($this,$shift,$qr['page_id'],$qr['code']),['kind'=>'admission']);}
        if(str_starts_with(strtoupper($input),'KH:')||preg_match('/^[A-Fa-f0-9]{16}$/D',$input)){
            $code=\Kulturhus\Verein\EventVoucherQr::searchCode($input,$s['event_id']);$vouchers=(new EventVouchers($this->db,$this->actorId,$this->calendar))->vouchers($s['event_id'],$input);
            foreach($vouchers as$v){if($v['code']===$code)return ['kind'=>'member-voucher','label'=>$v['label'],'remaining'=>(int)$v['remaining'],'unit'=>$v['unit'],'status'=>$v['state'],'code'=>$code];foreach($v['tickets']??[]as$t)if($t['code']===$code)return ['kind'=>'member-voucher','label'=>$v['label'],'remaining'=>$t['state']==='active'?1:0,'unit'=>'piece','status'=>$t['state'],'code'=>$code];}
            throw new \DomainException('Persönlicher Vereinsgutschein nicht verfügbar.');
        }
        return $this->valueVoucher($shift,$input);
    }
    public function checkIn(string $shift,string $input,int $version):array
    {
        $s=$this->shift($shift);$qr=\Kulturhus\Native\Events\Tickets\Qr::parse($input);return $this->ticketOrders($s['club_id'])->checkInAtRegister($this,$shift,$qr['page_id'],$qr['code'],$version);
    }
    public function valueVoucher(string $shift,string $input):array
    {
        $s=$this->shift($shift);$code=\Kulturhus\Native\Events\Tickets\Qr::valueCode($input);
        $v=Db::run($this->db,'SELECT id,face_cents,remaining_cents,state FROM pos_value_vouchers WHERE club_id=? AND code_hash=?',[$s['club_id'],hash('sha256',$code)])->fetch();if(!$v)throw new \DomainException('Wertgutschein nicht verfügbar.');
        return ['id'=>$v['id'],'kind'=>'value-voucher','face_cents'=>(int)$v['face_cents'],'remaining_cents'=>(int)$v['remaining_cents'],'status'=>$v['state'],'code'=>$code];
    }
    public function saveProduct(array $input,int $version=0):string
    {
        $label=Content::text($input['label']??'',160,true);$price=Money::cents($input['price']??'');$color=$input['color']??'#225544';$kind=$input['kind']??'goods';$sphere=$input['sphere']??'';
        if($price<1||!preg_match('/^#[0-9a-fA-F]{6}$/D',$color)||!in_array($kind,['goods','value-voucher'],true)||!in_array($sphere,['ideell','vermoegen','zweck','wirtschaftlich'],true))throw new \DomainException('Artikelpreis, Farbe und fachliche Sphäre prüfen.');
        return Db::atomic($this->db,function()use($input,$label,$price,$color,$kind,$sphere,$version){$a=$this->actor(null,true);$id=empty($input['id'])?bin2hex(random_bytes(16)):Content::id($input['id']);$old=Db::run($this->db,'SELECT version FROM pos_products WHERE club_id=? AND id=? FOR UPDATE',[$a['club_id'],$id])->fetchColumn();if(($old===false?0:(int)$old)!==$version)throw new \DomainException('Artikel geändert.');Db::run($this->db,'INSERT INTO pos_products(club_id,id,label,price_cents,color,kind,sphere,active,version) VALUES(?,?,?,?,?,?,?,1,?) ON DUPLICATE KEY UPDATE label=VALUES(label),price_cents=VALUES(price_cents),color=VALUES(color),kind=VALUES(kind),sphere=VALUES(sphere),version=VALUES(version)',[$a['club_id'],$id,$label,$price,$color,$kind,$sphere,$version+1]);Db::run($this->db,'UPDATE pos_products SET active=? WHERE club_id=? AND id=?',[(int)(($input['active']??'1')==='1'),$a['club_id'],$id]);Db::run($this->db,'UPDATE pos_products SET benefit_label=? WHERE club_id=? AND id=?',[Content::text($input['benefit_label']??'',160),$a['club_id'],$id]);return $id;});
    }
    private static function stationData(array $input,string $fallback):array
    {
        $name=Content::text($input['name']??'',160);if($name==='')$name=$fallback;
        $reader=Content::text($input['reader_id']??'',100);$readerName=Content::text($input['reader_name']??'',160);$merchant=Content::text($input['merchant_code']??'',60);
        if($reader!==''&&(!preg_match('/^[A-Za-z0-9_-]{1,100}$/D',$reader)||$readerName===''||!preg_match('/^[A-Za-z0-9_-]{1,60}$/D',$merchant)))throw new \DomainException('Kartenleser für diese Kasse prüfen.');
        return ['name'=>$name,'reader_id'=>$reader,'reader_name'=>$reader!==''?$readerName:'','merchant_code'=>$reader!==''?$merchant:''];
    }
    private static function presentShift(array $row):array
    {
        $metadata=json_decode((string)($row['station_payload']??'{}'),true);$metadata=is_array($metadata)?$metadata:[];
        $station=self::stationData($metadata,(string)($row['cash_account_name']??'Kasse'));
        unset($row['station_payload']);$row['station_name']=$station['name'];$row['reader_id']=$station['reader_id'];$row['reader_name']=$station['reader_name'];$row['merchant_code']=$station['merchant_code'];$row['station_version']=(int)($row['station_version']??0);return $row;
    }
    /** The club lock serialises names and physical reader assignments; an event can have many registers. */
    private function stationAvailable(string $club,string $event,array $station,string $exclude=''):void
    {
        $rows=Db::run($this->db,"SELECT * FROM pos_shifts WHERE club_id=? AND state='open' AND id<>? ORDER BY id FOR SHARE",[$club,$exclude])->fetchAll();
        foreach($rows as$row){$metadata=Db::run($this->db,'SELECT payload,version FROM club_settings WHERE club_id=? AND section=? FOR SHARE',[$club,'pos-shift:'.$row['id']])->fetch();$row['station_payload']=$metadata['payload']??null;$row['station_version']=$metadata['version']??0;$row['cash_account_name']=(string)Db::run($this->db,'SELECT name FROM finance_accounts WHERE club_id=? AND id=?',[$club,$row['cash_account']])->fetchColumn();$current=self::presentShift($row);if($current['event_id']===$event&&strcasecmp($current['station_name'],$station['name'])===0)throw new \DomainException('Dieser Kassenname ist bei der Veranstaltung bereits geöffnet. Bitte einen eigenen Namen wählen.');if($station['reader_id']!==''&&$current['reader_id']===$station['reader_id']&&$current['merchant_code']===$station['merchant_code'])throw new \DomainException('Dieser Kartenleser gehört bereits zu einer geöffneten Kasse. Bitte einen anderen Leser auswählen.');}
    }
    public function open(string $event,string $account,int $opening,array $station=[]):string
    {
        if($opening<0||$opening>10000000)throw new \DomainException('Anfangsbestand prüfen.');
        return Db::atomic($this->db,function()use($event,$account,$opening,$station){$a=$this->actor(null,true);$e=(new EventVouchers($this->db,$this->actorId,$this->calendar))->event($event);$finance=new Finance($this->db,$this->actorId);$cash=$finance->account($account);if($cash['kind']!=='cash')throw new \DomainException('Aktive Handkasse erforderlich.');$metadata=self::stationData($station,$cash['name']);Db::run($this->db,'SELECT id FROM clubs WHERE id=? FOR UPDATE',[$a['club_id']]);$this->stationAvailable($a['club_id'],$event,$metadata);
            // All current shift locks precede the account and its settings, as in close().
            $currentAccount=Db::run($this->db,'SELECT kind FROM finance_accounts WHERE club_id=? AND id=? FOR UPDATE',[$a['club_id'],$account])->fetchColumn();if($currentAccount!=='cash')throw new \DomainException('Aktive Handkasse erforderlich.');$finance->requireActiveAccount($account);$id=bin2hex(random_bytes(16));Db::run($this->db,"INSERT INTO pos_shifts(club_id,id,event_id,cash_account,opened_by,state,opening_cents,opened_at) VALUES(?,?,?,?,?,'open',?,UTC_TIMESTAMP())",[$a['club_id'],$id,$e['id'],$account,$a['id'],$opening]);Db::run($this->db,'INSERT INTO club_settings(club_id,section,payload,version,updated_at) VALUES(?,?,?,1,?)',[$a['club_id'],'pos-shift:'.$id,json_encode($metadata,JSON_THROW_ON_ERROR),gmdate('Y-m-d\\TH:i:s\\Z')]);return $id;});
    }
    public function configure(string $shift,int $version,array $station):void
    {
        Db::atomic($this->db,function()use($shift,$version,$station){$a=$this->actor(null,true);Db::run($this->db,'SELECT id FROM clubs WHERE id=? FOR UPDATE',[$a['club_id']]);$s=$this->shift($shift,true);if($s['state']!=='open'||$s['station_version']!==$version)throw new \DomainException('Kasseneinrichtung wurde geändert. Bitte die Kasse neu öffnen.');$metadata=self::stationData($station,$s['station_name']);if([$metadata['reader_id'],$metadata['merchant_code']]!==[$s['reader_id'],$s['merchant_code']]&&$this->readerChangeBlocked($a['club_id'],$shift))throw new \DomainException('Eine Kartenzahlung läuft noch. Zahlungsstand zuerst klären.');$this->stationAvailable($a['club_id'],$s['event_id'],$metadata,$shift);Db::run($this->db,'INSERT INTO club_settings(club_id,section,payload,version,updated_at) VALUES(?,?,?,?,?) ON DUPLICATE KEY UPDATE payload=VALUES(payload),version=VALUES(version),updated_at=VALUES(updated_at)',[$a['club_id'],'pos-shift:'.$shift,json_encode($metadata,JSON_THROW_ON_ERROR),$version+1,gmdate('Y-m-d\\TH:i:s\\Z')]);Db::run($this->db,'INSERT INTO audit_events(club_id,actor_id,action,object_id,object_version,happened_at) VALUES(?,?,?,?,?,?)',[$a['club_id'],$a['id'],'pos.station.configure',$shift,$version+1,gmdate('c')]);});
    }
    private function readerChangeBlocked(string $club,string $shift):bool
    {
        try{return (bool)Db::run($this->db,"SELECT 1 FROM pos_attempts p JOIN pos_sales v ON v.club_id=p.club_id AND v.id=p.sale_id WHERE v.club_id=? AND v.shift_id=? AND p.state IN ('processing','pending','uncertain') FOR SHARE NOWAIT",[$club,$shift])->fetchColumn();}
        catch(\PDOException $error){if((int)($error->errorInfo[1]??0)!==3572)throw $error;throw new \DomainException('Eine Zahlung wird gerade verarbeitet. Bitte den Zahlungsstand zuerst klären und die Kasseneinrichtung danach erneut öffnen.',0,$error);}
    }
    public function shifts():array
    {
        if($this->guard)($this->guard)();$a=Permissions::actor($this->db,$this->actorId);$rows=Db::run($this->db,"SELECT s.*,e.title,a.name cash_account_name,p.payload station_payload,p.version station_version FROM pos_shifts s JOIN event_voucher_events e ON e.club_id=s.club_id AND e.id=s.event_id LEFT JOIN finance_accounts a ON a.club_id=s.club_id AND a.id=s.cash_account LEFT JOIN club_settings p ON p.club_id=s.club_id AND p.section=CONCAT('pos-shift:',s.id) WHERE s.club_id=? AND (?=1 OR EXISTS(SELECT 1 FROM event_voucher_helpers h WHERE h.club_id=s.club_id AND h.event_id=s.event_id AND h.actor_id=?)) ORDER BY s.opened_at DESC,s.id LIMIT 100",[$a['club_id'],(int)$this->canManage(),$a['id']])->fetchAll();return array_map(self::presentShift(...),$rows);
    }
    public function shift(string $id,bool $lock=false):array
    {
        $id=Content::id($id);if($this->guard)($this->guard)();$a=Permissions::actor($this->db,$this->actorId);$s=Db::run($this->db,'SELECT * FROM pos_shifts WHERE club_id=? AND id=?'.($lock?' FOR UPDATE':''),[$a['club_id'],$id])->fetch();if(!$s)throw new \DomainException('Kasse nicht verfügbar.');$this->actor($s['event_id']);$settings=Db::run($this->db,'SELECT payload,version FROM club_settings WHERE club_id=? AND section=?'.($lock?' FOR SHARE':''),[$a['club_id'],'pos-shift:'.$id])->fetch();$s['station_payload']=$settings['payload']??null;$s['station_version']=$settings['version']??0;$s['cash_account_name']=(string)Db::run($this->db,'SELECT name FROM finance_accounts WHERE club_id=? AND id=?',[$a['club_id'],$s['cash_account']])->fetchColumn();return self::presentShift($s);
    }
    public function shiftSummary(string $id):array
    {
        $shift=$this->shift($id);$cash=(int)Db::run($this->db,"SELECT COALESCE(SUM(cash_cents),0) FROM pos_sales WHERE club_id=? AND shift_id=? AND state='paid'",[$shift['club_id'],$id])->fetchColumn();return ['opening_cents'=>(int)$shift['opening_cents'],'cash_cents'=>$cash,'expected_cents'=>(int)$shift['opening_cents']+$cash,'counted_cents'=>$shift['counted_cents']===null?null:(int)$shift['counted_cents']];
    }
    public function eventSummary(string $event):array
    {
        $a=$this->actor($event);(new EventVouchers($this->db,$this->actorId,$this->calendar))->event($event);
        $shifts=Db::run($this->db,"SELECT COUNT(*) registers,COALESCE(SUM(state='open'),0) open_registers,COALESCE(SUM(state='closed'),0) closed_registers FROM pos_shifts WHERE club_id=? AND event_id=?",[$a['club_id'],$event])->fetch();
        $sales=Db::run($this->db,"SELECT COALESCE(SUM(v.state='paid'),0) sales_count,COALESCE(SUM(IF(v.state='paid',v.total_cents,0)),0) paid_cents,COALESCE(SUM(IF(v.state='paid',v.cash_cents,0)),0) cash_cents,COALESCE(SUM(IF(v.state='paid',v.voucher_cents,0)),0) voucher_cents,COALESCE(SUM(IF(v.state='paid',v.total_cents-v.cash_cents-v.voucher_cents,0)),0) card_cents,COALESCE(SUM(IF(v.state='refunded',v.total_cents,0)),0) refunded_cents FROM pos_sales v JOIN pos_shifts s ON s.club_id=v.club_id AND s.id=v.shift_id WHERE s.club_id=? AND s.event_id=?",[$a['club_id'],$event])->fetch();return array_map('intval',array_merge($shifts,$sales));
    }
    public function cart(string $shift,array $lines,int $discount,string $key):array
    {
        Content::id($key);if(!array_is_list($lines)||!$lines||count($lines)>100||$discount<0)throw new \DomainException('Warenkorb prüfen.');
        return Db::atomic($this->db,function()use($shift,$lines,$discount,$key){$s=$this->shift($shift,true);$a=$this->actor($s['event_id']);if($s['state']!=='open')throw new \DomainException('Kasse abgeschlossen.');$fingerprint=hash('sha256',json_encode([$shift,$lines,$discount],JSON_THROW_ON_ERROR));$old=Db::run($this->db,'SELECT * FROM pos_sales WHERE club_id=? AND request_key=? FOR UPDATE',[$a['club_id'],$key])->fetch();if($old){if(!hash_equals($old['fingerprint'],$fingerprint)||$old['actor_id']!==$a['id'])throw new \DomainException('Wiederholung mit anderem Warenkorb.');return $this->present($old);}
            if($discount&&!$a['_finance'])throw new \DomainException('Rabatt benötigt Finanzberechtigung.');$items=[];$total=0;$available=[];foreach($this->products($s['event_id'])as$p)$available[$p['id']]=$p;
            foreach($lines as $line){$p=$available[Content::id($line['id']??'')]??null;$qty=$line['quantity']??null;if(!$p||!is_int($qty)||$qty<1||$qty>100)throw new \DomainException('Artikel oder Menge geändert.');$amount=(int)$p['price_cents']*$qty;$total+=$amount;$items[]=['id'=>$p['id'],'version'=>(int)$p['version'],'label'=>$p['label'],'quantity'=>$qty,'price_cents'=>(int)$p['price_cents'],'amount_cents'=>$amount,'kind'=>$p['kind'],'sphere'=>$p['sphere']];}
            if($discount>$total||$total>100000000)throw new \DomainException('Rabatt oder Gesamtbetrag prüfen.');if($discount&&array_filter($items,static fn($p)=>in_array($p['kind'],['value-voucher','admission'],true)))throw new \DomainException('Tickets und gekaufte Wertgutscheine nicht rabattieren.');$id=bin2hex(random_bytes(16));$payload=['lines'=>$items,'discount_cents'=>$discount,'benefit_cents'=>0];Db::run($this->db,"INSERT INTO pos_sales(club_id,id,shift_id,state,payload,total_cents,request_key,fingerprint,actor_id,version,created_at) VALUES(?,?,?,'cart',?,?,?,?,?,1,UTC_TIMESTAMP())",[$a['club_id'],$id,$shift,json_encode($payload,JSON_THROW_ON_ERROR),$total-$discount,$key,$fingerprint,$a['id']]);$sale=$this->sale($id);if(self::admissions($sale))$this->ticketOrders($a['club_id'])->reserveAtRegister($this,$id);return $sale;});
    }
    private function present(array $sale):array{$sale['payload']=json_decode($sale['payload'],true,64,JSON_THROW_ON_ERROR);$sale['total_cents']=(int)$sale['total_cents'];$sale['version']=(int)$sale['version'];return $sale;}
    public function sale(string $id,bool $lock=false):array
    {$id=Content::id($id);$a=Permissions::actor($this->db,$this->actorId);$sale=Db::run($this->db,'SELECT * FROM pos_sales WHERE club_id=? AND id=?'.($lock?' FOR UPDATE':''),[$a['club_id'],$id])->fetch();if(!$sale)throw new \DomainException('Verkauf nicht verfügbar.');$this->shift($sale['shift_id']);return $this->present($sale);}
    public function recent(string $shift):array
    {$s=$this->shift($shift);return Db::run($this->db,'SELECT id,state,total_cents,created_at FROM pos_sales WHERE club_id=? AND shift_id=? ORDER BY created_at DESC,id LIMIT 50',[$s['club_id'],$shift])->fetchAll();}
    /** Freeze cash and voucher reservations before any external payment is initiated. */
    public function tender(string $id,int $version,int $cash,string $voucherCode='',int $voucherAmount=0,int $received=0):array
    {
        if($voucherCode!=='')$voucherCode=\Kulturhus\Native\Events\Tickets\Qr::valueCode($voucherCode);
        if($cash<0||$voucherAmount<0||$received<$cash)throw new \DomainException('Bargeld und Rückgeld prüfen.');
        return Db::atomic($this->db,function()use($id,$version,$cash,$voucherCode,$voucherAmount,$received){$sale=$this->sale($id,true);$shift=$this->shift($sale['shift_id'],true);$a=$this->actor($shift['event_id']);if($sale['state']!=='cart'||$sale['version']!==$version||$shift['state']!=='open')throw new \DomainException('Verkauf inzwischen geändert.');if($cash+$voucherAmount>$sale['total_cents'])throw new \DomainException('Zahlung übersteigt den Betrag.');$payload=$sale['payload'];
            if($voucherAmount){if(!preg_match('/^[A-F0-9]{32}$/D',$voucherCode)||array_filter($payload['lines'],static fn($p)=>$p['kind']==='value-voucher'))throw new \DomainException('Wertgutschein-Code prüfen; kein Gutscheinverkauf mit Gutscheinen.');$v=Db::run($this->db,"SELECT * FROM pos_value_vouchers WHERE club_id=? AND code_hash=? AND state='active' FOR UPDATE",[$a['club_id'],hash('sha256',$voucherCode)])->fetch();if(!$v||$voucherAmount>(int)$v['remaining_cents'])throw new \DomainException('Wertgutschein gesperrt oder unzureichend.');Db::run($this->db,'UPDATE pos_value_vouchers SET remaining_cents=remaining_cents-? WHERE club_id=? AND id=?',[$voucherAmount,$a['club_id'],$v['id']]);Db::run($this->db,"INSERT INTO pos_value_moves(club_id,id,voucher_id,sale_id,cents,kind) VALUES(?,?,?,?,?,'reserve')",[$a['club_id'],bin2hex(random_bytes(16)),$v['id'],$id,-$voucherAmount]);$payload['value_voucher_id']=$v['id'];}
            $payload['received_cents']=$received;$payload['change_cents']=$received-$cash;Db::run($this->db,"UPDATE pos_sales SET state='payment',payload=?,cash_cents=?,voucher_cents=?,version=version+1 WHERE club_id=? AND id=?",[json_encode($payload,JSON_THROW_ON_ERROR),$cash,$voucherAmount,$a['club_id'],$id]);$sale=$this->sale($id);if($cash+$voucherAmount===$sale['total_cents'])$this->complete($sale,null);return $this->sale($id);
        });
    }
    public function attempt(string $saleId,string $method):array
    {
        if(!in_array($method,['sumup-reader','sumup-tap','paypal'],true))throw new \DomainException('Zahlungsweg prüfen.');
        return Db::atomic($this->db,function()use($saleId,$method){$s=$this->sale($saleId,true);$shift=$this->shift($s['shift_id'],true);if($shift['state']!=='open'||$s['state']!=='payment')throw new \DomainException('Verkauf nicht zahlungsbereit.');$old=Db::run($this->db,"SELECT * FROM pos_attempts WHERE club_id=? AND sale_id=? AND state NOT IN ('failed','cancelled') ORDER BY created_at,id LIMIT 1 FOR UPDATE",[$s['club_id'],$saleId])->fetch();if($old){if($old['method']!==$method)throw new \DomainException('Bestehenden Zahlungsversuch zuerst klären.');return $old;}$id=bin2hex(random_bytes(16));$reference='KH-'.$id;$amount=$s['total_cents']-(int)$s['cash_cents']-(int)$s['voucher_cents'];if($amount<=0)throw new \DomainException('Kein offener Zahlungsbetrag.');Db::run($this->db,"INSERT INTO pos_attempts(club_id,id,sale_id,method,amount_cents,state,reference,created_at) VALUES(?,?,?,?,?,'prepared',?,UTC_TIMESTAMP())",[$s['club_id'],$id,$saleId,$method,$amount,$reference]);return $this->payment($id);});
    }
    public function payment(string $id):array
    {$a=Permissions::actor($this->db,$this->actorId);$r=Db::run($this->db,'SELECT * FROM pos_attempts WHERE club_id=? AND id=?',[$a['club_id'],Content::id($id)])->fetch();if(!$r)throw new \DomainException('Zahlungsversuch nicht verfügbar.');$this->sale($r['sale_id']);return $r;}
    public function cancelPrepared(string $id):void
    {Db::atomic($this->db,function()use($id){$a=$this->payment($id);$sale=$this->sale($a['sale_id'],true);if($a['state']!=='prepared'||$sale['state']!=='payment'||Db::run($this->db,"UPDATE pos_attempts SET state='cancelled' WHERE club_id=? AND id=? AND state='prepared'",[$a['club_id'],$id])->rowCount()!==1)throw new \DomainException('Nur einen noch nicht gestarteten Versuch verwerfen. Begonnene Zahlungen unabhängig prüfen.');});}
    private static function readerSection(array $station):string
    {return 'pos-reader:'.substr(hash('sha256',$station['merchant_code']."\0".$station['reader_id']),0,40);}
    /** A reader is occupied across sales until its independently verified payment completes or definitively fails. */
    private function claimReader(array $station,string $attempt):void
    {
        if($station['reader_id']==='')return;$section=self::readerSection($station);$payload=json_encode(['attempt_id'=>$attempt,'shift_id'=>$station['id'],'reader_id'=>$station['reader_id'],'merchant_code'=>$station['merchant_code']],JSON_THROW_ON_ERROR);
        // Insert-first avoids mutually held missing-key gap locks for different readers.
        Db::run($this->db,'INSERT INTO club_settings(club_id,section,payload,version,updated_at) VALUES(?,?,?,1,?) ON DUPLICATE KEY UPDATE section=VALUES(section)',[$station['club_id'],$section,$payload,gmdate('Y-m-d\TH:i:s\Z')]);$held=Db::run($this->db,'SELECT payload FROM club_settings WHERE club_id=? AND section=? FOR UPDATE',[$station['club_id'],$section])->fetchColumn();$owner=json_decode((string)$held,true);if(($owner['attempt_id']??null)!==$attempt)throw new \DomainException('An diesem Kartenleser läuft bereits eine Zahlung. Bitte zuerst deren Zahlungsstand klären.');
    }
    private function releaseReader(array $sale,string $attempt):void
    {
        $station=$this->shift($sale['shift_id'],true);if($station['reader_id']==='')return;Db::run($this->db,"DELETE FROM club_settings WHERE club_id=? AND section=? AND JSON_UNQUOTE(JSON_EXTRACT(payload,'$.attempt_id'))=?",[$station['club_id'],self::readerSection($station),$attempt]);
    }
    public function start(string $id,callable $transport,?callable $beforeStart=null):array
    {
        $attempt=Db::atomic($this->db,function()use($id,$beforeStart){$a=$this->payment($id);$s=$this->sale($a['sale_id'],true);$station=$this->shift($s['shift_id'],true);if($station['state']!=='open'||$a['state']!=='prepared'||$s['state']!=='payment')throw new \DomainException('Versuch wurde bereits gestartet; Ergebnis prüfen.');if($beforeStart!==null)$beforeStart($station);if($a['method']==='sumup-reader')$this->claimReader($station,$id);$changed=Db::run($this->db,"UPDATE pos_attempts SET state='processing' WHERE club_id=? AND id=? AND state='prepared'",[$a['club_id'],$id])->rowCount();if($changed!==1)throw new \DomainException('Versuch wird bereits verarbeitet.');return $a;});
        try{Db::atomic($this->db,function()use($attempt,$transport,$id){$sale=$this->sale($attempt['sale_id'],true);if($sale['state']!=='payment')throw new \DomainException('Verkauf inzwischen geändert.');$result=$transport($attempt);if(!is_array($result)||!is_string($result['external_id']??null))throw new \RuntimeException('Referenz fehlt.');Db::run($this->db,"UPDATE pos_attempts SET state='pending',external_id=?,result_json=? WHERE club_id=? AND id=? AND state='processing'",[$result['external_id'],json_encode($result,JSON_THROW_ON_ERROR),$attempt['club_id'],$id]);});}
        catch(\Throwable){Db::run($this->db,"UPDATE pos_attempts SET state='uncertain' WHERE club_id=? AND id=? AND state='processing'",[$attempt['club_id'],$id]);}
        return $this->payment($id);
    }
    public function verify(string $id,callable $read):array
    {
        $a=$this->payment($id);if(!in_array($a['state'],['processing','pending','uncertain','prepared','verified','failed'],true))throw new \DomainException('Versuch nicht prüfbar.');if($a['state']==='failed')return $this->sale($a['sale_id']);$proof=$read($a);
        return Db::atomic($this->db,function()use($a,$proof){
            $sale=$this->sale($a['sale_id'],true);$current=$this->payment($a['id']);if(($current['state']==='verified'&&$sale['state']==='paid')||$current['state']==='failed')return $sale;
            if($sale['state']!=='payment'||($proof['reference']??'')!==$a['reference']||($proof['amount_cents']??null)!==(int)$a['amount_cents']||($proof['currency']??'')!=='EUR'||!is_string($proof['transaction_id']??null)||$proof['transaction_id']===''||!is_string($proof['merchant']??null)||$proof['merchant']===''||($proof['provider']??'')!==($a['method']==='paypal'?'paypal':'sumup'))throw new \DomainException('Unabhängiger Zahlungsnachweis fehlt.');
            $negative=($proof['outcome']??'paid')==='unpaid';
            if($negative){$station=$this->shift($sale['shift_id'],true);if($a['method']!=='sumup-reader'||!in_array($current['state'],['processing','pending','uncertain'],true)||$station['state']!=='open'||$station['reader_id']===''||$station['merchant_code']!==$proof['merchant']||!in_array($proof['terminal_status']??null,['FAILED','CANCELLED'],true))throw new \DomainException('Endgültig unbezahltes Ergebnis nicht unabhängig bestätigt.');}
            elseif(($proof['outcome']??'paid')!=='paid')throw new \DomainException('Zahlungsstatus noch nicht eindeutig.');
            Db::run($this->db,'INSERT INTO pos_transactions(provider,merchant,transaction_id,club_id,attempt_id) VALUES(?,?,?,?,?)',[$proof['provider'],$proof['merchant'],$proof['transaction_id'],$sale['club_id'],$a['id']]);
            Db::run($this->db,'UPDATE pos_attempts SET state=?,result_json=? WHERE club_id=? AND id=?',[$negative?'failed':'verified',json_encode($proof,JSON_THROW_ON_ERROR),$sale['club_id'],$a['id']]);
            if(!$negative)$this->complete($sale,$proof);else Db::run($this->db,'INSERT INTO audit_events(club_id,actor_id,action,object_id,object_version,happened_at) VALUES(?,?,?,?,?,?)',[$sale['club_id'],$this->actorId,'pos.payment.failed',$a['id'],$sale['version'],gmdate('c')]);
            if($a['method']==='sumup-reader')$this->releaseReader($sale,$a['id']);return $this->sale($sale['id']);
        });
    }
    private function complete(array $sale,?array $proof):void
    {
        $s=$this->shift($sale['shift_id'],true);if($s['state']!=='open'||$sale['state']!=='payment')throw new \DomainException('Kasse oder Zahlung geändert.');$a=$this->actor($s['event_id']);$receipt=['number'=>'KH-'.$sale['id'],'sale_id'=>$sale['id'],'created_at'=>gmdate(DATE_ATOM),'club'=>Identity::forClub($this->db,ManagerBridge::clubKey($a['club_id'])),'lines'=>$sale['payload']['lines'],'discount_cents'=>$sale['payload']['discount_cents'],'benefit_cents'=>$sale['payload']['benefit_cents'],'total_cents'=>$sale['total_cents'],'cash_cents'=>(int)$sale['cash_cents'],'voucher_cents'=>(int)$sale['voucher_cents'],'received_cents'=>$sale['payload']['received_cents'],'change_cents'=>$sale['payload']['change_cents'],'external_payment'=>$proof,'issued_vouchers'=>[]];
        $receipt['brand']=\Kulturhus\Native\Shared\PrintedBrand::snapshot($this->db,ManagerBridge::clubKey($a['club_id']),$receipt['club'],$this->mediaRoot??(string)getenv('KH_NATIVE_MANAGER_MEDIA_ROOT'));
        foreach($receipt['lines'] as $line)if($line['kind']==='value-voucher')for($i=0;$i<$line['quantity'];$i++){$code=strtoupper(bin2hex(random_bytes(16)));$id=bin2hex(random_bytes(16));Db::run($this->db,"INSERT INTO pos_value_vouchers(club_id,id,code_hash,issued_sale,face_cents,remaining_cents,state) VALUES(?,?,?,?,?,?,'active')",[$a['club_id'],$id,hash('sha256',$code),$sale['id'],$line['price_cents'],$line['price_cents']]);$receipt['issued_vouchers'][]=['id'=>$id,'code'=>$code,'face_cents'=>$line['price_cents']];}
        $receipt['issued_tickets']=self::admissions($sale)?$this->ticketOrders($a['club_id'])->settleAtRegister($this,$sale['id'],$receipt):[];
        Db::run($this->db,"UPDATE pos_value_moves SET kind='redeem' WHERE club_id=? AND sale_id=? AND kind='reserve'",[$a['club_id'],$sale['id']]);Db::run($this->db,"UPDATE pos_sales SET state='paid',receipt_json=?,version=version+1 WHERE club_id=? AND id=?",[json_encode($receipt,JSON_THROW_ON_ERROR),$a['club_id'],$sale['id']]);
    }
    public function cancel(string $id,int $version):void
    {Db::atomic($this->db,function()use($id,$version){$s=$this->sale($id,true);if(!in_array($s['state'],['cart','payment'],true)||$s['version']!==$version)throw new \DomainException('Bezahlten Verkauf nur mit Storno korrigieren.');if(Db::run($this->db,"SELECT 1 FROM pos_attempts WHERE club_id=? AND sale_id=? AND state NOT IN ('failed','cancelled')",[$s['club_id'],$id])->fetchColumn())throw new \DomainException('Zahlungsversuch zuerst unabhängig klären.');if(self::admissions($s))$this->ticketOrders($s['club_id'])->cancelAtRegister($this,$id);$this->restoreVoucher($s,'release');$this->restoreBenefit($s);Db::run($this->db,"UPDATE pos_sales SET state='cancelled',version=version+1 WHERE club_id=? AND id=?",[$s['club_id'],$id]);});}
    private function restoreVoucher(array $sale,string $kind):void
    {foreach(Db::run($this->db,"SELECT * FROM pos_value_moves WHERE club_id=? AND sale_id=? AND kind IN ('reserve','redeem') FOR UPDATE",[$sale['club_id'],$sale['id']])->fetchAll() as $move){Db::run($this->db,'UPDATE pos_value_vouchers SET remaining_cents=remaining_cents+? WHERE club_id=? AND id=?',[-(int)$move['cents'],$sale['club_id'],$move['voucher_id']]);Db::run($this->db,'INSERT INTO pos_value_moves(club_id,id,voucher_id,sale_id,cents,kind) VALUES(?,?,?,?,?,?)',[$sale['club_id'],bin2hex(random_bytes(16)),$move['voucher_id'],$sale['id'],-(int)$move['cents'],$kind]);}}
    public function close(string $shift,int $counted):array
    {return Db::atomic($this->db,function()use($shift,$counted){$this->actor(null,true);$s=$this->shift($shift,true);if($s['state']==='closed')return $s;Db::run($this->db,'SELECT id FROM finance_accounts WHERE club_id=? AND id=? FOR UPDATE',[$s['club_id'],$s['cash_account']]);if(Db::run($this->db,"SELECT 1 FROM pos_sales WHERE club_id=? AND shift_id=? AND state IN ('cart','payment')",[$s['club_id'],$shift])->fetchColumn())throw new \DomainException('Offene Warenkörbe/Zahlungen zuerst klären.');if(Db::run($this->db,"SELECT 1 FROM pos_refunds r JOIN pos_sales v ON v.club_id=r.club_id AND v.id=r.sale_id WHERE v.club_id=? AND v.shift_id=? AND r.state<>'confirmed'",[$s['club_id'],$shift])->fetchColumn())throw new \DomainException('Unklare Rückzahlungen zuerst unabhängig klären.');$cash=(int)Db::run($this->db,"SELECT COALESCE(SUM(cash_cents),0) FROM pos_sales WHERE club_id=? AND shift_id=? AND state='paid'",[$s['club_id'],$shift])->fetchColumn();if($counted!==$s['opening_cents']+$cash)throw new \DomainException('Gezählter Bestand weicht ab; Differenz zuerst aufklären.');$transfer=null;if($cash){$finance=new Finance($this->db,$this->actorId);$transfer=$finance->createBankTransactionLocked(['account'=>$s['cash_account'],'booked_on'=>gmdate('Y-m-d'),'direction'=>'income','amount'=>Money::decimal($cash),'description'=>'Veranstaltungskasse '.$shift,'counterparty'=>'Veranstaltungskasse'],substr(hash('sha256','pos-close:'.$shift),0,32));}Db::run($this->db,"UPDATE pos_shifts SET state='closed',counted_cents=?,transfer_id=?,closed_at=UTC_TIMESTAMP() WHERE club_id=? AND id=?",[$counted,$transfer,$s['club_id'],$shift]);return $this->shift($shift);});}
    public function receipt(string $id):array
    {$s=$this->sale($id);if(!in_array($s['state'],['paid','refunded'],true)||!$s['receipt_json'])throw new \DomainException('Originalbeleg noch nicht erstellt.');return json_decode($s['receipt_json'],true,64,JSON_THROW_ON_ERROR);}
    public function benefit(string $saleId,int $version,string $code,string $product,int $quantity):array
    {
        if(!preg_match('/^[A-F0-9]{16}$/D',$code)||$quantity<1||$quantity>100)throw new \DomainException('Mitgliedervorteil prüfen.');
        return Db::atomic($this->db,function()use($saleId,$version,$code,$product,$quantity){
            $s=$this->sale($saleId,true);$shift=$this->shift($s['shift_id'],true);$a=$this->actor($shift['event_id']);
            if($s['state']!=='cart'||$s['version']!==$version||isset($s['payload']['benefit_move']))throw new \DomainException('Vorteil/Warenkorb bereits geändert.');
            $line=null;foreach($s['payload']['lines'] as $candidate)if($candidate['id']===$product)$line=$candidate;
            $p=Db::run($this->db,'SELECT benefit_label FROM pos_products WHERE club_id=? AND id=? FOR SHARE',[$a['club_id'],$product])->fetchColumn();
            if(!$line||$line['kind']!=='goods'||$quantity>$line['quantity']||!is_string($p)||$p==='')throw new \DomainException('Artikel besitzt keine zugeordnete Vorteilsart.');
            $ticket=Db::run($this->db,'SELECT * FROM event_voucher_tickets WHERE club_id=? AND code=? FOR UPDATE',[$a['club_id'],$code])->fetch();
            $v=Db::run($this->db,"SELECT * FROM event_vouchers WHERE club_id=? AND event_id=? AND ".($ticket?'id=?':'code=?')." FOR UPDATE",[$a['club_id'],$shift['event_id'],$ticket?$ticket['voucher_id']:$code])->fetch();
            if(!$v||$v['state']!=='active'||$v['label']!==$p||$v['unit']!=='piece'||$quantity>(int)$v['remaining']||($ticket&&($quantity!==1||$ticket['state']!=='active')))throw new \DomainException('Persönlicher Vorteil ist nicht einlösbar.');
            $member=Db::run($this->db,"SELECT 1 FROM memberships m JOIN contacts c ON c.club_id=m.club_id AND c.id=m.contact_id WHERE m.club_id=? AND m.contact_id=? AND m.status IN ('aktiv','passiv') AND m.starts_on<=CURRENT_DATE() AND (m.ends_on IS NULL OR m.ends_on>=CURRENT_DATE()) AND c.archived_at IS NULL FOR SHARE",[$a['club_id'],$v['contact_id']])->fetchColumn();if(!$member)throw new \DomainException('Mitgliedschaft nicht berechtigt.');
            $benefits=new EventVouchers($this->db,$this->actorId,$this->calendar);$benefits->liveEvent($benefits->event($shift['event_id']));
            $deduction=$line['price_cents']*$quantity;if($deduction>$s['total_cents'])throw new \DomainException('Vorteil und Rabatt übersteigen den Warenkorb.');
            $move=bin2hex(random_bytes(16));Db::run($this->db,"INSERT INTO event_voucher_moves(club_id,id,voucher_id,actor_id,kind,amount,reason,created_at) VALUES(?,?,?,?,'redeem',?,?,?)",[$a['club_id'],$move,$v['id'],$a['id'],-$quantity,'Kassenvorteil '.$saleId,gmdate('c')]);\Kulturhus\Verein\EventVoucherTickets::debit($this->db,$v,$quantity,$ticket['id']??'','redeem',$move);Db::run($this->db,'UPDATE event_vouchers SET remaining=remaining-?,version=version+1 WHERE club_id=? AND id=?',[$quantity,$a['club_id'],$v['id']]);$payload=$s['payload'];$payload['benefit_cents']=$deduction;$payload['benefit_move']=['id'=>$move,'voucher'=>$v['id'],'quantity'=>$quantity];Db::run($this->db,'UPDATE pos_sales SET payload=?,total_cents=total_cents-?,version=version+1 WHERE club_id=? AND id=?',[json_encode($payload,JSON_THROW_ON_ERROR),$deduction,$a['club_id'],$saleId]);return $this->sale($saleId);
        });
    }
    private function restoreBenefit(array $sale):void
    {
        $move=$sale['payload']['benefit_move']??null;if(!$move)return;
        $v=Db::run($this->db,'SELECT * FROM event_vouchers WHERE club_id=? AND id=? FOR UPDATE',[$sale['club_id'],$move['voucher']])->fetch();if(!$v)throw new \DomainException('Vorteilsbestand fehlt.');
        \Kulturhus\Verein\EventVoucherTickets::reverse($this->db,$v,$move['id'],$move['quantity']);
        Db::run($this->db,'UPDATE event_vouchers SET remaining=remaining+?,version=version+1 WHERE club_id=? AND id=?',[$move['quantity'],$sale['club_id'],$v['id']]);Db::run($this->db,"INSERT INTO event_voucher_moves(club_id,id,voucher_id,actor_id,kind,amount,reversal_of,reason,created_at) VALUES(?,?,?,?,'reverse',?,?,?,?)",[$sale['club_id'],bin2hex(random_bytes(16)),$v['id'],$this->actorId,$move['quantity'],$move['id'],'Kassenkorrektur '.$sale['id'],gmdate('c')]);
    }
    public function refund(string $id,string $reason,?callable $transport=null):array
    {
        $reason=Content::text($reason,500,true);
        $sale=Db::atomic($this->db,function()use($id,$reason){$this->actor(null,true);$s=$this->sale($id,true);$shift=$this->shift($s['shift_id'],true);if($shift['state']!=='open'||$s['state']!=='paid')throw new \DomainException('Storno benötigt bezahlten Verkauf in offener Kasse.');if(Db::run($this->db,'SELECT 1 FROM pos_refunds WHERE club_id=? AND sale_id=?',[$s['club_id'],$id])->fetchColumn())throw new \DomainException('Storno bereits begonnen; Ergebnis unabhängig klären.');$this->refundableVouchers($s);if(self::admissions($s))$this->ticketOrders($s['club_id'])->pauseRegisterRefund($this,$id);Db::run($this->db,"INSERT INTO pos_refunds(club_id,sale_id,state,reason,actor_id,created_at) VALUES(?,?,'processing',?,?,UTC_TIMESTAMP())",[$s['club_id'],$id,$reason,$this->actorId]);Db::run($this->db,"UPDATE pos_value_vouchers SET state='refund-pending' WHERE club_id=? AND issued_sale=? AND state='active'",[$s['club_id'],$id]);return $s;});
        $external=false;
        try {
            Db::atomic($this->db,function()use($sale,$transport,&$external){$this->actor(null,true);$s=$this->sale($sale['id'],true);$this->refundableVouchers($s,true);$receipt=$this->receipt($s['id']);$proof=$receipt['external_payment'];if($proof!==null){if(!$transport)throw new \DomainException('Bestätigte externe Rückzahlung erforderlich.');$external=true;$result=$transport($proof,$s['id']);$this->refundProof($proof,$result);}else $result=['confirmed'=>true,'method'=>'cash/voucher'];$this->finishRefund($s,$result);});
        }catch(\Throwable $e){$checkpoint=$e instanceof \Kulturhus\Native\Payments\RefundPending?json_encode($e->checkpoint,JSON_THROW_ON_ERROR):null;Db::run($this->db,'UPDATE pos_refunds SET state=?,result_json=? WHERE club_id=? AND sale_id=? AND state=\'processing\'',[$external?'uncertain':'blocked',$checkpoint,$sale['club_id'],$id]);}
        return Db::run($this->db,'SELECT state,reason FROM pos_refunds WHERE club_id=? AND sale_id=?',[$sale['club_id'],$id])->fetch();
    }
    public function refundStatus(string $id):?array
    {$this->actor(null,true);$sale=$this->sale($id);$row=Db::run($this->db,'SELECT state,reason,result_json FROM pos_refunds WHERE club_id=? AND sale_id=?',[$sale['club_id'],$id])->fetch();return $row?:null;}
    /** Recovery reads provider state only. It can never initiate another refund. */
    public function reconcileRefund(string $id,callable $reader):array
    {
        return Db::atomic($this->db,function()use($id,$reader){$this->actor(null,true);$sale=$this->sale($id,true);$shift=$this->shift($sale['shift_id'],true);$refund=Db::run($this->db,'SELECT * FROM pos_refunds WHERE club_id=? AND sale_id=? FOR UPDATE',[$sale['club_id'],$id])->fetch();if(!$refund)throw new \DomainException('Kein Rückzahlungsversuch vorhanden.');if($refund['state']==='confirmed')return ['state'=>'confirmed','reason'=>$refund['reason']];if($shift['state']!=='open'||$sale['state']!=='paid')throw new \DomainException('Rückzahlung benötigt einen bezahlten Originalvorgang in offener Kasse.');
            $this->refundableVouchers($sale,true);$proof=$this->receipt($id)['external_payment'];if($proof===null)$result=['confirmed'=>true,'method'=>'cash/voucher'];else{$checkpoint=$refund['result_json']?json_decode($refund['result_json'],true,32,JSON_THROW_ON_ERROR):[];$result=$reader($proof,$id,$checkpoint);$this->refundProof($proof,$result);}$this->finishRefund($sale,$result);return ['state'=>'confirmed','reason'=>$refund['reason']];
        });
    }
    private function refundProof(array $payment,array $result):void
    {
        if(($result['confirmed']??false)!==true||!is_string($result['external_id']??null)||$result['external_id']==='')throw new \DomainException('Rückzahlung nicht unabhängig bestätigt.');
        foreach(['provider','transaction_id','reference','amount_cents','currency','merchant'] as $key)if(($result[$key]??null)!==($payment[$key]??null))throw new \DomainException('Rückzahlungsnachweis passt nicht zum Original.');
    }
    private function refundableVouchers(array $sale,bool $pending=false):void
    {if(self::admissions($sale))$this->ticketOrders($sale['club_id'])->assertRegisterRefund($this,$sale['id']);foreach(Db::run($this->db,'SELECT * FROM pos_value_vouchers WHERE club_id=? AND issued_sale=? FOR UPDATE',[$sale['club_id'],$sale['id']])->fetchAll() as $v)if(!in_array($v['state'],$pending?['active','refund-pending']:['active'],true)||(int)$v['remaining_cents']!==(int)$v['face_cents'])throw new \DomainException('Verkauf mit bereits eingelöstem Wertgutschein kann nicht vollständig storniert werden.');}
    private function finishRefund(array $sale,array $result):void
    {if(self::admissions($sale))$this->ticketOrders($sale['club_id'])->finishRegisterRefund($this,$sale['id']);$this->restoreVoucher($sale,'refund');$this->restoreBenefit($sale);Db::run($this->db,"UPDATE pos_value_vouchers SET state='refunded',remaining_cents=0 WHERE club_id=? AND issued_sale=?",[$sale['club_id'],$sale['id']]);Db::run($this->db,"UPDATE pos_sales SET state='refunded',version=version+1 WHERE club_id=? AND id=?",[$sale['club_id'],$sale['id']]);Db::run($this->db,"UPDATE pos_refunds SET state='confirmed',result_json=? WHERE club_id=? AND sale_id=?",[json_encode($result,JSON_THROW_ON_ERROR),$sale['club_id'],$sale['id']]);Db::run($this->db,'INSERT INTO audit_events(club_id,actor_id,action,object_id,object_version,happened_at) VALUES(?,?,?,?,?,?)',[$sale['club_id'],$this->actorId,'pos.refund.confirmed',$sale['id'],$sale['version']+1,gmdate('c')]);}
}
