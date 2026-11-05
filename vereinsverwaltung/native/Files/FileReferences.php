<?php declare(strict_types=1);
namespace Kulturhus\Native\Files;

use DomainException;
use InvalidArgumentException;
use PDO;
use Kulturhus\Native\Cms\{FieldValues,MediaPolicy,NewsChannels,PageTrash,TemplatePolicy,WebsiteDocuments};
use Kulturhus\Native\Shared\{Access,Db,MediaDescription};

/** Closed union: existing immutable website PDFs or new immutable asset revisions. */
final class FileReferences
{
    public function __construct(private PDO $db,private Access $access,private string $fileRoot,private string $documentRoot,private ?string $mediaRoot=null){}
    public static function installed(PDO $db):bool
    {
        $count=(int)Db::run($db,"SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('khcms_assets','khcms_asset_revisions','khcms_file_blobs') AND TABLE_TYPE='BASE TABLE'")->fetchColumn();
        if($count!==0&&$count!==3)throw new DomainException('Dateifunktionen benötigen das CMS-Update im Installer.');return $count===3;
    }
    public static function capture(PDO $db,string $club,array $links):array
    {
        if(!self::installed($db))return WebsiteDocuments::capture($db,$club,$links);
        // Reuse the established bounded ID/label input grammar; old PDFs keep their exact capture shape.
        $out=[];
        foreach(WebsiteDocuments::normalize($links)as$link){
            $row=Db::run($db,"SELECT r.*,a.kind,a.state FROM khcms_asset_revisions r JOIN khcms_assets a ON a.id=r.asset_id AND a.club_id=r.club_id WHERE r.id=? AND r.club_id=? AND a.state='active'",[$link['id'],$club])->fetch();
            if(!$row){$out[] = WebsiteDocuments::capture($db,$club,[$link])[0];continue;}
            if($row['kind']!=='file'||$row['source_kind']!=='file')throw new InvalidArgumentException('Für ein Bild die Bildverknüpfung verwenden.');
            $metadata=json_decode($row['metadata_json'],true,32,JSON_THROW_ON_ERROR);
            $out[]=['id'=>$row['id'],'label'=>$link['label']!==''?$link['label']:$metadata['title'],'name'=>$row['original_name'],'bytes'=>(int)$row['bytes'],'sha256'=>$row['sha256'],'mime'=>$row['mime'],'source'=>'asset','asset_id'=>$row['asset_id'],'revision'=>(int)$row['revision']];
        }
        return $out;
    }
    public function file(string $id):array
    {
        AssetVersions::identifier($id);$club=$this->access->require('website','read')['club_id'];
        if(!self::installed($this->db))return(new WebsiteDocuments($this->db,$this->access,$this->documentRoot))->file($id);
        if(!Db::run($this->db,'SELECT 1 FROM khcms_asset_revisions WHERE id=? AND club_id=?',[$id,$club])->fetchColumn())return (new WebsiteDocuments($this->db,$this->access,$this->documentRoot))->file($id);
        return (new AssetVersions($this->db,$this->access,$this->fileRoot,$this->mediaRoot))->file($id);
    }
    public function publicFile(string $id):?array
    {
        if(!preg_match('/\A[a-f0-9]{32}\z/D',$id))return null;$club=$this->access->require('website','read')['club_id'];
        if(!self::installed($this->db))return(new WebsiteDocuments($this->db,$this->access,$this->documentRoot))->publicFile($id);
        $asset=Db::run($this->db,"SELECT a.id,a.state,h.metadata_json FROM khcms_asset_revisions r JOIN khcms_assets a ON a.id=r.asset_id AND a.club_id=r.club_id JOIN khcms_asset_revisions h ON h.id=a.head_id AND h.asset_id=a.id AND h.club_id=a.club_id WHERE r.id=? AND r.club_id=? AND a.kind='file'",[$id,$club])->fetch();
        if(!$asset)return (new WebsiteDocuments($this->db,$this->access,$this->documentRoot))->publicFile($id);
        if($asset['state']!=='active'||!MediaDescription::usable(json_decode($asset['metadata_json'],true,32,JSON_THROW_ON_ERROR)+['usage_policy'=>MediaPolicy::current($this->db,$club)],'website'))return null;
        [$policy,$parameters]=TemplatePolicy::publicFilter($this->db,$club,'p');
        $from=" FROM khcms_asset_revisions v JOIN khcms_pages p ON p.club_id=v.club_id JOIN khcms_revisions r ON r.page_id=p.id AND r.club_id=p.club_id AND r.revision=p.published_revision WHERE v.id=? AND v.club_id=? AND ".PageTrash::activeSql()." AND NOT EXISTS(SELECT 1 FROM khcms_archives a WHERE a.page_id=p.id AND a.club_id=p.club_id) AND JSON_CONTAINS(r.payload_json,JSON_OBJECT('id',v.id,'sha256',v.sha256,'bytes',v.bytes,'name',v.original_name,'source','asset'),'$.custom_file_metadata') AND ".NewsChannels::where('website')." AND (".$policy.')';
        $after='';
        do{
            $rows=Db::run($this->db,'SELECT p.id,p.published_revision'.$from.' AND p.id>? ORDER BY p.id LIMIT 25',[$id,$club,...$parameters,$after])->fetchAll();
            foreach($rows as$row){
                $json=Db::run($this->db,'SELECT r.payload_json'.$from.' AND p.id=? AND p.published_revision=?',[$id,$club,...$parameters,$row['id'],$row['published_revision']])->fetchColumn();
                if(!is_string($json))continue;$payload=json_decode($json,true,512,JSON_THROW_ON_ERROR);
                if(class_exists(\Kulturhus\Native\Cms\FieldPolicy::class))$payload=\Kulturhus\Native\Cms\FieldPolicy::publicSource($this->db,$club,$payload);
                if(in_array($id,FieldValues::references($payload,'file',true),true))return $this->file($id);
            }
            if($rows!==[])$after=$rows[array_key_last($rows)]['id'];
        }while(count($rows)===25);
        return null;
    }
    public static function assertPublication(PDO $db,string $club,array $metadata):void
    {
        foreach($metadata as$file){
            if(($file['source']??null)!=='asset')continue;$captured=self::capture($db,$club,[['id'=>$file['id'],'label'=>$file['label']]])[0];if($captured!==$file)throw new DomainException('Ursprüngliche Dateifassung wurde verändert. Inhalt erneut prüfen.');
            $row=Db::run($db,"SELECT h.metadata_json FROM khcms_asset_revisions r JOIN khcms_assets a ON a.id=r.asset_id AND a.club_id=r.club_id JOIN khcms_asset_revisions h ON h.id=a.head_id AND h.asset_id=a.id AND h.club_id=a.club_id WHERE r.id=? AND r.club_id=? AND a.state='active'",[$file['id'],$club])->fetchColumn();
            if($row===false||!MediaDescription::usable(json_decode($row,true,32,JSON_THROW_ON_ERROR)+['usage_policy'=>MediaPolicy::current($db,$club)],'website'))throw new DomainException('Dateinutzung gesperrt, abgelaufen oder vor Verwendung zu klären.');
        }
    }
}
