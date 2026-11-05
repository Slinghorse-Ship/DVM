<?php declare(strict_types=1);
namespace Kulturhus\Native\Communication;
use DomainException;
use PDO;
use Kulturhus\Native\Shared\{Access,Db,SecretBox};

/** Authenticated retained native inbox bytes; strictly read-only, without provider authority. */
final class InboxHistory
{
    public function __construct(private PDO $db,private SecretBox $box){}
    public static function query(array $input):array
    {
        $kind=$input['inbox_history_kind']??'versions';$connection=$input['inbox_connection']??'';$version=$input['inbox_version']??'';
        $page=filter_var($input['inbox_history_page']??1,FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>1000000]]);
        foreach([$connection,$version]as$id)if(!is_string($id)||($id!==''&&!preg_match('/\A[a-f0-9]{32}\z/D',$id)))throw new DomainException('Frühere Nachrichtenfassung prüfen.');
        if(!is_string($kind)||!in_array($kind,['versions','connections'],true)||$page===false)throw new DomainException('Nachrichtenhistorie prüfen.');
        return ['kind'=>$kind,'connection'=>$connection,'version'=>$version,'page'=>$page];
    }
    private static function key(string $thread,string $scope,string $kind,string $id,int $index):string
    {
        return substr(hash('sha256',$thread."\0".$scope."\0".$kind."\0".$id."\0".$index),0,32);
    }
    private static function snapshot(array $row,array $snapshot,string $club):array
    {
        if(!is_string($snapshot['conversation']??null)||!in_array($row['channel'],['facebook','instagram'],true)||MessageReplyProvider::threadId($club,$row['channel'],$snapshot['conversation'])!==$row['id']||!is_array($snapshot['messages']??null)||!array_is_list($snapshot['messages'])||count($snapshot['messages'])>5000)throw new DomainException('Ursprünglicher Gesprächsbezug nicht bestätigt.');
        foreach($snapshot['messages']as$message)self::message($message);
        return $snapshot;
    }
    private static function message(mixed $message):void
    {
        if(!is_array($message)||!is_string($message['id']??null)||$message['id']===''||!is_bool($message['incoming']??null))throw new DomainException('Ursprüngliche Nachricht prüfen.');
        foreach(['text','from_name','created_at']as$field)if(!is_string($message[$field]??null)||!mb_check_encoding($message[$field],'UTF-8'))throw new DomainException('Ursprüngliche Nachricht prüfen.');
    }
    public function view(Access $access,string $thread,array $input):array
    {
        $q=self::query($input);
        return Db::atomic($this->db,function()use($access,$thread,$q,$input):array{
            $actor=$access->require('social','manage');$club=$actor['club_id'];
            $row=(new MessageReplyProvider($this->db,$this->box))->conversation($access,$thread);$current=self::snapshot($row,$row['payload'],$club);unset($row['payload']);
            $past=$current['connection_history']??[];if(!is_array($past)||!array_is_list($past)||count($past)>100)throw new DomainException('Kontoverlauf prüfen.');
            $snapshot=$current;$scope='current';
            if($q['connection']!==''){
                $snapshot=null;
                foreach($past as$index=>$candidate)if(self::key($thread,'current','connection','',$index)===$q['connection']){$snapshot=self::snapshot($row,$candidate,$club);$scope=$q['connection'];break;}
                if(!$snapshot)throw new DomainException('Früherer Kontoverlauf gehört nicht zum Gespräch.');
            }
            $metadata=[];$total=0;$chosen=null;$offset=($q['page']-1)*25;
            $keep=static function(array $meta,array $original)use(&$metadata,&$total,&$chosen,$q,$offset):void{
                if($total>=$offset&&count($metadata)<25)$metadata[]=$meta;
                if(($q['version']===''&&$chosen===null&&$total>=$offset)||$q['version']===$meta['id'])$chosen=['meta'=>$meta,'original'=>$original];
                ++$total;
            };
            if($q['kind']==='connections'){
                if($q['version']!=='')throw new DomainException('Kontoverlauf ohne Nachrichtenversion wählen.');
                for($index=count($past)-1;$index>=0;--$index){
                    $candidate=self::snapshot($row,$past[$index],$club);$last=$candidate['messages'][array_key_last($candidate['messages'])]??null;
                    $meta=['id'=>self::key($thread,'current','connection','',$index),'name'=>$last['from_name']??'Gespräch','text'=>$last['text']??'','date'=>$last['created_at']??'','number'=>$index+1];
                    $keep($meta,$candidate);
                    if($q['connection']!==''&&$meta['id']===$q['connection'])$chosen=['meta'=>$meta,'original'=>$candidate];
                }
            }else{
                $versions=$snapshot['message_versions']??[];if(!is_array($versions)||count($versions)>5000)throw new DomainException('Nachrichtenversionen prüfen.');
                foreach(array_reverse($versions,true)as$id=>$originals){
                    if((!is_string($id)&&!is_int($id))||!is_array($originals)||!array_is_list($originals)||count($originals)>100)throw new DomainException('Nachrichtenversionen prüfen.');
                    for($index=count($originals)-1;$index>=0;--$index){$message=$originals[$index];self::message($message);if($message['id']!==(string)$id)throw new DomainException('Originalnachrichtenbezug prüfen.');
                        $keep(['id'=>self::key($thread,$scope,'version',(string)$id,$index),'name'=>$message['from_name']??'Gespräch','text'=>$message['text']??'','date'=>$message['created_at']??'','number'=>$index+1],$message);
                    }
                }
                if($q['version']!==''&&!$chosen)throw new DomainException('Frühere Fassung gehört nicht zum Kontoverlauf.');
            }
            $pages=max(1,(int)ceil($total/25));if($q['page']>$pages)throw new DomainException('Frühere Nachrichtenseite nicht verfügbar.');
            $timeline=['messages'=>[],'page'=>1,'pages'=>1,'total'=>0,'older'=>false,'newer'=>false];$selectedConnection=$q['connection'];
            if($chosen){
                if($q['kind']==='versions')$timeline['messages']=[$chosen['original']];
                else{
                    $snapshot=$chosen['original'];$selectedConnection=$chosen['meta']['id'];$binding=$snapshot['connection_id']??null;
                    if(!is_string($binding)||!preg_match('/\A[a-f0-9]{32,64}\z/D',$binding))$binding=null;
                    // These authenticated retained fields only scope read-only mail references.
                    // They are never an Attention source or a provider/send account.
                    $source=['kind'=>'message','channel'=>$row['channel'],'parent_id'=>$snapshot['conversation'],'_archived'=>true];
                    $timeline=(new ConversationTimeline(new Replies($this->db,$this->box)))->page($access,$snapshot['messages'],$binding?$source:null,$binding,$input['message_page']??1);
                }
            }
            $access->require('social','manage');
            return ['thread'=>$thread,'channel'=>$row['channel'],'rows'=>$metadata,'total'=>$total,'pages'=>$pages,'selected'=>$chosen['meta']??null,'timeline'=>$timeline,'selected_connection'=>$selectedConnection,'scope'=>$scope,'has_connections'=>count($past)>0,'has_versions'=>!empty($snapshot['message_versions'])]+$q;
        });
    }
}
