<?php declare(strict_types=1);
namespace Kulturhus\Native\Communication;
use DomainException;
use PDO;
use Kulturhus\Native\Shared\{Access,Db};

/** Six metadata summaries; private event targets and credentials stay encrypted. */
final class MetaWebhookCatalogue
{
    public function __construct(private PDO $db){}
    public function page(Access $access,array $input=[]):array
    {
        $access->require('social','manage');$page=$input['webhook_page']??'1';$focus=$input['webhook_focus']??'';
        if((!is_int($page)&&!is_string($page))||!preg_match('/\A[1-9][0-9]{0,6}\z/D',(string)$page)||(int)$page>1000000||!is_string($focus)||($focus!==''&&!preg_match('/\A[a-f0-9]{32}\z/D',$focus)))throw new DomainException('Eingangsseite prüfen.');
        $club=$access->club();$total=(int)Db::run($this->db,'SELECT COUNT(*) FROM native_meta_webhook_receipts WHERE club_id=?',[$club])->fetchColumn();$pages=max(1,(int)ceil($total/6));$page=min($pages,(int)$page);
        if($focus!==''){$target=Db::run($this->db,'SELECT received_at FROM native_meta_webhook_receipts WHERE club_id=? AND id=?',[$club,$focus])->fetchColumn();if($target===false)throw new DomainException('Eigenen Eingang auswählen.');$before=(int)Db::run($this->db,'SELECT COUNT(*) FROM native_meta_webhook_receipts WHERE club_id=? AND (received_at>? OR(received_at=? AND id>?))',[$club,$target,$target,$focus])->fetchColumn();$page=intdiv($before,6)+1;}
        $rows=Db::run($this->db,'SELECT id,channel,kind,state,received_at FROM native_meta_webhook_receipts WHERE club_id=? ORDER BY received_at DESC,id DESC LIMIT 6 OFFSET '.(($page-1)*6),[$club])->fetchAll();$access->require('social','manage');if($access->club()!==$club)throw new DomainException('Vereinszuordnung geändert.');return compact('rows','total','pages','page','focus');
    }
}
