<?php declare(strict_types=1);
namespace Kulturhus\Verein\Web;

use DomainException;
use PDO;
use Kulturhus\Verein\{Finance,Permissions};

/** A conscious current read; it never allocates money or replaces a retry key. */
final class FinanceMatchReview
{
    public static function read(PDO $db,string $actor,array $post,array $route):array
    {
        $action=self::value($post,'action');$source=self::value($post,'transaction_id');
        if(!in_array($action,['allocate','internal-link','internal-cash'],true)||!FinanceMatchDraft::matchesRoute($post,$route))throw new DomainException('Ursprüngliche Zuordnung in ihrem eigenen Vorgang öffnen.',409);
        if($db->inTransaction())throw new DomainException('Die Zuordnungsprüfung benötigt einen eigenen abgeschlossenen Leseschritt.',503);
        $before=Permissions::actor($db,$actor);self::writer($before);$finance=new Finance($db,$actor);
        $db->beginTransaction();
        try{
            $transaction=$finance->transactionDetail($source,true);
            $target=match($action){
                'allocate'=>$finance->currentEntry(self::value($post,'entry_id')),
                'internal-link'=>$finance->transactionDetail(self::value($post,'other_id'),true),
                'internal-cash'=>self::cash($db,$before['club_id'],self::value($post,'cash_account')),
            };
            if($transaction['club_id']!==$before['club_id']||$target['club_id']!==$before['club_id'])throw new DomainException('Vereinszugang geändert.',409);
            if($action==='internal-cash'&&$target['kind']!=='cash')throw new DomainException('Vorhandenen Bankumsatz und tatsächliche Handkasse wählen.',422);
            $db->commit();
        }catch(\Throwable $failure){if($db->inTransaction())$db->rollBack();if($failure instanceof \PDOException&&in_array((int)($failure->errorInfo[1]??0),[1205,3572],true))throw new DomainException('Umsatz oder Beleg wird gerade bearbeitet. Bitte erneut prüfen.',503,$failure);throw $failure;}
        $after=Permissions::actor($db,$actor);self::writer($after);if($after['club_id']!==$before['club_id'])throw new DomainException('Vereinszugang geändert.',409);
        return ['action'=>$action,'route'=>$route,'source'=>$transaction,'target'=>$target];
    }
    private static function cash(PDO $db,string $club,string $id):array
    {
        $q=$db->prepare('SELECT * FROM finance_accounts WHERE club_id=? AND id=? FOR SHARE NOWAIT');$q->execute([$club,$id]);$row=$q->fetch();if(!$row)throw new DomainException('Konto nicht verfügbar.',404);return $row;
    }
    private static function writer(array $actor):void
    {
        if($actor['finance_access']!=='write')throw new DomainException('Aktuelle Finanzschreibberechtigung erforderlich.',403);
    }
    private static function value(array $post,string $key):string
    {
        if(!is_string($post[$key]??null)||$post[$key]==='')throw new DomainException('Ursprüngliche Zuordnung mit vollständiger Auswahl öffnen.',422);
        return $post[$key];
    }
}
