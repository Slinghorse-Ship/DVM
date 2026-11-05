<?php declare(strict_types=1);
namespace Kulturhus\Verein\Finance\Banking\NativeSepa;

use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use PDO;
use Kulturhus\Native\Shared\Db;
use Kulturhus\Verein\{Permissions,Finance,FinancePaymentAuthority,SepaOrders,MemberBankVault,ProductionRuntime,ContributionInvoices,Documents,PaymentFunding,NativeDebitReturnHistory};
use Kulturhus\Verein\Infrastructure\Database\Transactions;

/** Atomic conscious local decisions. XML and full IBANs remain in the encrypted bank vault. */
final class SepaRequests
{
    public const SECTION='sepa-order';
    public function __construct(private PDO $db,private string $actorId,private ?MemberBankVault $vault=null){}
    public static function json(array $value):string{return json_encode($value,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRESERVE_ZERO_FRACTION);}
    private function service():SepaOrders{return new SepaOrders($this->db,$this->actorId,$this->vault??=MemberBankVault::fromEnvironment($this->db,$this->actorId,ProductionRuntime::enabled()));}
    private function busy(\Throwable $failure):void
    {
        if(($failure instanceof \PDOException&&in_array((int)($failure->errorInfo[1]??0),[1205,3572],true))||($failure instanceof DomainException&&$failure->getMessage()==='Ein Speichervorgang läuft bereits. Bitte erneut versuchen.'))throw new DomainException('Lastschriftauftrag oder Quelle wird gerade bearbeitet. Erneut prüfen.',503,$failure);
    }
    private function read(callable $work):mixed
    {
        $own=!$this->db->inTransaction();try{if($own)$this->db->exec('START TRANSACTION');$result=$work();if($own)$this->db->commit();return $result;}
        catch(\Throwable $failure){if($own&&$this->db->inTransaction())$this->db->rollBack();$this->busy($failure);throw $failure;}
    }
    private function actor(bool $write=false):array
    {
        try{return Permissions::finance($this->db,$this->actorId,$write);}catch(DomainException $failure){throw new DomainException($write?'Aktuelle Finanzschreibberechtigung erforderlich.':'Aktuelle Finanzleseberechtigung erforderlich.',403,$failure);}
    }
    public function assertAccess(bool $write=false):array{return $this->read(fn():array=>$this->actor($write));}
    private function authority():array
    {
        $actor=$this->actor(true);$office=FinancePaymentAuthority::find($this->db,$this->actorId);if(!$office)throw new DomainException('Für diesen Zahlungsauftrag ist ein bestätigtes Vorstandsamt oder der Superuser mit Finanz-Schreibrecht erforderlich.',403);return ['actor'=>$actor,'office'=>$office];
    }
    public function assertAuthority():array{return $this->read(fn():array=>$this->authority()['actor']);}
    private function available():void
    {
        if(!Db::run($this->db,"SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='native_settings_requests'")->fetchColumn())throw new DomainException('Lastschriftentscheidungen benötigen das aktuelle installierte Paket.',503);
    }
    private static function orderId(string $id):void{if(!preg_match('/\A[a-f0-9]{32}\z/D',$id))throw new DomainException('Lastschriftauftrag nicht verfügbar.',404);}
    private static function hash(array $review):string{unset($review['hash']);return hash('sha256',self::json($review));}
    private function entrySource(string $id):array
    {
        $actor=$this->actor();$entry=(new Finance($this->db,$this->actorId))->currentEntry($id);$invoice=null;
        if(Db::run($this->db,'SELECT 1 FROM contribution_invoices WHERE club_id=? AND entry_id=? FOR SHARE NOWAIT',[$actor['club_id'],$id])->fetchColumn()){
            $original=(new ContributionInvoices($this->db,$this->actorId))->currentOriginal($id);$invoice=['invoice'=>$original['invoice'],'file'=>['document_id'=>$original['invoice']['document_id']]+$original['original']['file']+['snapshot_sha256'=>hash('sha256',$original['file']['snapshot']),'draft'=>$original['snapshot']['draft']]];
        }
        $entry_sha256=hash('sha256',self::json($entry));$notes_sha256=hash('sha256',(string)$entry['notes']);unset($entry['notes']);
        return compact('entry','entry_sha256','notes_sha256','invoice');
    }
    public function context(string $id):array
    {
        self::orderId($id);return $this->read(function()use($id):array{$this->authority();return $this->service()->decisionSnapshot($id);});
    }
    public function prepare(array $raw):array
    {
        $input=SepaInput::capture($raw);$normalized=SepaInput::normalize($input);
        return $this->read(function()use($input,$normalized):array{
            $authorization=$this->authority();$this->available();$operation=$normalized['operation'];$data=$normalized['data'];$service=$this->service();$source=['authorization'=>$authorization];
            if($operation==='prepare'){$preview=$service->preview($data['entries'],$data['account'],$data['collection_date'],$data['notice_date']);$source['preview']=$preview;$ids=array_column($preview['items'],'entry_id');if(Db::run($this->db,"SELECT entry_id FROM sepa_order_items WHERE club_id=? AND state='active' AND entry_id IN (".implode(',',array_fill(0,count($ids),'?')).') FOR SHARE NOWAIT',[$authorization['actor']['club_id'],...$ids])->fetchColumn())throw new DomainException('Eine Rechnung ist bereits in einem laufenden Lastschriftauftrag.',409);}
            else{
                $source['snapshot']=$service->decisionSnapshot($normalized['order'],in_array($operation,['approve','export'],true));$order=$source['snapshot']['order'];
                $states=['approve'=>['prepared'],'export'=>['approved','exported'],'submitted'=>['exported'],'cancel'=>['prepared','approved'],'return'=>['exported','submitted'],'fints_submitted'=>['approved'],'fints_not_sent'=>['approved'],'return_attach'=>['exported','submitted'],'return_existing'=>['exported','submitted'],'return_undo'=>['exported','submitted']];
                if(!in_array($order['state'],$states[$operation],true))throw new DomainException('Diese Aktion passt nicht mehr zum tatsächlichen Lastschriftstatus. Aktuellen Auftrag erneut prüfen.',409);
                $pending=$source['snapshot']['transport']['pending']??false;
                if($pending&&!in_array($operation,['fints_submitted','fints_not_sent'],true))throw new DomainException('FinTS-Ausgang dieses Auftrags ist noch offen. Bankstatus anhand eines tatsächlichen Nachweises klären; nicht erneut senden, exportieren oder verwerfen.',409);
                if(!$pending&&in_array($operation,['fints_submitted','fints_not_sent'],true))throw new DomainException('Genau den aktuellen offenen FinTS-Ausgang erneut prüfen.',409);
                $ids=array_column($order['items'],'entry_id');
                if($operation==='export'){
                    $approved=FinancePaymentAuthority::require($this->db,(string)$order['approved_by']);if((int)$approved['version']!==(int)$order['office_version'])throw new DomainException('Freigabeamt geändert. Auftrag neu prüfen.',409);$source['approval_office']=$approved;
                }
                if(in_array($operation,['return_attach','return_existing','return_undo'],true)){
                    if(!in_array($data['item'],array_column($order['items'],'id'),true))throw new DomainException('Eine dokumentierte Rücklastschrift genau dieses Auftrags auswählen.',422);
                    $kind=['return_attach'=>'attach','return_existing'=>'attach_existing','return_undo'=>'undo'][$operation];
                    $source['correction']=(new Finance($this->db,$this->actorId))->nativeDebitCorrectionReview($data['item'],$kind,$data['return_allocations']??[],$data['return_financials']??[],$input['data']['reason'],$input['data']['evidence'],$this->vault);
                }
                if($operation==='return'){
                    $items=array_values(array_filter($order['items'],static fn(array $item):bool=>$item['id']===$data['item']&&$item['state']==='active'));if(count($items)!==1)throw new DomainException('Genau einen aktiven Einzug dieses Lastschriftauftrags auswählen.',422);$item=$items[0];$finance=new Finance($this->db,$this->actorId);$transaction=$finance->transactionDetail($data['transaction'],true);
                    if($transaction['account_id']!==$order['account_id']||(int)$transaction['cents']!==-(int)$item['cents']||$transaction['reversal_of']||$transaction['reversed_on']||(int)$transaction['allocated_cents']!==0)throw new DomainException('Unzugeordneten Rücklastschriftumsatz auf demselben Konto und mit genauem Einzugsbetrag auswählen. Gebühren separat erfassen.',422);
                    $source['return']=['item'=>$item,'transaction'=>$transaction,'allocations'=>$finance->allocations($item['entry_id'],false,true)];
                    $financial=$service->returnFundingReview($item['id'],$data['transaction'],$input['data']['reason'],$data['return_allocations']??null,$data['document']!==''?$data['document']:null);if($financial!==null)$source['return']['financial']=$financial;
                    foreach($source['return']['allocations']as$allocation)$source['return']['transactions'][$allocation['transaction_id']]=$finance->transactionDetail($allocation['transaction_id'],true);
                    if($data['document']!=='')$source['return']['document']=$this->document($data['document']);
                }
            }
            foreach($ids as$id){$source['entries'][$id]=$this->entrySource($id);if(in_array($operation,['prepare','approve','export'],true)&&($source['entries'][$id]['invoice']['file']['draft']??false))throw new DomainException('Beitragsrechnung zuerst endgültig ausgeben und tatsächlich über die Lastschrift informieren.',422);}
            $review=['input'=>$input,'normalized'=>$normalized,'source'=>$source,'no_transport'=>true,'no_new_money'=>true];return $review+['hash'=>self::hash($review)];
        });
    }
    public function returnFundingSources(string $item,mixed $page=1,mixed $search=''):array{return (new Finance($this->db,$this->actorId))->nativeDebitReturnSourcesPage($item,$page,$search);}
    public function returnFinancials(string $item):array
    {
        return $this->read(function()use($item):array{$a=$this->authority()['actor'];$effect=NativeDebitReturnHistory::current($this->db,$a['club_id'],$item,true);if(!$effect['record'])return [];$finance=new Finance($this->db,$this->actorId);$rows=[];foreach($finance->partialReturnHistory($effect['record']['transaction_id'])as$first)if($first['active']&&$first['counter_id']===$effect['record']['transaction_id']&&!isset($first['request']['native_debit']))$rows[]=['id'=>$first['id'],'cents'=>(int)$first['cents'],'created_at'=>$first['created_at'],'reason'=>$first['reason']];return $rows;});
    }
    public function returnFundingChoice(string $item,string $allocation):array{return (new Finance($this->db,$this->actorId))->nativeDebitReturnSourceChoice($item,$allocation);}
    public function accept(array $raw,string $key,string $reviewHash,bool $approved):array
    {
        $input=SepaInput::capture($raw);if(!preg_match('/\A[a-f0-9]{32}\z/D',$key)||!preg_match('/\A[a-f0-9]{64}\z/D',$reviewHash))throw new DomainException('Eigene ursprüngliche Lastschriftkennung und Prüfung auswählen.',422);if(!$approved)throw new DomainException('Genau diese geprüfte Lastschriftentscheidung ausdrücklich bestätigen.',422);
        $club=Db::run($this->db,'SELECT club_id FROM actors WHERE id=?',[$this->actorId])->fetchColumn();if(!is_string($club)||$club==='')throw new DomainException('Eigenen aktiven Verein auswählen.',403);
        try{return Transactions::run($this->db,function()use($input,$key,$reviewHash,$club):array{
            if(!Db::run($this->db,'SELECT id FROM clubs WHERE id=? FOR UPDATE NOWAIT',[$club])->fetchColumn())throw new DomainException('Eigenen aktiven Verein auswählen.',403);$actor=$this->actor();$this->available();if($actor['club_id']!==$club)throw new DomainException('Vereinszuordnung inzwischen geändert.',403);$json=self::json($input);$inputHash=hash('sha256',self::SECTION."\0".$json);
            $old=Db::run($this->db,'SELECT * FROM native_settings_requests WHERE club_id=? AND request_key=? FOR SHARE NOWAIT',[$club,$key])->fetch();
            if($old){if($old['actor_id']!==$actor['id']||$old['section']!==self::SECTION||$old['input_json']!==$json||!hash_equals($old['input_sha256'],$inputHash))throw new DomainException('Diese Lastschriftkennung gehört zu anderen ursprünglichen Angaben.',409);$first=$this->decode($old);if(!hash_equals($first['review']['hash'],$reviewHash))throw new DomainException('Diese Lastschriftkennung gehört zu einer anderen ursprünglichen Prüfung.',409);return $first;}
            $review=$this->prepare($input);if(!hash_equals($review['hash'],$reviewHash))throw new DomainException('Auftrag, Forderung, Mandat oder Freigabequelle inzwischen geändert. Ganze Entscheidung erneut prüfen.',409);
            $operation=$review['normalized']['operation'];$data=$review['normalized']['data'];$id=$review['normalized']['order'];$version=(int)($review['source']['snapshot']['order']['version']??0);$service=$this->service();
            if($operation==='prepare')$id=$service->prepare($data['entries'],$data['account'],$data['collection_date'],$data['notice_date'],$review['source']['preview']['hash'],$key);
            elseif($operation==='approve')$service->approve($id,$version);
            elseif($operation==='export'){$xml=$service->export($id,$version);if(!hash_equals($review['source']['snapshot']['xml_sha256'],hash('sha256',$xml)))throw new DomainException('Tatsächliche Bankdatei bitte prüfen lassen.',503);unset($xml);}
            elseif($operation==='submitted')$service->submitted($id,$version,$data['bank_reference']);
            elseif(in_array($operation,['fints_submitted','fints_not_sent'],true))$service->resolveFints($id,$version,(int)$review['source']['snapshot']['transport']['event']['sequence'],$operation==='fints_submitted',$data['bank_reference']);
            elseif($operation==='cancel')$service->cancel($id,$version);
            elseif(in_array($operation,['return_attach','return_existing','return_undo'],true))$correction=(new Finance($this->db,$this->actorId))->correctNativeDebitLocked($data['item'],['return_attach'=>'attach','return_existing'=>'attach_existing','return_undo'=>'undo'][$operation],$data['return_allocations']??[],$data['return_financials']??[],$input['data']['reason'],$input['data']['evidence'],$key,$review['source']['correction']['hash'],$this->vault);
            else $financialReturn=$service->recordReturn($data['item'],$data['transaction'],$input['data']['reason'],$data['document']!==''?$data['document']:null,$data['return_allocations']??null,$key,$review['source']['return']['financial']['hash']??null);
            $result=$service->decisionSnapshot($id);
            if(isset($correction)){
                $finance=new Finance($this->db,$this->actorId);$basis=$review['source']['correction']['review'];$entry=$finance->currentEntry($basis['entry']['id']);
                $result['correction']=$correction+['entry'=>array_intersect_key($entry,array_fill_keys(['id','club_id','version','amount_cents','allocated_cents','remaining_cents','payment_state','status'],true)),'transaction'=>$finance->transactionDetail($basis['counter']['id'],true)];
            }
            if($operation==='return'){$returned=array_values(array_filter($result['order']['items'],static fn(array $item):bool=>$item['id']===$data['item']));if(count($returned)!==1||$returned[0]['state']!=='returned')throw new DomainException('Tatsächliche Rücklastschriftentscheidung bitte prüfen lassen.',503);$finance=new Finance($this->db,$this->actorId);$entry=$finance->currentEntry($returned[0]['entry_id']);$result['return']=['item'=>$returned[0],'entry'=>array_intersect_key($entry,array_fill_keys(['id','club_id','version','amount_cents','allocated_cents','remaining_cents','payment_state','status'],true)),'transaction'=>$finance->transactionDetail($data['transaction'],true)];if(isset($financialReturn))$result['return']['financial']=$financialReturn;}
            $now=new DateTimeImmutable('now',new DateTimeZone('UTC'));$original=self::json(['format'=>'dvm-sepa-order-original/1','request_key'=>$key,'club_id'=>$club,'actor_id'=>$actor['id'],'section'=>self::SECTION,'input'=>$input,'review'=>$review,'approved'=>true,'result'=>$result,'created_at'=>$now->format('Y-m-d\TH:i:s.u\Z')]);
            Db::run($this->db,'INSERT INTO audit_events(club_id,actor_id,action,object_id,object_version,happened_at)VALUES(?,?,?,?,?,?)',[$club,$actor['id'],'sepa.decision.'.$operation,$id,(int)$result['order']['version'],$now->format('Y-m-d\TH:i:s.u\Z')]);
            Db::run($this->db,'INSERT INTO native_settings_requests(club_id,request_key,actor_id,section,version,input_sha256,input_json,original_sha256,original_json,created_at)VALUES(?,?,?,?,1,?,?,?,?,?)',[$club,$key,$actor['id'],self::SECTION,$inputHash,$json,hash('sha256',$original),$original,$now->format('Y-m-d H:i:s.u')]);
            return $this->decode(Db::run($this->db,'SELECT * FROM native_settings_requests WHERE club_id=? AND request_key=? FOR SHARE NOWAIT',[$club,$key])->fetch());
        });}catch(\Throwable $failure){$this->busy($failure);throw $failure;}
    }
    private function decode(array $row):array
    {
        $input=json_decode($row['input_json'],true,64,JSON_THROW_ON_ERROR);$first=json_decode($row['original_json'],true,64,JSON_THROW_ON_ERROR);
        if(!is_array($input)||!is_array($first)||$row['section']!==self::SECTION||(int)$row['version']!==1||!hash_equals($row['input_sha256'],hash('sha256',self::SECTION."\0".$row['input_json']))||!hash_equals($row['original_sha256'],hash('sha256',$row['original_json']))||($first['format']??null)!=='dvm-sepa-order-original/1'||($first['request_key']??null)!==$row['request_key']||($first['club_id']??null)!==$row['club_id']||($first['actor_id']??null)!==$row['actor_id']||($first['section']??null)!==self::SECTION||($first['input']??null)!==$input||($first['review']['input']??null)!==$input||($first['approved']??null)!==true||($first['review']['no_transport']??null)!==true||($first['review']['no_new_money']??null)!==true||!hash_equals((string)($first['review']['hash']??''),self::hash($first['review']??[]))||($first['review']['normalized']??null)!==SepaInput::normalize($input)||($first['created_at']??null)!==(new DateTimeImmutable($row['created_at'],new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.u\Z'))throw new DomainException('Ursprüngliche persönliche Lastschriftentscheidung bitte prüfen lassen.',503);
        $order=$first['result']['order']??[];$operation=$input['operation'];$states=['prepare'=>'prepared','approve'=>'approved','export'=>'exported','submitted'=>'submitted','cancel'=>'cancelled','fints_submitted'=>'submitted','fints_not_sent'=>'approved'];
        if(($order['club_id']??null)!==$row['club_id']||!preg_match('/\A[a-f0-9]{32}\z/D',(string)($order['id']??''))||($operation!=='prepare'&&$order['id']!==$input['order'])||($operation==='prepare'&&($order['request_key']??null)!==$row['request_key'])||(!in_array($operation,['return','return_attach','return_existing','return_undo'],true)&&($order['state']??null)!==$states[$operation])||isset($order['sealed_snapshot'])||!preg_match('/\A[a-f0-9]{64}\z/D',(string)($first['result']['xml_sha256']??''))||!preg_match('/\A[a-f0-9]{64}\z/D',(string)($first['result']['sealed_sha256']??'')))throw new DomainException('Ursprüngliche persönliche Lastschriftentscheidung bitte prüfen lassen.',503);
        if($operation==='return'&&(($first['result']['return']['item']['id']??null)!==$input['data']['item']||($first['result']['return']['item']['state']??null)!=='returned'||($first['result']['return']['entry']['id']??null)!==($first['result']['return']['item']['entry_id']??null)||($first['result']['return']['transaction']['id']??null)!==$input['data']['transaction']))throw new DomainException('Ursprüngliche persönliche Lastschriftentscheidung bitte prüfen lassen.',503);
        if(in_array($operation,['return_attach','return_existing','return_undo'],true)){
            $event=NativeDebitReturnHistory::event($this->db,$row['club_id'],(string)($first['result']['correction']['event']??''),$this->db->inTransaction());
            if(!$event||$event['native_request_key']!==$row['request_key']||$event['created_by']!==$row['actor_id']||$event['item_id']!==$input['data']['item']||$event['order_id']!==$input['order']||$event['request']['reason']!==$input['data']['reason']||$event['review']!==($first['review']['source']['correction']['review']??null)||$event['kind']!==['return_attach'=>'attach','return_existing'=>'attach_existing','return_undo'=>'undo'][$operation])throw new DomainException('Ursprüngliche gemeinsame Rücklastschriftänderung prüfen lassen.',503);
            $basis=$event['review'];$face=(int)$basis['entry']['amount_cents'];$paid=(int)$basis['entry']['allocated_cents'];
            if($event['kind']==='attach')$paid-=array_sum(array_column($basis['funding']['selected'],'amount_cents'));
            if($event['kind']==='undo'){if($basis['legacy']!==null)$paid=$face;elseif($basis['restored_ranges']!==null)$paid=\Kulturhus\Verein\PaymentFundingRanges::cents($basis['restored_ranges'],$face);}
            $after=$first['result']['correction'];$entry=$after['entry']??[];$money=$after['transaction']??[];$remaining=$event['kind']==='undo'?abs((int)$basis['counter']['cents']):0;
            if(($entry['id']??null)!==$basis['entry']['id']||($entry['club_id']??null)!==$row['club_id']||($entry['amount_cents']??null)!==$face||($entry['allocated_cents']??null)!==$paid||($entry['remaining_cents']??null)!==$face-$paid||($money['id']??null)!==$event['counter_id']||($money['club_id']??null)!==$row['club_id']||($money['account_id']??null)!==$basis['counter']['account_id']||($money['booked_on']??null)!==$basis['counter']['booked_on']||(int)($money['cents']??0)!==(int)$basis['counter']['cents']||($money['remaining_cents']??null)!==$remaining)throw new DomainException('Ursprünglichen Buchungsstand der gemeinsamen Rücklastschriftänderung prüfen lassen.',503);

        }
        if(isset($first['result']['correction']['financial'])){
            $financial=$first['result']['correction']['financial'];$stored=\Kulturhus\Verein\PartialReturnOriginals::read($this->db,$row['club_id'],$financial['id'],$this->db->inTransaction());foreach(['request_sha256','review_sha256','result_sha256']as$field)if(!hash_equals($stored[$field],(string)($financial[$field]??'')))throw new DomainException('Ursprüngliche gemeinsame Rücklastschriftänderung prüfen lassen.',503);
            if(($event['financial_id']??null)!==$stored['id']||($stored['request']['native_debit']['native_request_key']??null)!==$row['request_key'])throw new DomainException('Ursprüngliche gemeinsame Rücklastschriftänderung prüfen lassen.',503);
        }
        if(isset($first['result']['return']['financial'])){$financial=$first['result']['return']['financial'];$stored=\Kulturhus\Verein\PartialReturnOriginals::read($this->db,$row['club_id'],$financial['id'],$this->db->inTransaction());foreach(['request_sha256','review_sha256','result_sha256']as$field)if(!hash_equals($stored[$field],(string)($financial[$field]??'')))throw new DomainException('Ursprüngliche Rücklastschrift und Finanzzuordnung gemeinsam prüfen lassen.',503);if(($stored['request']['native_debit']['native_request_key']??null)!==$row['request_key']||($stored['request']['native_debit']['item_id']??null)!==$input['data']['item']||($stored['request']['native_debit']['order_id']??null)!==$input['order']||$stored['counter_id']!==$input['data']['transaction']||$stored['created_by']!==$row['actor_id'])throw new DomainException('Ursprüngliche Rücklastschrift und Finanzzuordnung gemeinsam prüfen lassen.',503);}
        return ['key'=>$row['request_key'],'input'=>$input,'review'=>$first['review'],'result'=>$first['result'],'created_at'=>$first['created_at'],'original_json'=>$row['original_json'],'sha256'=>$row['original_sha256']];
    }
    public function receipt(string $key):array
    {
        if(!preg_match('/\A[a-f0-9]{32}\z/D',$key))throw new DomainException('Eigene ursprüngliche Lastschriftentscheidung nicht verfügbar.',404);
        return $this->read(function()use($key):array{$actor=$this->actor();$this->available();$row=Db::run($this->db,'SELECT * FROM native_settings_requests WHERE club_id=? AND actor_id=? AND section=? AND request_key=? FOR SHARE NOWAIT',[$actor['club_id'],$actor['id'],self::SECTION,$key])->fetch();if(!$row)throw new DomainException('Eigene ursprüngliche Lastschriftentscheidung nicht verfügbar.',404);return $this->decode($row);});
    }
    public function xml(string $key):string
    {
        return $this->read(function()use($key):string{$first=$this->receipt($key);$this->authority();if($first['input']['operation']!=='export')throw new DomainException('Eigene ursprüngliche exportierte Bankdatei nicht verfügbar.',404);return $this->service()->exportedOriginal($first['result']['order']['id'],$first['result']['sealed_sha256'],$first['result']['xml_sha256']);});
    }
    public function orderXml(string $id):string
    {
        self::orderId($id);return $this->read(function()use($id):string{$this->authority();$snapshot=$this->service()->decisionSnapshot($id);return $this->service()->exportedOriginal($id,$snapshot['sealed_sha256'],$snapshot['xml_sha256']);});
    }
    private static function controls(array $query,bool $state=false):array
    {
        $search=$query['query']??'';$page=$query['page']??'1';$status=$query['state']??'';
        if(!is_string($search)||preg_match('//u',$search)!==1||mb_strlen($search,'UTF-8')>200||preg_match('/[\x00-\x1f\x7f]/',$search)||(!is_string($page)&&!is_int($page))||!preg_match('/\A[1-9][0-9]{0,8}\z/D',(string)$page)||($state&&(!is_string($status)||!in_array($status,['','prepared','approved','exported','submitted','cancelled'],true))))throw new DomainException('Ganze Lastschriftsuche, Status und Seite prüfen.',422);
        return ['search'=>$search,'page'=>(string)$page,'state'=>$status];
    }
    private function page(string $select,string $from,string $where,array $args,string $sort,array $filter):array
    {
        $total=(int)Db::run($this->db,'SELECT COUNT(*) '.$from.$where.' FOR SHARE NOWAIT',$args)->fetchColumn();$pages=max(1,(int)ceil($total/25));$error=(int)$filter['page']>$pages?'Verfügbare Lastschriftseite auswählen.':'';
        $rows=$error===''?Db::run($this->db,'SELECT '.$select.' '.$from.$where.' ORDER BY '.$sort.' LIMIT 25 OFFSET '.(((int)$filter['page']-1)*25).' FOR SHARE NOWAIT',$args)->fetchAll():[];return ['rows'=>$rows,'total'=>$total,'pages'=>$pages,'page_input'=>$filter['page'],'page'=>$error===''?(int)$filter['page']:null,'error'=>$error,'per_page'=>25];
    }
    public function listing(array $query=[]):array
    {
        $filter=self::controls($query,true);return $this->read(function()use($filter):array{$actor=$this->authority()['actor'];$where=' WHERE o.club_id=?';$args=[$actor['club_id']];if($filter['search']!==''){$where.=' AND (LOCATE(LOWER(?),LOWER(CONCAT_WS(CHAR(10),o.id,o.collection_date,o.bank_reference)))>0 OR EXISTS(SELECT 1 FROM sepa_order_items i JOIN finance_entries e ON e.club_id=i.club_id AND e.id=i.entry_id WHERE i.club_id=o.club_id AND i.order_id=o.id AND LOCATE(LOWER(?),LOWER(CONCAT_WS(CHAR(10),e.display_number,e.counterparty,e.description)))>0))';array_push($args,$filter['search'],$filter['search']);}if($filter['state']!==''){$where.=' AND o.state=?';$args[]=$filter['state'];}return $this->page('o.id,o.collection_date,o.state,o.total_cents,o.version,o.created_at,o.bank_reference','FROM sepa_orders o',$where,$args,'o.created_at DESC,o.id DESC',$filter);});
    }
    public function candidates(array $query=[]):array
    {
        $filter=self::controls($query);return $this->read(function()use($filter):array{$actor=$this->authority()['actor'];$where=" WHERE e.club_id=? AND e.kind='contribution' AND e.status='open' AND NOT EXISTS(SELECT 1 FROM sepa_order_items i WHERE i.club_id=e.club_id AND i.entry_id=e.id AND i.state='active')";$args=[$actor['club_id']];if($filter['search']!==''){$where.=' AND LOCATE(LOWER(?),LOWER(CONCAT_WS(CHAR(10),e.display_number,e.counterparty,e.description)))>0';$args[]=$filter['search'];}$result=$this->page('e.*','FROM finance_entries e',$where,$args,'e.due_date,e.id',$filter);$finance=new Finance($this->db,$this->actorId);foreach($result['rows']as&$row)$row=$finance->currentEntry($row['id']);unset($row);return $result;});
    }
    public function accounts():array
    {
        return $this->read(function():array{$actor=$this->authority()['actor'];return Db::run($this->db,"SELECT a.id,a.name FROM finance_accounts a LEFT JOIN finance_account_settings s ON s.club_id=a.club_id AND s.account_id=a.id WHERE a.club_id=? AND a.kind='offline' AND COALESCE(s.active,1)=1 ORDER BY a.name,a.id FOR SHARE NOWAIT",[$actor['club_id']])->fetchAll();});
    }
    public function selected(array $ids):array
    {
        if(count($ids)>500)throw new DomainException('1 bis 500 verschiedene Beitragsrechnungen auswählen.',422);
        return $this->read(function()use($ids):array{$actor=$this->authority()['actor'];$ids=array_values(array_filter($ids,static fn(mixed $id):bool=>is_string($id)&&preg_match('/\A[a-zA-Z0-9_-]{1,128}\z/D',$id)===1));if(!$ids)return[];$rows=Db::run($this->db,"SELECT id,display_number,counterparty,amount_cents FROM finance_entries WHERE club_id=? AND kind='contribution' AND id IN (".implode(',',array_fill(0,count($ids),'?')).') FOR SHARE NOWAIT',[$actor['club_id'],...$ids])->fetchAll();$finance=new Finance($this->db,$this->actorId);foreach($rows as&$row)$row=$finance->currentEntry($row['id']);unset($row);return array_column($rows,null,'id');});
    }
    public function returns(string $order,array $query=[]):array
    {
        self::orderId($order);$filter=self::controls($query);return $this->read(function()use($order,$filter):array{
            $actor=$this->authority()['actor'];$snapshot=$this->service()->get($order);$from='FROM finance_transactions t LEFT JOIN finance_transactions r ON r.club_id=t.club_id AND r.reversal_of=t.id LEFT JOIN finance_allocations l ON l.club_id=t.club_id AND l.transaction_id=t.id AND l.voided_at IS NULL';$where=' WHERE t.club_id=? AND t.account_id=? AND t.cents<0 AND t.reversal_of IS NULL AND r.id IS NULL AND l.id IS NULL AND '.PaymentFunding::consumedSql($this->db).'=0';$args=[$actor['club_id'],$snapshot['account_id']];if($filter['search']!==''){$where.=' AND LOCATE(LOWER(?),LOWER(CONCAT_WS(CHAR(10),t.id,t.booked_on,t.counterparty,t.description)))>0';$args[]=$filter['search'];}return $this->page('t.*',$from,$where,$args,'t.booked_on DESC,t.id DESC',$filter);
        });
    }
    public function document(string $id):array
    {
        return $this->read(function()use($id):array{$this->authority();$source=(new Documents($this->db,$this->actorId))->currentFinancialDocument($id);if($source['document']['origin']!=='upload'||$source['document']['state']!=='active'||$source['trashed']||$source['document']['admission_only']||!in_array('finance',$source['document']['scopes'],true))throw new DomainException('Ein eigenes aktives hochgeladenes Finanzdokument als Rücklastschriftnachweis auswählen.',422);return $source;});
    }
    public function documents(array $query=[]):array
    {
        $filter=self::controls($query);return $this->read(function()use($filter):array{$actor=$this->authority()['actor'];$ids=Db::run($this->db,"SELECT id FROM documents WHERE club_id=? AND origin='upload' AND state='active' AND admission_only=0 AND JSON_CONTAINS(scopes,'\"finance\"') ORDER BY title,id FOR SHARE NOWAIT",[$actor['club_id']])->fetchAll(PDO::FETCH_COLUMN);$rows=[];
            foreach($ids as$id){try{$source=$this->document($id);}catch(DomainException $failure){if(in_array($failure->getCode(),[403,404,422],true))continue;throw $failure;}if($filter['search']!==''&&!str_contains(mb_strtolower($source['document']['title']."\n".$source['file']['name']."\n".$source['path'],'UTF-8'),mb_strtolower($filter['search'],'UTF-8')))continue;$rows[]=$source;}
            $total=count($rows);$pages=max(1,(int)ceil($total/25));$error=(int)$filter['page']>$pages?'Verfügbare Lastschriftseite auswählen.':'';return ['rows'=>$error===''?array_slice($rows,((int)$filter['page']-1)*25,25):[],'total'=>$total,'pages'=>$pages,'page_input'=>$filter['page'],'page'=>$error===''?(int)$filter['page']:null,'error'=>$error,'per_page'=>25];
        });
    }
    public function history(?string $order=null,array $query=[]):array
    {
        if($order!==null)self::orderId($order);$filter=self::controls($query);return $this->read(function()use($order,$filter):array{$actor=$this->actor();$this->available();$where=' WHERE club_id=? AND actor_id=? AND section=?';$args=[$actor['club_id'],$actor['id'],self::SECTION];if($order!==null){$where.=" AND JSON_UNQUOTE(JSON_EXTRACT(original_json,'$.result.order.id'))=?";$args[]=$order;}if($filter['search']!==''){$where.=' AND LOCATE(LOWER(?),LOWER(CONCAT_WS(CHAR(10),request_key,created_at,input_json)))>0';$args[]=$filter['search'];}return $this->page('request_key,created_at','FROM native_settings_requests',$where,$args,'created_at DESC,request_key DESC',$filter);});
    }
}
