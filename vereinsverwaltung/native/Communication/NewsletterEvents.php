<?php declare(strict_types=1);
namespace Kulturhus\Native\Communication;
use Kulturhus\Native\Shared\{Access,Identity};
/** A read-only catalogue of the actual published event revisions, frozen on ordinary draft save. */
final class NewsletterEvents
{
 public static function catalogue(\PDO $db,Access $access,?\DateTimeImmutable $now=null):array
 {
  $access->require('newsletter','read');$access->require('website','read');$now??=new \DateTimeImmutable();
  $events=(new \Kulturhus\Native\Cms\PublishedEvents($db,$access->club()))->between($now->format('c'),$now->modify('+1 year')->format('c'));
  usort($events,static fn(array $a,array $b):int=>[$a['starts_at'],$a['id']]<=>[$b['starts_at'],$b['id']]);
  $origin=Identity::forClub($db,$access->club())['origin'];$blocks=[];
  foreach(array_slice($events,0,12)as$event)if(!$event['cancelled'])$blocks[]=self::block($event,$origin);
  return$blocks;
 }
 public static function block(array $event,string $origin):array
 {
  return Content::blocks([['type'=>'event','title'=>$event['title'],'date'=>(new \DateTimeImmutable($event['starts_at']))->setTimezone(new \DateTimeZone('Europe/Berlin'))->format('d.m.Y · H:i').' Uhr','venue'=>$event['location'],'text'=>mb_strcut($event['description'],0,5000,'UTF-8'),'media_ref'=>$event['media_ids'][0]??'','url'=>rtrim($origin,'/').'/'.rawurlencode($event['slug']).'/']])[0];
 }
}
