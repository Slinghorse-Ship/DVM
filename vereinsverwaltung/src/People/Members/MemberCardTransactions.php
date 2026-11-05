<?php declare(strict_types=1);
namespace Kulturhus\Verein;
use PDO;
use DomainException;

/** Current MySQL row locks; reads never acquire the legacy global writer mutex. */
final class MemberCardTransactions
{
    public static function run(PDO $db,callable $work,string $busy='Mitgliederausweis wird gerade bearbeitet. Deine Angaben bleiben erhalten.'):mixed
    {
        $own=!$db->inTransaction();$save='member_card_'.bin2hex(random_bytes(8));
        try{$db->exec($own?'START TRANSACTION':'SAVEPOINT '.$save);$result=$work();if($own)$db->commit();else$db->exec('RELEASE SAVEPOINT '.$save);return$result;}
        catch(\Throwable $error){
            if($db->inTransaction()){if($own)$db->rollBack();else{$db->exec('ROLLBACK TO SAVEPOINT '.$save);$db->exec('RELEASE SAVEPOINT '.$save);}}
            if($error instanceof \PDOException&&in_array((int)($error->errorInfo[1]??0),[1205,1213,3572],true))throw new DomainException($busy,503);throw$error;
        }
    }
}
