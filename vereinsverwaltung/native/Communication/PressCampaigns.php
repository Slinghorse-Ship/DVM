<?php declare(strict_types=1);
namespace Kulturhus\Native\Communication;

use PDO;
use DomainException;
use Kulturhus\Native\Shared\{Access,Authority,Db,Identity,SecretBox};
use Kulturhus\Native\Delivery\Outbox;
use Kulturhus\Native\Cms\Media;

/** Exact approved press originals, one private durable job per selected contact. No SMTP here. */
final class PressCampaigns
{
    public function __construct(private PDO $db,private Access $access,private SecretBox $box,private string $mediaRoot) {}

    public function queue(string $id,int $version,string $due): array
    {
        if(!PressSchema::installed($this->db))throw new DomainException('Presseversand mit dem aktuellen Installationspaket einrichten.');
        Content::id($id);$at=\DateTimeImmutable::createFromFormat('!Y-m-d H:i:s',$due,new \DateTimeZone('UTC'));
        if(!$at||$at->format('Y-m-d H:i:s')!==$due)throw new DomainException('Gültigen Versandzeitpunkt wählen.');
        return Db::atomic($this->db,function()use($id,$version,$due):array{
            $actor=$this->access->require('press','publish');$club=$actor['club_id'];
            $item=(new Workspace($this->db,$this->access,'press',$this->mediaRoot))->get($id,true);
            if($item['version']!==$version||!in_array($item['status'],['approved','scheduled'],true)||!$item['approved_by']||$item['approved_version']!==($item['status']==='scheduled'?$version-1:$version))throw new DomainException('Genau die aktuelle Pressefassung freigeben.');
            // Check local configuration before accepting a send intent; no SMTP connection is opened.
            (new \Kulturhus\Native\Delivery\MailAccount($this->db,$this->box,static fn()=>\Kulturhus\Verein\Infrastructure\Runtime\ServerKey::nativeRuntime((string)getenv('KH_NATIVE_PRIVATE_KEY_FILE'))))->configuration($club,'press');
            Authority::resolve($this->db,['created_by'=>$item['approved_by'],'authority'=>$item['approved_authority']??'native','club_id'=>$club])->require('press','publish');
            if(Db::run($this->db,"SELECT 1 FROM native_delivery_sources s JOIN native_delivery_jobs j ON j.id=s.job_id AND j.club_id=s.club_id WHERE s.club_id=? AND s.module='press' AND s.item_id=? AND j.status IN ('processing','uncertain') FOR SHARE",[$club,$id])->fetchColumn())throw new DomainException('Offenes Versandergebnis zuerst im Zustellverlauf prüfen.');
            if(Db::run($this->db,"SELECT 1 FROM native_delivery_sources s JOIN native_delivery_jobs j ON j.id=s.job_id AND j.club_id=s.club_id WHERE s.club_id=? AND s.module='press' AND s.item_id=? AND j.status='sent' AND s.item_version<>? FOR SHARE",[$club,$id,$version])->fetchColumn())throw new DomainException('Pressemitteilung bereits versandt. Eine neue Mitteilung getrennt anlegen.');
            $ids=PressRecipientCatalogue::ids($item['recipient_ids']);if(!$ids)throw new DomainException('Presseempfänger auswählen.');
            $in=implode(',',array_fill(0,count($ids),'?'));
            $contacts=Db::run($this->db,"SELECT id,email,name,outlet,version,active FROM khn_press_contacts WHERE club_id=? AND id IN ($in) ORDER BY id FOR SHARE",[$club,...$ids])->fetchAll(PDO::FETCH_ASSOC);
            if(count($contacts)!==count($ids)||array_filter($contacts,static fn($row)=>(int)$row['active']!==1))throw new DomainException('Ausgewählte Presseempfänger sind nicht mehr aktiv.');
            $usage=ImageUsage::item($this->db,$club,$item,'press');
            $frozen=Db::run($this->db,'SELECT ciphertext FROM native_press_messages WHERE club_id=? AND item_id=? AND item_version=? FOR SHARE',[$club,$id,$version])->fetchColumn();
            if(!is_string($frozen)){
                $inline=[];$bytes=0;
                foreach(ImageUsage::refs(array_replace($item,['attachment_media_ids'=>[]]))as$mediaId){
                    $file=(new Media($this->db,new PressMediaAccess($this->db,$this->access),$this->mediaRoot))->variant($mediaId,'medium');
                    $bytes+=$file['bytes'];if($bytes>10000000)throw new DomainException('Pressebilder überschreiten zusammen 10 MB.');
                    $raw=file_get_contents($file['path']);if(!is_string($raw)||strlen($raw)!==$file['bytes']||!hash_equals($file['sha256'],hash('sha256',$raw)))throw new DomainException('Ursprüngliches Pressebild verändert.');
                    $inline[$mediaId]=['name'=>'Pressebild-'.$mediaId.'.'.(['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'][$file['mime']]??throw new DomainException('Bildformat prüfen.')),'mime'=>$file['mime'],'base64'=>base64_encode($raw),'sha256'=>$file['sha256'],'cid'=>'press-'.$mediaId.'@dvm.local'];
                }
                $item['_identity']=Identity::forClub($this->db,$club);$item['_identity']['logo_id']='';
                $html=Content::preview($item,static fn(string $mediaId):string=>'cid:'.($inline[$mediaId]['cid']??throw new DomainException('Pressebild nicht verfügbar.')));
                $text=html_entity_decode(strip_tags(str_replace(['</p>','</h2>','</h3>','<br>','<br />'],"\n",$html)),ENT_QUOTES|ENT_HTML5,'UTF-8');
                $body=['subject'=>$item['title'],'html'=>$html,'text'=>$text,'media_usage'=>$usage,'inline_images'=>array_values($inline)];
                if($item['attachment_media_ids']??[])$body['photo_attachments']=PressPhotoAttachments::freeze($this->db,$this->access,$this->mediaRoot,$item['attachment_media_ids']);
                // A repeated enqueue never replaces original bytes after an accepted commit.
                $context='press-message:'.$club.':'.$id.':'.$version;
                $cipher=$this->box->seal($context,$body);
                if(strlen($cipher)>16000000)throw new DomainException('Weniger oder kleinere Fotoanlagen für diese Pressemitteilung auswählen.');
                Db::run($this->db,'INSERT IGNORE INTO native_press_messages(club_id,item_id,item_version,ciphertext,created_at) VALUES(?,?,?,?,UTC_TIMESTAMP(6))',[$club,$id,$version,$cipher]);
            }
            $jobs=[];
            foreach($contacts as$contact){
                $payload=['to'=>$contact['email'],'press_contact'=>['id'=>$contact['id'],'version'=>(int)$contact['version'],'name'=>$contact['name'],'outlet'=>$contact['outlet']],'approval'=>['actor'=>$item['approved_by'],'authority'=>$item['approved_authority']??'native','item'=>$id,'version'=>$version]];
                $jobs[]=(new Outbox($this->db,$this->box))->enqueue($club,'press',$id.':'.$version.':'.$contact['id'],$payload,$due,$this->access);
            }
            return $jobs;
        });
    }

    public static function prepare(PDO $db,SecretBox $box,array $job,array $payload): array
    {
        $approval=$payload['approval']??[];Content::id($approval['item']??null);
        if(!is_int($approval['version']??null)||$approval['version']<1)throw new DomainException('Originale Pressefassung fehlt.');
        $cipher=Db::run($db,'SELECT ciphertext FROM native_press_messages WHERE club_id=? AND item_id=? AND item_version=?',[$job['club_id'],$approval['item'],$approval['version']])->fetchColumn();
        if(!is_string($cipher))throw new DomainException('Gespeicherte Pressefassung fehlt.');
        $body=$box->open('press-message:'.$job['club_id'].':'.$approval['item'].':'.$approval['version'],$cipher);
        if(!is_string($body['subject']??null)||!is_string($body['html']??null)||!is_string($body['text']??null)||!is_array($body['media_usage']??null)||!is_array($body['inline_images']??null))throw new DomainException('Gespeicherte Pressefassung ist unvollständig.');
        if(array_key_exists('photo_attachments',$body))$body['attachments']=PressPhotoAttachments::open($body['photo_attachments']);
        return array_replace($payload,$body);
    }
}
