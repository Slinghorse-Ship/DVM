<?php declare(strict_types=1);
namespace Kulturhus\Verein;
use PDO;
use DomainException;

/** Each physical piece has its own identity; the original voucher remains the balance ledger. */
final class EventVoucherTickets
{
    public static function create(PDO $db,array $v):void
    {
        if($v['unit']!=='piece')return;
        if((int)$v['granted']>1000)throw new DomainException('Höchstens 1.000 Einzelgutscheine je Zuteilung.');
        $q=static fn($sql,$args=[])=>DocumentCatalog::query($db,$sql,$args);
        if($q('SELECT 1 FROM event_voucher_tickets WHERE club_id=? AND voucher_id=?',[$v['club_id'],$v['id']])->fetchColumn())throw new DomainException('Einzelgutscheine sind bereits vorhanden.');
        $used=[];
        if($v['state']==='active'){
            $moves=$q("SELECT m.id,m.amount FROM event_voucher_moves m WHERE m.club_id=? AND m.voucher_id=? AND m.kind='redeem' AND NOT EXISTS(SELECT 1 FROM event_voucher_moves r WHERE r.club_id=m.club_id AND r.reversal_of=m.id) ORDER BY m.created_at,m.id",[$v['club_id'],$v['id']])->fetchAll();
            foreach($moves as $m)for($i=0;$i<-(int)$m['amount'];++$i)$used[]=$m['id'];
            if(count($used)!==(int)$v['granted']-(int)$v['remaining'])throw new DomainException('Gutscheinverlauf und Restbestand stimmen nicht überein.');
        }
        for($i=1;$i<=(int)$v['granted'];++$i){
            $move=$used[$i-1]??null;$state=$v['state']==='revoked'?'revoked':($move?'redeemed':'active');
            $q('INSERT INTO event_voucher_tickets(club_id,id,voucher_id,unit_no,code,state,redeemed_move_id) VALUES(?,?,?,?,?,?,?)',[$v['club_id'],bin2hex(random_bytes(16)),$v['id'],$i,strtoupper(bin2hex(random_bytes(8))),$state,$move]);
        }
    }
    public static function list(PDO $db,array $v,bool $current=false,bool $activeOnly=false):array
    {
        return DocumentCatalog::query($db,'SELECT * FROM event_voucher_tickets WHERE club_id=? AND voucher_id=?'.($activeOnly?" AND state='active'":'').' ORDER BY unit_no'.($current&&$db->inTransaction()?' FOR SHARE NOWAIT':''),[$v['club_id'],$v['id']])->fetchAll();
    }
    /** All original pieces must be explicitly revoked; spent or missing pieces are not deleted. */
    public static function fullyRevoked(PDO $db,array $v):bool
    {
        if($v['unit']!=='piece'||(int)$v['remaining']!==0||(int)$v['granted']<1)return false;
        $r=DocumentCatalog::query($db,"SELECT COUNT(*) total,COALESCE(SUM(CASE WHEN state='revoked' THEN 1 ELSE 0 END),0) revoked FROM event_voucher_tickets WHERE club_id=? AND voucher_id=?",[$v['club_id'],$v['id']])->fetch();
        return (int)$r['total']===(int)$v['granted']&&(int)$r['revoked']===(int)$v['granted'];
    }
    public static function debit(PDO $db,array $v,int $amount,string $ticketId,string $kind,string $move):void
    {
        if($v['unit']!=='piece'){if($ticketId!=='')throw new DomainException('Kein Einzelgutschein.');return;}
        if($ticketId!==''&&$amount!==1)throw new DomainException('Ein Einzelgutschein gilt für genau ein Stück.');
        if($amount<0||$amount>1000)throw new DomainException('Menge prüfen.');
        $q=static fn($sql,$args=[])=>DocumentCatalog::query($db,$sql,$args);
        $balance=(int)$q("SELECT COUNT(*) FROM event_voucher_tickets WHERE club_id=? AND voucher_id=? AND state='active'",[$v['club_id'],$v['id']])->fetchColumn();
        if($balance!==(int)$v['remaining'])throw new DomainException('Einzelgutscheine und Restbestand stimmen nicht überein. Bitte Verwaltung prüfen lassen.');
        $rows=$q("SELECT id FROM event_voucher_tickets WHERE club_id=? AND voucher_id=? AND state='active' AND (?='' OR id=?) ORDER BY unit_no LIMIT ".$amount.' FOR UPDATE',[$v['club_id'],$v['id'],$ticketId,$ticketId])->fetchAll(PDO::FETCH_COLUMN);
        if(count($rows)!==$amount)throw new DomainException('Dieser Gutschein ist bereits eingelöst oder gesperrt. Bitte neu laden.');
        foreach($rows as $id)$q('UPDATE event_voucher_tickets SET state=?,redeemed_move_id=? WHERE club_id=? AND id=?',[$kind==='redeem'?'redeemed':'revoked',$kind==='redeem'?$move:null,$v['club_id'],$id]);
    }
    public static function reverse(PDO $db,array $v,string $move,int $amount):void
    {
        if($v['unit']!=='piece')return;
        $q=DocumentCatalog::query($db,"UPDATE event_voucher_tickets SET state='active',redeemed_move_id=NULL WHERE club_id=? AND voucher_id=? AND redeemed_move_id=? AND state='redeemed'",[$v['club_id'],$v['id'],$move]);
        if($q->rowCount()!==$amount)throw new DomainException('Einzelgutscheine der Einlösung prüfen.');
    }
    public static function cards(array $vouchers):array
    {
        $cards=[];foreach($vouchers as $v){
            if($v['unit']!=='piece'){$v['ticket_id']='';$cards[]=$v;continue;}
            foreach($v['tickets'] as $t){$card=$v;$card['ticket_id']=$t['id'];$card['code']=$t['code'];$card['state']=$t['state'];$card['granted']=1;$card['remaining']=$t['state']==='active'?1:0;$card['ticket_number']=$t['unit_no'];$card['ticket_total']=$v['granted'];$cards[]=$card;}
        }return $cards;
    }
}
