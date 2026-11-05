<?php declare(strict_types=1);
namespace Kulturhus\Native\Communication;

use DomainException;
use PDO;
use Kulturhus\Native\Shared\{Access,Authority,Db,SecretBox,Identity};
use Kulturhus\Native\Integration\Accounts;
use Kulturhus\Native\Delivery\Outbox;

/** Immutable delivery evidence and a bounded, expiring original-file capability. */
final class VideoPublication
{
    public static function installed(PDO $db): bool
    {return (int)Db::run($db,"SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='native_video_capabilities'")->fetchColumn()===1;}

    public static function queue(PDO $db,SecretBox $box,Access $access,array $publication,array $account,string $due,string $mediaRoot): array
    {
        if(!$db->inTransaction()||!self::installed($db))throw new DomainException('Video-Veröffentlichung über den Installer aktivieren.');
        $access->require('social','publish');$item=$publication['item'];$club=$access->club();

        $identity=Identity::forClub($db,$club);$jobs=[];
        $caption=self::caption($item);
        if(in_array('instagram',$item['channels'],true)&&(mb_strlen($caption)>2200||preg_match_all('/(?:^|\s)#[\p{L}\p{N}_]+/u',$caption)>30||preg_match_all('/(?:^|\s)@[A-Za-z0-9_.]+/u',$caption)>20))throw new DomainException('Instagram erlaubt höchstens 2200 Zeichen, 30 Hashtags und 20 Erwähnungen einschließlich Quellenangaben.');
        $connectionVersion=(int)Db::run($db,"SELECT version FROM native_private_settings WHERE club_id=? AND purpose='api-meta' FOR SHARE",[$club])->fetchColumn();if($connectionVersion<1)throw new DomainException('Meta-Verbindung erneut öffnen.');
        foreach($item['channels']as$channel){
            if($channel==='instagram'&&$account['instagram_id']==='')throw new DomainException('Instagram-Konto nicht eingerichtet.');
            $dedupe=$item['id'].':'.$item['version'].':'.$channel.':reel';
            $existing=Db::run($db,'SELECT id FROM native_delivery_jobs WHERE club_id=? AND dedupe=? FOR SHARE',[$club,hash('sha256','meta:'.$dedupe)])->fetchColumn();
            if($existing!==false){$jobs[]=$existing;continue;}
            $token=bin2hex(random_bytes(32));
            $frame=[
                'channel'=>$channel,'kind'=>'reel','title'=>$item['title'],
                'caption'=>$caption,
                'video'=>$publication['video'],'video_url'=>$identity['origin'].'/publication-video/'.$token,
                'page_id'=>$account['page_id'],'instagram_id'=>$account['instagram_id'],
                'connection_id'=>Accounts::binding($account),
                'approval'=>['actor'=>$item['approved_by'],'authority'=>$item['approved_authority']??'native','item'=>$item['id'],'version'=>$item['version']],
            ];
            $id=(new Outbox($db,$box))->enqueue($club,'meta',$dedupe,$frame,$due,$access);
            $expiry=(new \DateTimeImmutable($due,new \DateTimeZone('UTC')))->modify('+7 days')->format('Y-m-d H:i:s');
            if(!Db::run($db,'SELECT 1 FROM native_video_capabilities WHERE job_id=? FOR SHARE',[$id])->fetchColumn())Db::run($db,'INSERT INTO native_video_capabilities VALUES(?,?,?,?,?,?,UTC_TIMESTAMP(6))',[hash('sha256',$token),$club,$publication['video']['id'],$id,$connectionVersion,$expiry]);
            $jobs[]=$id;
        }
        return $jobs;
    }

    public static function guard(PDO $db,Access $access,array $frame,?string $mediaRoot=null): array
    {
        $actor=$access->require('social','publish');$club=$actor['club_id'];
        if(($frame['kind']??null)!=='reel'||!is_array($frame['video']??null)||!is_array($frame['approval']??null))throw new DomainException('Gespeicherten Reel-Auftrag prüfen.');
        $proof=$frame['video'];$approval=$frame['approval'];
        $item=(new Workspace($db,$access,'social'))->get(Content::id($approval['item']??''));
        if(!in_array($item['status'],['approved','scheduled'],true)||$item['version']!==($approval['version']??null)||$item['approved_by']!==($approval['actor']??null)||($item['approved_authority']??'native')!==($approval['authority']??null)||($item['kind']??null)!=='reel'||!self::same($item['approved_video']??null,$proof))throw new DomainException('Originale Reel-Freigabe ist nicht mehr aktuell.');
        $approver=Authority::resolve($db,['created_by'=>$approval['actor'],'authority'=>$approval['authority'],'club_id'=>$club]);
        if($approver->require('social','publish')['club_id']!==$club)throw new DomainException('Vereinszuordnung der Freigabe geändert.');
        $identityData=Identity::forClub($db,$club);$caption=self::caption($item);
        $prefix=$identityData['origin'].'/publication-video/';$url=$frame['video_url']??null;
        if(!is_string($url)||!str_starts_with($url,$prefix)||!preg_match('/^[a-f0-9]{64}$/D',substr($url,strlen($prefix)))||($frame['title']??null)!==$item['title']||($frame['caption']??null)!==$caption||!in_array($frame['channel']??null,$item['channels'],true))throw new DomainException('Originaler Reel-Kanal, Text oder Dateizugang wurde geändert.');
        $cap=Db::run($db,"SELECT c.video_id,s.item_id,s.item_version FROM native_video_capabilities c JOIN native_delivery_jobs j ON j.id=c.job_id AND j.club_id=c.club_id JOIN native_delivery_sources s ON s.job_id=j.id AND s.club_id=c.club_id JOIN native_private_settings a ON a.club_id=c.club_id AND a.purpose='api-meta' AND a.version=c.connection_version WHERE c.club_id=? AND c.token_hash=? AND c.expires_at>UTC_TIMESTAMP() AND j.kind='meta' AND j.status IN ('pending','processing','uncertain','sent')".($db->inTransaction()?' FOR SHARE':''),[$club,hash('sha256',substr($url,strlen($prefix)))])->fetch();
        if(!$cap||$cap['video_id']!==($proof['id']??null)||$cap['item_id']!==$item['id']||(int)$cap['item_version']!==$item['version'])throw new DomainException('Originaler Videozugang ist nicht mehr aktuell.');

        $row=Db::run($db,'SELECT v.source_id,v.item_version,v.content_hash,v.identity_version,s.sha256,s.bytes,s.profile_json FROM native_social_videos v JOIN native_social_video_sources s ON s.club_id=v.club_id AND s.id=v.source_id WHERE v.club_id=? AND v.item_id=? AND v.id=?'.($db->inTransaction()?' FOR SHARE':''),[$club,$item['id'],Content::id($proof['id']??'')])->fetch();
        $identity=Db::run($db,'SELECT version FROM native_club_settings WHERE club_id=?'.($db->inTransaction()?' FOR SHARE':''),[$club])->fetchColumn();
        if(!$row||$row['source_id']!==($proof['source_id']??null)||$row['sha256']!==($proof['sha256']??null)||(int)$row['bytes']!==($proof['bytes']??null)||(int)$row['item_version']+1!==$item['approved_version']||(int)$row['identity_version']!==($identity===false?0:(int)$identity)||!hash_equals($row['content_hash'],Images::fingerprint($item))||!self::same(json_decode($row['profile_json'],true),$proof['profile']??null))throw new DomainException('Originale Videofassung wurde geändert.');
        $metadata=VideoMetadata::current($db,$club,$row['source_id']);VideoMetadata::assertUsage($metadata);$snapshot=VideoMetadata::snapshot($metadata);
        if(($proof['metadata_version']??0)!==$snapshot['version']||(array_key_exists('metadata_public',$proof)&&!self::same($proof['metadata_public'],$snapshot['public'])))throw new DomainException('Videoangaben wurden geändert. Freigabe erneuern.');
        if($mediaRoot!==null)(new VideoApproval($db,$access,$mediaRoot))->check($item);
        return $item;
    }

    public static function publicFile(PDO $db,string $club,string $token,string $mediaRoot): ?array
    {
        if(!preg_match('/^[a-f0-9]{64}$/D',$token)||!self::installed($db))return null;
        try{
            return Db::atomic($db,function()use($db,$club,$token,$mediaRoot):?array{
                $row=Db::run($db,"SELECT c.video_id,j.actor_id,j.authority,s.item_id,s.item_version FROM native_video_capabilities c JOIN native_delivery_jobs j ON j.id=c.job_id AND j.club_id=c.club_id JOIN native_delivery_sources s ON s.job_id=j.id AND s.club_id=c.club_id JOIN native_private_settings a ON a.club_id=c.club_id AND a.purpose='api-meta' AND a.version=c.connection_version WHERE c.club_id=? AND c.token_hash=? AND c.expires_at>UTC_TIMESTAMP() AND j.kind='meta' AND j.status IN ('pending','processing','uncertain','sent') FOR SHARE",[$club,hash('sha256',$token)])->fetch();
                if(!$row)return null;
                $access=Authority::resolve($db,['created_by'=>$row['actor_id'],'authority'=>$row['authority'],'club_id'=>$club]);
                $publication=PublicationSnapshot::social($db,$access,$row['item_id'],$mediaRoot);
                if($publication['item']['version']!==(int)$row['item_version']||($publication['video']['id']??null)!==$row['video_id'])return null;

                return (new Videos($db,$access,$mediaRoot))->file($row['video_id']);
            });
        }catch(DomainException){return null;}
    }

    public static function caption(array $item): string
    {
        $body=$item['title']."\n\n".$item['body'].(($item['destination_url']??'')!==''?"\n".$item['destination_url']:'');
        $source=\Kulturhus\Native\Shared\AttributionText::parts($item['approved_video']['metadata_public']??[]);
        return $body.($source?"\n\n".implode("\n",$source):'');
    }

    private static function same(mixed $a,mixed $b): bool
    {
        $normalize=static function(mixed $value)use(&$normalize):mixed{if(!is_array($value))return $value;if(!array_is_list($value))ksort($value,SORT_STRING);foreach($value as$key=>$entry)$value[$key]=$normalize($entry);return $value;};
        return json_encode($normalize($a),JSON_THROW_ON_ERROR)===json_encode($normalize($b),JSON_THROW_ON_ERROR);
    }
}
