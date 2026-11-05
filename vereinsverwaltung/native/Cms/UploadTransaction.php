<?php declare(strict_types=1);
namespace Kulturhus\Native\Cms;
use PDO;

/** One owned SQL transaction and cleanup of only the new files registered by its services. */
final class UploadTransaction
{
    private bool $active=true;
    private array $cleanup=[];
    private bool $uncertain=false;
    private function __construct(private PDO $db) {}
    private function __clone() {}
    public function __serialize():array {throw new \LogicException('Uploadtransaktionen dürfen nicht gespeichert werden.');}
    public static function run(PDO $db,callable $operation): mixed
    {
        if($db->inTransaction())throw new \LogicException('Gemeinsamer Upload benötigt eine eigene Transaktion.');
        $context=new self($db);
        try {
            if(!$db->beginTransaction())throw new \RuntimeException('Uploadtransaktion konnte nicht begonnen werden.');
            $result=$operation($context);
            if($context->uncertain)throw new \RuntimeException('Teil-Upload nicht sicher zurückgesetzt. Gespeicherten Stand prüfen.');
            if(!$db->commit())throw new \RuntimeException('Uploadannahme nicht bestätigt. Gespeicherten Stand prüfen.');
            return $result;
        }
        catch(\Throwable $error){
            $rolledBack=false;
            try{if($db->inTransaction())$rolledBack=$db->rollBack();}catch(\Throwable){error_log('Upload-Rollback nicht bestätigt; neue Dateien bleiben zum Prüfen erhalten.');}
            // A lost acknowledgement may have committed every original already. Only a positively acknowledged rollback authorises cleanup.
            if($rolledBack)foreach(array_reverse($context->cleanup)as$cleanup){try{$cleanup();}catch(\Throwable){error_log('Neue Uploaddatei konnte nach Rollback nicht bereinigt werden.');}}
            throw $error;
        }finally{$context->active=false;$context->cleanup=[];}
    }
    public function require(PDO $db): void
    {
        if(!$this->active||$db!==$this->db||!$db->inTransaction())throw new \LogicException('Aktive gemeinsame Uploadtransaktion fehlt.');
    }
    public function onRollback(PDO $db,callable $cleanup): void
    {
        $this->require($db);$this->cleanup[]=$cleanup;
    }

    /** A caught child failure cleans only its own acknowledged savepoint, never earlier successful copies. */
    public function segment(PDO $db,callable $operation):mixed
    {
        $this->require($db);$start=count($this->cleanup);$savepoint='upload_'.bin2hex(random_bytes(8));if($db->exec('SAVEPOINT '.$savepoint)===false)throw new \RuntimeException('Teil-Upload konnte nicht begonnen werden.');
        try{$result=$operation();if($db->exec('RELEASE SAVEPOINT '.$savepoint)===false)throw new \RuntimeException('Teil-Upload nicht bestätigt.');return $result;}
        catch(\Throwable $error){
            $rolledBack=false;
            try{if($db->exec('ROLLBACK TO SAVEPOINT '.$savepoint)===false)throw new \RuntimeException('Teil-Upload-Rollback nicht bestätigt.');$rolledBack=true;$db->exec('RELEASE SAVEPOINT '.$savepoint);}catch(\Throwable){if(!$rolledBack)$this->uncertain=true;}
            if($rolledBack){$callbacks=array_splice($this->cleanup,$start);foreach(array_reverse($callbacks)as$cleanup){try{$cleanup();}catch(\Throwable){error_log('Neue Teil-Uploaddatei konnte nach Rollback nicht bereinigt werden.');}}}
            throw $error;
        }
    }
}
