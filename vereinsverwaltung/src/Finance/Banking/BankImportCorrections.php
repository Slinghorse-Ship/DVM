<?php declare(strict_types=1);
namespace Kulturhus\Verein;

use DomainException;

/** Explicit corrections archive their entire previous state; no bank transport is involved. */
trait BankImportCorrections
{
    public function correctionsAvailable():bool
    {
        $this->access();return Database::tableExists($this->db,'bank_import_corrections');
    }

    public function correctionsPage(string $record,mixed $page=1):array
    {
        $page=self::importPage($page);
        return $this->importRead(function()use($record,$page):array{
            $r=$this->record($record);$available=$this->correctionsAvailable();$args=[$r['club_id'],$record];
            $bounds=self::importBounds($available?(int)$this->q('SELECT COUNT(*) FROM bank_import_corrections WHERE club_id=? AND record_id=?',$args)->fetchColumn():0,$page,20);
            $rows=$available?$this->q('SELECT id,record_version,actor_id,kind,reason,original_sha256,original_json,created_at FROM bank_import_corrections WHERE club_id=? AND record_id=? ORDER BY created_at DESC,id LIMIT 20 OFFSET '.(($bounds['page']-1)*20),$args)->fetchAll():[];
            foreach($rows as &$row){$row['verified']=hash_equals($row['original_sha256'],hash('sha256',$row['original_json']));$row['movements']=[];if($row['verified']){
                $snapshot=json_decode($row['original_json'],true,64,JSON_THROW_ON_ERROR);
                foreach(['Früheren Kontoumsatz öffnen'=>$snapshot['before']['transaction']['id']??null,'Tatsächlichen Kontoumsatz öffnen'=>$snapshot['after']['bank_source']['transaction_id']??null,'Gegenbuchung öffnen'=>$snapshot['after']['counter']['id']??null] as $label=>$id)if($id)$row['movements'][$label]=$id;
                $group=$snapshot['after']['payment_group']??$snapshot['before']['payment_group']??null;foreach($group['items']??[] as$item)$row['movements']['Ursprüngliche Teilbewegung '.$item['ordinal'].' öffnen']=$item['transaction_id'];
            }unset($row['original_json']);}unset($row);
            return $bounds+['available'=>$available,'rows'=>$rows];
        });
    }

    private static function verifyCorrection(array $row):array
    {
        if(!hash_equals($row['original_sha256'],hash('sha256',$row['original_json'])))throw new DomainException('Das gespeicherte Korrekturoriginal weicht von seiner Prüfsumme ab.',409);
        return $row;
    }

    public function correctionOriginal(string $record,string $id):array
    {
        return $this->importRead(function()use($record,$id):array{
            $r=$this->record($record);if(!$this->correctionsAvailable())throw new DomainException('Bankkorrekturen benötigen das geprüfte Paketupgrade.',503);
            $row=$this->q('SELECT * FROM bank_import_corrections WHERE club_id=? AND record_id=? AND id=?',[$r['club_id'],$record,$id])->fetch();
            if(!$row)throw new DomainException('Korrekturoriginal nicht verfügbar.',404);
            return self::verifyCorrection($row);
        });
    }

    public function correct(string $record,int $version,string $kind,string $target,int $sourceVersion,string $date,string $reason,string $key,bool $confirmed):string
    {
        $requested=['record'=>$record,'version'=>$version,'kind'=>$kind,'target'=>$target,'source_version'=>$sourceVersion,'date'=>$date,'reason'=>$reason,'confirmed'=>$confirmed];
        $normalReason=self::text($reason,500);self::key($key);
        if(!$confirmed||$normalReason===''||!in_array($kind,['reopen','unlink','duplicate'],true))throw new DomainException('Korrekturart, Begründung und ausdrückliche Bestätigung prüfen.',422);
        if(($kind!=='duplicate'&&($target!==''||$date!==''))||($kind==='reopen'&&$sourceVersion!==0))throw new DomainException('Korrekturangaben passen nicht zum gewählten Weg.',422);
        if($kind==='duplicate')self::date($date);
        $inputHash=hash('sha256',json_encode($requested,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
        try{return $this->tx(function()use($record,$version,$kind,$target,$sourceVersion,$date,$normalReason,$key,$requested,$inputHash):string{
            $a=$this->access();if(!$this->correctionsAvailable())throw new DomainException('Bankkorrekturen benötigen das geprüfte Paketupgrade.',503);
            $r=$this->q('SELECT * FROM bank_import_records WHERE club_id=? AND id=? FOR UPDATE NOWAIT',[$a['club_id'],$record])->fetch();
            if(!$r)throw new DomainException('Bankumsatz nicht verfügbar.',403);
            $accepted=$this->q('SELECT * FROM bank_import_corrections WHERE club_id=? AND request_key=? FOR SHARE NOWAIT',[$a['club_id'],$key])->fetch();
            if($accepted){self::verifyCorrection($accepted);if($accepted['actor_id']!==$a['id']||!hash_equals($accepted['input_hash'],$inputHash))throw new DomainException('Dieses Korrekturformular wurde bereits mit anderen Angaben angenommen. Ursprünglichen Verlauf prüfen.',409);return $accepted['id'];}
            if((int)$r['version']!==$version)throw new DomainException('Der Bankstand wurde inzwischen geändert. Aktuellen Stand prüfen und anschließend gesondert bestätigen.',409);
            $source=$this->q('SELECT * FROM bank_transaction_sources WHERE club_id=? AND record_id=? FOR UPDATE NOWAIT',[$a['club_id'],$record])->fetch()?:null;
            $paymentGroup=$this->paymentGroups()->active($record,true);
            $transaction=null;$counter=null;$f=new Finance($this->db,$this->actor);$f->assertReturnOrderBankReferenceMutable($record);
            if($source){
                $ids=[$source['transaction_id']];if($kind==='duplicate')$ids[]=$target;sort($ids,SORT_STRING);
                foreach(array_unique($ids) as $id)if(!$this->q('SELECT id FROM finance_transactions WHERE club_id=? AND id=? FOR UPDATE NOWAIT',[$a['club_id'],$id])->fetchColumn())throw new DomainException('Tatsächlichen Kontoumsatz auswählen.',422);
                $transaction=$f->transactionDetail($source['transaction_id'],true);
            }
            $before=['record'=>$r,'bank_source'=>$source,'transaction'=>$transaction];if($paymentGroup)$before['payment_group']=$paymentGroup;
            if($kind==='reopen'){
                if($r['state']!=='ignored')throw new DomainException('Nur eine zuvor nicht zusätzlich gebuchte Bankzeile wieder öffnen.',409);
                $previous=$this->q('SELECT * FROM bank_import_decisions WHERE club_id=? AND record_id=? ORDER BY record_version DESC,created_at DESC,id LIMIT 1 FOR SHARE NOWAIT',[$a['club_id'],$record])->fetch();
                if(!$previous||!in_array($previous['decision'],['ignore','keep'],true))throw new DomainException('Frühere Nichtbuchungsentscheidung fehlt. Ursprünglichen Verlauf prüfen.',409);
                $conflict=$previous['decision']==='keep';$this->reviewOriginals($conflict?array_replace($r,['state'=>'conflict']):$r);
                $this->q('UPDATE bank_import_records SET state=?,reason=?,version=version+1 WHERE club_id=? AND id=?',[$conflict?'conflict':'review',$conflict?'changed_bank_record':'manual_review',$a['club_id'],$record]);
            }elseif($paymentGroup){
                if($kind!=='unlink'||$r['state']!=='imported'||$source||(int)$paymentGroup['link']['version']!==$sourceVersion)throw new DomainException('Aktuellen vollständigen Sammelbankbezug zuerst prüfen.',409);
                $this->q('SELECT id FROM finance_accounts WHERE club_id=? AND id=? FOR UPDATE NOWAIT',[$a['club_id'],$paymentGroup['group']['account_id']]);$this->reviewOriginals($r);$this->paymentGroups()->releaseLocked($paymentGroup,$normalReason);$this->q("UPDATE bank_import_records SET state='review',reason='manual_review',version=version+1 WHERE club_id=? AND id=?",[$a['club_id'],$record]);
            }else{
                if($r['state']!=='imported'||!$source||!$transaction||(int)$transaction['version']!==$sourceVersion)throw new DomainException('Übernommener Bankstand oder Kontoumsatz wurde geändert. Aktuellen Stand prüfen.',409);
                $this->reviewOriginals($r);
                if($kind==='unlink'&&$source['kind']!=='reconciled')throw new DomainException('Eine zusätzlich gebuchte Zahlung muss mit einer nachvollziehbaren Gegenbuchung korrigiert werden.',422);
                if($kind==='duplicate'){
                    if($source['kind']!=='imported')throw new DomainException('Nur eine zusätzlich übernommene Zahlung als doppelte Erfassung korrigieren.',422);
                    $result=$f->correctDuplicateBankPaymentLocked($transaction['id'],$sourceVersion,$target,$date,substr(hash('sha256','bank-correction:'.$a['club_id'].':'.$key),0,32));$counter=$result['counter'];
                }
                $this->q('DELETE FROM bank_transaction_sources WHERE club_id=? AND record_id=?',[$a['club_id'],$record]);
                if($kind==='duplicate'){
                    $this->q('INSERT INTO bank_transaction_sources VALUES(?,?,?,?,?,?)',[$a['club_id'],$record,$target,'reconciled',$a['id'],(new \DateTimeImmutable('now',new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.uP')]);
                    $this->q('UPDATE bank_import_records SET version=version+1 WHERE club_id=? AND id=?',[$a['club_id'],$record]);
                }else $this->q("UPDATE bank_import_records SET state='review',reason='manual_review',version=version+1 WHERE club_id=? AND id=?",[$a['club_id'],$record]);
            }
            $after=['record'=>$this->q('SELECT * FROM bank_import_records WHERE club_id=? AND id=? FOR SHARE NOWAIT',[$a['club_id'],$record])->fetch(),'bank_source'=>$this->q('SELECT * FROM bank_transaction_sources WHERE club_id=? AND record_id=? FOR SHARE NOWAIT',[$a['club_id'],$record])->fetch()?:null,'counter'=>$counter];
            $original=json_encode(['schema'=>1,'requested'=>$requested,'before'=>$before,'after'=>$after],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);$id=bin2hex(random_bytes(16));
            $this->q('INSERT INTO bank_import_corrections VALUES(?,?,?,?,?,?,?,?,?,?,?,?)',[$a['club_id'],$id,$record,$version,$a['id'],$kind,$normalReason,$key,$inputHash,$original,hash('sha256',$original),(new \DateTimeImmutable('now',new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.uP')]);
            $this->audit($a,'bank.review.corrected',$record,$version+1);return $id;
        });}catch(\PDOException $error){if(in_array((int)($error->errorInfo[1]??0),[1205,1213,3572],true))throw new DomainException('Banknachweis oder Zahlung wird gerade bearbeitet. Aktuellen Stand erneut prüfen.',503,$error);throw $error;}
    }
}
