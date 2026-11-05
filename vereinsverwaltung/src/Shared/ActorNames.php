<?php declare(strict_types=1);
namespace Kulturhus\Verein;
use PDO;
use Kulturhus\Native\Integration\ManagerBridge;
/** Display names for actors in an already authorized audit view; never changes identity. */
final class ActorNames
{
 public function __construct(private PDO $db,private string $viewer){}
 public function label(?string $id,?callable $text=null):string
 {
  $text??=static fn(string $source):string=>$source;
  if($id===null||$id==='')return '';
  $actor=Permissions::actor($this->db,$this->viewer);
  $login=self::name($this->db,$actor['club_id'],$id);if($login!==$id)return $login;
  $q=$this->db->prepare("SELECT c.name,s.payload FROM actors a LEFT JOIN contacts c ON c.club_id=a.club_id AND c.id=a.contact_id LEFT JOIN club_settings s ON s.club_id=a.club_id AND s.section=CONCAT('account:',a.id) WHERE a.club_id=? AND a.id=?");
  $q->execute([$actor['club_id'],$id]);$row=$q->fetch(PDO::FETCH_ASSOC);
  if(!$row)return $text('Name nicht verfügbar');
  $name=trim((string)($row['name']??''));
  if($name!=='')return $name;
  $data=json_decode((string)($row['payload']??''),true);$display=is_array($data)?($data['display_name']??''):'';
  return is_string($display)&&trim($display)!==''?trim($display):$text('Name nicht hinterlegt');
 }
    public static function name(PDO $db,string $club,string $actor,string $fallback=''):string
    {
        try{$q=$db->prepare('SELECT a.login_name FROM native_auth_accounts a JOIN native_users u ON u.id=a.actor_id WHERE u.id=? AND u.club_id=?');$q->execute([ManagerBridge::actorKey($actor,$club),ManagerBridge::clubKey($club)]);$name=$q->fetchColumn();if(is_string($name)&&trim($name)!=='')return $name;}
        catch(\PDOException $e){if((int)($e->errorInfo[1]??0)!==1146)throw $e;}
        return $fallback!==''?$fallback:$actor;
    }
    public static function rows(PDO $db,string $club,array $rows,string $id='id'):array
    {
        if(!$rows)return [];$keys=[];foreach($rows as$row)$keys[(string)$row[$id]]=ManagerBridge::actorKey((string)$row[$id],$club);$names=[];
        try{foreach(array_chunk(array_values(array_unique($keys)),200)as$chunk){$q=$db->prepare('SELECT u.id,a.login_name FROM native_auth_accounts a JOIN native_users u ON u.id=a.actor_id WHERE u.club_id=? AND u.id IN ('.implode(',',array_fill(0,count($chunk),'?')).')');$q->execute([ManagerBridge::clubKey($club),...$chunk]);foreach($q as$r)if(is_string($r['login_name'])&&trim($r['login_name'])!=='')$names[$r['id']]=$r['login_name'];}}
        catch(\PDOException $e){if((int)($e->errorInfo[1]??0)!==1146)throw $e;}
        foreach($rows as&$row)$row['name']=$names[$keys[(string)$row[$id]]]??($row['name']??$row[$id]);unset($row);return $rows;
    }
}
