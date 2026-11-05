<?php declare(strict_types=1);
namespace Kulturhus\Verein;
use PDO;use DomainException;
/** Read-only source lookup; no inventory, price, stock or financial mutation. */
final class InventoryFinancialSources
{
 public function __construct(private PDO$db,private string$actor){}
 private function access():string{if(!(new Inventory($this->db,$this->actor))->canRead())throw new DomainException('Inventarberechtigung erforderlich.');$a=Permissions::actor($this->db,$this->actor);if(!in_array($a['finance_access'],['read','write'],true))throw new DomainException('Finanzberechtigung für Belegauswahl erforderlich.');return$a['club_id'];}
 public function page(mixed$kind='expense',mixed$page=1,mixed$search='',mixed$move=''):array{
  $club=$this->access();if(!is_string($kind)||!in_array($kind,['expense','goods'],true)||(!is_int($page)&&!is_string($page))||!preg_match('/^[1-9][0-9]{0,6}$/D',(string)$page)||(int)$page>1000000||!is_string($search)||!mb_check_encoding($search,'UTF-8')||mb_strlen($search)>150||!is_string($move)||!in_array($move,['','next','previous','search'],true))throw new DomainException('Belegauswahl prüfen.');
  $page=(int)$page;if($move==='next')$page=min(1000000,$page+1);elseif($move==='previous')$page=max(1,$page-1);elseif($move==='search')$page=1;$f=new Finance($this->db,$this->actor);
  $result=$kind==='goods'?$f->goodsPage($page,$search):$f->entriesPage(['kind'=>'expense','q'=>$search],$page);$result['kind']=$kind;$result['search']=trim($search);if($this->access()!==$club)throw new DomainException('Vereinszugang geändert.');return$result;
 }
 public function original(mixed$reference,string$item=''):array{
  $club=$this->access();if(!is_string($reference)||!preg_match('/^(expense|goods):([a-zA-Z0-9_-]{1,80})$/D',$reference,$m))throw new DomainException('Belegzuordnung prüfen.');$old=$item!==''?(new Inventory($this->db,$this->actor))->item($item):null;$f=new Finance($this->db,$this->actor);$r=$m[1]==='goods'?$f->goodsEntry($m[2]):$f->entry($m[2]);
  if($m[1]==='expense'&&($r['kind']!=='expense'||(in_array($r['payment_state'],['cancelled','reversed'],true)&&($old['source_entry']??'')!==$m[2])))throw new DomainException('Keine verfügbare Anschaffungsrechnung.');if($this->access()!==$club)throw new DomainException('Vereinszugang geändert.');return$r;
 }
}
