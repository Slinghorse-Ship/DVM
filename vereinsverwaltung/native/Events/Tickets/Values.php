<?php declare(strict_types=1);
namespace Kulturhus\Native\Events\Tickets;
use PDO;
use Kulturhus\Native\Shared\Db;
use Kulturhus\Native\Integration\ManagerBridge;
use Kulturhus\Native\Communication\Content;

/** Online sales use the original register voucher balance and movement ledger. */
final class Values
{
    public static function managerClub(PDO $db,string $club):?string
    {
        if(!Db::run($db,"SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='native_manager_identities'")->fetchColumn())return null;
        $rows=Db::run($db,'SELECT DISTINCT i.manager_club_id FROM native_manager_identities i JOIN native_users u ON u.id=i.native_id WHERE u.club_id=?',[$club])->fetchAll(PDO::FETCH_COLUMN);
        if(!$rows)return null;
        if(count($rows)!==1||!hash_equals(ManagerBridge::clubKey($rows[0]),$club))throw new \DomainException('Gutschein-Vereinszuordnung prüfen.');
        return $rows[0];
    }
    public static function catalogue(PDO $db,string $club):array
    {
        $manager=self::managerClub($db,$club);if($manager===null)return [];
        return Db::run($db,"SELECT id,label,price_cents,version FROM pos_products WHERE club_id=? AND kind='value-voucher' AND active=1 ORDER BY price_cents,label,id",[$manager])->fetchAll();
    }
    public static function input(array $selection):array
    {
        if(!array_is_list($selection)||count($selection)>10)throw new \DomainException('Gutscheinauswahl prüfen.');
        $result=[];$seen=[];$count=0;
        foreach($selection as $line){
            if(!is_array($line))throw new \DomainException('Gutscheinauswahl prüfen.');
            $id=Content::id($line['id']??'');$qty=$line['quantity']??null;
            if(!is_int($qty)||$qty<1||$qty>10||isset($seen[$id])||($count+=$qty)>10)throw new \DomainException('Höchstens zehn Gutscheine je Bestellung.');$seen[$id]=true;
            $result[]=['id'=>$id,'quantity'=>$qty];
        }
        return $result;
    }
    public static function selection(PDO $db,string $club,array $selection):array
    {
        $selection=self::input($selection);if(!$selection)return ['manager_club'=>null,'items'=>[],'amount_cents'=>0];
        $manager=self::managerClub($db,$club);if($manager===null)throw new \DomainException('Gutscheinverkauf nicht eingerichtet.');$items=[];$total=0;
        foreach($selection as$line){$id=$line['id'];$qty=$line['quantity'];
            $p=Db::run($db,"SELECT id,label,price_cents,version FROM pos_products WHERE club_id=? AND id=? AND kind='value-voucher' AND active=1 FOR SHARE",[$manager,$id])->fetch();
            if(!$p||(int)$p['price_cents']<1)throw new \DomainException('Gutscheinangebot inzwischen geändert.');
            $items[]=['id'=>$id,'kind'=>'value-voucher','label'=>$p['label'],'quantity'=>$qty,'price_cents'=>(int)$p['price_cents'],'version'=>(int)$p['version']];$total+=(int)$p['price_cents']*$qty;
        }
        if($total>100000000)throw new \DomainException('Gutscheinbetrag prüfen.');
        return ['manager_club'=>$manager,'items'=>$items,'amount_cents'=>$total];
    }
    /** Called once inside the same transaction that issues admission passes. */
    public static function issue(PDO $db,array $order,array $original):array
    {
        if(!$db->inTransaction()||$order['status']!=='paid')throw new \LogicException('Gutscheine erst nach bestätigter Zahlung ausstellen.');
        $vouchers=[];
        foreach($original['items']??[]as$item)if($item['kind']==='value-voucher')for($i=0;$i<$item['quantity'];$i++){
            $id=bin2hex(random_bytes(16));$code=strtoupper(bin2hex(random_bytes(16)));$state=($original['account']['mode']??null)==='test'?'test':'active';
            Db::run($db,"INSERT INTO pos_value_vouchers(club_id,id,code_hash,issued_sale,face_cents,remaining_cents,state) VALUES(?,?,?,?,?,?,?)",[$original['manager_club'],$id,hash('sha256',$code),$order['id'],$item['price_cents'],$item['price_cents'],$state]);
            $vouchers[]=['id'=>$id,'code'=>$code,'label'=>$item['label'],'face_cents'=>$item['price_cents']];
        }
        return $vouchers;
    }
    public static function present(PDO $db,array $original):array
    {
        $result=[];
        foreach($original['vouchers']??[]as$v){
            $row=Db::run($db,'SELECT remaining_cents,state FROM pos_value_vouchers WHERE club_id=? AND id=? AND code_hash=?',[$original['manager_club'],$v['id'],hash('sha256',$v['code'])])->fetch();
            if(!$row)throw new \DomainException('Originalgutschein nicht verfügbar.');
            $result[]=array_merge($v,['remaining_cents'=>(int)$row['remaining_cents'],'status'=>$row['state'],'kind'=>'value-voucher']);
        }
        return $result;
    }
    public static function paymentState(PDO $db,array $original,string $state):void
    {
        if(empty($original['issued']))return;
        foreach($original['vouchers']??[]as$v){
            if(in_array($state,['payment_review','refund_pending'],true))Db::run($db,"UPDATE pos_value_vouchers SET state='payment-review' WHERE club_id=? AND id=? AND code_hash=? AND state='active'",[$original['manager_club'],$v['id'],hash('sha256',$v['code'])]);
            elseif($state==='paid')Db::run($db,"UPDATE pos_value_vouchers SET state='active' WHERE club_id=? AND id=? AND code_hash=? AND state='payment-review'",[$original['manager_club'],$v['id'],hash('sha256',$v['code'])]);
        }
    }
}
