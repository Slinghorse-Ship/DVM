<?php declare(strict_types=1);
namespace Kulturhus\Native\Cms;
use InvalidArgumentException;
use Kulturhus\Native\Shared\{Access, Db};
use PDO;

/** One bounded hierarchy walk; materialized edges avoid a full JSON scan for every leaf. */
final class TreeCopySources
{
    public const LIMIT = 500;
    public const JOB_LIMIT = 5000;
    public const BYTE_LIMIT = 64 * 1024 * 1024;
    public function __construct(private PDO $db, private Access $access) {}

    public function read(string $rootId,int $expectedVersion,string $basis,int $maximum=self::LIMIT):array
    {
        if(!in_array($basis,['draft','published'],true)||$maximum<1||$maximum>self::JOB_LIMIT)throw new InvalidArgumentException('Unbekannte Kopiergrundlage oder ungültige Teilbaumgrenze.');
        $root=(new Pages($this->db,$this->access))->get($rootId);
        $club=$this->access->club();
        if($root['version']!==$expectedVersion)throw new Conflict('Die Quelle wurde inzwischen geändert. Vor dem Kopieren neu laden.');
        if($root['trashed'])throw new Conflict('Quelle zuerst aus dem Papierkorb zurückholen.');
        if($basis==='published'&&!$root['published_revision'])throw new Conflict('Die Quelle hat keine veröffentlichte Fassung.');
        $from='khcms_pages p';$json='p.draft_json';
        if($basis==='published'){$from.=' JOIN khcms_revisions r ON r.page_id=p.id AND r.club_id=p.club_id AND r.revision=p.published_revision';$json='r.payload_json';}
        // LIMIT prevents merging edges back into the recursive join. MySQL can index its materialized parent_id.
        // The recursive LIMIT also bounds work for a much larger selected source, before payloads are loaded.
        $sql="WITH RECURSIVE edges AS (
            SELECT p.id COLLATE utf8mb4_bin AS id,p.version,p.slug COLLATE utf8mb4_bin AS slug,
                CAST(JSON_UNQUOTE(JSON_EXTRACT($json,'$.parent_id')) AS CHAR(32) CHARACTER SET utf8mb4) COLLATE utf8mb4_bin AS parent_id
            FROM $from WHERE p.club_id=? AND ".PageTrash::activeSql()." LIMIT 18446744073709551615
        ), walk AS (
            SELECT id,version,slug,parent_id,0 AS depth,CAST(CONCAT(',',id,',') AS CHAR(3300) CHARACTER SET utf8mb4) COLLATE utf8mb4_bin AS seen,0 AS cycle
            FROM edges WHERE id=?
            UNION ALL
            SELECT child.id,child.version,child.slug,child.parent_id,parent.depth+1,
                CONCAT(parent.seen,child.id,','),LOCATE(CONCAT(',',child.id,','),parent.seen)>0
            FROM edges child JOIN walk parent ON child.parent_id=parent.id
            WHERE parent.depth<100 AND parent.cycle=0 LIMIT ".($maximum+1)."
        ) SELECT id,version,depth,cycle FROM walk ORDER BY depth,slug COLLATE utf8mb4_bin,id";
        $rows=Db::run($this->db,$sql,[$club,$rootId])->fetchAll(PDO::FETCH_ASSOC);
        if(count($rows)>$maximum)throw new InvalidArgumentException('Teilbaum überschreitet '.$maximum.' Seiten. Einen kleineren Teilbaum oder den fortsetzbaren Kopierauftrag wählen.');
        TemplatePolicy::assertAllReadable($this->db,$this->access,array_column($rows,'id'));
        $entries=[];$bytes=0;
        foreach(array_chunk($rows,25)as $group){
            $ids=array_column($group,'id');
            $savedRows=Db::run($this->db,"SELECT p.id,$json AS payload_json FROM $from WHERE p.club_id=? AND p.id IN (".implode(',',array_fill(0,count($ids),'?')).')',[$club,...$ids])->fetchAll(PDO::FETCH_ASSOC);
            $savedById=array_column($savedRows,'payload_json','id');unset($savedRows);
            foreach($group as $row){
            if((int)$row['cycle']!==0||(int)$row['depth']>=100||isset($entries[$row['id']]))throw new InvalidArgumentException('Teilbaum enthält einen Kreis oder mehr als 100 Ebenen.');
            {
                $saved=$savedById[$row['id']]??null;
                if(!is_string($saved))throw new \RuntimeException('Originalrevision fehlt.');
                $payload=json_decode($saved,true,512,JSON_THROW_ON_ERROR);
            }
            if(!empty($payload['field_definitions']))FieldPolicy::assertCopy($this->db,$this->access,$payload);
            $bytes+=strlen(json_encode($payload,JSON_THROW_ON_ERROR));
            if($bytes>self::BYTE_LIMIT)throw new InvalidArgumentException('Teilbaum auf höchstens 64 MiB gespeicherten Seiteninhalt eingrenzen. Bilder bleiben im gemeinsamen Bestand.');
            $entries[$row['id']]=['id'=>$row['id'],'version'=>(int)$row['version'],'payload'=>$payload];
            unset($savedById[$row['id']]);
            }
        }
        if(!isset($entries[$rootId]))throw new Conflict('Die gewählte Originalhierarchie ist nicht mehr verfügbar.');
        return $entries;
    }
}
