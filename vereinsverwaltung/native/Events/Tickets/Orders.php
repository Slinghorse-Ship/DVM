<?php declare(strict_types=1);
namespace Kulturhus\Native\Events\Tickets;
use PDO;
use Kulturhus\Native\Shared\{Access,Db,SecretBox,Identity};
use Kulturhus\Native\Integration\Accounts;
use Kulturhus\Native\Communication\Content;
use Kulturhus\Native\Payments\{TicketCheckout,StripeWebhook};

/** Private order originals, serialized capacity and provider-proven issuance. */
final class Orders
{
    use OnlineRefunds;
    public function __construct(private PDO $db,private string $club,private SecretBox $box,private ?Access $access=null,private ?\Closure $request=null)
    {
        if(!preg_match('/^[A-Za-z0-9_-]{1,64}$/D',$club))throw new \DomainException('Ticketverein prüfen.');
    }
    private function actor(string $capability):array
    {
        if(!$this->access)throw new \DomainException('Aktueller Vereinszugang erforderlich.');
        $actor=$this->access->require('website',$capability);if($actor['club_id']!==$this->club)throw new \DomainException('Ticketzugang gehört nicht zu diesem Verein.');return $actor;
    }
    private function payload(array $row):array{return $this->box->open('ticket-order:'.$this->club.':'.$row['id'],$row['ciphertext']);}
    private function row(string $id,bool $lock=false):array
    {
        Content::id($id);$row=Db::run($this->db,'SELECT * FROM native_ticket_orders WHERE club_id=? AND id=?'.($lock?' FOR UPDATE':''),[$this->club,$id])->fetch();
        if(!$row)throw new \DomainException('Ticketbestellung nicht gefunden.');return $row;
    }
    private function owned(string $token):array
    {
        if(!preg_match('/^[a-f0-9]{64}$/D',$token))throw new \DomainException('Ticketbestellzugang prüfen.');
        $row=Db::run($this->db,'SELECT * FROM native_ticket_orders WHERE club_id=? AND access_hash=?',[$this->club,hash('sha256',$token)])->fetch();
        if(!$row)throw new \DomainException('Ticketbestellzugang ungültig.');return $row;
    }
    private function change(array $row,string $status,string $reason,?string $actor=null):array
    {
        Values::paymentState($this->db,$this->payload($row),$status);
        Db::run($this->db,'UPDATE native_ticket_orders SET status=?,version=version+1,updated_at=UTC_TIMESTAMP(6) WHERE club_id=? AND id=?',[$status,$this->club,$row['id']]);
        $next=$this->row($row['id'],true);
        Db::run($this->db,'INSERT INTO native_ticket_history(club_id,order_id,version,status,reason,actor_id,created_at) VALUES(?,?,?,?,?,?,UTC_TIMESTAMP(6))',[$this->club,$row['id'],(int)$next['version'],$status,$reason,$actor]);
        return $next;
    }
    /** Caller validates its public form CSRF/rate limit; prices and availability always come from the current offer. */
    public function book(string $page,int $offerVersion,int $quantity,string $name,string $email,string $request,array $vouchers=[]):array
    {
        if($this->db->inTransaction())throw new \DomainException('Ticketbuchung benötigt einen eigenen Vorgang.');
        Content::id($page);$name=Content::text($name,160,true);$email=Content::email($email);$vouchers=Values::input($vouchers);
        if($quantity<0||$quantity>10||($quantity===0&&!$vouchers)||!preg_match('/^[a-f0-9]{64}$/D',$request))throw new \DomainException('Ticketanzahl und Buchungskennung prüfen.');
        return Db::atomic($this->db,function()use($page,$offerVersion,$quantity,$name,$email,$request,$vouchers):array{
            // This current lock must precede the first consistent read in the transaction.
            Db::run($this->db,'SELECT generation FROM khcms_state WHERE club_id=? FOR UPDATE',[$this->club])->fetchColumn();
            $locked=Db::run($this->db,'SELECT * FROM native_ticket_offers WHERE club_id=? AND page_id=? FOR UPDATE',[$this->club,$page])->fetch();
            if(!$locked)throw new \DomainException('Ticketverkauf nicht verfügbar.');
            foreach(Db::run($this->db,"SELECT * FROM native_ticket_orders WHERE club_id=? AND page_id=? AND status='reserved' AND checkout_id IS NULL AND operation_token IS NULL AND expires_at<? FOR UPDATE",[$this->club,$page,time()])->fetchAll()as$expired)$this->change($expired,'expired','reservation_expired');
            $previous=Db::run($this->db,'SELECT * FROM native_ticket_orders WHERE club_id=? AND request_hash=?',[$this->club,hash('sha256',$request)])->fetch();
            if($previous){$original=$this->payload($previous);if($previous['page_id']!==$page||(int)$previous['quantity']!==$quantity||$original['buyer_name']!==$name||$original['buyer_email']!==$email||array_map(static fn($v)=>[$v['id'],$v['quantity']],array_values(array_filter($original['items']??[],static fn($i)=>$i['kind']==='value-voucher')))!==array_map(static fn($v)=>[$v['id'],$v['quantity']],$vouchers))throw new \DomainException('Buchungskennung gehört zu einer anderen ursprünglichen Bestellung.');return $this->view($original['access_token']);}
            $selected=Values::selection($this->db,$this->club,$vouchers);
            $offer=Offers::publicOffer($this->db,$this->club,$page);
            if(!$offer||$offer['version']!==$offerVersion)throw new \DomainException('Ticketangebot inzwischen geändert. Bitte neu prüfen.');
            if($quantity>$offer['remaining'])throw new \DomainException('Nicht mehr genügend Tickets verfügbar.');
            $amount=$offer['price_cents']*$quantity+$selected['amount_cents'];if($amount>100000000)throw new \DomainException('Bestellbetrag prüfen.');$provider=$amount>0?\Kulturhus\Native\Events\Workflow::forClub($this->db,$this->club)['payment']:'free';$account=null;
            if($amount>0){if(!in_array($provider,['sumup','stripe'],true))throw new \DomainException('Onlinezahlung nicht eingerichtet.');$account=(new Accounts($this->db,$this->box))->get($this->club,$provider);}
            $id=bin2hex(random_bytes(16));$access=bin2hex(random_bytes(32));$expires=time()+3600;
            $original=['buyer_name'=>$name,'buyer_email'=>$email,'access_token'=>$access,'hook_token'=>bin2hex(random_bytes(32)),'account'=>$account,'title'=>$offer['event']['title'],'starts_at'=>$offer['event']['event_start_utc'],'slug'=>$offer['event']['slug'],'origin'=>Identity::forClub($this->db,$this->club)['origin'],'checkout_url'=>null,'manager_club'=>$selected['manager_club'],'items'=>array_merge($quantity?[['kind'=>'admission','label'=>$offer['event']['title'],'quantity'=>$quantity,'price_cents'=>$offer['price_cents']]]:[],$selected['items']),'vouchers'=>[]];
            Db::run($this->db,"INSERT INTO native_ticket_orders(club_id,id,page_id,version,status,quantity,unit_price_cents,amount_cents,payment_provider,access_hash,request_hash,buyer_hash,ciphertext,expires_at,created_at,updated_at) VALUES(?,?,?,1,'reserved',?,?,?,?,?,?,?,?,?,UTC_TIMESTAMP(6),UTC_TIMESTAMP(6))",[$this->club,$id,$page,$quantity,$offer['price_cents'],$amount,$provider,hash('sha256',$access),hash('sha256',$request),$this->box->fingerprint('ticket-buyer:'.strtolower($email)),$this->box->seal('ticket-order:'.$this->club.':'.$id,$original),$expires]);
            $row=$this->row($id,true);Db::run($this->db,"INSERT INTO native_ticket_history VALUES(?,?,1,'reserved','booked',NULL,UTC_TIMESTAMP(6))",[$this->club,$id]);
            if($amount===0){$row=$this->change($row,'paid','free');$this->issue($row);}
            return $this->view($access);
        });
    }
    private function issue(array $order):void
    {
        if(!$this->db->inTransaction()||$order['status']!=='paid')throw new \LogicException('Tickets erst im bestätigten Zahlungsvorgang ausstellen.');
        $original=$this->payload($order);
        $existing=(int)Db::run($this->db,'SELECT COUNT(*) FROM native_ticket_passes WHERE club_id=? AND order_id=?',[$this->club,$order['id']])->fetchColumn();
        if(!empty($original['issued'])){if($existing!==(int)$order['quantity'])throw new \DomainException('Ticketbestand unvollständig.');return;}
        if($existing!==0)throw new \DomainException('Ticketbestand vor Ausgabe prüfen.');
        for($ordinal=1;$ordinal<=(int)$order['quantity'];$ordinal++){
            $id=bin2hex(random_bytes(16));$code=bin2hex(random_bytes(16));$passState=($original['account']['mode']??null)==='test'?'test':'valid';
            Db::run($this->db,"INSERT INTO native_ticket_passes(club_id,id,page_id,order_id,ordinal,token_hash,ciphertext,status,version,created_at) VALUES(?,?,?,?,?,?,?,?,1,UTC_TIMESTAMP(6))",[$this->club,$id,$order['page_id'],$order['id'],$ordinal,hash('sha256',$order['page_id'].':'.$code),$this->box->seal('ticket-pass:'.$this->club.':'.$id,['code'=>$code]),$passState]);
        }
        $original['vouchers']=Values::issue($this->db,$order,$original);$original['issued']=true;
        Db::run($this->db,'UPDATE native_ticket_orders SET ciphertext=? WHERE club_id=? AND id=?',[$this->box->seal('ticket-order:'.$this->club.':'.$order['id'],$original),$this->club,$order['id']]);
        if($order['payment_provider']!=='pos')Db::run($this->db,"INSERT INTO native_ticket_mail(club_id,order_id,status,created_at,updated_at) VALUES(?,?,'queued',UTC_TIMESTAMP(6),UTC_TIMESTAMP(6))",[$this->club,$order['id']]);
    }
    public function view(string $access):array
    {
        $row=$this->owned($access);$original=$this->payload($row);$passes=[];
        foreach(Db::run($this->db,'SELECT id,ordinal,ciphertext,status,version,checked_in_at FROM native_ticket_passes WHERE club_id=? AND order_id=? ORDER BY ordinal',[$this->club,$row['id']])->fetchAll()as$pass){$private=$this->box->open('ticket-pass:'.$this->club.':'.$pass['id'],$pass['ciphertext']);$passes[]=['id'=>$pass['id'],'ordinal'=>(int)$pass['ordinal'],'status'=>$pass['status'],'version'=>(int)$pass['version'],'checked_in_at'=>$pass['checked_in_at'],'code'=>$private['code'],'page_id'=>$row['page_id']];}
        return ['id'=>$row['id'],'page_id'=>$row['page_id'],'version'=>(int)$row['version'],'status'=>$row['status'],'quantity'=>(int)$row['quantity'],'amount_cents'=>(int)$row['amount_cents'],'unit_price_cents'=>(int)$row['unit_price_cents'],'payment_provider'=>$row['payment_provider'],'expires_at'=>(int)$row['expires_at'],'title'=>$original['title'],'starts_at'=>$original['starts_at'],'buyer_name'=>$original['buyer_name'],'buyer_email'=>$original['buyer_email'],'access_token'=>$access,'checkout_url'=>$original['checkout_url'],'passes'=>$passes,'items'=>$original['items']??[],'vouchers'=>Values::present($this->db,$original),'test_mode'=>($original['account']['mode']??null)==='test'];
    }
    private function gate(array $row,callable $operation):mixed
    {
        if($this->db->inTransaction())throw new \DomainException('Zahlungsabgleich benötigt einen eigenen Vorgang.');
        $lock='ticket:'.substr(hash('sha256',$this->club.':'.$row['id']),0,56);
        if((int)Db::run($this->db,'SELECT GET_LOCK(?,0)',[$lock])->fetchColumn()!==1)throw new \DomainException('Diese Ticketbestellung wird gerade geprüft.');
        try{return $operation();}finally{Db::run($this->db,'SELECT RELEASE_LOCK(?)',[$lock]);}
    }
    private function gateway(array $row,array $payload):TicketCheckout{return new TicketCheckout($row['payment_provider'],$payload['account'],$this->request);}
    private static function paymentOrder(array $row):array{return ['id'=>$row['id'],'amount_cents'=>(int)$row['amount_cents'],'expires_at'=>(int)$row['expires_at']];}
    public function beginCheckout(string $access):array
    {
        $initial=$this->owned($access);
        return $this->gate($initial,function()use($initial,$access):array{
            $row=$this->row($initial['id']);if($row['payment_provider']==='pos')throw new \DomainException('Kassenbestellung an der Kasse bezahlen.');if($row['status']!=='reserved')return $this->view($access);
            if((int)$row['expires_at']<=time()){Db::atomic($this->db,fn()=>$this->change($this->row($row['id'],true),'expired','reservation_expired'));return $this->view($access);}
            $payload=$this->payload($row);$operation=bin2hex(random_bytes(16));
            Db::atomic($this->db,function()use($row,$operation):void{
                Db::run($this->db,'SELECT generation FROM khcms_state WHERE club_id=? FOR UPDATE',[$this->club])->fetchColumn();
                Db::run($this->db,'SELECT page_id FROM native_ticket_offers WHERE club_id=? AND page_id=? FOR UPDATE',[$this->club,$row['page_id']])->fetchColumn();
                if(!Offers::publicOffer($this->db,$this->club,$row['page_id']))throw new \DomainException('Ticketverkauf nicht mehr aktiv.');
                $current=$this->row($row['id'],true);if($current['status']!=='reserved')throw new \DomainException('Ticketbestellung inzwischen geändert.');Db::run($this->db,'UPDATE native_ticket_orders SET operation_token=? WHERE club_id=? AND id=?',[$operation,$this->club,$row['id']]);$this->change($current,'creating_checkout','checkout_started');
            });
            try{
                $base=rtrim($payload['origin'],'/');$created=$this->gateway($row,$payload)->create(self::paymentOrder($row),$base.'/tickets/order?access='.$access,$base.'/tickets/callback?order='.$row['id'].'&hook='.$payload['hook_token']);
                Db::atomic($this->db,function()use($row,$payload,$created,$operation):void{
                    $current=$this->row($row['id'],true);if($current['operation_token']!==$operation||$current['status']!=='creating_checkout')throw new \DomainException('Ticketzahlung inzwischen geändert.');
                    $payload['checkout_url']=$created['checkout_url'];Db::run($this->db,'UPDATE native_ticket_orders SET checkout_id=?,ciphertext=? WHERE club_id=? AND id=?',[$created['checkout_id'],$this->box->seal('ticket-order:'.$this->club.':'.$row['id'],$payload),$this->club,$row['id']]);$this->change($current,'awaiting_payment','checkout_prepared');
                });
            }catch(\Throwable $e){Db::atomic($this->db,function()use($row,$operation):void{$current=$this->row($row['id'],true);if($current['operation_token']===$operation&&$current['status']==='creating_checkout')$this->change($current,'payment_uncertain','checkout_unknown');});throw $e;}
            return $this->view($access);
        });
    }
    public function reconcile(string $access):array
    {
        $initial=$this->owned($access);
        if((int)$initial['amount_cents']===0||$initial['payment_provider']==='pos')return $this->view($access);
        if(in_array($initial['status'],['refund_pending','refunded','cancelled'],true))return $this->view($access);
        return $this->gate($initial,function()use($initial,$access):array{
            $row=$this->row($initial['id']);if(in_array($row['status'],['refund_pending','refunded','cancelled'],true))return $this->view($access);$payload=$this->payload($row);$gateway=$this->gateway($row,$payload);
            if($row['checkout_id']===null){
                if(!in_array($row['status'],['creating_checkout','payment_uncertain'],true)||$row['payment_provider']!=='sumup')return $this->view($access);
                $found=$gateway->find(self::paymentOrder($row));if(!$found)return $this->view($access);
                Db::atomic($this->db,function()use($row,$payload,$found):void{$current=$this->row($row['id'],true);$payload['checkout_url']=$found['checkout_url'];Db::run($this->db,'UPDATE native_ticket_orders SET checkout_id=?,ciphertext=? WHERE club_id=? AND id=?',[$found['checkout_id'],$this->box->seal('ticket-order:'.$this->club.':'.$row['id'],$payload),$this->club,$row['id']]);$this->change($current,'awaiting_payment','checkout_reconciled');});$row=$this->row($row['id']);
            }
            $proof=$gateway->status(self::paymentOrder($row),$row['checkout_id']);
            Db::atomic($this->db,function()use($row,$proof):void{
                // Serialize fulfillment against new reservations and capacity changes.
                Db::run($this->db,'SELECT page_id FROM native_ticket_offers WHERE club_id=? AND page_id=? FOR UPDATE',[$this->club,$row['page_id']])->fetchColumn();
                $current=$this->row($row['id'],true);
                if($proof['status']==='paid'){
                    $previousProof=$current['proof_json']===null?null:json_decode($current['proof_json'],true,32,JSON_THROW_ON_ERROR);
                    if($current['transaction_id']!==null&&($current['transaction_id']!==$proof['transaction_id']||$current['payment_merchant']!==$proof['merchant']||(isset($previousProof['charge_id'])&&($proof['charge_id']??null)!==$previousProof['charge_id']))){if(in_array($current['status'],['paid','creating_checkout','awaiting_payment','payment_uncertain'],true))$this->change($current,'payment_review','payment_identity_changed');return;}
                    if($current['status']==='paid'){
                        if($current['transaction_id']!==$proof['transaction_id']||$current['payment_merchant']!==$proof['merchant'])$this->change($current,'payment_review','payment_identity_changed');
                        return;
                    }
                    Db::run($this->db,'UPDATE native_ticket_orders SET payment_merchant=?,transaction_id=?,proof_json=? WHERE club_id=? AND id=?',[$proof['merchant'],$proof['transaction_id'],json_encode($proof,JSON_THROW_ON_ERROR),$this->club,$row['id']]);
                    if(!in_array($current['status'],['creating_checkout','awaiting_payment','payment_uncertain','payment_review'],true)||(bool)Db::run($this->db,"SELECT 1 FROM native_ticket_history WHERE club_id=? AND order_id=? AND status IN ('expired','cancelled','refunded') LIMIT 1",[$this->club,$current['id']])->fetchColumn()){if($current['status']!=='paid_unallocated')$this->change($current,'paid_unallocated','late_payment');return;}
                    $paid=$this->change($current,'paid','payment_confirmed');$this->issue($paid);
                }elseif($proof['status']==='expired'&&in_array($current['status'],['awaiting_payment','payment_uncertain'],true))$this->change($current,'expired','provider_expired');
                elseif($proof['status']!=='paid'&&$current['status']==='paid')$this->change($current,'payment_review','provider_state_changed');
                elseif($proof['status']==='review'&&in_array($current['status'],['creating_checkout','awaiting_payment','payment_uncertain'],true))$this->change($current,'payment_review','provider_correction');
            });
            return $this->view($access);
        });
    }
    public function callback(string $id,string $hook):array
    {
        $row=$this->row($id);$payload=$this->payload($row);
        if(!preg_match('/^[a-f0-9]{64}$/D',$hook)||!hash_equals($payload['hook_token'],$hook))throw new \DomainException('Ticket-Zahlungsrückmeldung ungültig.');
        $view=$this->reconcile($payload['access_token']);return ['id'=>$view['id'],'status'=>$view['status']];
    }
    public function stripeWebhook(string $body,string $signature):array
    {
        if($this->db->inTransaction())throw new \DomainException('Stripe-Rückmeldung benötigt einen eigenen Vorgang.');
        if(strlen($body)>65536)throw new \DomainException('Stripe-Rückmeldung zu groß.');
        try{$hint=json_decode($body,true,32,JSON_THROW_ON_ERROR);}catch(\JsonException){throw new \DomainException('Stripe-Rückmeldung unlesbar.');}
        $object=$hint['data']['object']??null;if(!is_array($object))throw new \DomainException('Stripe-Rückmeldung ohne Zahlungsobjekt.');
        $type=$hint['type']??'';$checkoutEvent=is_string($type)&&str_starts_with($type,'checkout.session.');
        $correction=in_array($type,['charge.refunded','refund.created','refund.updated','refund.failed','charge.dispute.created','charge.dispute.updated','charge.dispute.closed'],true);
        if(!$checkoutEvent&&!$correction)throw new \DomainException('Stripe-Rückmeldungsart prüfen.');
        $identifier=static function(mixed $v,string $prefix):?string{if(is_array($v))$v=$v['id']??null;return is_string($v)&&str_starts_with($v,$prefix)&&preg_match('/^[A-Za-z0-9_]{4,200}$/D',$v)?$v:null;};
        $charge=$identifier($type==='charge.refunded'?($object['id']??null):($object['charge']??null),'ch_');$intent=$identifier($object['payment_intent']??null,'pi_');
        $id=$object['metadata']['ticket_order_id']??null;
        if($id===null&&$correction&&($charge!==null||$intent!==null)){
            $matches=Db::run($this->db,"SELECT id FROM native_ticket_orders WHERE club_id=? AND payment_provider='stripe' AND ((? IS NOT NULL AND transaction_id=?) OR (? IS NOT NULL AND JSON_UNQUOTE(JSON_EXTRACT(proof_json,'$.charge_id'))=?))",[$this->club,$intent,$intent,$charge,$charge])->fetchAll(PDO::FETCH_COLUMN);
            if(count($matches)===1)$id=$matches[0];
        }
        if(!is_string($id)||!preg_match('/^[a-f0-9]{32}$/D',$id))throw new \DomainException('Stripe-Rückmeldung ohne ursprüngliche Ticketbestellung.');
        $row=$this->row($id);$payload=$this->payload($row);
        if($row['payment_provider']!=='stripe')throw new \DomainException('Stripe-Rückmeldung gehört nicht zu dieser Zahlung.');
        $event=StripeWebhook::verify($body,$signature,$payload['account']['webhook_secret']??'');
        if($event['livemode']!==($payload['account']['mode']==='live')||($checkoutEvent&&!in_array($event['type'],['checkout.session.completed','checkout.session.async_payment_succeeded','checkout.session.async_payment_failed','checkout.session.expired'],true)))throw new \DomainException('Stripe-Ticketmodus oder Rückmeldungsart prüfen.');
        if($correction){
            $proof=$row['proof_json']===null?null:json_decode($row['proof_json'],true,32,JSON_THROW_ON_ERROR);
            if(!is_array($proof)||$charge===null||($proof['charge_id']??null)!==$charge||($intent!==null&&$row['transaction_id']!==$intent)||(!in_array($row['status'],['refund_pending','refunded'],true)&&empty($payload['issued']))||!in_array($row['status'],['paid','payment_review','refund_pending','refunded'],true))throw new \DomainException('Stripe-Korrektur passt nicht zur ursprünglich bestätigten Belastung.');
        }
        $eventLock='ticket-event:'.substr(hash('sha256',$this->club.':'.$event['id']),0,50);
        if((int)Db::run($this->db,'SELECT GET_LOCK(?,0)',[$eventLock])->fetchColumn()!==1)throw new \DomainException('Stripe-Rückmeldung wird bereits geprüft.');
        try{
            $previous=Db::run($this->db,"SELECT body_hash FROM native_ticket_webhook_events WHERE club_id=? AND provider='stripe' AND event_id=?",[$this->club,$event['id']])->fetchColumn();
            if(is_string($previous)){if(!hash_equals($previous,hash('sha256',$body)))throw new \DomainException('Stripe-Rückmeldung verändert denselben ursprünglichen Vorgang.');$view=$this->view($payload['access_token']);return ['id'=>$id,'status'=>$view['status'],'repeated'=>true];}
            if($checkoutEvent){
            $checkout=$event['data']['object']['id']??null;
            if(!is_string($checkout))throw new \DomainException('Stripe-Checkoutkennung fehlt.');
            if($row['checkout_id']!==null&&$row['checkout_id']!==$checkout)throw new \DomainException('Stripe-Rückmeldung gehört zu einem anderen Checkout.');
            if($row['checkout_id']===null){
                $this->gate($row,function()use($row,$payload,$checkout):void{
                    $current=$this->row($row['id']);if($current['checkout_id']!==null){if($current['checkout_id']!==$checkout)throw new \DomainException('Stripe-Checkout inzwischen anders zugeordnet.');return;}
                    $located=$this->gateway($current,$payload)->locateStripe(self::paymentOrder($current),$checkout);
                    Db::atomic($this->db,function()use($current,$payload,$located):void{
                        $latest=$this->row($current['id'],true);$payload['checkout_url']=$located['checkout_url'];
                        Db::run($this->db,'UPDATE native_ticket_orders SET checkout_id=?,ciphertext=? WHERE club_id=? AND id=?',[$located['checkout_id'],$this->box->seal('ticket-order:'.$this->club.':'.$current['id'],$payload),$this->club,$current['id']]);
                        if(in_array($latest['status'],['creating_checkout','payment_uncertain'],true))$this->change($latest,'awaiting_payment','signed_checkout_reconciled');
                    });
                });
            }
            }else Db::atomic($this->db,function()use($id):void{$current=$this->row($id,true);if($current['status']==='paid')$this->change($current,'payment_review','signed_payment_correction');});
            $view=$this->reconcile($payload['access_token']);
            Db::atomic($this->db,fn()=>Db::run($this->db,"INSERT INTO native_ticket_webhook_events(club_id,provider,event_id,body_hash,order_id,created_at) VALUES(?,'stripe',?,?,?,UTC_TIMESTAMP(6))",[$this->club,$event['id'],hash('sha256',$body),$id]));
            return ['id'=>$id,'status'=>$view['status'],'repeated'=>false];
        }finally{Db::run($this->db,'SELECT RELEASE_LOCK(?)',[$eventLock]);}
    }
    /** The authenticated register remains the authority; callers cannot supply a role. */
    private function registerSale(\Kulturhus\Native\Pos\Register $register,string $sale):array
    {
        $s=$register->sale($sale,true);
        if(!hash_equals(\Kulturhus\Native\Integration\ManagerBridge::clubKey($s['club_id']),$this->club))throw new \DomainException('Kassenverein stimmt nicht überein.');
        return $s;
    }
    public function reserveAtRegister(\Kulturhus\Native\Pos\Register $register,string $sale):void
    {
        if(!$this->db->inTransaction())throw new \LogicException('Ticket und Warenkorb gemeinsam reservieren.');
        $s=$this->registerSale($register,$sale);$lines=array_values(array_filter($s['payload']['lines'],static fn($l)=>$l['kind']==='admission'));if(!$lines)return;
        if(count($lines)!==1||$s['state']!=='cart')throw new \DomainException('Ticket-Warenkorb prüfen.');$line=$lines[0];$page=$line['id'];
        $shift=$register->shift($s['shift_id'],true);$source=Db::run($this->db,'SELECT source_id FROM event_voucher_events WHERE club_id=? AND id=?',[$s['club_id'],$shift['event_id']])->fetchColumn();
        if($source!=='website:'.$page)throw new \DomainException('Eintritt gehört zu einer anderen Veranstaltungskasse.');
        Db::run($this->db,'SELECT generation FROM khcms_state WHERE club_id=? FOR UPDATE',[$this->club])->fetchColumn();
        Db::run($this->db,'SELECT page_id FROM native_ticket_offers WHERE club_id=? AND page_id=? FOR UPDATE',[$this->club,$page])->fetchColumn();
        $offer=Offers::publicOffer($this->db,$this->club,$page);
        if(!$offer||$line['version']!==$offer['version']||$line['price_cents']!==$offer['price_cents']||$line['quantity']>$offer['remaining'])throw new \DomainException('Ticketangebot oder freie Plätze geändert.');
        $token=bin2hex(random_bytes(32));$original=['buyer_name'=>'Kassenverkauf','buyer_email'=>'','access_token'=>$token,'hook_token'=>bin2hex(random_bytes(32)),'account'=>null,'title'=>$offer['event']['title'],'starts_at'=>$offer['event']['event_start_utc'],'slug'=>$offer['event']['slug'],'origin'=>Identity::forClub($this->db,$this->club)['origin'],'checkout_url'=>null,'manager_club'=>$s['club_id'],'items'=>[$line],'vouchers'=>[]];
        // Register reservations remain held until their original cart is paid or explicitly cancelled.
        Db::run($this->db,"INSERT INTO native_ticket_orders(club_id,id,page_id,version,status,quantity,unit_price_cents,amount_cents,payment_provider,access_hash,request_hash,buyer_hash,ciphertext,expires_at,operation_token,created_at,updated_at) VALUES(?,?,?,1,'reserved',?,?,?,'pos',?,?,?,?,?,?,UTC_TIMESTAMP(6),UTC_TIMESTAMP(6))",[$this->club,$sale,$page,$line['quantity'],$line['price_cents'],$line['amount_cents'],hash('sha256',$token),hash('sha256','pos:'.$s['club_id'].':'.$sale),hash('sha256','pos:'.$sale),$this->box->seal('ticket-order:'.$this->club.':'.$sale,$original),time()+86400,bin2hex(random_bytes(16))]);
        Db::run($this->db,"INSERT INTO native_ticket_history VALUES(?,?,1,'reserved','register_cart',NULL,UTC_TIMESTAMP(6))",[$this->club,$sale]);
    }
    private function registerOrder(\Kulturhus\Native\Pos\Register $register,string $sale):?array
    {
        if(!$this->db->inTransaction())throw new \LogicException('Ticket und Kassenoriginal gemeinsam bearbeiten.');
        $s=$this->registerSale($register,$sale);
        if(!array_filter($s['payload']['lines'],static fn($l)=>$l['kind']==='admission'))return null;
        Db::run($this->db,'SELECT generation FROM khcms_state WHERE club_id=? FOR UPDATE',[$this->club])->fetchColumn();
        $row=$this->row($sale,true);$original=$this->payload($row);
        if($row['payment_provider']!=='pos'||($original['manager_club']??null)!==$s['club_id'])throw new \DomainException('Ursprüngliche Ticket-Kassenzuordnung prüfen.');return $row;
    }
    private static function proofJson(mixed $value):string
    {
        $sort=static function(mixed $v)use(&$sort):mixed{if(!is_array($v))return $v;if(!array_is_list($v))ksort($v,SORT_STRING);foreach($v as&$child)$child=$sort($child);unset($child);return $v;};
        return json_encode($sort($value),JSON_THROW_ON_ERROR|JSON_PRESERVE_ZERO_FRACTION);
    }
    public function settleAtRegister(\Kulturhus\Native\Pos\Register $register,string $sale,array $receipt):array
    {
        if(!$this->db->inTransaction())throw new \LogicException('Ticket und Kassenbeleg gemeinsam bestätigen.');
        $s=$this->registerSale($register,$sale);
        if($s['state']!=='payment'||($receipt['cash_cents']??null)!==(int)$s['cash_cents']||($receipt['voucher_cents']??null)!==(int)$s['voucher_cents']||($receipt['total_cents']??null)!==$s['total_cents'])throw new \DomainException('Originale Kassen-Zahlungsaufteilung erforderlich.');
        $remaining=$s['total_cents']-(int)$s['cash_cents']-(int)$s['voucher_cents'];
        if($remaining>0){$verified=Db::run($this->db,"SELECT result_json FROM pos_attempts WHERE club_id=? AND sale_id=? AND state='verified' AND amount_cents=? FOR SHARE",[$s['club_id'],$sale,$remaining])->fetchAll(PDO::FETCH_COLUMN);if(count($verified)!==1||self::proofJson(json_decode($verified[0],true,32,JSON_THROW_ON_ERROR))!==self::proofJson($receipt['external_payment']??null))throw new \DomainException('Unabhängig bestätigter Kassen-Zahlungsnachweis erforderlich.');}
        elseif(($receipt['external_payment']??null)!==null)throw new \DomainException('Kein zusätzlicher Kartennachweis für vollständig bezahlte Kasse.');
        $row=$this->registerOrder($register,$sale);if(!$row)return [];
        if($row['status']!=='reserved'||($receipt['sale_id']??null)!==$sale)throw new \DomainException('Ticket-Kassenbestellung inzwischen geändert.');
        Db::run($this->db,'UPDATE native_ticket_orders SET proof_json=? WHERE club_id=? AND id=?',[json_encode(['provider'=>'pos','sale_id'=>$sale,'cash_cents'=>$receipt['cash_cents'],'voucher_cents'=>$receipt['voucher_cents'],'external_payment'=>$receipt['external_payment']],JSON_THROW_ON_ERROR),$this->club,$sale]);
        $paid=$this->change($row,'paid','register_paid');$this->issue($paid);$view=$this->view($this->payload($paid)['access_token']);return $view['passes'];
    }
    public function cancelAtRegister(\Kulturhus\Native\Pos\Register $register,string $sale):void
    {
        $row=$this->registerOrder($register,$sale);if(!$row)return;
        if($row['status']!=='reserved')throw new \DomainException('Bestätigte Tickets nur mit Storno zurücknehmen.');$this->change($row,'cancelled','register_cancelled');
    }
    public function assertRegisterRefund(\Kulturhus\Native\Pos\Register $register,string $sale):void
    {
        $row=$this->registerOrder($register,$sale);if(!$row)return;
        if(!in_array($row['status'],['paid','refund_pending'],true)||Db::run($this->db,"SELECT 1 FROM native_ticket_passes WHERE club_id=? AND order_id=? AND (status<>'valid' OR checked_in_at IS NOT NULL) FOR UPDATE",[$this->club,$sale])->fetchColumn())throw new \DomainException('Bereits eingelassene Tickets können nicht vollständig storniert werden.');
    }
    public function pauseRegisterRefund(\Kulturhus\Native\Pos\Register $register,string $sale):void
    {
        $this->assertRegisterRefund($register,$sale);$row=$this->registerOrder($register,$sale);if($row&&$row['status']==='paid')$this->change($row,'refund_pending','register_refund_started');
    }
    public function finishRegisterRefund(\Kulturhus\Native\Pos\Register $register,string $sale):void
    {
        $this->assertRegisterRefund($register,$sale);$row=$this->registerOrder($register,$sale);if(!$row)return;
        Db::run($this->db,"UPDATE native_ticket_passes SET status='voided',version=version+1 WHERE club_id=? AND order_id=? AND status='valid'",[$this->club,$sale]);$this->change($row,'refunded','register_refund_confirmed');
    }
    private function readPass(string $page,string $code):array
    {
        Content::id($page);if(!preg_match('/^[a-f0-9]{32}$/D',$code))throw new \DomainException('Ticket-QR-Code prüfen.');
        $pass=Db::run($this->db,'SELECT id,order_id,ordinal,status,version,checked_in_at FROM native_ticket_passes WHERE club_id=? AND page_id=? AND token_hash=?',[$this->club,$page,hash('sha256',$page.':'.$code)])->fetch();
        if(!$pass)throw new \DomainException('Ticket gehört nicht zu dieser Veranstaltung.');$order=$this->row($pass['order_id']);$original=$this->payload($order);
        return ['id'=>$pass['id'],'order_id'=>$pass['order_id'],'ordinal'=>(int)$pass['ordinal'],'status'=>$pass['status'],'version'=>(int)$pass['version'],'checked_in_at'=>$pass['checked_in_at'],'payment_status'=>$order['status'],'title'=>$original['title'],'buyer_name'=>$original['buyer_name']];
    }
    public function pass(string $page,string $code):array{$this->actor('read');return $this->readPass($page,$code);}
    public function checkIn(string $page,string $code,int $version):array
    {
        return $this->applyCheckIn($page,$code,$version,function():array{$actor=$this->actor('edit');$this->actor('read');return $actor;});
    }
    private function registerActor(\Kulturhus\Native\Pos\Register $register,string $shift,string $page):array
    {
        $s=$register->shift($shift);$actor=$register->scannerActor($shift);
        $source=Db::run($this->db,'SELECT source_id FROM event_voucher_events WHERE club_id=? AND id=?',[$s['club_id'],$s['event_id']])->fetchColumn();
        if(!hash_equals(\Kulturhus\Native\Integration\ManagerBridge::clubKey($s['club_id']),$this->club)||$source!=='website:'.$page)throw new \DomainException('QR-Ticket gehört zu einer anderen Veranstaltungskasse.');
        return ['id'=>\Kulturhus\Native\Integration\ManagerBridge::actorKey($actor['id'],$s['club_id'])];
    }
    public function inspectAtRegister(\Kulturhus\Native\Pos\Register $register,string $shift,string $page,string $code):array
    {
        $this->registerActor($register,$shift,$page);return $this->readPass($page,$code);
    }
    public function checkInAtRegister(\Kulturhus\Native\Pos\Register $register,string $shift,string $page,string $code,int $version):array
    {
        return $this->applyCheckIn($page,$code,$version,fn():array=>$this->registerActor($register,$shift,$page));
    }
    private function applyCheckIn(string $page,string $code,int $version,\Closure $authorize):array
    {
        $authorize();Content::id($page);if(!preg_match('/^[a-f0-9]{32}$/D',$code))throw new \DomainException('Ticket-QR-Code prüfen.');
        return Db::atomic($this->db,function()use($page,$code,$version,$authorize):array{
            $actor=$authorize();
            Db::run($this->db,'SELECT generation FROM khcms_state WHERE club_id=? FOR UPDATE',[$this->club])->fetchColumn();
            $original=Db::run($this->db,'SELECT p.draft_json FROM khcms_pages p LEFT JOIN khcms_archives a ON a.page_id=p.id AND a.club_id=p.club_id WHERE p.club_id=? AND p.id=? AND a.archived_at IS NULL AND NOT EXISTS(SELECT 1 FROM khcms_trash t WHERE t.page_id=p.id AND t.club_id=p.club_id) FOR SHARE',[$this->club,$page])->fetchColumn();if(!is_string($original))throw new \DomainException('Veranstaltung nicht verfügbar.');$event=json_decode($original,true,512,JSON_THROW_ON_ERROR);
            $published=(new \Kulturhus\Native\Cms\Events($this->db,$this->club))->publicId($page,true);
            $source=Db::run($this->db,'SELECT state FROM native_event_sources WHERE club_id=? AND page_id=? FOR SHARE',[$this->club,$page])->fetchColumn();
            if(($published['event_status']??$event['event_status']??'')==='cancelled'||$source==='cancelled')throw new \DomainException('Diese Veranstaltung wurde abgesagt. Kein Einlass.');
            $pass=Db::run($this->db,'SELECT * FROM native_ticket_passes WHERE club_id=? AND page_id=? AND token_hash=? FOR UPDATE',[$this->club,$page,hash('sha256',$page.':'.$code)])->fetch();
            if(!$pass)throw new \DomainException('Ticket gehört nicht zu dieser Veranstaltung.');$order=$this->row($pass['order_id'],true);
            if($order['status']!=='paid'||!in_array($pass['status'],['valid','checked_in'],true))throw new \DomainException('Dieses Ticket ist nicht für den Einlass freigegeben.');
            if($pass['status']==='checked_in')return $this->readPass($page,$code);
            if((int)$pass['version']!==$version)throw new \DomainException('Ticketfassung geändert. Bitte neu prüfen.');
            Db::run($this->db,"UPDATE native_ticket_passes SET status='checked_in',version=version+1,checked_in_by=?,checked_in_at=UTC_TIMESTAMP(6) WHERE club_id=? AND id=?",[$actor['id'],$this->club,$pass['id']]);
            return $this->readPass($page,$code);
        });
    }
}
