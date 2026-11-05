<?php
declare(strict_types=1);

namespace Kulturhus\Native\Cms;

use InvalidArgumentException;
use Kulturhus\Native\Shared\Access;
use Kulturhus\Native\Shared\Db;
use PDO;
use RuntimeException;

/** Re-encodes accepted image pixels; original uploaded bytes are never served or retained. */
final class Media
{
    private readonly string $root;
    private const SIZES = ['original' => 2400, 'large' => 1920, 'medium' => 960, 'thumb' => 320];
    private const TYPES = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];

    public function __construct(private readonly PDO $db, private readonly Access $access, string $root)
    {
        if (preg_match('~^[a-z][a-z0-9+.-]*://~i', $root) || is_link($root)) {
            throw new InvalidArgumentException('Der Medienspeicher muss ein lokales Verzeichnis sein.');
        }
        if (!is_dir($root) && !@mkdir($root, 0700, true) && !is_dir($root)) {
            throw new RuntimeException('Medienspeicher konnte nicht angelegt werden.');
        }
        $resolved = realpath($root);
        if ($resolved === false) {
            throw new RuntimeException('Medienspeicher konnte nicht aufgelöst werden.');
        }
        $this->root = $resolved;
        $webroots = [dirname(__DIR__, 2) . '/public', $_SERVER['DOCUMENT_ROOT'] ?? ''];
        foreach ($webroots as $webroot) {
            if ($webroot !== '' && realpath($webroot) !== false && $this->inside($resolved, (string) realpath($webroot))) {
                throw new InvalidArgumentException('Medien müssen außerhalb des Webroots gespeichert werden.');
            }
        }
    }

    /** The HTTP adapter must first verify UPLOAD_ERR_OK and is_uploaded_file(). */
    public function upload(string $sourcePath, string $originalName, string $alt, ?string $derivedFrom = null, ?callable $associate = null, ?string $uploadId = null, ?UploadTransaction $context = null): array
    {
        $this->access->require('website','edit');
        return CameraPhotos::withFile($sourcePath,$originalName,function(string $camera)use($originalName,$alt,$derivedFrom,$associate,$uploadId,$context):array{return RasterPhotos::withFile($camera,function(string $prepared)use($originalName,$alt,$derivedFrom,$associate,$uploadId,$context):array{
            if($context!==null)return $context->segment($this->db,fn()=>$this->storeUpload($prepared,$originalName,$alt,$derivedFrom,$associate,$uploadId,$context));
            return $this->storeUpload($prepared,$originalName,$alt,$derivedFrom,$associate,$uploadId,null);
        });});
    }

    private function storeUpload(string $sourcePath,string $originalName,string $alt,?string $derivedFrom,?callable $associate,?string $uploadId,?UploadTransaction $context):array
    {
        $initialActor = $this->access->require('website', 'edit');
        if($context!==null)$context->require($this->db);
        if ($uploadId !== null) {
            if (!preg_match('/\A[a-f0-9]{32}\z/D', $uploadId)) throw new InvalidArgumentException('Upload-Kennung prüfen.');
            if (Db::run($this->db, 'SELECT 1 FROM khcms_media WHERE id=?', [$uploadId])->fetchColumn()) throw new Conflict('Upload-Kennung bereits verwendet. Bestand prüfen.');
        }
        if ($associate !== null && $this->db->inTransaction() && $context===null) throw new \LogicException('Atomare Bildzuordnung benötigt eine eigene Uploadtransaktion.');
        $uploadClub = (string) $initialActor['club_id'];
        $original = null;
        if ($derivedFrom !== null) {
            if (!preg_match('/\A[a-f0-9]{32}\z/D', $derivedFrom)) throw new InvalidArgumentException('Ursprüngliches Bild prüfen.');
            $original = $this->record($derivedFrom);
        }
        if (!extension_loaded('gd') || !extension_loaded('fileinfo')) {
            throw new RuntimeException('Sichere Bildverarbeitung benötigt GD und Fileinfo.');
        }
        if (preg_match('~^[a-z][a-z0-9+.-]*://~i', $sourcePath) || is_link($sourcePath) || !is_file($sourcePath)) {
            throw new InvalidArgumentException('Keine lokale Bilddatei.');
        }
        $bytes = filesize($sourcePath);
        if ($bytes === false || $bytes < 1 || $bytes > 12 * 1024 * 1024) {
            throw new InvalidArgumentException('Bilder müssen zwischen 1 Byte und 12 MiB groß sein.');
        }
        if (!preg_match('//u', $alt) || mb_strlen($alt, 'UTF-8') > 500 || preg_match('/[\x00-\x1F\x7F]/', $alt)) {
            throw new InvalidArgumentException('Alternativtext: maximal 500 UTF-8-Zeichen ohne Steuerzeichen.');
        }
        $alt = trim($alt);
        if ($alt === '') {
            $label=preg_replace('/[\x00-\x1f\x7f]/u','',basename(str_replace('\\','/',$originalName)));
            $alt=is_string($label)&&preg_match('//u',$label)?mb_substr(pathinfo($label,PATHINFO_FILENAME),0,500):'';
            if(trim($alt)==='')$alt='Bild';
        }
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($sourcePath);
        $info = @getimagesize($sourcePath);
        if (!is_string($mime) || !isset(self::TYPES[$mime]) || !$info || ($info['mime'] ?? '') !== $mime) {
            throw new InvalidArgumentException('Nur decodierbare JPEG-, PNG- und WebP-Bilder sind erlaubt.');
        }
        [$width, $height] = $info;
        if ($width < 1 || $height < 1 || $width > 12000 || $height > 12000 || $width * $height > 16000000) {
            throw new InvalidArgumentException('Bilddimensionen überschreiten die Grenze von 16 Megapixeln.');
        }
        $estimatedMemory = $width * $height * 8 + min($width, 2400) * min($height, 2400) * 8 + 16 * 1024 * 1024;
        $limit = $this->memoryLimit();
        // Revalidation may refill unused Zend pages after raster preparation.
        if($limit>0&&function_exists('gc_mem_caches'))gc_mem_caches();
        if ($limit > 0 && memory_get_usage(true) + $estimatedMemory > $limit) {
            throw new InvalidArgumentException('Das Bild ist für das verfügbare Arbeitsspeicherlimit zu groß.');
        }
        $image = match ($mime) {
            'image/jpeg' => @imagecreatefromjpeg($sourcePath),
            'image/png' => @imagecreatefrompng($sourcePath),
            'image/webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($sourcePath) : false,
        };
        if (!$image) {
            throw new InvalidArgumentException('Bilddaten konnten nicht vollständig decodiert werden.');
        }
        if ($mime === 'image/jpeg') {
            $image = ImageOrientation::apply($image, $sourcePath);
            $width = imagesx($image);
            $height = imagesy($image);
        }
        $id = $uploadId ?? bin2hex(random_bytes(16));
        $clubDirectory = $this->root . DIRECTORY_SEPARATOR . hash('sha256', $uploadClub);
        if (is_link($clubDirectory) || (!is_dir($clubDirectory) && !@mkdir($clubDirectory, 0700) && !is_dir($clubDirectory))) {
            unset($image);
            throw new RuntimeException('Vereins-Medienspeicher ist nicht verfügbar.');
        }
        $directory = $clubDirectory . DIRECTORY_SEPARATOR . $id;
        if (!@mkdir($directory, 0700)) {
            unset($image);
            throw new RuntimeException('Bildspeicher konnte nicht angelegt werden.');
        }
        $variants = [];
        $databaseOwned=false;
        $cleanup=static function()use($directory,$mime):void{
            if(is_link($directory))return;
            foreach(self::SIZES as$size=>$_edge){$path=$directory.DIRECTORY_SEPARATOR.$size.'.'.self::TYPES[$mime];if(is_file($path)&&!is_link($path))@unlink($path);}
            @rmdir($directory);
        };
        try {
            if($context!==null)$context->onRollback($this->db,$cleanup);
            foreach (self::SIZES as $size => $edge) {
                $scale = min(1.0, $edge / max($width, $height));
                $targetWidth = max(1, (int) round($width * $scale));
                $targetHeight = max(1, (int) round($height * $scale));
                $target = imagecreatetruecolor($targetWidth, $targetHeight);
                if (!$target) {
                    throw new RuntimeException('Bildvariante konnte nicht erzeugt werden.');
                }
                try {
                    if ($mime !== 'image/jpeg') {
                        imagealphablending($target, false);
                        imagesavealpha($target, true);
                    }
                    if (!imagecopyresampled($target, $image, 0, 0, 0, 0, $targetWidth, $targetHeight, $width, $height)) {
                        throw new RuntimeException('Bildvariante konnte nicht skaliert werden.');
                    }
                    $filename = $size . '.' . self::TYPES[$mime];
                    $path = $directory . DIRECTORY_SEPARATOR . $filename;
                    $written = match ($mime) {
                        'image/jpeg' => imagejpeg($target, $path, 86),
                        'image/png' => imagepng($target, $path, 6),
                        'image/webp' => imagewebp($target, $path, 84),
                    };
                    if (!$written || !is_file($path) || filesize($path) === 0) {
                        throw new RuntimeException('Bildvariante konnte nicht gespeichert werden.');
                    }
                    @chmod($path, 0600);
                    $variants[$size] = ['file' => $filename, 'width' => $targetWidth, 'height' => $targetHeight, 'bytes' => filesize($path)];
                } finally {
                    unset($target);
                }
            }
            $name = basename(str_replace('\\', '/', $originalName));
            $name = preg_match('//u', $name) ? preg_replace('/[\x00-\x1F\x7F]/', '', $name) : 'Bild';
            $name = mb_substr($name !== '' ? $name : 'Bild', 0, 240, 'UTF-8');
            $hash = hash_file('sha256', $directory . DIRECTORY_SEPARATOR . $variants['original']['file']);
            if ($original !== null) $variants['original']['derived_from'] = ['media_id'=>$original['id'], 'sha256'=>$original['sha256']];
            $operation=function () use ($id, $name, $alt, $mime, $variants, $hash, $uploadClub, $original, $associate): array {
                $actor = $this->access->require('website', 'edit');
                if ((string) $actor['club_id'] !== $uploadClub) {
                    throw new Conflict('Die Vereinszugehörigkeit hat sich während des Uploads geändert.');
                }
                MediaQuota::assertUpload($this->db, $uploadClub, array_sum(array_column($variants, 'bytes')));
                if ($original !== null) MediaAvailability::assertSelectable($this->db,$uploadClub,$original['id']);
                if ($original !== null && Db::run($this->db, 'SELECT sha256 FROM khcms_media WHERE club_id=? AND id=? FOR SHARE', [$uploadClub, $original['id']])->fetchColumn() !== $original['sha256']) {
                    throw new Conflict('Das ursprüngliche Bild ist nicht mehr verfügbar.');
                }
                Db::run($this->db, 'INSERT INTO khcms_media (id,club_id,original_name,alt,mime,width,height,variants_json,sha256,created_by,created_at) VALUES (?,?,?,?,?,?,?,?,?,?,UTC_TIMESTAMP(6))', [$id, $this->access->club(), $name, $alt, $mime, $variants['original']['width'], $variants['original']['height'], json_encode($variants, JSON_THROW_ON_ERROR), $hash, $actor['id']]);
                if ($associate !== null) $associate($this->record($id));
                return $this->record($id);
            };
            if($context!==null)return Db::atomic($this->db,$operation);
            // Existing composed callers retain their savepoint. Direct uploads own the commit and its acknowledgement.
            if($this->db->inTransaction())return Db::atomic($this->db,$operation);
            $databaseOwned=true;
            return UploadTransaction::run($this->db,function($tx)use($operation,$cleanup){$tx->onRollback($this->db,$cleanup);return $operation();});
        } catch (\Throwable $error) {
            // Shared cleanup belongs to its owning acknowledged rollback. An uncertain own commit keeps its possibly accepted originals.
            if($context===null&&!$databaseOwned)$cleanup();
            throw $error;
        } finally {
            unset($image);
        }
    }

    public function list(): array
    {
        $this->access->require('website', 'read');
        $rows = Db::run($this->db, 'SELECT m.* FROM khcms_media m WHERE m.club_id=? AND '.MediaAvailability::activeSql($this->db).' ORDER BY m.created_at DESC,m.id', [$this->access->club()])->fetchAll(PDO::FETCH_ASSOC);
        return array_map(fn (array $row): array => $this->present($row), $rows);
    }

    public function variant(string $id, string $size): array
    {
        $this->access->require('website', 'read');
        if (!isset(self::SIZES[$size]) || !preg_match('/\A[a-f0-9]{32}\z/D', $id)) {
            throw new InvalidArgumentException('Unbekannte Bildvariante.');
        }
        $record = $this->record($id);
        $variant = $record['variants'][$size] ?? null;
        if (!$variant || !isset(self::TYPES[$record['mime']])) {
            throw new RuntimeException('Bildvariante nicht verfügbar.');
        }
        $expectedFile = $size . '.' . self::TYPES[$record['mime']];
        if ($variant['file'] !== $expectedFile) {
            throw new RuntimeException('Ungültige gespeicherte Bildvariante.');
        }
        $directory = $this->root . DIRECTORY_SEPARATOR . hash('sha256', $this->access->club()) . DIRECTORY_SEPARATOR . $id;
        $path = realpath($directory . DIRECTORY_SEPARATOR . $expectedFile);
        if ($path === false || !$this->inside($path, $this->root) || is_link($directory) || is_link($directory . DIRECTORY_SEPARATOR . $expectedFile) || !is_file($path)) {
            throw new RuntimeException('Bilddatei nicht verfügbar.');
        }
        $metadata = (new MediaMetadata($this->db, $this->access))->get($id);
        return ['path' => $path, 'mime' => $record['mime'], 'width' => $variant['width'], 'height' => $variant['height'], 'bytes' => $variant['bytes'], 'alt' => $metadata['alt'], 'sha256' => hash_file('sha256', $path), 'name' => $record['original_name']];
    }

    /** Only assets referenced by at least one currently published page may be exposed publicly. */
    public function publicVariant(string $id, string $size, ?array $publication = null): ?array
    {
        $this->access->require('website', 'read');
        [$policy,$parameters]=TemplatePolicy::readFilter($this->db,$this->access,'p',true);
        $channels='('.NewsChannels::where('website')." OR (".NewsChannels::where('rss')." AND JSON_UNQUOTE(JSON_EXTRACT(r.payload_json,'$.media_ids[0]'))=pm.media_id))";
        $sql=' FROM khcms_published_media pm JOIN khcms_pages p ON p.id=pm.page_id AND p.club_id=pm.club_id JOIN khcms_revisions r ON r.page_id=p.id AND r.club_id=p.club_id AND r.revision=p.published_revision WHERE pm.club_id=? AND pm.media_id=? AND ('.$policy.') AND '.$channels;$parameters=[$this->access->club(),$id,...$parameters];
        if(isset($publication['id'])){if(!is_string($publication['id'])||!preg_match('/\A[a-f0-9]{32}\z/D',$publication['id']))return null;$sql.=' AND p.id=?';$parameters[]=$publication['id'];}
        $cursor=null;$authorized=false;$selected=[];
        do{
            $after=$cursor===null?'':' AND p.id COLLATE utf8mb4_bin>?';$args=$parameters;if($cursor!==null)$args[]=$cursor;
            $rows=Db::run($this->db,'SELECT p.id,p.published_revision'.$sql.$after.' ORDER BY p.id COLLATE utf8mb4_bin LIMIT 25',$args)->fetchAll(PDO::FETCH_ASSOC);
            foreach($rows as $row){
                // IDs are bounded; load just one immutable original rather than 25 large payloads.
                $json=Db::run($this->db,'SELECT r.payload_json'.$sql.' AND p.id=? AND p.published_revision=?',[...$parameters,$row['id'],$row['published_revision']])->fetchColumn();
                if($json===false)continue;$page=FieldPolicy::publicSource($this->db,$this->access->club(),json_decode($json,true,512,JSON_THROW_ON_ERROR));unset($json);
                $ids=NewsChannels::visible($page,'website')?[...($page['media_ids']??[]),...ContentSections::mediaIds($page),...FieldValues::references($page,'media',true)]:(NewsChannels::visible($page,'rss')?array_slice($page['media_ids']??[],0,1):[]);
                if(in_array($id,$ids,true)){$authorized=true;$selected=$page;break;}
                unset($page);
            }
            if($rows)$cursor=$rows[array_key_last($rows)]['id'];
        }while(!$authorized&&count($rows)===25);
        if(!$authorized){
            // A provider cancellation retains a real earlier publication even
            // though the ordinary page has been withdrawn. Reuse the event
            // catalogue's current source, archive and public policy checks.
            $selected=$this->cancelledPublication($id,$publication);
            if($selected===[])return null;
        }
        $metadata = (new MediaMetadata($this->db, $this->access))->get($id);
        if (!MediaMetadata::usable($metadata, 'website')) return null;
        return array_replace($this->variant($id, $size), MediaMetadata::original($this->db, $this->access->club(), $id, isset($publication['id'])?$selected:[]));
    }

    private function cancelledPublication(string $id,?array $publication):array
    {
        $sql=' FROM khcms_published_media pm JOIN khcms_events i ON i.club_id=pm.club_id AND i.page_id=pm.page_id WHERE pm.club_id=? AND pm.media_id=? AND i.is_notice=1';
        $parameters=[$this->access->club(),$id];
        if(isset($publication['id'])){$sql.=' AND i.page_id=?';$parameters[]=$publication['id'];}
        $events=new Events($this->db,$this->access->club(),$this->access);$cursor=null;
        do{
            $after=$cursor===null?'':' AND i.page_id COLLATE utf8mb4_bin>?';$args=$parameters;if($cursor!==null)$args[]=$cursor;
            $rows=Db::run($this->db,'SELECT i.page_id'.$sql.$after.' ORDER BY i.page_id COLLATE utf8mb4_bin LIMIT 25',$args)->fetchAll(PDO::FETCH_COLUMN);
            foreach($rows as$pageId){
                $page=$events->publicId($pageId,true);
                if(!$page||!$page['cancellation_notice'])continue;
                $ids=NewsChannels::visible($page,'website')?[...($page['media_ids']??[]),...ContentSections::mediaIds($page),...FieldValues::references($page,'media',true)]:(NewsChannels::visible($page,'rss')?array_slice($page['media_ids']??[],0,1):[]);
                if(in_array($id,$ids,true))return $page;
            }
            if($rows)$cursor=$rows[array_key_last($rows)];
        }while(count($rows)===25);
        return [];
    }

    private function record(string $id): array
    {
        MediaAvailability::assertSelectable($this->db,$this->access->club(),$id);
        $row = Db::run($this->db, 'SELECT * FROM khcms_media WHERE id=? AND club_id=?', [$id, $this->access->club()])->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new RuntimeException('Bild nicht gefunden.');
        }
        return $this->present($row);
    }

    private function present(array $row): array
    {
        $row['variants'] = json_decode($row['variants_json'], true, 512, JSON_THROW_ON_ERROR);
        $row['width'] = (int) $row['width'];
        $row['height'] = (int) $row['height'];
        unset($row['variants_json'], $row['club_id']);
        return $row;
    }

    private function inside(string $path, string $directory): bool
    {
        $path = str_replace('\\', '/', $path);
        $directory = rtrim(str_replace('\\', '/', $directory), '/');
        if (PHP_OS_FAMILY === 'Windows') {
            $path = strtolower($path);
            $directory = strtolower($directory);
        }
        return $path === $directory || str_starts_with($path, $directory . '/');
    }

    private function memoryLimit(): int
    {
        $limit = trim((string) ini_get('memory_limit'));
        if ($limit === '-1') {
            return -1;
        }
        $number = (int) $limit;
        return match (strtolower(substr($limit, -1))) {
            'g' => $number * 1024 * 1024 * 1024,
            'm' => $number * 1024 * 1024,
            'k' => $number * 1024,
            default => $number,
        };
    }
}
