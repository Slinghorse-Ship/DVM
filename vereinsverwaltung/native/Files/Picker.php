<?php declare(strict_types=1);
namespace Kulturhus\Native\Files;

use InvalidArgumentException;
use PDO;
use Kulturhus\Native\Shared\{Access,Db};

/** Existing PDFs and current general-file heads share one bounded picker; stored old heads stay selectable. */
final class Picker
{
    public function __construct(private PDO $db,private Access $access){}
    public function listing(string $query='',int $page=1,string $selected=''):array
    {
        if(!preg_match('//u',$query)||mb_strlen($query)>120||preg_match('/[\x00-\x1f\x7f]/',$query)||$page<1||$page>999999||($selected!==''&&!preg_match('/\A[a-f0-9]{32}\z/D',$selected)))throw new InvalidArgumentException('Dateisuche und ausgewählte Dateifassung prüfen.');
        return Db::atomic($this->db,function()use($query,$page,$selected):array{
            $club=$this->access->require('website','read')['club_id'];$documents='khcms_documents d';$newsletter=(bool)Db::run($this->db,"SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='native_communication_documents'")->fetchColumn();$ownDocs=$newsletter?' WHERE NOT EXISTS(SELECT 1 FROM native_communication_documents cd WHERE cd.club_id=d.club_id AND cd.document_id=d.id)':'';
            $assetLabel="CONCAT(JSON_UNQUOTE(JSON_EXTRACT(r.metadata_json,'$.title')),' · ',r.original_name,' · Fassung ',r.revision)";
            $from="(SELECT d.id,d.club_id,CONVERT(d.original_name USING utf8mb4) COLLATE utf8mb4_bin AS label,CONVERT(d.original_name USING utf8mb4) COLLATE utf8mb4_bin AS search_text FROM $documents$ownDocs UNION ALL SELECT r.id,r.club_id,CONVERT($assetLabel USING utf8mb4) COLLATE utf8mb4_bin AS label,CONVERT(CONCAT($assetLabel,' ',JSON_EXTRACT(r.metadata_json,'$.tags')) USING utf8mb4) COLLATE utf8mb4_bin AS search_text FROM khcms_assets a JOIN khcms_asset_revisions r ON r.id=a.head_id AND r.asset_id=a.id AND r.club_id=a.club_id WHERE a.kind='file' AND a.state='active') p";
            $where='p.club_id=?';$parameters=[$club];if($query!==''){$where.=" AND LOWER(p.search_text) COLLATE utf8mb4_bin LIKE LOWER(?) COLLATE utf8mb4_bin ESCAPE '!'";$parameters[]='%'.strtr($query,['!'=>'!!','%'=>'!%','_'=>'!_']).'%';}
            $total=(int)Db::run($this->db,'SELECT COUNT(*) FROM '.$from.' WHERE '.$where,$parameters)->fetchColumn();$last=max(1,(int)ceil($total/25));$page=min($page,$last);$rows=Db::run($this->db,'SELECT p.id,p.label FROM '.$from.' WHERE '.$where.' ORDER BY LOWER(p.label) COLLATE utf8mb4_bin,p.id LIMIT 25 OFFSET '.(($page-1)*25),$parameters)->fetchAll();$original=null;
            if($selected!==''){
                $row=Db::run($this->db,"SELECT r.id,$assetLabel AS label FROM khcms_asset_revisions r JOIN khcms_assets a ON a.id=r.asset_id AND a.club_id=r.club_id WHERE r.id=? AND r.club_id=? AND a.kind='file' AND a.state='active'",[$selected,$club])->fetch();
                if(!$row)$row=Db::run($this->db,'SELECT d.id,d.original_name AS label FROM '.$documents.($ownDocs!==''?$ownDocs.' AND ':' WHERE ').'d.id=? AND d.club_id=?',[$selected,$club])->fetch();$original=$row?:null;
            }
            return compact('rows','total','page','last')+['selected'=>$original];
        });
    }
}
