<?php declare(strict_types=1);
namespace Kulturhus\Verein;

/** Preserve source permissions when a preview is copied into the document archive. */
final class DocumentProtection
{
    /** Read inherited protection before authorizing a move, even on older data. */
    public static function folderRestricted(\PDO $db,string $club,string $folder):bool
    {
        $seen=[];$restricted=false;
        while($folder!==''){
            if(isset($seen[$folder])||count($seen)>30)throw new \DomainException('Ungültige Ordnerstruktur.');$seen[$folder]=true;
            $q=$db->prepare('SELECT parent_id,admission_only FROM document_folders WHERE club_id=? AND id=?');$q->execute([$club,$folder]);$row=$q->fetch();
            if(!$row)throw new \DomainException('Ordner nicht verfügbar.');
            $restricted=$restricted||(bool)$row['admission_only'];$folder=(string)($row['parent_id']??'');
        }
        return $restricted;
    }
    /** Monotonic descendant protection, inside the caller's transaction. */
    public static function protectFolder(\PDO $db,string $club,string $folder):void
    {
        $q=$db->prepare('SELECT id,parent_id FROM document_folders WHERE club_id=?');$q->execute([$club]);$children=[];
        foreach($q as $row)$children[(string)($row['parent_id']??'')][]=$row['id'];
        $pending=[$folder];$seen=[];
        $folderUpdate=$db->prepare('UPDATE document_folders SET admission_only=1 WHERE club_id=? AND id=?');
        $documents=$db->prepare('UPDATE documents SET admission_only=1 WHERE club_id=? AND folder_id=? AND admission_only=0');
        while($pending){$id=array_pop($pending);if(isset($seen[$id]))continue;$seen[$id]=true;
            $folderUpdate->execute([$club,$id]);$documents->execute([$club,$id]);foreach($children[$id]??[] as $child)$pending[]=$child;
        }
    }
    public static function previewScopes(array $snapshot):array
    {
        $scopes=['settings'];$values=$snapshot['values']??null;
        // Older or incomplete snapshots cannot establish that finance data was absent.
        if(!is_array($values))return ['finance','settings'];
        foreach(['VEREINS_IBAN','VEREINS_BIC','KONTOINHABER','GLAEUBIGER_ID','PAYPAL_LINK'] as $key){
            if(!array_key_exists($key,$values)||!is_string($values[$key])||trim($values[$key])!=='')return ['finance','settings'];
        }
        return $scopes;
    }
}
