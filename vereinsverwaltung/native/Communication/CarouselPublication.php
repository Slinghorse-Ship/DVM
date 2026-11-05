<?php declare(strict_types=1);
namespace Kulturhus\Native\Communication;

use DomainException;
use PDO;
use Kulturhus\Native\Shared\{Access,Authority,Db,Identity,SecretBox};
use Kulturhus\Native\Integration\Accounts;
use Kulturhus\Native\Delivery\Outbox;

/** One whole approved publication per channel, with exact ordered JPEG originals. */
final class CarouselPublication
{
    public static function fingerprint(mixed $value):string
    {
        $normalize=static function(mixed $entry)use(&$normalize):mixed{if(!is_array($entry))return $entry;if(!array_is_list($entry))ksort($entry,SORT_STRING);foreach($entry as$key=>$child)$entry[$key]=$normalize($child);return $entry;};
        return hash('sha256',json_encode($normalize($value),JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE));
    }

    public static function caption(array $item):string
    {
        return $item['title']."\n\n".$item['body'].(($item['destination_url']??'')!==''?"\n".$item['destination_url']:'');
    }

    public static function queue(PDO $db,SecretBox $box,Access $access,array $publication,array $account,string $due,string $mediaRoot):array
    {
        if(!$db->inTransaction()||!CarouselProgress::installed($db))throw new DomainException('Karussells über den Installer aktivieren.');
        $access->require('social','publish');$item=$publication['item'];$club=$access->club();$expected=Carousel::count($item);
        if(count($publication['images'])!==$expected)throw new DomainException('Alle ursprünglichen Karussell-Bilder auswählen.');
        $caption=self::caption($item);
        if(mb_strlen($caption,'UTF-8')>63206)throw new DomainException('Beitragstext für Facebook kürzen.');
        if(in_array('instagram',$item['channels'],true)&&(mb_strlen($caption,'UTF-8')>2200||preg_match_all('/(?:^|\s)#[\p{L}\p{N}_]+/u',$caption)>30||preg_match_all('/(?:^|\s)@[A-Za-z0-9_.]+/u',$caption)>20))throw new DomainException('Instagram erlaubt höchstens 2200 Zeichen, 30 Hashtags und 20 Erwähnungen.');
        $identity=Identity::forClub($db,$club);$store=new Images($db,$access,$mediaRoot);$jobs=[];
        $connectionVersion=(int)Db::run($db,"SELECT version FROM native_private_settings WHERE club_id=? AND purpose='api-meta' FOR SHARE",[$club])->fetchColumn();if($connectionVersion<1)throw new DomainException('Meta-Verbindung erneut öffnen.');
        foreach($item['channels']as$channel){
            if($channel==='instagram'&&$account['instagram_id']==='')throw new DomainException('Instagram-Konto nicht eingerichtet.');
            $dedupe=$item['id'].':'.$item['version'].':'.$channel.':carousel';
            $existing=Db::run($db,'SELECT id FROM native_delivery_jobs WHERE club_id=? AND dedupe=? FOR SHARE',[$club,hash('sha256','meta:'.$dedupe)])->fetchColumn();
            if($existing!==false){$jobs[]=$existing;continue;}
            $previous=Db::run($db,"SELECT j.ciphertext,j.id FROM native_delivery_jobs j JOIN native_delivery_sources s ON s.club_id=j.club_id AND s.job_id=j.id WHERE s.club_id=? AND s.module='social' AND s.item_id=? AND j.kind='meta' AND j.status IN ('processing','uncertain') FOR SHARE",[$club,$item['id']])->fetchAll();
            foreach($previous as$job){$old=$box->open('job:'.$club.':'.$job['id'],$job['ciphertext']);if(($old['channel']??null)===$channel)throw new DomainException('Früheres Veröffentlichungsergebnis für diesen Kanal zuerst prüfen.');}
            $expiry=(new \DateTimeImmutable($due,new \DateTimeZone('UTC')))->modify('+7 days')->format('Y-m-d H:i:s');$images=[];
            foreach($publication['images']as$index=>$image){
                $file=$store->jpeg($image['id']);$token=bin2hex(random_bytes(32));
                Db::run($db,"INSERT INTO native_image_capabilities(token_hash,club_id,image_id,expires_at,format) VALUES(?,?,?,?,'jpeg')",[hash('sha256',$token),$club,$image['id'],$expiry]);
                $images[]=['id'=>$image['id'],'slide'=>$index,'sha256'=>$file['sha256'],'url'=>$identity['origin'].'/publication-image/'.$token];
            }
            $frame=['channel'=>$channel,'kind'=>'carousel','caption'=>$caption,'images'=>$images,'page_id'=>$account['page_id'],'instagram_id'=>$account['instagram_id'],'connection_id'=>Accounts::binding($account),'approval'=>['actor'=>$item['approved_by'],'authority'=>$item['approved_authority']??'native','item'=>$item['id'],'version'=>$item['version']]];
            $job=(new Outbox($db,$box))->enqueue($club,'meta',$dedupe,$frame,$due,$access);$jobs[]=$job;
            foreach($images as$index=>$image)Db::run($db,'INSERT INTO native_carousel_capabilities VALUES(?,?,?,?,?)',[hash('sha256',substr($image['url'],strrpos($image['url'],'/')+1)),$club,$job,$index,$connectionVersion]);
        }
        return $jobs;
    }

    public static function guard(PDO $db,Access $access,array $frame,?string $mediaRoot=null):array
    {
        $actor=$access->require('social','publish');$club=$actor['club_id'];$approval=$frame['approval']??null;$images=$frame['images']??null;
        if(($frame['kind']??null)!=='carousel'||!is_array($approval)||!is_array($images)||!array_is_list($images)||count($images)<2||count($images)>10)throw new DomainException('Originalen Karussell-Auftrag prüfen.');
        $item=(new Workspace($db,$access,'social'))->get(Content::id($approval['item']??''),true);
        if($item['kind']!=='carousel'||!in_array($item['status'],['approved','scheduled'],true)||$item['version']!==($approval['version']??null)||$item['approved_by']!==($approval['actor']??null)||($item['approved_authority']??'native')!==($approval['authority']??null)||self::caption($item)!==($frame['caption']??null)||!in_array($frame['channel']??null,$item['channels'],true)||count($images)!==Carousel::count($item)||array_column($images,'id')!==($item['approved_image_ids']??[]))throw new DomainException('Originale ganze Karussell-Freigabe ist nicht mehr aktuell.');
        $approver=Authority::resolve($db,['created_by'=>$approval['actor'],'authority'=>$approval['authority'],'club_id'=>$club]);if($approver->require('social','publish')['club_id']!==$club)throw new DomainException('Vereinszuordnung der Freigabe geändert.');
        $identity=Identity::forClub($db,$club);$identityVersion=Db::run($db,'SELECT version FROM native_club_settings WHERE club_id=? FOR SHARE',[$club])->fetchColumn();$prefix=$identity['origin'].'/publication-image/';
        $rows=(new ImageHistory($db,$access))->originals($item['id'],array_column($images,'id'));
        foreach($images as$index=>$image){
            $row=$rows[$index];$meta=json_decode($row['metadata_json'],true,32,JSON_THROW_ON_ERROR);$url=$image['url']??null;
            if(($image['slide']??null)!==$index||(int)$row['slide']!==$index||$row['kind']!=='carousel'||(int)$row['item_version']!==$item['approved_version']-1||!hash_equals($row['content_hash'],Images::fingerprint($item))||($meta['identity_version']??null)!==($identityVersion===false?0:(int)$identityVersion)||!is_string($image['sha256']??null)||!hash_equals($meta['jpeg_sha256']??'',$image['sha256'])||!is_string($url)||!str_starts_with($url,$prefix)||!preg_match('/\A[a-f0-9]{64}\z/D',substr($url,strlen($prefix))))throw new DomainException('Originale Karussell-Dateien, Reihenfolge oder Vereinsdaten geändert.');
            $cap=Db::run($db,"SELECT 1 FROM native_image_capabilities c JOIN native_carousel_capabilities p ON p.token_hash=c.token_hash AND p.club_id=c.club_id JOIN native_private_settings a ON a.club_id=p.club_id AND a.purpose='api-meta' AND a.version=p.connection_version WHERE c.club_id=? AND c.image_id=? AND c.token_hash=? AND c.format='jpeg' AND c.expires_at>UTC_TIMESTAMP()".($db->inTransaction()?' FOR SHARE':''),[$club,$image['id'],hash('sha256',substr($url,strlen($prefix)))])->fetchColumn();
            if(!$cap)throw new DomainException('Originaler Karussell-Dateizugang ist abgelaufen.');
            ImageUsage::image($db,$club,$row);
            if($mediaRoot!==null){$store=new Images($db,$access,$mediaRoot);$store->file($row['id']);$store->jpeg($row['id']);}
        }
        return $item;
    }

    /** A temporary provider URL remains bound to its current whole publication, account and authorities. */
    public static function publicActive(PDO $db,string $club,string $token,array $image):bool
    {
        if(!CarouselProgress::installed($db))return false;
        try{
            $row=Db::run($db,"SELECT j.actor_id,j.authority,i.payload,c.image_index FROM native_carousel_capabilities c JOIN native_delivery_jobs j ON j.id=c.job_id AND j.club_id=c.club_id JOIN native_delivery_sources s ON s.job_id=j.id AND s.club_id=j.club_id AND s.module='social' JOIN khn_social_items i ON i.id=s.item_id AND i.club_id=s.club_id AND i.version=s.item_version JOIN native_private_settings a ON a.club_id=c.club_id AND a.purpose='api-meta' AND a.version=c.connection_version WHERE c.club_id=? AND c.token_hash=? AND j.kind='meta' AND j.status IN ('pending','processing','uncertain','sent') AND i.status IN ('approved','scheduled')",[$club,hash('sha256',$token)])->fetch();
            if(!$row)return false;$item=json_decode($row['payload'],true,64,JSON_THROW_ON_ERROR);
            if(($item['kind']??null)!=='carousel'||($item['approved_image_ids'][(int)$row['image_index']]??null)!==$image['id'])return false;
            foreach([['created_by'=>$row['actor_id'],'authority'=>$row['authority'],'club_id'=>$club],['created_by'=>$item['approved_by']??'','authority'=>$item['approved_authority']??'native','club_id'=>$club]]as$binding)if(Authority::resolve($db,$binding)->require('social','publish')['club_id']!==$club)return false;
            $metadata=json_decode($image['metadata_json'],true,32,JSON_THROW_ON_ERROR);$identity=Db::run($db,'SELECT version FROM native_club_settings WHERE club_id=?',[$club])->fetchColumn();if(($metadata['identity_version']??null)!==($identity===false?0:(int)$identity))return false;
            ImageUsage::image($db,$club,$image);return true;
        }catch(DomainException|\InvalidArgumentException){return false;}
    }
}
