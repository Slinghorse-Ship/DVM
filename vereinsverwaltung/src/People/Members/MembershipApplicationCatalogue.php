<?php declare(strict_types=1);
namespace Kulturhus\Verein;
use PDO;
use DomainException;

/** Current board-owned counted summaries; original review and consent remain in MembershipApplications. */
final class MembershipApplicationCatalogue
{
    public function __construct(private PDO $db,private string $actorId){}
    public function page(array $input=[]):array
    {
        $a=BoardAccess::require($this->db,$this->actorId);
        $status=$input['status']??'pending';$term=$input['applications_q']??'';$page=$input['applications_page']??'1';
        if(!is_string($status)||!in_array($status,['pending','accepted','rejected',''],true)||!is_string($term)||!mb_check_encoding($term,'UTF-8')||mb_strlen($term)>150||preg_match('/[\x00-\x1f\x7f]/',$term)||(!is_string($page)&&!is_int($page))||!preg_match('/\A[1-9][0-9]{0,5}\z/D',(string)$page))throw new DomainException('Suchangaben und Antragsseite prüfen.');
        $term=trim($term);$page=(int)$page;
        $first="COALESCE(JSON_UNQUOTE(JSON_EXTRACT(answers,'$.first_name')),'')";$last="COALESCE(JSON_UNQUOTE(JSON_EXTRACT(answers,'$.last_name')),'')";$email="COALESCE(JSON_UNQUOTE(JSON_EXTRACT(answers,'$.email')),'')";
        $where=' WHERE club_id=?';$args=[$a['club_id']];
        if($status!==''){$where.=' AND status=?';$args[]=$status;}
        if($term!==''){$where.=" AND LOCATE(LOWER(?),LOWER(CONCAT($first,' ',$last,CHAR(10 USING utf8mb4),$email)))>0";$args[]=$term;}
        $q=$this->db->prepare('SELECT COUNT(*) FROM membership_applications'.$where);$q->execute($args);$total=(int)$q->fetchColumn();$pages=max(1,(int)ceil($total/6));$page=min($page,$pages);
        $q=$this->db->prepare("SELECT id,status,submitted_at,version,$first AS first_name,$last AS last_name,$email AS email FROM membership_applications".$where.' ORDER BY submitted_at DESC,id DESC LIMIT 6 OFFSET '.(($page-1)*6));$q->execute($args);
        return['rows'=>$q->fetchAll(PDO::FETCH_ASSOC),'total'=>$total,'page'=>$page,'pages'=>$pages,'status'=>$status,'q'=>$term];
    }
}
