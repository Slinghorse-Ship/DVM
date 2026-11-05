<?php declare(strict_types=1);
namespace Kulturhus\Verein;
use PDO;use DomainException;
/** Reuse physically integrated original positions when subsequently booking the invoice. */
final class InvoiceAcquisitionLinks
{
 public static function rows(PDO $db,string $actor,string $document):array
 {
  $docs=new Documents($db,$actor);$doc=$docs->document($document);$f=$docs->file($document,(int)$doc['revision']);
  if(!hash_equals($f['sha256'],hash('sha256',$f['bytes'])))throw new DomainException('Originaldatei konnte nicht geprüft werden.');
  $q=$db->prepare('SELECT s.*,COALESCE(s.gross_cents,p.gross_cents) original_gross_cents FROM inventory_acquisition_sources s LEFT JOIN inventory_acquisition_pools p ON p.club_id=s.club_id AND p.id=s.pool_id WHERE s.club_id=? AND s.sha256=? ORDER BY s.source_ordinal,s.item_id');$q->execute([$doc['club_id'],$f['sha256']]);return $q->fetchAll(PDO::FETCH_ASSOC);
 }
 public static function prepare(PDO $db,string $actor,string $document,array &$data,?array &$review):array
 {
  $rows=self::rows($db,$actor,$document);if(!$rows)return [];
  if(!$review)throw new DomainException('Dieses Original ist bereits mit Inventar verknüpft. Bitte seine Rechnungspositionen prüfen und übernehmen.');
  $inventory=new Inventory($db,$actor);if(!$inventory->canWrite())throw new DomainException('Bestehende Inventarzuordnung benötigt Inventar-Schreibrecht.');$grouped=[];
  foreach($rows as $r){$ordinal=(int)$r['source_ordinal'];$item=$inventory->item($r['item_id']);if(!$item['active'])throw new DomainException('Bereits zugeordneter Gegenstand ist archiviert. Zuordnung zuerst klären.');
   if(!isset($review['details'][$ordinal])||(int)$review['details'][$ordinal]['gross_cents']!==(int)$r['original_gross_cents'])throw new DomainException('Positionen stimmen nicht mit der bereits übernommenen Inventarzuordnung überein. Originalpositionen zuerst prüfen.');
   $grouped[$ordinal][$item['id']]=(int)$item['version'];
  }
  foreach($grouped as $ordinal=>$items){$manual=$review['links'][$ordinal]??[];if($manual&&array_diff_key($manual,$items))throw new DomainException('Manuell gewählte Gegenstände widersprechen der bestehenden Originalzuordnung.');foreach($manual as $id=>$v)if($items[$id]!==$v)throw new DomainException('Ausgewählter Gegenstand inzwischen geändert.');
   $review['links'][$ordinal]=$items;unset($data['invoice_positions'][$ordinal-1]['new_item']);
  }
  return $rows;
 }
 public static function assertCurrent(PDO $db,string $actor,string $document,array $expected):void
 {
  if(self::rows($db,$actor,$document)!==$expected)throw new DomainException('Inventarzuordnung wurde während der Rechnungserfassung geändert. Bitte neu öffnen.');
 }
}
