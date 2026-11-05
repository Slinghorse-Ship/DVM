<?php declare(strict_types=1);
namespace Kulturhus\Verein;
use DomainException;

/** Personal metadata only; every original remains at its existing protected source. */
trait PortalDocumentPages
{
 private static function personalWindow(array $rows,array $input,callable $search):array
 {
  $query=array_key_exists('query',$input)?$input['query']:'';$page=array_key_exists('page',$input)?$input['page']:1;
  if(!is_string($query)||preg_match('//u',$query)!==1||mb_strlen($query,'UTF-8')>240||preg_match('/[\x00-\x1f\x7f]/',$query))throw new DomainException('Persönliche Belegsuche auf 240 Zeichen ohne Steuerzeichen begrenzen.');
  if((!is_int($page)&&!is_string($page))||!preg_match('/\A[1-9][0-9]{0,8}\z/D',(string)$page))throw new DomainException('Gültige persönliche Belegseite angeben.');
  $needle=trim($query);$total=count($rows);
  if($needle!=='')$rows=array_values(array_filter($rows,static fn(array $row):bool=>mb_stripos($search($row),$needle,0,'UTF-8')!==false));
  return Pagination::slice($rows,(int)$page,25)+['query'=>$query,'all_total'=>$total];
 }
 public function invoicePage(array $input=[]):array
 {
  return $this->readContext(fn():array=>self::personalWindow($this->invoiceRows(),$input,static fn(array $row):string=>$row['title'].' '.$row['number'].' '.$row['year']));
 }
 public function sharePage(array $input=[]):array
 {
  return $this->readContext(fn():array=>self::personalWindow($this->sharedRows(),$input,static fn(array $row):string=>$row['title']));
 }
 public function voucherPage(array $input=[],?WebsiteCalendar $website=null):array
 {
  return $this->readContext(fn():array=>self::personalWindow($this->voucherRows($website,true),$input,static fn(array $row):string=>$row['title'].' '.implode(' ',array_column($row['benefit_rows'],'label'))));
 }
 /** Prepare all contact-bound sections under the same current identity locks. */
 public function personalPages(array $inputs,?WebsiteCalendar $website=null):array
 {
  return $this->readContext(function()use($inputs,$website):array{
   $actor=$this->identity();$result=['actor'=>$actor,'pages'=>[],'errors'=>[]];
   foreach(['invoices'=>'invoicePage','shared'=>'sharePage','vouchers'=>'voucherPage']as$section=>$method){
    try{$result['pages'][$section]=$section==='vouchers'?$this->$method($inputs[$section]??[],$website):$this->$method($inputs[$section]??[]);}
    catch(DomainException $failure){$result['pages'][$section]=null;$result['errors'][$section]=['message'=>$failure->getMessage(),'code'=>$failure->getCode()===503?503:422];}
   }
   $current=$this->identity();if($current['id']!==$actor['id']||$current['club_id']!==$actor['club_id']||$current['contact_id']!==$actor['contact_id'])throw new DomainException('Persönliche Belege nach geänderten Zugangsrechten erneut öffnen.',503);
   return $result;
  });
 }
 /** Own printing never expands a helper's general redemption permission to other people. */
 public function voucherFile(string $event,?WebsiteCalendar $website=null):string
 {
  $result=$this->readContext(function()use($event,$website):array{
   $actor=$this->identity();
   $record=DocumentCatalog::query($this->db,'SELECT * FROM event_voucher_events WHERE club_id=? AND id=?'.$this->currentReadLock(),[$actor['club_id'],$event])->fetch();
   if(!$record)throw new DomainException('Veranstaltung nicht verfügbar.');
   $rows=DocumentCatalog::query($this->db,"SELECT v.*,c.name FROM event_vouchers v JOIN contacts c ON c.club_id=v.club_id AND c.id=v.contact_id WHERE v.club_id=? AND v.event_id=? AND v.contact_id=? AND v.state='active' ORDER BY v.id".$this->currentReadLock(),[$actor['club_id'],$event,$actor['contact_id']])->fetchAll();
   if(!$rows)throw new DomainException('Keine offenen Gutscheine für dieses Mitglied.');
   $count=0;foreach($rows as$row)if((int)$row['remaining']>0){$count+=$row['unit']==='piece'?(int)$row['remaining']:1;if($count>200)throw new DomainException('Höchstens 200 Gutscheine pro Druckdatei.');}
   if($count===0)throw new DomainException('Keine offenen Gutscheine für dieses Mitglied.');
   $source=(new EventVouchers($this->db,$this->actor,$website))->liveEvent($record,true);
   foreach($rows as&$row)if($row['unit']==='piece'){$row['tickets']=(int)$row['remaining']>0?EventVoucherTickets::list($this->db,$row,true,true):[];if(count($row['tickets'])!==(int)$row['remaining'])throw new DomainException('Gutscheinbestand bitte prüfen lassen.');}unset($row);
   $cards=array_values(array_filter(EventVoucherTickets::cards($rows),static fn(array $row):bool=>$row['state']==='active'&&(int)$row['remaining']>0));
   if(!$cards||count($cards)>200)throw new DomainException(!$cards?'Keine offenen Gutscheine für dieses Mitglied.':'Höchstens 200 Gutscheine pro Druckdatei.');
   return ['event_id'=>$event,'title'=>$source['title'],'date'=>$source['starts_at'],'person'=>['name'=>$rows[0]['name']],'vouchers'=>$cards];
  });
  // The complete authorized snapshot is immutable in memory; PDF rendering needs no database locks.
  return EventVoucherPdf::fromSnapshot($result);
 }
}
