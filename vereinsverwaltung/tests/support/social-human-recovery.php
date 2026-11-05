<?php declare(strict_types=1);
use Kulturhus\Native\Shared\{Access,Db,SecretBox};
use Kulturhus\Native\Communication\{Attention,Replies};
use Kulturhus\Native\Integration\Accounts;

/** Uses the already owned recovery account; never replaces an existing Meta connection. */
function ownHumanRecoveryCreate(PDO $db,Access $access,SecretBox $box):array
{
    $account=(new Accounts($db,$box))->get($access->club(),'meta');$parent=$account['page_id'].'_980';$remote=$account['page_id'].'_981';$object=substr(hash('sha256','own-human-recovery-object'),0,32);
    Db::run($db,"INSERT INTO native_social_objects(club_id,id,channel,remote_id,kind,caption,payload_json,last_seen) VALUES(?,?,'facebook',?,'post','Own human recovery post',?,UTC_TIMESTAMP())",[$access->club(),$object,$parent,json_encode(['connection_id'=>Accounts::binding($account),'thumbnail'=>'','permalink'=>''],JSON_THROW_ON_ERROR)]);
    (new Attention($db,$box))->observe($access,'facebook','comment',$parent,[['id'=>$remote,'text'=>'Own exact original recovery question','name'=>'Own recovery person','title'=>'Own recovery post']],true);
    $attention=substr(hash('sha256',$access->club().':facebook:comment:'.$parent.':'.$remote),0,32);$key=bin2hex(random_bytes(16));$source=(new Replies($db,$box))->source($access,$attention);$text="Own immutable human recovery answer ä🙂\nExact original newline";$published=$account['page_id'].'_982';
    $http=static function($method,$path,$headers,$body)use($account,$remote,$published,$text):array{
        if($headers!==['Authorization: Bearer '.$account['token']])throw new RuntimeException('Own human recovery account changed');$id=explode('?',substr($path,strlen('/'.$account['api_version'].'/')))[0];
        if($method==='GET'&&$id===$remote)return['status'=>200,'body'=>['id'=>$remote,'message'=>'Own exact original recovery question','can_comment'=>true]];
        if($method==='POST'&&$id===$remote.'/comments'){if($body!==['message'=>$text])throw new RuntimeException('Own human recovery body changed');return['status'=>200,'body'=>['id'=>$published]];}
        if($method==='GET'&&$id===$published)return['status'=>200,'body'=>['id'=>$published,'message'=>$text,'can_comment'=>true]];
        throw new RuntimeException('Unexpected own human recovery provider call');
    };
    $reply=(new Replies($db,$box,$http))->sendHuman($access,$attention,(int)$source['version'],$text,$key);
    if($reply['status']!=='sent')throw new RuntimeException('Own human recovery reply not confirmed');
    $command=Db::run($db,'SELECT * FROM native_social_human_sends WHERE club_id=? AND request_key=?',[$access->club(),$key])->fetch();$row=Db::run($db,'SELECT * FROM native_social_replies WHERE club_id=? AND id=?',[$access->club(),$reply['id']])->fetch();
    return ['attention'=>$attention,'source_version'=>(int)$source['version'],'text'=>$text,'key'=>$key,'reply'=>$reply,'command'=>$command,'row'=>$row];
}
function ownHumanRecoveryVerify(PDO $db,Access $access,SecretBox $box,array $original):array
{
    $tables=$db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);$before=ownOriginalDatabaseHash($db,$tables);
    $service=new Replies($db,$box,static function(){throw new RuntimeException('Restored accepted human intention must not call a provider');});
    $reply=$service->sendHuman($access,$original['attention'],$original['source_version'],$original['text'],$original['key']);
    return [
        Db::run($db,'SELECT * FROM native_social_human_sends WHERE club_id=? AND request_key=?',[$access->club(),$original['key']])->fetch()===$original['command']&&Db::run($db,'SELECT * FROM native_social_replies WHERE club_id=? AND id=?',[$access->club(),$reply['id']])->fetch()===$original['row'],
        $reply===$original['reply'],
        ownOriginalDatabaseHash($db,$tables)===$before,
    ];
}
