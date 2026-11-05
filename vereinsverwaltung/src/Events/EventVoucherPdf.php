<?php declare(strict_types=1);
namespace Kulturhus\Verein;
use PDO;
use DomainException;

final class EventVoucherPdf
{
    public static function file(PDO $db,string $actor,string $event,string $person,?WebsiteCalendar $website=null):string
    {
        $service=new EventVouchers($db,$actor);if($website)$service=new EventVouchers($db,$actor,$website);
        return self::fromSnapshot($service->printSnapshot($event,$person));
    }
    /** Render the already-authorized, frozen mail preview; never query another person's balance. */
    public static function fromSnapshot(array $snapshot):string
    {
        $rows=$snapshot['vouchers'];
        if(!$rows)throw new DomainException('Keine offenen Gutscheine für dieses Mitglied.');
        if(count($rows)>200)throw new DomainException('Höchstens 200 Gutscheine pro Druckdatei.');
        $cards=[];$date=(new \DateTimeImmutable($snapshot['date']))->setTimezone(new \DateTimeZone('Europe/Berlin'))->format('d.m.Y');
        foreach($rows as $v)$cards[]=['event'=>$snapshot['title'],'date'=>$date,'name'=>$snapshot['person']['name'],'label'=>$v['label'],'amount'=>EventVouchers::display((int)$v['remaining'],$v['unit']),'code'=>implode('-',str_split($v['code'],4)),'qr'=>base64_encode(EventVoucherQr::png($snapshot['event_id'],$v['code']))];
        return DocumentPreview::pdf(['event_voucher_cards'=>$cards]);
    }
}
