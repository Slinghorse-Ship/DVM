<?php declare(strict_types=1);
namespace Kulturhus\Native\Communication;
use DomainException;
use PDO;
use Kulturhus\Native\Shared\{Access,Db,SecretBox};
use Kulturhus\Native\Integration\Accounts;

/** Several notifications about the same original share one complete canonical read in a worker run. */
final class MetaWebhookProcessor
{
    private SocialHub $hub;
    private array $observed=[];
    private array $synced=[];
    public function __construct(private PDO $db,private SecretBox $box,?callable $http=null){$this->hub=new SocialHub($db,$box,$http);}
    public function read(Access $access,array $task):bool
    {
        $access->require('social','manage');$club=$access->club();$account=(new Accounts($this->db,$this->box))->get($club,'meta');$version=(int)Db::run($this->db,"SELECT version FROM native_private_settings WHERE club_id=? AND purpose='api-meta'",[$club])->fetchColumn();
        $key=$club.':'.$version.':'.Accounts::binding($account).':'.$task['channel'].':'.$task['kind'].':'.$task['target'];
        if($task['kind']==='message'){
            $required=$task['message_id']??null;
            if(isset($this->observed[$key])&&($required===null||in_array($required,$this->observed[$key],true)))return true;
            $this->hub->syncInbox($access,$task['channel'],$task['target'],$required,function(array $ids)use($key):void{$this->observed[$key]=$ids;});return true;
        }
        if(isset($this->observed[$key]))return true;
        $original=fn()=>Db::run($this->db,"SELECT id FROM native_social_objects WHERE club_id=? AND channel=? AND remote_id=? AND JSON_UNQUOTE(JSON_EXTRACT(payload_json,'$.connection_id'))=?",[$club,$task['channel'],$task['target'],Accounts::binding($account)])->fetchColumn();$id=$original();
        $group=$club.':'.$version.':'.Accounts::binding($account);
        if($id===false&&!isset($this->synced[$group])){$this->hub->sync($access);$this->synced[$group]=true;$id=$original();}
        if(!is_string($id))throw new DomainException('Gemeldeter eigener Beitrag ist bei Meta noch nicht bestätigt.');
        if($task['kind']==='comment')$this->hub->comments($access,$id);
        elseif($task['kind']==='metric')$this->hub->refreshCounts($access,$id);
        else throw new DomainException('Eingangsart prüfen.');
        $this->observed[$key]=[];return true;
    }
}
