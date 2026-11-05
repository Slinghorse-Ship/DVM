<?php declare(strict_types=1);
namespace Kulturhus\Native\Files;

use DomainException;
use PDO;
use Kulturhus\Native\Shared\{Access,Db};

/** Fail closed on every retained original, including internal collection values and copy sources. */
final class References
{
    public function __construct(private PDO $db,private Access $access){}
    public function inspect(string $id):array
    {
        AssetVersions::identifier($id);$club=$this->access->require('website','manage')['club_id'];
        if(!Db::run($this->db,'SELECT 1 FROM khcms_assets WHERE id=? AND club_id=?',[$id,$club])->fetchColumn())throw new DomainException('Datei nicht verfügbar.');
        $sources=[['khcms_revisions','payload_json','website'],['khcms_pages','draft_json','website'],['native_language_revisions','payload_json','language'],['native_language_pages','draft_json','language'],['khcms_tree_copy_items','source_json','copy'],['khcms_tree_copy_items','prepared_json','copy']];$found=[];
        foreach($sources as[$table,$column,$kind]){
            if(!self::table($this->db,$table))continue;
            $sql='SELECT 1 FROM '.$table.' s JOIN khcms_asset_revisions r ON r.club_id=s.club_id AND r.asset_id=? WHERE s.club_id=? AND s.'.$column." IS NOT NULL AND JSON_SEARCH(s.$column,'one',r.id) IS NOT NULL LIMIT 1";
            if(Db::run($this->db,$sql,[$id,$club])->fetchColumn())$found[$kind]=true;
        }
        return ['referenced'=>$found!==[],'sources'=>array_keys($found)];
    }
    public function assertUnused(string $id):void
    {if($this->inspect($id)['referenced'])throw new DomainException('Datei wird in einem gespeicherten Inhalt oder einer Originalfassung verwendet.');}
    /** Existing image removal must also retain each explicitly saved image revision. */
    public static function imageReferenced(PDO $db,string $club,string $mediaId):bool
    {
        if(!self::table($db,'khcms_asset_revisions'))return false;
        return(bool)Db::run($db,"SELECT 1 FROM khcms_asset_revisions r JOIN khcms_assets a ON a.id=r.asset_id AND a.club_id=r.club_id WHERE r.club_id=? AND r.source_kind='media' AND r.source_id=? AND a.state NOT IN ('purged','detached') LIMIT 1",[$club,$mediaId])->fetchColumn();
    }
    private static function table(PDO $db,string $table):bool
    {return(bool)Db::run($db,'SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND TABLE_TYPE=\'BASE TABLE\'',[$table])->fetchColumn();}
}
