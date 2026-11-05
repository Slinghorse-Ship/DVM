<?php declare(strict_types=1);
namespace Kulturhus\Native\Cms;

use InvalidArgumentException;
use Kulturhus\Native\Shared\{Access,Db};
use PDO;
use RuntimeException;

/** New immutable website PDFs; private storage is independent of internal club documents. */
final class WebsiteDocuments
{
    private string $root;
    public const MAX_BYTES = 10 * 1024 * 1024;
    public function __construct(private PDO $db, private Access $access, string $root)
    {
        if (preg_match('~^[a-z][a-z0-9+.-]*://~i',$root) || is_link($root)) throw new InvalidArgumentException('Dokumentenspeicher prüfen.');
        if (!is_dir($root) && !mkdir($root,0700,true)) throw new RuntimeException('Dokumentenspeicher nicht verfügbar.');
        $resolved=realpath($root);
        if ($resolved===false) throw new RuntimeException('Dokumentenspeicher nicht verfügbar.');
        foreach ([dirname(__DIR__,2).'/public',$_SERVER['DOCUMENT_ROOT']??''] as $webroot) {
            if ($webroot==='') continue;
            $web=realpath($webroot);
            if ($web!==false && ($resolved===$web || str_starts_with($resolved,$web.DIRECTORY_SEPARATOR))) throw new InvalidArgumentException('Website-Dokumente außerhalb des Webroots speichern.');
        }
        $this->root=$resolved;
    }
    public static function install(PDO $db): void
    {
        $db->exec("CREATE TABLE IF NOT EXISTS khcms_documents (
            id CHAR(32) PRIMARY KEY, club_id VARCHAR(64) NOT NULL, original_name VARCHAR(240) NOT NULL,
            bytes INT UNSIGNED NOT NULL, sha256 CHAR(64) NOT NULL, created_by VARCHAR(64) NOT NULL,
            created_at DATETIME(6) NOT NULL, KEY cms_documents_club (club_id,created_at,id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }
    /** Bounded ordered links; only the server may capture original file metadata. */
    public static function normalize(mixed $links): array
    {
        if (!is_array($links) || !array_is_list($links) || count($links)>20) throw new InvalidArgumentException('Höchstens 20 Dokumente je Seite auswählen.');
        $out=[];$seen=[];
        foreach ($links as $link) {
            if (!is_array($link) || array_diff(array_keys($link),['id','label','name','bytes','sha256'])) throw new InvalidArgumentException('Dokumentenverknüpfung prüfen.');
            $id=$link['id']??null;$label=$link['label']??'';
            if (!is_string($id) || !preg_match('/^[a-f0-9]{32}$/D',$id) || isset($seen[$id])) throw new InvalidArgumentException('Eindeutige Dokumente auswählen.');
            if (!is_string($label) || !preg_match('//u',$label) || mb_strlen($label)>240 || preg_match('/[\x00-\x1f\x7f]/',$label)) throw new InvalidArgumentException('Dokumententitel bis 240 Zeichen angeben.');
            $seen[$id]=true;$out[]=['id'=>$id,'label'=>trim($label)];
        }
        return $out;
    }
    public static function capture(PDO $db,string $club,array $links): array
    {
        $out=[];
        $newsletterDocuments=(bool)Db::run($db,"SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='native_communication_documents'")->fetchColumn();
        foreach (self::normalize($links) as $link) {
            if($newsletterDocuments&&Db::run($db,'SELECT 1 FROM native_communication_documents WHERE club_id=? AND document_id=?',[$club,$link['id']])->fetchColumn())throw new InvalidArgumentException('Für die Website ein eigenes PDF auswählen.');
            $row=Db::run($db,'SELECT original_name,bytes,sha256 FROM khcms_documents WHERE id=? AND club_id=?',[$link['id'],$club])->fetch(PDO::FETCH_ASSOC);
            if (!$row) throw new InvalidArgumentException('Dokument ist in diesem Verein nicht verfügbar.');
            $out[]=['id'=>$link['id'],'label'=>$link['label']!==''?$link['label']:$row['original_name'],'name'=>$row['original_name'],'bytes'=>(int)$row['bytes'],'sha256'=>$row['sha256']];
        }
        return $out;
    }
    /** All new files and the caller's page save commit together; rollback removes only these new copies. */
    public function uploadMany(array $files, ?callable $associate=null, ?UploadTransaction $context=null): mixed
    {
        $this->access->require('website','edit');
        if($context===null)return UploadTransaction::run($this->db,fn($tx)=>$this->uploadMany($files,$associate,$tx));
        return $context->segment($this->db,fn()=>$this->storeMany($files,$associate,$context));
    }

    private function storeMany(array $files,?callable $associate,UploadTransaction $context):mixed
    {
        $club=$this->access->require('website','edit')['club_id'];
        $context->require($this->db);
        if (!array_is_list($files) || count($files)>10) throw new InvalidArgumentException('Höchstens zehn PDF-Dateien gleichzeitig auswählen.');
        $total=0;$copies=[];
        $context->onRollback($this->db,static function()use(&$copies):void{foreach($copies as$copy)if(!is_link($copy['path'])&&is_file($copy['path']))@unlink($copy['path']);});
        foreach ($files as $file) {
            $source=$file['path']??null;$name=$file['name']??null;
            if (!is_string($source) || preg_match('~^[a-z][a-z0-9+.-]*://~i',$source) || is_link($source) || !is_file($source)) throw new InvalidArgumentException('PDF-Datei prüfen.');
            if (!is_string($name) || !preg_match('//u',$name) || preg_match('/[\x00-\x1f\x7f]/',$name)) throw new InvalidArgumentException('PDF-Dateiname prüfen.');
            $name=basename(str_replace('\\','/',$name));
            if ($name==='' || mb_strlen($name)>240 || strtolower(pathinfo($name,PATHINFO_EXTENSION))!=='pdf') throw new InvalidArgumentException('PDF-Datei mit gültigem Dateinamen auswählen.');
            $bytes=filesize($source);$total+=(int)$bytes;
            if ($bytes===false || $bytes<8 || $bytes>self::MAX_BYTES || $total>20*1024*1024) throw new InvalidArgumentException('PDF bis 10 MiB, zusammen bis 20 MiB auswählen.');
            if ((new \finfo(FILEINFO_MIME_TYPE))->file($source)!=='application/pdf') throw new InvalidArgumentException('Nur PDF-Dateien auswählen.');
            $in=fopen($source,'rb');
            if (!$in) throw new RuntimeException('PDF nicht lesbar.');
            try {$head=fread($in,8);fseek($in,max(0,$bytes-2048));$tail=stream_get_contents($in);} finally {fclose($in);}
            if (!preg_match('/^%PDF-(?:1\.[0-7]|2\.0)/D',$head) || !preg_match('/%%EOF\s*$/D',$tail)) throw new InvalidArgumentException('Vollständige PDF-Datei auswählen.');
            $id=bin2hex(random_bytes(16));$path=$this->root.'/'.$id.'.pdf';$out=fopen($path,'x+b');
            if (!$out) throw new RuntimeException('PDF konnte nicht gespeichert werden.');
            $copies[]=['id'=>$id,'name'=>$name,'path'=>$path,'bytes'=>$bytes];
            try { if (!chmod($path,0600)) throw new RuntimeException('Privaten Dokumentenspeicher prüfen.'); $in=fopen($source,'rb');if(!$in)throw new RuntimeException('PDF nicht lesbar.');try{$written=stream_copy_to_stream($in,$out,self::MAX_BYTES+1);}finally{fclose($in);} }
            finally { fclose($out); }
            if ($written!==$bytes || hash_file('sha256',$source)!==hash_file('sha256',$path)) throw new RuntimeException('PDF wurde während der Speicherung verändert.');
            $copies[array_key_last($copies)]['sha256']=hash_file('sha256',$path);
        }
        $operation=function()use($copies,$club,$associate,$total){
            $actor=$this->access->require('website','edit');
            if ($actor['club_id']!==$club) throw new \DomainException('Verein wurde geändert. Bitte neu laden.');
            DocumentQuota::assertUpload($this->db,$club,$total);
            foreach ($copies as $copy) Db::run($this->db,'INSERT INTO khcms_documents(id,club_id,original_name,bytes,sha256,created_by,created_at) VALUES(?,?,?,?,?,?,UTC_TIMESTAMP(6))',[$copy['id'],$club,$copy['name'],$copy['bytes'],$copy['sha256'],$actor['id']]);
            return $associate ? $associate($copies) : $copies;
        };
        return $operation();
    }
    public function file(string $id): array
    {
        $this->access->require('website','read');
        if (!preg_match('/^[a-f0-9]{32}$/D',$id)) throw new InvalidArgumentException('Dokument prüfen.');
        $row=Db::run($this->db,'SELECT * FROM khcms_documents WHERE id=? AND club_id=?',[$id,$this->access->club()])->fetch(PDO::FETCH_ASSOC);
        $path=$this->root.'/'.$id.'.pdf';
        if (!$row || is_link($path) || !is_file($path) || filesize($path)!==(int)$row['bytes'] || !hash_equals($row['sha256'],hash_file('sha256',$path))) throw new \DomainException('Dokument nicht verfügbar.');
        return ['path'=>$path,'name'=>$row['original_name'],'bytes'=>(int)$row['bytes'],'sha256'=>$row['sha256'],'mime'=>'application/pdf'];
    }
    public function publicFile(string $id): ?array
    {
        $this->access->require('website','read');
        if (!preg_match('/^[a-f0-9]{32}$/D',$id)) return null;
        $sql=" FROM khcms_documents d JOIN khcms_pages p ON p.club_id=d.club_id JOIN khcms_revisions r ON r.page_id=p.id AND r.club_id=p.club_id AND r.revision=p.published_revision
            WHERE d.id=? AND d.club_id=? AND ".PageTrash::activeSql()." AND NOT EXISTS(SELECT 1 FROM khcms_archives a WHERE a.page_id=p.id AND a.club_id=p.club_id)
            AND (JSON_CONTAINS(r.payload_json,JSON_OBJECT('id',d.id,'sha256',d.sha256,'bytes',d.bytes,'name',d.original_name),'$.document_links')
            OR JSON_CONTAINS(r.payload_json,JSON_OBJECT('id',d.id,'sha256',d.sha256,'bytes',d.bytes,'name',d.original_name),'$.custom_file_metadata'))
            AND COALESCE(JSON_UNQUOTE(JSON_EXTRACT(r.payload_json,'$.template_definition.renderer')),JSON_UNQUOTE(JSON_EXTRACT(r.payload_json,'$.template')))='standard'";
        [$policy,$parameters]=TemplatePolicy::readFilter($this->db,$this->access,'p',true);
        $sql.=' AND ('.$policy.') AND '.NewsChannels::where('website');$parameters=[$id,$this->access->club(),...$parameters];$cursor=null;
        do{
            $after=$cursor===null?'':' AND p.id COLLATE utf8mb4_bin>?';$args=$parameters;if($cursor!==null)$args[]=$cursor;
            $rows=Db::run($this->db,'SELECT p.id,p.published_revision'.$sql.$after.' ORDER BY p.id COLLATE utf8mb4_bin LIMIT 25',$args)->fetchAll(PDO::FETCH_ASSOC);
            foreach($rows as $row){
                $json=Db::run($this->db,'SELECT r.payload_json'.$sql.' AND p.id=? AND p.published_revision=?',[...$parameters,$row['id'],$row['published_revision']])->fetchColumn();
                if($json===false)continue;$page=FieldPolicy::publicSource($this->db,$this->access->club(),json_decode($json,true,512,JSON_THROW_ON_ERROR));unset($json);
                if(in_array($id,array_column($page['document_links']??[],'id'),true)||in_array($id,FieldValues::references($page,'file',true),true))return $this->file($id);
            }
            if($rows)$cursor=$rows[array_key_last($rows)]['id'];
        }while(count($rows)===25);
        return null;
    }
}
