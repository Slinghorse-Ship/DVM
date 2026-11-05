<?php declare(strict_types=1);
namespace Kulturhus\Verein;

use DomainException;

/** Match an actual returned excess to a paid document. Neither movement nor demand is rewritten. */
trait ExcessPaymentReturns
{
    public const EXCESS_RETURN_ALLOCATED="COALESCE((SELECT SUM(x.cents) FROM finance_excess_returns x WHERE x.club_id=t.club_id AND (x.source_id=t.id OR x.returned_id=t.id) AND x.voided_at IS NULL),0)";

    public static function excessReturnsAvailable(\PDO $db):bool{return Database::tableExists($db,'finance_excess_returns');}
    public static function excessReturnAllocatedSql(\PDO $db):string{return self::excessReturnsAvailable($db)?self::EXCESS_RETURN_ALLOCATED:'0';}
    public static function neutralAllocatedSql(\PDO $db):string{return '('.self::INTERNAL_ALLOCATED.'+'.self::excessReturnAllocatedSql($db).')';}

    private static function excessJson(array $value):string{return json_encode($value,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);}
    private static function excessFact(array $transaction):array
    {
        foreach(['cents','version','allocated_cents','remaining_cents'] as $field)$transaction[$field]=(int)$transaction[$field];
        return $transaction;
    }
    private static function excessReason(string $reason):void
    {
        if(trim($reason)===''||!mb_check_encoding($reason,'UTF-8')||mb_strlen($reason,'UTF-8')>500||str_contains($reason,"\0"))throw new DomainException('Rückzahlungsbezug mit bis zu 500 Zeichen angeben.',422);
    }
    private function excessSourceFacts(string $source,bool $current=false):array
    {
        $this->actor();$t=self::excessFact($this->transactionDetail($source,$current));$rows=$this->allocations($source,true,$current);$links=[];$receipts=[];
        foreach($rows as $link){if($link['voided_at']!==null)continue;$links[]=$link;if(!isset($receipts[$link['entry_id']]))$receipts[$link['entry_id']]=$current?$this->currentEntry($link['entry_id']):$this->entry($link['entry_id']);}
        $available=self::excessReturnsAvailable($this->db);$eligible=$available&&$t['remaining_cents']>0&&!$t['reversal_of']&&!$t['reversed_on']&&count($receipts)>0;
        foreach($receipts as $receipt)if($receipt['payment_state']!=='paid'||(($t['cents']<0)!==($receipt['kind']==='expense')))$eligible=false;
        return ['transaction'=>$t,'allocations'=>$links,'receipts'=>$receipts,'eligible'=>$eligible,'available'=>$available];
    }
    public function excessReturnSource(string $source):array{return $this->bankCatalogueRead(fn():array=>$this->excessSourceFacts($source));}
    public function excessReturnCandidatesPage(string $source,mixed $page=1,mixed $search=''):array
    {
        return $this->bankCatalogueRead(function()use($source,$page,$search):array{
            $s=$this->excessSourceFacts($source);if(!$s['eligible'])throw new DomainException('Zuerst die ursprüngliche Zahlung vollständig bezahlten Belegen zuordnen und ihren offenen Überschuss prüfen.',409);
            $t=$s['transaction'];return $this->transactionsPage(['account'=>$t['account_id'],'direction'=>$t['cents']>0?'expense':'income','from'=>$t['booked_on'],'to'=>$this->businessDay(),'assignment'=>'pending','available'=>true,'q'=>$search],$page,20);
        });
    }
    private function excessReviewFacts(string $source,string $returned,bool $current=false):array
    {
        $a=$this->actor();$s=$this->excessSourceFacts($source,$current);$t=$s['transaction'];$r=self::excessFact($this->transactionDetail($returned,$current));
        if(!$s['eligible'])throw new DomainException('Der ursprüngliche Überschuss oder die Belegzuordnung ist nicht mehr verfügbar. Aktuellen Stand prüfen.',409);
        if($source===$returned||$t['account_id']!==$r['account_id']||($t['cents']>0)===($r['cents']>0)||$r['reversal_of']||$r['reversed_on']||$r['remaining_cents']<=0||$r['booked_on']<$t['booked_on']||$r['booked_on']>$this->businessDay())throw new DomainException('Tatsächliche noch verfügbare Rückzahlung auf demselben Konto nach der ursprünglichen Zahlung auswählen.',409);
        return ['format'=>'dvm-excess-return-review/1','club_id'=>$a['club_id'],'source'=>$s,'returned'=>$r,'account'=>$this->account($t['account_id'])];
    }
    public function excessReturnReview(string $source,string $returned):array
    {
        return $this->bankCatalogueRead(function()use($source,$returned):array{$review=$this->excessReviewFacts($source,$returned);return ['review'=>$review,'hash'=>hash('sha256',self::excessJson($review))];});
    }
    private function checkedExcessOriginal(array $row):array
    {
        if(!hash_equals($row['request_sha256'],hash('sha256',$row['request_json']))||!hash_equals($row['review_sha256'],hash('sha256',$row['review_json'])))throw new DomainException('Ursprünglichen Rückzahlungsnachweis prüfen lassen.',503);
        $request=json_decode($row['request_json'],true,64,JSON_THROW_ON_ERROR);$review=json_decode($row['review_json'],true,64,JSON_THROW_ON_ERROR);
        if(($request['format']??null)!=='dvm-excess-return-request/1'||($review['format']??null)!=='dvm-excess-return-review/1'||($request['club_id']??null)!==$row['club_id']||($review['club_id']??null)!==$row['club_id']||($request['actor']??null)!==$row['created_by']||($request['source']??null)!==$row['source_id']||($request['returned']??null)!==$row['returned_id']||($request['reason']??null)!==$row['reason']||($request['review_hash']??null)!==$row['review_sha256']||(int)($review['source']['transaction']['cents']??0)===0||($review['source']['transaction']['id']??null)!==$row['source_id']||($review['returned']['id']??null)!==$row['returned_id']||self::cents($request['amount']??'')!==(int)$row['cents'])throw new DomainException('Ursprüngliche Rückzahlungszuordnung und Prüffassung stimmen nicht überein.',503);
        return $row+['request'=>$request,'review'=>$review];
    }
    /** Identical accepted repetitions return the first result, including its later release state. */
    public function linkExcessReturn(string $source,int $sourceVersion,string $returned,int $returnedVersion,string $amount,string $reason,string $key,string $reviewHash,bool $confirmed):array
    {
        if($this->db->inTransaction())throw new DomainException('Rückzahlungszuordnung benötigt einen eigenen Speichervorgang. Vorhandene Arbeit bleibt erhalten.',409);
        $this->actor(true);if(!self::excessReturnsAvailable($this->db))throw new DomainException('Überschussrückzahlungen benötigen das bewusst aktualisierte Installationspaket.',503);
        self::requestKey($key);self::excessReason($reason);$cents=self::cents($amount);
        if(!$confirmed||$cents<=0||!preg_match('/^[a-f0-9]{64}$/D',$reviewHash))throw new DomainException('Tatsächlich erfolgte Überschussrückzahlung prüfen und ausdrücklich bestätigen.',422);
        $actor=$this->actor(true);$request=['format'=>'dvm-excess-return-request/1','club_id'=>$actor['club_id'],'actor'=>$actor['id'],'source'=>$source,'source_version'=>$sourceVersion,'returned'=>$returned,'returned_version'=>$returnedVersion,'amount'=>$amount,'reason'=>$reason,'review_hash'=>$reviewHash];$json=self::excessJson($request);$fingerprint=hash('sha256',$json);
        try{return $this->transaction(function()use($source,$sourceVersion,$returned,$returnedVersion,$cents,$reason,$key,$reviewHash,$actor,$json,$fingerprint):array{
            $a=$this->actor(true);if($a['club_id']!==$actor['club_id'])throw new DomainException('Ursprünglicher Vereinszugang inzwischen geändert.',403);
            $q=$this->db->prepare('SELECT * FROM finance_excess_returns WHERE club_id=? AND request_key=? FOR UPDATE NOWAIT');$q->execute([$a['club_id'],$key]);
            if($old=$q->fetch()){$old=$this->checkedExcessOriginal($old);if(!hash_equals($old['request_sha256'],$fingerprint))throw new DomainException('Diese Rückzahlungskennung gehört zu einer anderen ursprünglichen Prüfung. Gespeicherten Verlauf öffnen.',409);return ['id'=>$old['id'],'active'=>$old['voided_at']===null,'replayed'=>true];}
            $this->lockMovements([$source,$returned]);$review=$this->excessReviewFacts($source,$returned,true);$t=$review['source']['transaction'];$r=$review['returned'];$reviewJson=self::excessJson($review);
            if($t['version']!==$sourceVersion||$r['version']!==$returnedVersion||!hash_equals(hash('sha256',$reviewJson),$reviewHash))throw new DomainException('Zahlung, Rückzahlung oder Beleg wurden inzwischen geändert. Den aktuellen Bezug erneut prüfen.',409);
            if($cents>$t['remaining_cents']||$cents>$r['remaining_cents'])throw new DomainException('Rückzahlungsbetrag darf den offenen Überschuss und die noch verfügbare Rückzahlung nicht überschreiten.',422);
            if($t['account_kind']==='cash')foreach([$t,$r] as $movement)$this->assertUnclosed($movement['account_id'],$movement['booked_on']);
            $id=bin2hex(random_bytes(16));$this->db->prepare('INSERT INTO finance_excess_returns(club_id,id,source_id,returned_id,cents,reason,request_key,created_by,created_at,request_json,request_sha256,review_json,review_sha256) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?)')->execute([$a['club_id'],$id,$source,$returned,$cents,$reason,$key,$a['id'],(new \DateTimeImmutable('now',new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.uP'),$json,$fingerprint,$reviewJson,$reviewHash]);
            $this->db->prepare('UPDATE finance_transactions SET version=version+1 WHERE club_id=? AND id IN (?,?)')->execute([$a['club_id'],$source,$returned]);$this->audit($a,'finance.excess-return.linked',$id,1);return ['id'=>$id,'active'=>true,'replayed'=>false];
        });}catch(\PDOException $error){
            // A lost COMMIT response is resolved from its durable first result before any retry.
            try{
                $a=$this->actor(true);if($a['club_id']!==$actor['club_id'])throw new DomainException('Ursprünglicher Vereinszugang inzwischen geändert.',403);
                $q=$this->db->prepare('SELECT * FROM finance_excess_returns WHERE club_id=? AND request_key=?');$q->execute([$a['club_id'],$key]);
                if($old=$q->fetch()){$old=$this->checkedExcessOriginal($old);if(!hash_equals($old['request_sha256'],$fingerprint))throw new DomainException('Diese Kennung gehört zu einer anderen ursprünglichen Rückzahlungsprüfung.',409);return ['id'=>$old['id'],'active'=>$old['voided_at']===null,'replayed'=>true];}
            }catch(\PDOException $unreadable){throw new DomainException('Der Speicherausgang ist noch unbestätigt. Dieselbe Rückzahlungskennung erneut prüfen; keinen weiteren Kontoumsatz erfassen.',503,$unreadable);}
            if(in_array((int)($error->errorInfo[1]??0),[1205,1213,3572],true))throw new DomainException('Konto oder Rückzahlungsbezug wird gerade bearbeitet. Aktuellen Stand erneut prüfen.',503,$error);
            throw new DomainException('Rückzahlungszuordnung konnte nicht bestätigt werden. Ursprüngliche Angaben und dieselbe Kennung erneut prüfen.',503,$error);
        }
    }
    public function excessReturnHistory(string $transaction,mixed $page=1):array
    {
        $page=self::cataloguePage($page);return $this->bankCatalogueRead(function()use($transaction,$page):array{
            $a=$this->actor();$this->transactionDetail($transaction);if(!self::excessReturnsAvailable($this->db))return ['rows'=>[],'total'=>0,'pages'=>1,'page'=>1,'size'=>20,'available'=>false];$q=$this->db->prepare('SELECT COUNT(*) FROM finance_excess_returns WHERE club_id=? AND (source_id=? OR returned_id=?)');$q->execute([$a['club_id'],$transaction,$transaction]);$total=(int)$q->fetchColumn();$size=20;$pages=max(1,(int)ceil($total/$size));$page=min($page,$pages);
            $q=$this->db->prepare('SELECT * FROM finance_excess_returns WHERE club_id=? AND (source_id=? OR returned_id=?) ORDER BY created_at,id LIMIT '.$size.' OFFSET '.(($page-1)*$size));$q->execute([$a['club_id'],$transaction,$transaction]);$rows=[];foreach($q as $row)$rows[]=$this->checkedExcessOriginal($row);return compact('rows','total','pages','page','size');
        });
    }
    /** Whole active financial evidence for a journal source; ordinary history remains paged. */
    public function excessReturnsForPosting(string $transaction,bool $current=false):array
    {
        if($current&&!$this->db->inTransaction())throw new DomainException('Aktuellen Rückzahlungsnachweis gemeinsam mit der Buchung prüfen.');
        return $this->bankCatalogueRead(function()use($transaction,$current):array{
            $a=$this->actor();$this->transactionDetail($transaction,$current);if(!self::excessReturnsAvailable($this->db))return [];$q=$this->db->prepare('SELECT * FROM finance_excess_returns WHERE club_id=? AND (source_id=? OR returned_id=?) AND voided_at IS NULL ORDER BY id'.($current?' FOR SHARE NOWAIT':''));$q->execute([$a['club_id'],$transaction,$transaction]);$rows=[];foreach($q as $row)$rows[]=$this->checkedExcessOriginal($row);return $rows;
        });
    }
    public function excessReturnsForEntry(string $entry,mixed $page=1):array
    {
        $page=self::cataloguePage($page);return $this->bankCatalogueRead(function()use($entry,$page):array{
            $a=$this->actor();$this->entry($entry);if(!self::excessReturnsAvailable($this->db))return ['rows'=>[],'total'=>0,'pages'=>1,'page'=>1,'size'=>20,'available'=>false];$path='$.source.receipts.'.json_encode($entry,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);$where=' FROM finance_excess_returns x WHERE x.club_id=? AND EXISTS(SELECT 1 FROM finance_allocations l WHERE l.club_id=x.club_id AND l.transaction_id=x.source_id AND l.entry_id=?) AND JSON_CONTAINS_PATH(x.review_json,\'one\',?)';$args=[$a['club_id'],$entry,$path];
            $q=$this->db->prepare('SELECT COUNT(*)'.$where);$q->execute($args);$total=(int)$q->fetchColumn();$size=20;$pages=max(1,(int)ceil($total/$size));$page=min($page,$pages);$q=$this->db->prepare('SELECT x.*'.$where.' ORDER BY x.created_at,x.id LIMIT '.$size.' OFFSET '.(($page-1)*$size));$q->execute($args);$rows=[];foreach($q as $row)$rows[]=$this->checkedExcessOriginal($row);return compact('rows','total','pages','page','size');
        });
    }
    public function releaseExcessReturn(string $id,int $version,string $reason):void
    {
        $this->actor(true);if(!self::excessReturnsAvailable($this->db))throw new DomainException('Überschussrückzahlungen benötigen das bewusst aktualisierte Installationspaket.',503);
        self::excessReason($reason);
        try{$this->transaction(function()use($id,$version,$reason):void{
            $a=$this->actor(true);$q=$this->db->prepare('SELECT * FROM finance_excess_returns WHERE club_id=? AND id=? FOR UPDATE NOWAIT');$q->execute([$a['club_id'],$id]);$row=$q->fetch();if(!$row)throw new DomainException('Rückzahlungsbezug nicht verfügbar.',403);$row=$this->checkedExcessOriginal($row);
            if($row['voided_at']!==null){if($row['void_reason']!==$reason||$row['voided_by']!==$a['id'])throw new DomainException('Dieser Bezug ist bereits mit einer anderen Bestätigung aufgehoben. Gespeicherten Verlauf prüfen.',409);return;}
            if((int)$row['version']!==$version)throw new DomainException('Rückzahlungsbezug inzwischen geändert. Verlauf erneut prüfen.',409);
            $this->lockMovements([$row['source_id'],$row['returned_id']]);foreach([$row['source_id'],$row['returned_id']] as $movement){$t=$this->transactionDetail($movement,true);if($t['account_kind']==='cash')$this->assertUnclosed($t['account_id'],$t['booked_on']);}
            $this->db->prepare('UPDATE finance_excess_returns SET version=2,voided_at=?,void_reason=?,voided_by=? WHERE club_id=? AND id=?')->execute([(new \DateTimeImmutable('now',new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.uP'),$reason,$a['id'],$a['club_id'],$id]);$this->db->prepare('UPDATE finance_transactions SET version=version+1 WHERE club_id=? AND id IN (?,?)')->execute([$a['club_id'],$row['source_id'],$row['returned_id']]);$this->audit($a,'finance.excess-return.released',$id,2);
        });}catch(\PDOException $error){if(in_array((int)($error->errorInfo[1]??0),[1205,1213,3572],true))throw new DomainException('Konto oder Rückzahlungsbezug wird gerade bearbeitet. Erneut prüfen.',503,$error);throw $error;}
    }
}
