<?php declare(strict_types=1);
namespace Kulturhus\Native\Events\Tickets;
use PDO;
use Kulturhus\Native\Shared\{Access,Db};
use Kulturhus\Native\Cms\{Pages,Events,EventDetails};
use Kulturhus\Native\Communication\Content;
use Kulturhus\Native\Events\Workflow;

final class Offers
{
    public const SPHERES=['ideell'=>'Ideeller Bereich','vermoegen'=>'Vermögensverwaltung','zweck'=>'Zweckbetrieb','wirtschaftlich'=>'Wirtschaftlicher Geschäftsbetrieb'];
    public function __construct(private PDO $db,private Access $access){}
    public function get(string $page):?array
    {
        Content::id($page);$club=$this->access->require('website','read')['club_id'];
        $row=Db::run($this->db,'SELECT * FROM native_ticket_offers WHERE club_id=? AND page_id=?',[$club,$page])->fetch();
        return $row?self::project($this->db,$row):null;
    }
    public function save(string $page,array $input,int $version):array
    {
        Content::id($page);$club=$this->access->require('website','manage')['club_id'];
        if(!is_int($input['capacity']??null)||$input['capacity']<1||$input['capacity']>100000||!is_int($input['price_cents']??null)||$input['price_cents']<0||$input['price_cents']>10000000||!is_bool($input['active']??null)||$version<0||$version>=2147483647)throw new \DomainException('Ticketkontingent, Centpreis und Verkaufswahl prüfen.');
        if(isset($input['accounting_sphere'])&&(!is_string($input['accounting_sphere'])||!isset(self::SPHERES[$input['accounting_sphere']])))throw new \DomainException('Fachliche Zuordnung des Eintritts prüfen.');
        return Db::atomic($this->db,function()use($page,$input,$version,$club):array{
            $actor=$this->access->require('website','manage');if($actor['club_id']!==$club)throw new \DomainException('Vereinszuordnung geändert.');
            Db::run($this->db,'SELECT generation FROM khcms_state WHERE club_id=? FOR UPDATE',[$club])->fetchColumn();
            $row=Db::run($this->db,'SELECT * FROM native_ticket_offers WHERE club_id=? AND page_id=? FOR UPDATE',[$club,$page])->fetch();
            $event=(new Pages($this->db,$this->access))->get($page);
            if(!EventDetails::isEvent($event)||$event['archived']||($event['trashed_at']??null)!==null)throw new \DomainException('Aktuelle eigene Veranstaltung auswählen.');
            if(($row?(int)$row['version']:0)!==$version)throw new \DomainException('Ticketangebot inzwischen geändert. Bitte neu laden.');
            $stats=self::stats($this->db,$club,$page);
            if($input['capacity']<$stats['allocated'])throw new \DomainException('Kontingent darf bestätigte oder laufende Bestellungen nicht unterschreiten.');
            if($input['active']){
                $workflow=Workflow::forClub($this->db,$club);
                if($workflow['tickets']!=='native'||($input['price_cents']>0&&!in_array($workflow['payment'],['sumup','stripe'],true)))throw new \DomainException('Eigenes Ticketsystem und passenden Zahlungsanbieter zuerst auswählen.');
            }
            Db::run($this->db,'INSERT INTO native_ticket_offers(club_id,page_id,version,capacity,price_cents,accounting_sphere,active,updated_by,updated_at) VALUES(?,?,?,?,?,?,?,?,UTC_TIMESTAMP(6)) ON DUPLICATE KEY UPDATE version=VALUES(version),capacity=VALUES(capacity),price_cents=VALUES(price_cents),accounting_sphere=VALUES(accounting_sphere),active=VALUES(active),updated_by=VALUES(updated_by),updated_at=VALUES(updated_at)',[$club,$page,$version+1,$input['capacity'],$input['price_cents'],$input['accounting_sphere']??$row['accounting_sphere']??'zweck',(int)$input['active'],$actor['id']]);
            Db::run($this->db,'UPDATE khcms_state SET generation=generation+1 WHERE club_id=?',[$club]);
            return $this->get($page);
        });
    }
    public static function stats(PDO $db,string $club,string $page):array
    {
        $rows=Db::run($db,'SELECT status,quantity,expires_at,checkout_id,operation_token FROM native_ticket_orders WHERE club_id=? AND page_id=?'.($db->inTransaction()?' FOR SHARE':''),[$club,$page])->fetchAll();
        $held=0;$sold=0;
        foreach($rows as$row){if($row['status']==='reserved'&&$row['checkout_id']===null&&$row['operation_token']===null&&(int)$row['expires_at']<time())continue;if(in_array($row['status'],['reserved','creating_checkout','awaiting_payment','payment_uncertain'],true))$held+=(int)$row['quantity'];elseif(in_array($row['status'],['paid','payment_review','refund_pending'],true))$sold+=(int)$row['quantity'];}
        return ['held'=>$held,'sold'=>$sold,'allocated'=>$held+$sold];
    }
    private static function project(PDO $db,array $row):array
    {
        $stats=self::stats($db,$row['club_id'],$row['page_id']);
        return ['page_id'=>$row['page_id'],'version'=>(int)$row['version'],'capacity'=>(int)$row['capacity'],'price_cents'=>(int)$row['price_cents'],'accounting_sphere'=>$row['accounting_sphere'],'active'=>(bool)$row['active'],...$stats,'remaining'=>max(0,(int)$row['capacity']-$stats['allocated'])];
    }
    public static function publicOffer(PDO $db,string $club,string $page):?array
    {
        Content::id($page);$workflow=Workflow::forClub($db,$club,$db->inTransaction());
        if($workflow['tickets']!=='native')return null;
        $event=(new Events($db,$club))->publicId($page,$db->inTransaction());
        if(!$event||!in_array($event['event_status'],['scheduled','started','postponed'],true))return null;
        $start=EventDetails::start($event);if(!$start)return null;$end=EventDetails::end($event);
        $saleUntil=$end??$start->setTimezone(new \DateTimeZone($event['event_timezone']??'Europe/Berlin'))->modify('tomorrow')->setTime(0,0);
        if($saleUntil<=new \DateTimeImmutable('now',new \DateTimeZone('UTC')))return null;
        $row=Db::run($db,'SELECT * FROM native_ticket_offers WHERE club_id=? AND page_id=? AND active=1'.($db->inTransaction()?' FOR SHARE':''),[$club,$page])->fetch();
        if(!$row)return null;
        $offer=self::project($db,$row);
        return $offer+['event'=>$event,'payment_provider'=>$offer['price_cents']===0?'free':$workflow['payment']];
    }
}
