<?php declare(strict_types=1);
namespace Kulturhus\Verein;

use DomainException;

/** Net bank evidence for several already recorded payments. Uses the existing original review. */
trait BankImportGroups
{
    private function paymentGroups():BankPaymentGroups{return new BankPaymentGroups($this->db,$this->actor);}
    public function groupsAvailable():bool{return $this->paymentGroups()->writable();}
    public function groupPreview(string $record,array $ids,string $reason,string $reuse='',string $duplicate=''):array{return $this->paymentGroups()->preview($record,$ids,$reason,$reuse,$duplicate);}
    public function groupOriginal(string $record,string $link):array{$this->record($record);return $this->paymentGroups()->original($record,$link);}

    public function sharePreview(string $record,array $amounts,string $reason,string $reuse='',string $duplicate=''):array{return $this->paymentGroups()->previewShares($record,$amounts,$reason,$reuse,$duplicate);}
    public function decideGroup(string $record,int $version,array $ids,string $reason,string $reuse,string $hash,bool $confirmed):string{return $this->decideComponents($record,$version,$ids,$reason,$reuse,$hash,$confirmed);}
    public function decideShares(string $record,int $version,array $amounts,string $reason,string $reuse,string $hash,bool $confirmed):string{$amounts=BankPaymentGroups::amounts($amounts);return $this->decideComponents($record,$version,array_map('strval',array_keys($amounts)),$reason,$reuse,$hash,$confirmed,$amounts);}
    private function decideComponents(string $record,int $version,array $ids,string $reason,string $reuse,string $hash,bool $confirmed,?array $amounts=null):string
    {
        if($this->db->inTransaction())throw new DomainException('Sammelbankzuordnung benötigt einen eigenen abgeschlossenen Speichervorgang.',409);
        $ids=BankPaymentGroups::ids($ids);BankPaymentGroups::reason($reason);
        if(!$confirmed||!preg_match('/\A[a-f0-9]{64}\z/D',$hash))throw new DomainException('Die vollständige Summe zuerst prüfen und diesen Sammelbanknachweis ausdrücklich bestätigen.',422);
        try{return $this->tx(function()use($record,$version,$ids,$reason,$reuse,$hash,$amounts):string{
            $a=$this->access();$groups=$this->paymentGroups();if(!$groups->writable())throw new DomainException('Zusammengehörige Bankzahlungen benötigen das geprüfte Paketupgrade.',503);
            $r=$this->q('SELECT * FROM bank_import_records WHERE club_id=? AND id=? FOR UPDATE NOWAIT',[$a['club_id'],$record])->fetch();if(!$r)throw new DomainException('Eigene Bankzeile nicht verfügbar.',404);
            $first=$groups->first($record,$version,true);
            if($first){$original=json_decode($first['original_json'],true,128,JSON_THROW_ON_ERROR);$input=['record'=>$record,'transactions'=>$ids,'reason'=>$reason,'reuse'=>$reuse,'duplicate'=>''];if($amounts!==null)$input['amounts']=$amounts;if(($original['schema']??1)!==($amounts===null?1:2)||$first['actor_id']!==$a['id']||$first['operation']!=='existing'||$original['input']!==$input||!hash_equals($original['review']['hash'],$hash))throw new DomainException('Dieser Sammelbankstand wurde bereits mit anderen Angaben angenommen. Sein ursprüngliches Original prüfen.',409);return $first['id'];}
            if((int)$r['version']!==$version||$r['state']!=='review'||$this->q('SELECT 1 FROM bank_import_decisions WHERE club_id=? AND record_id=? AND record_version=? FOR SHARE NOWAIT',[$a['club_id'],$record,$version])->fetchColumn())throw new DomainException('Der Bankstand wurde inzwischen anders entschieden. Ursprüngliche Auswahl erhalten und aktuellen Stand gesondert prüfen.',409);
            $this->reviewOriginals($r);$review=$amounts===null?$groups->reviewLocked($record,$ids,$reason,$reuse):$groups->reviewSharesLocked($record,$amounts,$reason,$reuse);
            if(!hash_equals($review['hash'],$hash))throw new DomainException('Teilbewegungen oder Banknachweise haben sich geändert. Die ganze Summe erneut bewusst prüfen und anschließend gesondert bestätigen.',409);
            $linked=$groups->linkLocked($review,'existing');$this->q("UPDATE bank_import_records SET state='imported',reason='',version=version+1 WHERE club_id=? AND id=?",[$a['club_id'],$record]);$this->q('INSERT INTO bank_import_decisions VALUES(?,?,?,?,?,?,?,?)',[$a['club_id'],bin2hex(random_bytes(16)),$record,$a['id'],'existing',$reason,$version,(new \DateTimeImmutable('now',new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.uP')]);$this->audit($a,'bank.group.reconciled',$record,$version+1);return $linked['link']['id'];
        });}catch(\PDOException$error){if(in_array((int)($error->errorInfo[1]??0),[1205,1213,3572],true))throw new DomainException('Zusammengehörige Bankzahlungen werden gerade bearbeitet. Aktuellen Stand erneut prüfen.',503,$error);throw $error;}
    }

    public function correctGroupDuplicate(string $record,int $version,int $sourceVersion,array $ids,string $reuse,string $date,string $reason,string $key,string $hash,bool $confirmed):string{return $this->correctComponentsDuplicate($record,$version,$sourceVersion,$ids,$reuse,$date,$reason,$key,$hash,$confirmed);}
    public function correctSharesDuplicate(string $record,int $version,int $sourceVersion,array $amounts,string $reuse,string $date,string $reason,string $key,string $hash,bool $confirmed):string{$amounts=BankPaymentGroups::amounts($amounts);return $this->correctComponentsDuplicate($record,$version,$sourceVersion,array_map('strval',array_keys($amounts)),$reuse,$date,$reason,$key,$hash,$confirmed,$amounts);}
    private function correctComponentsDuplicate(string $record,int $version,int $sourceVersion,array $ids,string $reuse,string $date,string $reason,string $key,string $hash,bool $confirmed,?array $amounts=null):string
    {
        if($this->db->inTransaction())throw new DomainException('Sammelbankkorrektur benötigt einen eigenen abgeschlossenen Speichervorgang.',409);
        $ids=BankPaymentGroups::ids($ids);BankPaymentGroups::reason($reason);self::key($key);self::date($date);
        if(!$confirmed||!preg_match('/\A[a-f0-9]{64}\z/D',$hash))throw new DomainException('Die vollständige Summe zuerst prüfen und diese doppelte Sammelbankübernahme ausdrücklich bestätigen.',422);
        $requested=['record'=>$record,'version'=>$version,'source_version'=>$sourceVersion,'kind'=>'duplicate','target_mode'=>$amounts===null?'group':'shares','transactions'=>$ids,'reuse'=>$reuse,'date'=>$date,'reason'=>$reason,'review_hash'=>$hash,'confirmed'=>$confirmed];if($amounts!==null)$requested['amounts']=$amounts;$inputHash=hash('sha256',json_encode($requested,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
        try{return $this->tx(function()use($record,$version,$sourceVersion,$ids,$reuse,$date,$reason,$key,$hash,$requested,$inputHash,$amounts):string{
            $a=$this->access();$groups=$this->paymentGroups();if(!$this->correctionsAvailable()||!$groups->writable())throw new DomainException('Zusammengehörige Bankzahlungen benötigen das geprüfte Paketupgrade.',503);
            $r=$this->q('SELECT * FROM bank_import_records WHERE club_id=? AND id=? FOR UPDATE NOWAIT',[$a['club_id'],$record])->fetch();if(!$r)throw new DomainException('Eigene Bankzeile nicht verfügbar.',404);
            $first=$this->q('SELECT * FROM bank_import_corrections WHERE club_id=? AND request_key=? FOR SHARE NOWAIT',[$a['club_id'],$key])->fetch();
            if($first){self::verifyCorrection($first);if($first['actor_id']!==$a['id']||!hash_equals($first['input_hash'],$inputHash))throw new DomainException('Dieses Korrekturformular wurde bereits mit anderen Angaben angenommen. Ursprünglichen Verlauf prüfen.',409);return $first['id'];}
            if($r['state']!=='imported'||(int)$r['version']!==$version)throw new DomainException('Der Bankstand wurde inzwischen geändert. Aktuellen Stand prüfen und anschließend gesondert bestätigen.',409);
            (new Finance($this->db,$this->actor))->assertReturnOrderBankReferenceMutable($record);$source=$this->q('SELECT * FROM bank_transaction_sources WHERE club_id=? AND record_id=? FOR UPDATE NOWAIT',[$a['club_id'],$record])->fetch();if(!$source||$source['kind']!=='imported')throw new DomainException('Nur eine zusätzlich übernommene Zahlung als doppelte Erfassung korrigieren.',422);
            $this->reviewOriginals($r);$review=$amounts===null?$groups->reviewLocked($record,$ids,$reason,$reuse,$source['transaction_id']):$groups->reviewSharesLocked($record,$amounts,$reason,$reuse,$source['transaction_id']);
            if(!hash_equals($hash,$review['hash']))throw new DomainException('Teilbewegungen oder Banknachweise haben sich geändert. Die ganze Summe erneut bewusst prüfen und anschließend gesondert bestätigen.',409);
            $f=new Finance($this->db,$this->actor);$before=['record'=>$r,'bank_source'=>$source,'transaction'=>$f->transactionDetail($source['transaction_id'],true)];
            if((int)$before['transaction']['version']!==$sourceVersion)throw new DomainException('Übernommener Bankstand oder Kontoumsatz wurde geändert. Aktuellen Stand prüfen.',409);
            $counterKey=substr(hash('sha256','bank-group-correction:'.$a['club_id'].':'.$key),0,32);$counter=($amounts===null?$f->correctDuplicateBankGroupLocked($source['transaction_id'],$sourceVersion,$ids,$date,$counterKey):$f->correctDuplicateBankSharesLocked($source['transaction_id'],$sourceVersion,$review['source']['portions'],$date,$counterKey))['counter'];
            $this->q('DELETE FROM bank_transaction_sources WHERE club_id=? AND record_id=?',[$a['club_id'],$record]);$linked=$groups->linkLocked($review,'duplicate');$this->q('UPDATE bank_import_records SET version=version+1 WHERE club_id=? AND id=?',[$a['club_id'],$record]);
            $after=['record'=>$this->q('SELECT * FROM bank_import_records WHERE club_id=? AND id=? FOR SHARE NOWAIT',[$a['club_id'],$record])->fetch(),'bank_source'=>null,'payment_group'=>$linked,'counter'=>$counter];$original=json_encode(['schema'=>2,'requested'=>$requested,'before'=>$before,'after'=>$after],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);$id=bin2hex(random_bytes(16));
            $this->q('INSERT INTO bank_import_corrections VALUES(?,?,?,?,?,?,?,?,?,?,?,?)',[$a['club_id'],$id,$record,$version,$a['id'],'duplicate',$reason,$key,$inputHash,$original,hash('sha256',$original),(new \DateTimeImmutable('now',new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.uP')]);$this->audit($a,'bank.review.corrected',$record,$version+1);return $id;
        });}catch(\PDOException$error){if(in_array((int)($error->errorInfo[1]??0),[1205,1213,3572],true))throw new DomainException('Zusammengehörige Bankzahlungen werden gerade bearbeitet. Aktuellen Stand erneut prüfen.',503,$error);throw $error;}
    }

    /** Conservative intake hint: unproved manual components can explain a different net amount. */
    private function possibleGroup(string $club,string $account,array $record):bool
    {
        $unproved=BankEvidence::unprovedSql($this->db);
        // A hint needs a possible signed sum, not merely unrelated old movements.
        // Search complete bounded pages; when the state space becomes too large,
        // keep the bank original for human review instead of assuming new money.
        $target=(int)$record['cents'];$eligible=$this->q('SELECT COUNT(*) AS n,COALESCE(SUM((t.cents>0)=?),0) AS same_direction FROM finance_transactions t WHERE t.club_id=? AND t.account_id=? AND '.BankCounterpayments::candidateSql($this->db).' AND '.$unproved,[(int)($target>0),$club,$account])->fetch();
        if((int)$eligible['n']>=2&&(int)$eligible['same_direction']>0){$sums=[0=>0];$last='';$seen=0;
        do{$rows=$this->q('SELECT t.id,t.cents FROM finance_transactions t WHERE t.club_id=? AND t.account_id=? AND t.id>? AND '.BankCounterpayments::candidateSql($this->db).' AND '.$unproved.' ORDER BY t.id LIMIT 200',[$club,$account,$last])->fetchAll();
            foreach($rows as$row){$last=$row['id'];if(++$seen>2000)return true;$previous=$sums;foreach($previous as$sum=>$count){if($count>=40)continue;$net=$sum+(int)$row['cents'];if(!is_int($net))return true;$parts=$count+1;if($parts>=2&&$net===$target)return true;if(!isset($sums[$net])||$parts<$sums[$net])$sums[$net]=$parts;}if(count($sums)>8192)return true;}
        }while(count($rows)===200);
        }
        if(!BankEvidence::groupsAvailable($this->db))return false;
        return (bool)$this->q("SELECT 1 FROM bank_payment_groups payment_group JOIN bank_payment_group_links binding ON binding.club_id=payment_group.club_id AND binding.group_id=payment_group.id JOIN bank_import_records earlier ON earlier.club_id=binding.club_id AND earlier.id=binding.record_id WHERE payment_group.club_id=? AND payment_group.account_id=? AND payment_group.total_cents=? AND CAST(JSON_UNQUOTE(JSON_EXTRACT(payment_group.original_json,'$.schema')) AS UNSIGNED)=1 AND binding.released_at IS NULL AND earlier.booked_on BETWEEN DATE_SUB(?,INTERVAL 7 DAY) AND DATE_ADD(?,INTERVAL 7 DAY) LIMIT 1",[$club,$account,$record['cents'],$record['booked_on'],$record['booked_on']])->fetchColumn();
    }

    /** A bank net can consume a portion of one original or several available portions. */
    private function possibleShares(string $club,string $account,array $record):bool
    {
        $last='';$capacity=0;$seen=0;$target=abs((int)$record['cents']);
        do{$rows=$this->q('SELECT t.* FROM finance_transactions t WHERE t.club_id=? AND t.account_id=? AND t.id>? AND (t.cents>0)=? AND '.BankCounterpayments::candidateSql($this->db).' AND NOT EXISTS(SELECT 1 FROM bank_transaction_sources classic WHERE classic.club_id=t.club_id AND classic.transaction_id=t.id) ORDER BY t.id LIMIT 200 FOR SHARE NOWAIT',[$club,$account,$last,(int)($record['cents']>0)])->fetchAll();$proofs=BankEvidence::proofs($this->db,$club,array_column($rows,'id'),true);
            foreach($rows as$row){$last=$row['id'];if(++$seen>2000)return true;$remaining=BankEvidence::describe($row,$proofs[$row['id']]??[])['bank_unproved_cents'];if($remaining>0&&(int)$record['cents']<0)return true; // A recorded outgoing amount may be followed by an explicitly documented extra bank fee.
                $capacity+=$remaining;if(!is_int($capacity)||$capacity>=$target)return true;}
        }while(count($rows)===200);
        if(!BankEvidence::groupsAvailable($this->db))return false;
        return (bool)$this->q("SELECT 1 FROM bank_payment_groups g JOIN bank_payment_group_links binding ON binding.club_id=g.club_id AND binding.group_id=g.id JOIN bank_import_records earlier ON earlier.club_id=binding.club_id AND earlier.id=binding.record_id WHERE g.club_id=? AND g.account_id=? AND g.total_cents=? AND CAST(JSON_UNQUOTE(JSON_EXTRACT(g.original_json,'$.schema')) AS UNSIGNED)=2 AND binding.released_at IS NULL AND earlier.booked_on BETWEEN DATE_SUB(?,INTERVAL 7 DAY) AND DATE_ADD(?,INTERVAL 7 DAY) LIMIT 1",[$club,$account,$record['cents'],$record['booked_on'],$record['booked_on']])->fetchColumn();
    }

    public function groupCandidatesPage(string $record,mixed $page=1,mixed $search=''):array
    {
        $page=self::importPage($page);$search=self::importText($search,150);
        return $this->importRead(function()use($record,$page,$search):array{
            $r=$this->record($record);$map=$this->mapping($r['mapping_id']);$where=['t.club_id=?','t.account_id=?',BankCounterpayments::candidateSql($this->db),BankEvidence::unprovedSql($this->db)];$args=[$map['club_id'],$map['account_id']];
            if($search!==''){$where[]='(INSTR(LOWER(t.description),LOWER(?))>0 OR INSTR(LOWER(t.counterparty),LOWER(?))>0 OR t.id=?)';array_push($args,$search,$search,$search);}
            $from=' FROM finance_transactions t WHERE '.implode(' AND ',$where);$bounds=self::importBounds((int)$this->q('SELECT COUNT(*)'.$from,$args)->fetchColumn(),$page,20);$rows=$this->q('SELECT t.*'.$from.' ORDER BY t.booked_on DESC,t.id LIMIT 20 OFFSET '.(($bounds['page']-1)*20),$args)->fetchAll();return $bounds+['rows'=>$rows,'q'=>$search];
        });
    }
    public function shareCandidatesPage(string $record,mixed $page=1,mixed $search=''):array
    {
        $page=self::importPage($page);$search=self::importText($search,150);
        return $this->importRead(function()use($record,$page,$search):array{
            $r=$this->record($record);$map=$this->mapping($r['mapping_id']);$where=['t.club_id=?','t.account_id=?',BankCounterpayments::candidateSql($this->db),'('.BankEvidence::remainingSql($this->db).')>0'];$args=[$map['club_id'],$map['account_id']];
            if($search!==''){$where[]='(INSTR(LOWER(t.description),LOWER(?))>0 OR INSTR(LOWER(t.counterparty),LOWER(?))>0 OR t.id=?)';array_push($args,$search,$search,$search);}
            $from=' FROM finance_transactions t WHERE '.implode(' AND ',$where);$bounds=self::importBounds((int)$this->q('SELECT COUNT(*)'.$from,$args)->fetchColumn(),$page,20);$rows=$this->q('SELECT t.*'.$from.' ORDER BY t.booked_on DESC,t.id LIMIT 20 OFFSET '.(($bounds['page']-1)*20),$args)->fetchAll();$proofs=BankEvidence::proofs($this->db,$map['club_id'],array_column($rows,'id'));
            foreach($rows as&$row)$row=array_replace($row,BankEvidence::describe($row,$proofs[$row['id']]??[]));unset($row);return $bounds+['rows'=>$rows,'q'=>$search];
        });
    }
    public function reusableGroupsPage(string $record,mixed $page=1,bool $shares=false):array
    {
        $page=self::importPage($page);return $this->importRead(function()use($record,$page,$shares):array{
            $r=$this->record($record);$map=$this->mapping($r['mapping_id']);if(!BankEvidence::groupsAvailable($this->db))return self::importBounds(0,1,20)+['rows'=>[]];$where=' FROM bank_payment_groups payment_group WHERE payment_group.club_id=? AND payment_group.account_id=? AND payment_group.total_cents=? AND CAST(JSON_UNQUOTE(JSON_EXTRACT(payment_group.original_json,\'$.schema\')) AS UNSIGNED) IN ('.($shares?'1,2':'1').') AND EXISTS(SELECT 1 FROM bank_payment_group_links binding WHERE binding.club_id=payment_group.club_id AND binding.group_id=payment_group.id AND binding.released_at IS NULL)';$args=[$map['club_id'],$map['account_id'],$r['cents']];$bounds=self::importBounds((int)$this->q('SELECT COUNT(*)'.$where,$args)->fetchColumn(),$page,20);$rows=$this->q('SELECT payment_group.*'.$where.' ORDER BY payment_group.created_at DESC,payment_group.id LIMIT 20 OFFSET '.(($bounds['page']-1)*20),$args)->fetchAll();foreach($rows as&$row)$row=$this->paymentGroups()->group($row['id']);unset($row);return $bounds+['rows'=>$rows];
        });
    }
    public function groupHistoryPage(string $record,mixed $page=1):array
    {
        $page=self::importPage($page);return $this->importRead(function()use($record,$page):array{
            $r=$this->record($record);if(!BankEvidence::groupsAvailable($this->db))return self::importBounds(0,1,20)+['rows'=>[]];$args=[$r['club_id'],$record];$bounds=self::importBounds((int)$this->q('SELECT COUNT(*) FROM bank_payment_group_links WHERE club_id=? AND record_id=?',$args)->fetchColumn(),$page,20);$rows=$this->q('SELECT * FROM bank_payment_group_links WHERE club_id=? AND record_id=? ORDER BY record_version DESC,id LIMIT 20 OFFSET '.(($bounds['page']-1)*20),$args)->fetchAll();foreach($rows as&$row){$row['verified']=hash_equals($row['original_sha256'],hash('sha256',$row['original_json']));unset($row['original_json']);}unset($row);return $bounds+['rows'=>$rows];
        });
    }
}
