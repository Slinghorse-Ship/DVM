<?php declare(strict_types=1);
namespace Kulturhus\Verein\Web;
use DomainException;
use PDO;
use Kulturhus\Verein\{Permissions,InvoiceApprovals};

/** Pure recovery controls for the existing invoice decision form. Never writes a decision. */
final class InvoiceReviewForm
{
    private const COMMANDS=['approval_read','approval_review','approval_new'];
    private const ACTIONS=['invoice-approve','invoice-approval-clarify','invoice-approval-withdraw'];
    public static function requested(array $post):bool{return (bool)array_intersect(self::COMMANDS,array_keys($post));}
    public static function handle(PDO $db,string $actor,array &$post,string $routeEntry):array
    {
        $commands=array_values(array_intersect(self::COMMANDS,array_keys($post)));
        if(count($commands)!==1||($post[$commands[0]]??null)!=='1')throw new DomainException('Einen angebotenen Prüfschritt ausdrücklich wählen.',422);
        foreach(['action','entry','request_key']as$field)if(!is_string($post[$field]??null))throw new DomainException('Das ursprüngliche Rechnungsformular vollständig öffnen.',422);
        if(!in_array($post['action'],self::ACTIONS,true)||$post['entry']===''||$routeEntry!==$post['entry']||!preg_match('/\A[a-f0-9]{32}\z/D',$post['request_key']))throw new DomainException('Das ursprüngliche Rechnungsformular vollständig öffnen.',422);
        foreach(['reason','snapshot_hash','decision_id']as$field)if(isset($post[$field])&&!is_string($post[$field]))throw new DomainException('Die ursprünglichen Prüfungsangaben vollständig beibehalten.',422);
        $own=!$db->inTransaction();$next=$post;
        try{
            if($own)$db->exec('START TRANSACTION');
            $identity=Permissions::finance($db,$actor);$service=new InvoiceApprovals($db,$actor);$current=$service->currentState($post['entry']);
            $q=$db->prepare('SELECT id,decision,reason,snapshot_hash,created_at FROM invoice_approvals WHERE club_id=? AND entry_id=? AND actor_id=? AND request_key=? LIMIT 1 FOR SHARE NOWAIT');
            $q->execute([$identity['club_id'],$post['entry'],$actor,$post['request_key']]);$first=$q->fetch()?:null;
            $mode=$commands[0];$active=false;
            if($mode==='approval_read')$notice=$first?'Früher gespeicherten Prüfschritt gelesen. Die Angaben dieses Formulars wurden nicht erneut gespeichert.':'Für dieses ursprüngliche Formular wurde noch kein gespeicherter Ausgang gelesen. Die Angaben bleiben erhalten.';
            else{
                Permissions::finance($db,$actor,true);if(!$service->canApprove())throw new DomainException('Für einen neuen Prüfschritt ist eine aktuelle bestätigte Zahlungsberechtigung erforderlich.',403);
                if($mode==='approval_new'&&!$first)throw new DomainException('Zuerst den früher gespeicherten Ausgang dieses Formulars lesen.',409);
                $available=match($post['action']){'invoice-approve'=>$current['can_approve'],'invoice-approval-clarify'=>$current['can_withdraw'],'invoice-approval-withdraw'=>$current['can_withdraw']&&($current['last']['decision']??'')==='approved'};
                if($mode==='approval_new'&&!$available)throw new DomainException('Dieser Prüfschritt ist beim aktuellen Rechnungsstand nicht verfügbar.',409);
                if($first&&$mode==='approval_review')$notice='Für diese ursprüngliche Kennung ist bereits eine frühere Entscheidung gespeichert. Den früheren Ausgang vergleichen und einen neuen Prüfschritt gesondert beginnen.';
                else{
                    $next['snapshot_hash']=$current['hash'];$next['decision_id']=$current['decision_id'];$next['reviewed']='';$next['approval_review_active']='1';
                    if($mode==='approval_new')$next['request_key']=bin2hex(random_bytes(16));
                    $active=true;$notice='Aktuellen Rechnungsstand gelesen. Originale und Angaben vergleichen und den Prüfschritt anschließend gesondert bestätigen.';
                }
            }
            if(Permissions::finance($db,$actor)['club_id']!==$identity['club_id'])throw new DomainException('Vereinszugang geändert. Das ursprüngliche Formular erneut prüfen.',409);
            if($own)$db->commit();$post=$next;
            return ['first'=>$first,'current'=>array_intersect_key($current,array_flip(['status','hash','decision_id','document_count','original_count','documents_readable','can_approve','can_withdraw','payment_state'])),'active'=>$active,'notice'=>$notice];
        }catch(\Throwable $failure){
            if($own&&$db->inTransaction())$db->rollBack();
            if($failure instanceof \PDOException&&in_array((int)($failure->errorInfo[1]??0),[1205,3572],true))throw new DomainException('Rechnung oder Prüfentscheidung wird gerade bearbeitet. Erneut lesen.',503,$failure);
            throw $failure;
        }
    }
}
