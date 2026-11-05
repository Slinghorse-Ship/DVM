<?php declare(strict_types=1);
namespace Kulturhus\Verein;
use PDO;use DomainException;

/** Original invoice eligibility and word search with counted six-document SQL pages. */
final class InvoiceIntakeCatalogue
{
    public function __construct(private PDO $db,private string $actor){}
    public function page(array $input=[]):array
    {
        $a=Permissions::finance($this->db,$this->actor);$documents=new Documents($this->db,$this->actor);
        $status=$input['status']??'open';$term=$input['inbox_q']??'';$year=$input['inbox_year']??'';$page=$input['inbox_page']??'1';
        if(!is_string($status)||!in_array($status,['open','assigned','archived'],true)||!is_string($term)||!mb_check_encoding($term,'UTF-8')||mb_strlen($term)>150||preg_match('/[\x00-\x1f\x7f]/',$term)||!is_string($year)||($year!==''&&!preg_match('/\A[0-9]{4}\z/D',$year))||(!is_string($page)&&!is_int($page))||!preg_match('/\A[1-9][0-9]{0,5}\z/D',(string)$page))throw new DomainException('Rechnungssuche, Jahr, Stand und Seite prüfen.');
        $term=trim($term);$page=(int)$page;$normal=[];foreach(array_keys(DocumentCatalog::SCOPES)as$scope)if($documents->canScope($scope))$normal[]=$scope;
        $board=BoardAccess::allowed($this->db,$this->actor);$admission=$board?array_values(array_unique([...$normal,'members'])):[];
        $normalFolders=$documents->readableFolderIds();$admissionFolders=$board?$documents->readableFolderIds(true):[];
        $args=[$a['club_id'],json_encode($normal,JSON_THROW_ON_ERROR),json_encode($normalFolders,JSON_THROW_ON_ERROR),json_encode($admission,JSON_THROW_ON_ERROR),json_encode($admissionFolders,JSON_THROW_ON_ERROR)];
        $where=" WHERE d.club_id=? AND BINARY d.origin='upload' AND BINARY d.kind='receipt' AND BINARY d.state IN ('active','archived') AND JSON_TYPE(d.scopes)='ARRAY' AND JSON_CONTAINS(d.scopes,'\"finance\"') AND ((d.admission_only=0 AND JSON_CONTAINS(CAST(? AS JSON),CAST(d.scopes AS JSON)) AND JSON_CONTAINS(CAST(? AS JSON),JSON_QUOTE(d.folder_id))) OR (d.admission_only<>0 AND JSON_CONTAINS(CAST(? AS JSON),CAST(d.scopes AS JSON)) AND JSON_CONTAINS(CAST(? AS JSON),JSON_QUOTE(d.folder_id)))) AND COALESCE((SELECT BINARY h.action FROM document_history h WHERE h.club_id=d.club_id AND h.document_id=d.id AND h.action IN ('trashed','trash-restored') ORDER BY h.sequence DESC LIMIT 1),'')<>'trashed'";
        $join=' FROM documents d JOIN document_files f ON f.club_id=d.club_id AND f.document_id=d.id AND f.revision=(SELECT MAX(v.revision) FROM document_files v WHERE v.club_id=d.club_id AND v.document_id=d.id)';
        $entry="EXISTS(SELECT 1 FROM document_links l JOIN document_sources s ON s.club_id=l.club_id AND s.source_type=l.source_type AND s.source_id=l.source_id WHERE l.club_id=d.club_id AND l.document_id=d.id AND l.source_type='entry')";
        $state="CASE WHEN d.state='archived' THEN 'archived' WHEN $entry THEN 'assigned' ELSE 'open' END";
        $q=$this->db->prepare('SELECT '.$state.' AS inbox_status,COUNT(*) AS total'.$join.$where.' GROUP BY inbox_status');$q->execute($args);$counts=['open'=>0,'assigned'=>0,'archived'=>0];foreach($q->fetchAll(PDO::FETCH_ASSOC)as$row)$counts[$row['inbox_status']]=(int)$row['total'];
        $q=$this->db->prepare('SELECT DISTINCT LEFT(d.document_date,4) AS year'.$join.$where.' ORDER BY year DESC');$q->execute($args);$years=[];foreach($q->fetchAll(PDO::FETCH_COLUMN)as$value)if(preg_match('/\A[0-9]{4}\z/D',$value))$years[$value]=$value;
        $where.=' AND '.$state.'=?';$args[]=$status;if($year!==''){$where.=' AND LEFT(d.document_date,4)=?';$args[]=$year;}
        $words=preg_split('/\s+/u',mb_strtolower($term),-1,PREG_SPLIT_NO_EMPTY)?:[];
        foreach($words as$word){
            $where.=" AND (LOCATE(?,LOWER(CONCAT(d.title,' ',f.name,' ',d.document_date)) COLLATE utf8mb4_bin)>0 OR EXISTS(SELECT 1 FROM invoice_mail_imports m WHERE m.club_id=d.club_id AND m.document_id=d.id AND LOCATE(?,LOWER(CONCAT(m.sender,' ',m.subject)) COLLATE utf8mb4_bin)>0) OR EXISTS(SELECT 1 FROM document_links l JOIN document_sources s ON s.club_id=l.club_id AND s.source_type=l.source_type AND s.source_id=l.source_id WHERE l.club_id=d.club_id AND l.document_id=d.id AND l.source_type='entry' AND LOCATE(?,LOWER(s.label) COLLATE utf8mb4_bin)>0))";
            array_push($args,$word,$word,$word);
        }
        $q=$this->db->prepare('SELECT COUNT(*)'.$join.$where);$q->execute($args);$total=(int)$q->fetchColumn();$pages=max(1,(int)ceil($total/6));$page=min($page,$pages);
        $q=$this->db->prepare('SELECT d.id'.$join.$where.' ORDER BY d.document_date DESC,d.created_at DESC,d.id LIMIT 6 OFFSET '.(($page-1)*6));$q->execute($args);$ids=$q->fetchAll(PDO::FETCH_COLUMN);$inbox=new InvoiceInbox($this->db,$this->actor);$rows=[];foreach($ids as$id)$rows[]=$inbox->document($id);
        return ['rows'=>$rows,'query'=>$term,'year'=>$year,'years'=>$years,'status'=>$status,'counts'=>$counts,'total'=>$total,'page'=>$page,'pages'=>$pages,'first'=>$total?($page-1)*6+1:0,'last'=>min($page*6,$total)];
    }
}
