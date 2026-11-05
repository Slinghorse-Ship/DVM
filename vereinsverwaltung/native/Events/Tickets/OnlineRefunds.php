<?php declare(strict_types=1);
namespace Kulturhus\Native\Events\Tickets;
use Kulturhus\Native\Shared\Db;
use Kulturhus\Native\Communication\Content;
use Kulturhus\Native\Payments\TicketRefund;

/** Order methods live with the original order state machine; no second payment store. */
trait OnlineRefunds
{
    public function managementOrders(string $page,int $offset=0):array
    {
        $this->actor('manage');$this->actor('read');Content::id($page);
        if($offset<0||$offset>1000000||$offset%10!==0)throw new \DomainException('Bestellseite prüfen.');
        $total=(int)Db::run($this->db,"SELECT COUNT(*) FROM native_ticket_orders WHERE club_id=? AND page_id=? AND payment_provider<>'pos'",[$this->club,$page])->fetchColumn();
        $rows=Db::run($this->db,"SELECT * FROM native_ticket_orders WHERE club_id=? AND page_id=? AND payment_provider<>'pos' ORDER BY created_at DESC,id DESC LIMIT 10 OFFSET ".$offset,[$this->club,$page])->fetchAll();
        $items=[];foreach($rows as$row){$p=$this->payload($row);$items[]=['id'=>$row['id'],'version'=>(int)$row['version'],'status'=>$row['status'],'quantity'=>(int)$row['quantity'],'amount_cents'=>(int)$row['amount_cents'],'buyer_name'=>$p['buyer_name'],'buyer_email'=>$p['buyer_email'],'payment_provider'=>$row['payment_provider'],'created_at'=>$row['created_at']];}
        return ['total'=>$total,'offset'=>$offset,'items'=>$items];
    }
    private function refundRow(string $page,string $id,bool $lock=false):array
    {
        $this->actor('manage');$this->actor('read');Content::id($page);$row=$this->row($id,$lock);
        if($row['page_id']!==$page||$row['payment_provider']==='pos')throw new \DomainException('Onlinebestellung dieser Veranstaltung auswählen; Kassenverkäufe an der Kasse stornieren.');
        return $row;
    }
    private function unusedOrder(array $row):void
    {
        $passes=Db::run($this->db,'SELECT status,checked_in_at FROM native_ticket_passes WHERE club_id=? AND order_id=? FOR UPDATE',[$this->club,$row['id']])->fetchAll();
        foreach($passes as$pass)if(!in_array($pass['status'],['valid','test'],true)||$pass['checked_in_at']!==null)throw new \DomainException('Bereits benutzte Tickets verhindern ein vollständiges Storno.');
        $original=$this->payload($row);
        if(count($passes)!==(empty($original['issued'])?0:(int)$row['quantity']))throw new \DomainException('Ursprünglicher Ticketbestand unvollständig.');
        foreach($original['vouchers']??[]as$v){
            $stored=Db::run($this->db,'SELECT face_cents,remaining_cents,state FROM pos_value_vouchers WHERE club_id=? AND id=? AND code_hash=? FOR UPDATE',[$original['manager_club'],$v['id'],hash('sha256',$v['code'])])->fetch();
            if(!$stored||(int)$stored['face_cents']!==$v['face_cents']||(int)$stored['remaining_cents']!==$v['face_cents']||!in_array($stored['state'],$row['status']==='refund_pending'?['payment-review','test']:['active','test'],true))throw new \DomainException('Bereits benutzte oder gesperrte Wertgutscheine verhindern ein vollständiges Storno.');
        }
    }
    public function refundOnline(string $page,string $id,int $version,string $reason):array
    {
        $reason=Content::text($reason,500,true);$initial=$this->refundRow($page,$id);
        return $this->gate($initial,function()use($page,$id,$version,$reason):array{
            $row=$this->refundRow($page,$id);
            if(in_array($row['status'],['refund_pending','refunded','cancelled'],true))return ['id'=>$id,'status'=>$row['status']];
            if((int)$row['version']!==$version||!in_array($row['status'],['paid','paid_unallocated','reserved'],true))throw new \DomainException('Bestellung inzwischen geändert. Bitte neu prüfen.');
            if($row['status']==='reserved'&&($row['checkout_id']!==null||$row['operation_token']!==null))throw new \DomainException('Begonnene Zahlung erst unabhängig prüfen.');
            $original=$this->payload($row);$payment=$row['proof_json']===null?null:json_decode($row['proof_json'],true,32,JSON_THROW_ON_ERROR);
            $external=$row['status']!=='reserved'&&(int)$row['amount_cents']>0;
            if($external){
                if(!$payment||$row['checkout_id']===null)throw new \DomainException('Ursprünglich bestätigter Zahlungsnachweis erforderlich.');
                $current=$this->gateway($row,$original)->status(self::paymentOrder($row),$row['checkout_id']);
                foreach(['provider','transaction_id','merchant','amount_cents','currency','reference','charge_id','test_mode']as$key)if(($current[$key]??null)!==($payment[$key]??null))throw new \DomainException('Originalzahlung vor Erstattung erneut prüfen.');
                if(($current['status']??null)!=='paid')throw new \DomainException('Zahlung vor Erstattung unabhängig klären.');
            }
            $operation=bin2hex(random_bytes(16));
            Db::atomic($this->db,function()use($page,$id,$version,$reason,$external,$operation):void{
                Db::run($this->db,'SELECT generation FROM khcms_state WHERE club_id=? FOR UPDATE',[$this->club])->fetchColumn();
                Db::run($this->db,'SELECT page_id FROM native_ticket_offers WHERE club_id=? AND page_id=? FOR UPDATE',[$this->club,$page])->fetchColumn();
                $row=$this->refundRow($page,$id,true);$actor=$this->actor('manage');
                if((int)$row['version']!==$version||!in_array($row['status'],['paid','paid_unallocated','reserved'],true))throw new \DomainException('Bestellung inzwischen geändert.');
                $this->unusedOrder($row);
                Db::run($this->db,"INSERT INTO native_ticket_refunds(club_id,order_id,operation_id,state,reason,actor_id,created_at,updated_at) VALUES(?,?,?,'pending',?,?,UTC_TIMESTAMP(6),UTC_TIMESTAMP(6))",[$this->club,$id,$operation,$reason,$actor['id']]);
                if($external)$this->change($row,'refund_pending','online_refund_started',$actor['id']);
                else $this->finishOnlineRefund($row,['provider'=>'none','amount_cents'=>0,'confirmed'=>true],$actor['id'],'cancelled');
            });
            if(!$external)return ['id'=>$id,'status'=>'cancelled'];
            try{(new TicketRefund($row['payment_provider'],$original['account'],$this->request))->submit(self::paymentOrder($row),$payment,$operation);}catch(\Throwable){return ['id'=>$id,'status'=>'refund_pending'];}
            return $this->verifyOnlineRefund($page,$id);
        });
    }
    public function reconcileOnlineRefund(string $page,string $id):array
    {
        $row=$this->refundRow($page,$id);
        return $this->gate($row,fn():array=>$this->verifyOnlineRefund($page,$id));
    }
    private function verifyOnlineRefund(string $page,string $id):array
    {
        $row=$this->refundRow($page,$id);
        if(in_array($row['status'],['refunded','cancelled'],true))return ['id'=>$id,'status'=>$row['status']];
        $attempt=Db::run($this->db,'SELECT * FROM native_ticket_refunds WHERE club_id=? AND order_id=?',[$this->club,$id])->fetch();
        if($row['status']!=='refund_pending'||!$attempt||$attempt['state']!=='pending')throw new \DomainException('Keine begonnene Online-Erstattung vorhanden.');
        $original=$this->payload($row);$payment=json_decode($row['proof_json'],true,32,JSON_THROW_ON_ERROR);
        try{$proof=(new TicketRefund($row['payment_provider'],$original['account'],$this->request))->verify(self::paymentOrder($row),$payment,$attempt['operation_id']);}catch(\Throwable){return ['id'=>$id,'status'=>'refund_pending'];}
        Db::atomic($this->db,function()use($page,$id,$proof):void{
            Db::run($this->db,'SELECT generation FROM khcms_state WHERE club_id=? FOR UPDATE',[$this->club])->fetchColumn();
            Db::run($this->db,'SELECT page_id FROM native_ticket_offers WHERE club_id=? AND page_id=? FOR UPDATE',[$this->club,$page])->fetchColumn();
            $row=$this->refundRow($page,$id,true);$actor=$this->actor('manage');
            if($row['status']!=='refund_pending')throw new \DomainException('Erstattungsstand inzwischen geändert.');
            $this->unusedOrder($row);$this->finishOnlineRefund($row,$proof,$actor['id'],'refunded');
        });
        return ['id'=>$id,'status'=>'refunded'];
    }
    private function finishOnlineRefund(array $row,array $proof,string $actor,string $state):void
    {
        $original=$this->payload($row);
        Db::run($this->db,"UPDATE native_ticket_passes SET status='voided',version=version+1 WHERE club_id=? AND order_id=? AND status IN ('valid','test')",[$this->club,$row['id']]);
        foreach($original['vouchers']??[]as$v)Db::run($this->db,"UPDATE pos_value_vouchers SET state='refunded',remaining_cents=0 WHERE club_id=? AND id=? AND code_hash=?",[$original['manager_club'],$v['id'],hash('sha256',$v['code'])]);
        Db::run($this->db,"UPDATE native_ticket_refunds SET state='confirmed',proof_json=?,updated_at=UTC_TIMESTAMP(6) WHERE club_id=? AND order_id=?",[json_encode($proof,JSON_THROW_ON_ERROR),$this->club,$row['id']]);
        $this->change($row,$state,$state==='cancelled'?'online_cancelled':'online_refund_confirmed',$actor);
    }
}
