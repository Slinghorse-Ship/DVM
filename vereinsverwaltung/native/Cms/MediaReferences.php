<?php declare(strict_types=1);
namespace Kulturhus\Native\Cms;

use DomainException;
use InvalidArgumentException;
use PDO;
use Kulturhus\Native\Shared\{Access,Db};

/** Current global dependency observation. A read result never authorises a later deletion. */
final class MediaReferences
{
    public function __construct(private PDO $db,private Access $access){}

    public function inspect(string $id):array
    {
        if(!preg_match('/\A[a-f0-9]{32}\z/D',$id))throw new InvalidArgumentException('Bildkennung prüfen.');
        return Db::atomic($this->db,function()use($id):array{
            $actor=$this->access->require('website','manage');$club=$actor['club_id'];
            if(!Db::run($this->db,'SELECT id FROM khcms_media WHERE club_id=? AND id=? FOR SHARE',[$club,$id])->fetchColumn())throw new DomainException('Bild nicht verfügbar.');
            $columns=Db::run($this->db,"SELECT c.TABLE_NAME,c.COLUMN_NAME,c.DATA_TYPE FROM information_schema.COLUMNS c WHERE c.TABLE_SCHEMA=DATABASE() AND EXISTS(SELECT 1 FROM information_schema.COLUMNS owner WHERE owner.TABLE_SCHEMA=c.TABLE_SCHEMA AND owner.TABLE_NAME=c.TABLE_NAME AND owner.COLUMN_NAME='club_id') AND (c.DATA_TYPE='json' OR (c.DATA_TYPE IN ('text','mediumtext','longtext','varchar') AND (c.COLUMN_NAME LIKE '%\\_json' OR c.COLUMN_NAME IN ('payload','snapshot','metadata','configuration','settings'))) OR c.COLUMN_NAME IN ('media_id','media_ref','image_media_id','logo_id','photo_id','avatar_id','cover_id')) ORDER BY c.TABLE_NAME,c.ORDINAL_POSITION")->fetchAll();
            $sources=[];
            foreach($columns as$column){
                $table=$column['TABLE_NAME'];$name=$column['COLUMN_NAME'];
                if(!preg_match('/\A[a-z][a-z0-9_]*\z/D',$table)||!preg_match('/\A[a-z][a-z0-9_]*\z/D',$name))throw new DomainException('Bildverwendungen konnten nicht vollständig geprüft werden.');
                $sources[$table][]=['name'=>$name,'json'=>$column['DATA_TYPE']==='json'||str_ends_with($name,'_json')||in_array($name,['payload','snapshot','metadata','configuration','settings'],true)];
            }
            $records=0;$unknown=0;
            foreach($sources as$table=>$fields){
                if(in_array($table,['khcms_media_archives','khcms_media_archive_history'],true))continue;
                $matches=[];$invalid=[];$parameters=[];
                foreach($fields as$field){
                    $name='`'.$field['name'].'`';
                    if($field['json']){
                        // MySQL canonical JSON decodes escaped characters in both keys and values.
                        // A malformed retained original blocks completion instead of being skipped.
                        $matches[]='CASE WHEN '.$name.' IS NULL THEN 0 WHEN JSON_VALID('.$name.') THEN LOCATE(CAST(? AS BINARY),CAST(CAST('.$name.' AS JSON) AS CHAR CHARACTER SET utf8mb4))>0 ELSE 0 END';
                        $invalid[]='('.$name.' IS NOT NULL AND NOT JSON_VALID('.$name.'))';
                    }else $matches[]='CAST('.$name.' AS BINARY)=CAST(? AS BINARY)';
                    $parameters[]=$id;
                }
                $where='CAST(club_id AS BINARY)=CAST(? AS BINARY)';$parameters[]=$club;
                // The selected asset's own paths, descriptions and bookkeeping are not another use.
                if($table==='khcms_media'){$where.=' AND id<>?';$parameters[]=$id;}
                if(in_array($table,['khcms_media_metadata','khcms_media_metadata_revisions'],true)){$where.=' AND media_id<>?';$parameters[]=$id;}
                if(in_array($table,['khcms_media_removals','khcms_media_removal_states','khcms_media_removal_history'],true)){$where.=' AND media_id<>?';$parameters[]=$id;}
                $row=Db::run($this->db,'SELECT COALESCE(SUM('.implode(' OR ',$matches).'),0) AS referenced,COALESCE(SUM('.($invalid?implode(' OR ',$invalid):'0').'),0) AS unreadable FROM `'.$table.'` WHERE '.$where,$parameters)->fetch();
                $records+=(int)$row['referenced'];$unknown+=(int)$row['unreadable'];
            }
            if($this->access->require('website','manage')['club_id']!==$club)throw new DomainException('Bildverwendungszugang geändert.');
            return ['referenced'=>$records>0,'complete'=>$unknown===0,'records'=>$records,'unreadable'=>$unknown];
        });
    }
}
