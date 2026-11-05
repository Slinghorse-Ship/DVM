<?php declare(strict_types=1);
namespace Kulturhus\Verein\Finance\Banking\NativeTransfer;

use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use PDO;
use Kulturhus\Native\Shared\Db;
use Kulturhus\Verein\{Permissions,Finance,FinancePaymentAuthority,TransferOrders,MemberBankVault,ProductionRuntime,InvoiceApprovals,InvoicePaymentReports,Documents,Settings};
use Kulturhus\Verein\Infrastructure\Database\Transactions;

/** Atomic conscious local decisions. XML and full IBANs remain in the encrypted bank vault. */
final class TransferRequests
{
    public const SECTION='transfer-order';
    public function __construct(private PDO $db,private string $actorId,private ?MemberBankVault $vault=null){}
    public static function json(array $value):string{return json_encode($value,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRESERVE_ZERO_FRACTION);}
    private function service():TransferOrders{return new TransferOrders($this->db,$this->actorId,$this->vault??=MemberBankVault::fromEnvironment($this->db,$this->actorId,ProductionRuntime::enabled()));}
    private function busy(\Throwable $failure):void
    {
        if(($failure instanceof \PDOException&&in_array((int)($failure->errorInfo[1]??0),[1205,3572],true))||($failure instanceof DomainException&&$failure->getMessage()==='Ein Speichervorgang läuft bereits. Bitte erneut versuchen.'))throw new DomainException('Überweisungsauftrag oder Quelle wird gerade bearbeitet. Erneut prüfen.',503,$failure);
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
        if(!Db::run($this->db,"SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='native_settings_requests'")->fetchColumn())throw new DomainException('Überweisungsentscheidungen benötigen das aktuelle installierte Paket.',503);
    }
    private static function orderId(string $id):void{if(!preg_match('/\A[a-f0-9]{32}\z/D',$id))throw new DomainException('Überweisungsauftrag nicht verfügbar.',404);}
    private static function hash(array $review):string{unset($review['hash']);return hash('sha256',self::json($review));}
    private function entrySource(string $id):array
    {
        $actor=$this->actor();$entry=(new Finance($this->db,$this->actorId))->currentEntry($id);if($entry['kind']!=='expense')throw new DomainException('Eigene Ausgabenrechnung für diesen Zahlungsauftrag auswählen.',422);
        $approval=(new InvoiceApprovals($this->db,$this->actorId))->currentState($id);if(is_array($approval['last'])){$approval['last']['snapshot_sha256']=hash('sha256',$approval['last']['snapshot']);unset($approval['last']['snapshot']);}
        $reports=(new InvoicePaymentReports($this->db,$this->actorId))->summary($id,true);$originals=[];$unavailable=0;
        $ids=Db::run($this->db,"SELECT document_id FROM document_links WHERE club_id=? AND source_type='entry' AND source_id=? ORDER BY document_id FOR SHARE NOWAIT",[$actor['club_id'],$id])->fetchAll(PDO::FETCH_COLUMN);
        foreach($ids as$document){try{$originals[]=(new Documents($this->db,$this->actorId))->currentFinancialDocument($document);}catch(DomainException $failure){if($failure->getCode()!==403)throw $failure;++$unavailable;}}
        return compact('entry','approval','reports','originals','unavailable');
    }
    public function context(string $entry,string $id):array
    {
        self::orderId($id);return $this->read(function()use($entry,$id):array{$this->actor(true);$snapshot=$this->service()->decisionSnapshot($id);if($snapshot['order']['entry_id']!==$entry)throw new DomainException('Überweisungsauftrag dieser Rechnung nicht verfügbar.',404);return $snapshot;});
    }
    public function entry(string $entry):array{return $this->read(function()use($entry):array{$this->actor();return $this->entrySource($entry);});}
    /** The current source decides the recipient; no separate local preparation is needed. */
    public function paymentReviews(string $entry,?array $existing=null):array
    {
        return $this->read(function()use($entry,$existing):array{
            $this->authority();
            if($existing!==null){
                $id=$existing['order']['id']??'';$current=$this->context($entry,$id);
                if($current['order']['state']!=='prepared')throw new DomainException('Diesen Zahlungsauftrag im Bankdialog fortsetzen.',409);
                $input=TransferInput::initial($entry,$id);$input['operation']='approve';$input['data']['reason']='Zahlung der freigegebenen Rechnung';
                return [($current['source']['transfer_mode']??'standard')=>$this->prepare($input)];
            }
            $invoice=$this->entrySource($entry);(new InvoiceApprovals($this->db,$this->actorId))->assertApproved($entry,true);
            $bank=(new Settings($this->db,$this->actorId))->section('bank')['data'];$account=(string)($bank['account_id']??'');
            if($account==='')throw new DomainException('Zuerst das Vereinskonto in den Bankeinstellungen hinterlegen.',422);
            $reference=(string)$invoice['entry']['display_number'];
            if(preg_match('/(?:\A|\R|\\\\n)Lieferanten-Rechnungsnummer: ([^\r\n]+)/u',(string)($invoice['entry']['notes']??''),$match))$reference=trim($match[1]);
            $purpose=trim(preg_replace('/[\x00-\x1f\x7f]+/u',' ',$reference.' · '.trim((string)$invoice['entry']['description'])));
            $purpose=mb_substr($purpose,0,140,'UTF-8');$reviews=[];
            foreach(['standard','instant']as$mode){
                $input=TransferInput::initial($entry);$input['operation']='pay';$input['data']=array_replace($input['data'],['account'=>$account,'execution_date'=>Finance::today(),'purpose'=>$purpose,'transfer_mode'=>$mode,'reason'=>'Zahlung der freigegebenen Rechnung']);
                $reviews[$mode]=$this->prepare($input);
            }
            return $reviews;
        });
    }
    /** Resume a still-running order instead of offering a second payment. */
    public function paymentOrder(string $entry,string $id=''):?array
    {
        return $this->read(function()use($entry,$id):?array{
            $actor=$this->actor(true);if($id!=='')return $this->context($entry,$id);
            $rows=Db::run($this->db,"SELECT id,state FROM finance_transfer_orders WHERE club_id=? AND entry_id=? AND state IN ('prepared','approved','exported','submitted') ORDER BY created_at DESC,id DESC FOR SHARE NOWAIT",[$actor['club_id'],$entry])->fetchAll();
            $finance=new Finance($this->db,$this->actorId);
            foreach($rows as$row)if($row['state']!=='submitted'||!$finance->paymentReturnResolvesTransfer($row['id']))return $this->context($entry,$row['id']);
            return null;
        });
    }
    public function prepare(array $raw):array
    {
        $input=TransferInput::capture($raw);$normalized=TransferInput::normalize($input);
        return $this->read(function()use($input,$normalized):array{
            $operation=$normalized['operation'];$authorization=in_array($operation,['prepare','cancel'],true)?['actor'=>$this->actor(true)]:$this->authority();$this->available();$data=$normalized['data'];$service=$this->service();$source=['authorization'=>$authorization,'invoice'=>$this->entrySource($normalized['entry'])];
            if(in_array($operation,['prepare','pay'],true)){
                if($operation==='pay')(new InvoiceApprovals($this->db,$this->actorId))->assertApproved($normalized['entry'],true);
                $preview=$service->preview($normalized['entry'],$data['account'],$data['execution_date'],$data['purpose'],$data['transfer_mode']);if($preview['execution_date']!==$data['execution_date'])throw new DomainException('Echtzeitüberweisung ausdrücklich für den heutigen Ausführungstag prüfen.',422);$source['preview']=$preview;
                $service->assertNoPendingOrder($normalized['entry']);
            }else{
                $source['snapshot']=$service->decisionSnapshot($normalized['order'],in_array($operation,['approve','export'],true));$order=$source['snapshot']['order'];if($order['entry_id']!==$normalized['entry'])throw new DomainException('Überweisungsauftrag dieser Rechnung nicht verfügbar.',404);
                $states=['approve'=>['prepared'],'export'=>['approved','exported'],'submitted'=>['exported'],'cancel'=>['prepared','approved'],'fints_submitted'=>['approved'],'fints_not_sent'=>['approved']];if(!in_array($order['state'],$states[$operation],true))throw new DomainException('Diese Aktion passt nicht mehr zum tatsächlichen Überweisungsstatus. Aktuellen Auftrag erneut prüfen.',409);
                $pending=$source['snapshot']['transport']['pending']??false;if($pending&&!in_array($operation,['fints_submitted','fints_not_sent'],true))throw new DomainException('FinTS-Ausgang dieses Auftrags ist noch offen. Bankstatus anhand eines tatsächlichen Nachweises klären; nicht erneut senden, exportieren oder verwerfen.',409);if(!$pending&&in_array($operation,['fints_submitted','fints_not_sent'],true))throw new DomainException('Genau den aktuellen offenen FinTS-Ausgang erneut prüfen.',409);
                if(in_array($operation,['approve','export'],true))(new InvoiceApprovals($this->db,$this->actorId))->assertApproved($normalized['entry'],true);
                if($operation==='export'){$approved=FinancePaymentAuthority::require($this->db,(string)$order['approved_by']);if((int)$approved['version']!==(int)$order['office_version'])throw new DomainException('Freigabeamt geändert. Auftrag neu prüfen.',409);$source['approval_office']=$approved;}
            }
            $review=['input'=>$input,'normalized'=>$normalized,'source'=>$source,'no_transport'=>true,'no_new_money'=>true];return $review+['hash'=>self::hash($review)];
        });
    }
    public function accept(array $raw,string $key,string $reviewHash,bool $approved):array
    {
        $input=TransferInput::capture($raw);if(!preg_match('/\A[a-f0-9]{32}\z/D',$key)||!preg_match('/\A[a-f0-9]{64}\z/D',$reviewHash))throw new DomainException('Eigene ursprüngliche Überweisungskennung und Prüfung auswählen.',422);if(!$approved)throw new DomainException('Genau diese geprüfte Überweisungsentscheidung ausdrücklich bestätigen.',422);
        $club=Db::run($this->db,'SELECT club_id FROM actors WHERE id=?',[$this->actorId])->fetchColumn();if(!is_string($club)||$club==='')throw new DomainException('Eigenen aktiven Verein auswählen.',403);
        try{return Transactions::run($this->db,function()use($input,$key,$reviewHash,$club):array{
            if(!Db::run($this->db,'SELECT id FROM clubs WHERE id=? FOR UPDATE NOWAIT',[$club])->fetchColumn())throw new DomainException('Eigenen aktiven Verein auswählen.',403);$actor=$this->actor();$this->available();if($actor['club_id']!==$club)throw new DomainException('Vereinszuordnung inzwischen geändert.',403);$json=self::json($input);$inputHash=hash('sha256',self::SECTION."\0".$json);
            $old=Db::run($this->db,'SELECT * FROM native_settings_requests WHERE club_id=? AND request_key=? FOR SHARE NOWAIT',[$club,$key])->fetch();
            if($old){if($old['actor_id']!==$actor['id']||$old['section']!==self::SECTION||$old['input_json']!==$json||!hash_equals($old['input_sha256'],$inputHash))throw new DomainException('Diese Überweisungskennung gehört zu anderen ursprünglichen Angaben.',409);$first=$this->decode($old);if(!hash_equals($first['review']['hash'],$reviewHash))throw new DomainException('Diese Überweisungskennung gehört zu einer anderen ursprünglichen Prüfung.',409);return $first;}
            $review=$this->prepare($input);if(!hash_equals($review['hash'],$reviewHash))throw new DomainException('Auftrag, Rechnung, Bankdaten oder Freigabequelle inzwischen geändert. Ganze Entscheidung erneut prüfen.',409);
            $operation=$review['normalized']['operation'];$data=$review['normalized']['data'];$id=$review['normalized']['order'];$version=(int)($review['source']['snapshot']['order']['version']??0);$service=$this->service();
            if(in_array($operation,['prepare','pay'],true)){
                $id=$service->prepare($review['normalized']['entry'],$data['account'],$data['execution_date'],$data['purpose'],$review['source']['preview']['hash'],$key,$data['transfer_mode']);
                if($operation==='pay')$service->approve($id,1);
            }
            elseif($operation==='approve')$service->approve($id,$version);
            elseif($operation==='export'){$xml=$service->export($id,$version);if(!hash_equals($review['source']['snapshot']['xml_sha256'],hash('sha256',$xml)))throw new DomainException('Tatsächliche Bankdatei bitte prüfen lassen.',503);unset($xml);}
            elseif($operation==='submitted')$service->submitted($id,$version,$data['bank_reference']);
            elseif(in_array($operation,['fints_submitted','fints_not_sent'],true))$service->resolveFints($id,$version,(int)$review['source']['snapshot']['transport']['event']['sequence'],$operation==='fints_submitted',$data['bank_reference']);
            elseif($operation==='cancel')$service->cancel($id,$version);
            $result=$service->decisionSnapshot($id);
            $now=new DateTimeImmutable('now',new DateTimeZone('UTC'));$original=self::json(['format'=>'dvm-transfer-order-original/1','request_key'=>$key,'club_id'=>$club,'actor_id'=>$actor['id'],'section'=>self::SECTION,'input'=>$input,'review'=>$review,'approved'=>true,'result'=>$result,'created_at'=>$now->format('Y-m-d\TH:i:s.u\Z')]);
            Db::run($this->db,'INSERT INTO audit_events(club_id,actor_id,action,object_id,object_version,happened_at)VALUES(?,?,?,?,?,?)',[$club,$actor['id'],'transfer.decision.'.$operation,$id,(int)$result['order']['version'],$now->format('Y-m-d\TH:i:s.u\Z')]);
            Db::run($this->db,'INSERT INTO native_settings_requests(club_id,request_key,actor_id,section,version,input_sha256,input_json,original_sha256,original_json,created_at)VALUES(?,?,?,?,1,?,?,?,?,?)',[$club,$key,$actor['id'],self::SECTION,$inputHash,$json,hash('sha256',$original),$original,$now->format('Y-m-d H:i:s.u')]);
            return $this->decode(Db::run($this->db,'SELECT * FROM native_settings_requests WHERE club_id=? AND request_key=? FOR SHARE NOWAIT',[$club,$key])->fetch());
        });}catch(\Throwable $failure){$this->busy($failure);throw $failure;}
    }
    private function decode(array $row):array
    {
        $input=json_decode($row['input_json'],true,64,JSON_THROW_ON_ERROR);$first=json_decode($row['original_json'],true,64,JSON_THROW_ON_ERROR);
        if(!is_array($input)||!is_array($first)||$row['section']!==self::SECTION||(int)$row['version']!==1||!hash_equals($row['input_sha256'],hash('sha256',self::SECTION."\0".$row['input_json']))||!hash_equals($row['original_sha256'],hash('sha256',$row['original_json']))||($first['format']??null)!=='dvm-transfer-order-original/1'||($first['request_key']??null)!==$row['request_key']||($first['club_id']??null)!==$row['club_id']||($first['actor_id']??null)!==$row['actor_id']||($first['section']??null)!==self::SECTION||($first['input']??null)!==$input||($first['review']['input']??null)!==$input||($first['approved']??null)!==true||($first['review']['no_transport']??null)!==true||($first['review']['no_new_money']??null)!==true||!hash_equals((string)($first['review']['hash']??''),self::hash($first['review']??[]))||($first['review']['normalized']??null)!==TransferInput::normalize($input)||($first['created_at']??null)!==(new DateTimeImmutable($row['created_at'],new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.u\Z'))throw new DomainException('Ursprüngliche persönliche Überweisungsentscheidung bitte prüfen lassen.',503);
        $order=$first['result']['order']??[];$operation=$input['operation'];$states=['prepare'=>'prepared','pay'=>'approved','approve'=>'approved','export'=>'exported','submitted'=>'submitted','cancel'=>'cancelled','fints_submitted'=>'submitted','fints_not_sent'=>'approved'];$creates=in_array($operation,['prepare','pay'],true);
        if(($order['club_id']??null)!==$row['club_id']||!preg_match('/\A[a-f0-9]{32}\z/D',(string)($order['id']??''))||(!$creates&&$order['id']!==$input['order'])||($creates&&($order['request_key']??null)!==$row['request_key'])||(($order['state']??null)!==$states[$operation])||($order['entry_id']??null)!==$input['entry']||isset($order['sealed_snapshot'])||isset($order['snapshot'])||!preg_match('/\A[a-f0-9]{64}\z/D',(string)($first['result']['xml_sha256']??''))||!preg_match('/\A[a-f0-9]{64}\z/D',(string)($first['result']['sealed_sha256']??'')))throw new DomainException('Ursprüngliche persönliche Überweisungsentscheidung bitte prüfen lassen.',503);
        return ['key'=>$row['request_key'],'input'=>$input,'review'=>$first['review'],'result'=>$first['result'],'created_at'=>$first['created_at'],'original_json'=>$row['original_json'],'sha256'=>$row['original_sha256']];
    }
    public function receipt(string $key):array
    {
        if(!preg_match('/\A[a-f0-9]{32}\z/D',$key))throw new DomainException('Eigene ursprüngliche Überweisungsentscheidung nicht verfügbar.',404);
        return $this->read(function()use($key):array{$actor=$this->actor();$this->available();$row=Db::run($this->db,'SELECT * FROM native_settings_requests WHERE club_id=? AND actor_id=? AND section=? AND request_key=? FOR SHARE NOWAIT',[$actor['club_id'],$actor['id'],self::SECTION,$key])->fetch();if(!$row)throw new DomainException('Eigene ursprüngliche Überweisungsentscheidung nicht verfügbar.',404);return $this->decode($row);});
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
        if(!is_string($search)||preg_match('//u',$search)!==1||mb_strlen($search,'UTF-8')>200||preg_match('/[\x00-\x1f\x7f]/',$search)||(!is_string($page)&&!is_int($page))||!preg_match('/\A[1-9][0-9]{0,8}\z/D',(string)$page)||($state&&(!is_string($status)||!in_array($status,['','prepared','approved','exported','submitted','cancelled'],true))))throw new DomainException('Ganze Überweisungssuche, Status und Seite prüfen.',422);
        return ['search'=>$search,'page'=>(string)$page,'state'=>$status];
    }
    private function page(string $select,string $from,string $where,array $args,string $sort,array $filter):array
    {
        $total=(int)Db::run($this->db,'SELECT COUNT(*) '.$from.$where.' FOR SHARE NOWAIT',$args)->fetchColumn();$pages=max(1,(int)ceil($total/25));$error=(int)$filter['page']>$pages?'Verfügbare Überweisungsseite auswählen.':'';
        $rows=$error===''?Db::run($this->db,'SELECT '.$select.' '.$from.$where.' ORDER BY '.$sort.' LIMIT 25 OFFSET '.(((int)$filter['page']-1)*25).' FOR SHARE NOWAIT',$args)->fetchAll():[];return ['rows'=>$rows,'total'=>$total,'pages'=>$pages,'page_input'=>$filter['page'],'page'=>$error===''?(int)$filter['page']:null,'error'=>$error,'per_page'=>25];
    }
    public function listing(string $entry,array $query=[]):array
    {
        $filter=self::controls($query,true);return $this->read(function()use($entry,$filter):array{$actor=$this->actor(true);$this->entrySource($entry);$where=' WHERE club_id=? AND entry_id=?';$args=[$actor['club_id'],$entry];if($filter['search']!==''){$where.=' AND LOCATE(LOWER(?),LOWER(CONCAT_WS(CHAR(10),id,execution_date,bank_reference)))>0';$args[]=$filter['search'];}if($filter['state']!==''){$where.=' AND state=?';$args[]=$filter['state'];}return $this->page('id,entry_id,execution_date,state,total_cents,version,created_at,bank_reference','FROM finance_transfer_orders',$where,$args,'created_at DESC,id DESC',$filter);});
    }
    public function accounts():array
    {
        return $this->read(function():array{$actor=$this->actor(true);return Db::run($this->db,"SELECT a.id,a.name FROM finance_accounts a LEFT JOIN finance_account_settings s ON s.club_id=a.club_id AND s.account_id=a.id WHERE a.club_id=? AND a.kind='offline' AND COALESCE(s.active,1)=1 ORDER BY a.name,a.id FOR SHARE NOWAIT",[$actor['club_id']])->fetchAll();});
    }
    public function history(string $entry,?string $order=null,array $query=[]):array
    {
        if($order!==null)self::orderId($order);$filter=self::controls($query);return $this->read(function()use($entry,$order,$filter):array{$actor=$this->actor();$this->available();$where=' WHERE club_id=? AND actor_id=? AND section=?';$args=[$actor['club_id'],$actor['id'],self::SECTION];$where.=" AND JSON_UNQUOTE(JSON_EXTRACT(original_json,'$.result.order.entry_id'))=?";$args[]=$entry;if($order!==null){$where.=" AND JSON_UNQUOTE(JSON_EXTRACT(original_json,'$.result.order.id'))=?";$args[]=$order;}if($filter['search']!==''){$where.=' AND LOCATE(LOWER(?),LOWER(CONCAT_WS(CHAR(10),request_key,created_at,input_json)))>0';$args[]=$filter['search'];}return $this->page('request_key,created_at','FROM native_settings_requests',$where,$args,'created_at DESC,request_key DESC',$filter);});
    }
}
