<?php declare(strict_types=1);
namespace Kulturhus\Verein;
use DomainException;
trait InventoryDeletion
{
 /** Deliberate removal of an archived empty item; identities and immutable evidence survive. */
 public function purgeArchived(string $item,int $version,int $stockVersion,string $reason,string $key):string
 {
  $reason=self::text($reason,1000,true);
  return $this->change('purge',$key,$item,[$version,['reason'=>$reason,'stock_version'=>$stockVersion]],function($a,$id)use($version,$stockVersion){
   $old=$this->row('inventory_items',$a['club_id'],$id);self::version($old,$version);self::version($old,$stockVersion,'stock_version');
   if($old['active'])throw new DomainException('Nur archivierte Gegenstände können endgültig entfernt werden.');
   $stocks=$this->query('SELECT * FROM inventory_stock WHERE club_id=? AND item_id=?',[$a['club_id'],$id])->fetchAll();
   if(array_filter($stocks,static fn($s)=>$s['shelf']!=0||$s['reserved']!=0||$s['lent']!=0||$s['blocked']!=0))throw new DomainException('Der Gegenstand hat noch Bestand.');
   if($this->archiveBlocker($old,$stocks)!=='')throw new DomainException('Offene Ausleihe oder Kassenverknüpfung verhindert das Entfernen.');
   if($this->query('SELECT 1 FROM inventory_accessories WHERE club_id=? AND (parent_item=? OR child_item=?) LIMIT 1',[$a['club_id'],$id,$id])->fetchColumn())throw new DomainException('Der Gegenstand ist noch in einer Zubehörzuordnung enthalten. Bitte diese Zuordnung zuerst ausdrücklich ändern; sie wird nicht automatisch gelöscht.');
   if($old['source_entry']||$old['source_goods']||$old['cash_account']||$this->query("SELECT 1 FROM document_links WHERE club_id=? AND source_type='item' AND source_id=? LIMIT 1",[$a['club_id'],$id])->fetchColumn())throw new DomainException('Verknüpfte Belege oder Dokumente müssen erhalten bleiben; Gegenstand wird nicht entfernt.');
   if($this->query('SELECT 1 FROM inventory_acquisition_sources WHERE club_id=? AND item_id=? LIMIT 1',[$a['club_id'],$id])->fetchColumn())throw new DomainException('Die Anschaffung ist mit einem Originalnachweis oder Paketpreis verbunden. Der Gegenstand bleibt im Archiv erhalten.');
   $this->query('UPDATE inventory_items SET deleted_at=?,deleted_by=?,version=version+1 WHERE club_id=? AND id=? AND version=? AND stock_version=? AND deleted_at IS NULL',[gmdate('c'),$a['id'],$a['club_id'],$id,$version,$stockVersion]);
   $this->query("UPDATE document_sources SET available=0 WHERE club_id=? AND source_type='item' AND source_id=?",[$a['club_id'],$id]);return $version+1;
  });
 }
}
