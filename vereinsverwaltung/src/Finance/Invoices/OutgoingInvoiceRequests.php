<?php declare(strict_types=1);
namespace Kulturhus\Verein\Finance\Invoices;

use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use PDO;
use Kulturhus\Native\Shared\Db;
use Kulturhus\Verein\{Permissions,OutgoingInvoices,SettingsSchema,Documents};
use Kulturhus\Verein\Infrastructure\Database\Transactions;

/** First personal outcomes for draft saving, review and deliberate actual issuance. */
final class OutgoingInvoiceRequests
{
    public const SECTION='outgoing-invoice';
    public function __construct(private PDO $db,private string $actorId) {}
    public static function json(array $value): string { return OutgoingMailMessage::json($value); }

    private function busy(\Throwable $failure): void
    {
        if (($failure instanceof \PDOException&&in_array((int)($failure->errorInfo[1]??0),[1205,3572],true))
            || ($failure instanceof DomainException&&$failure->getMessage()==='Ein Speichervorgang läuft bereits. Bitte erneut versuchen.')) {
            throw new DomainException('Ausgangsrechnung wird gerade bearbeitet. Bitte erneut prüfen.',503,$failure);
        }
    }

    private function read(callable $operation): array
    {
        $own=!$this->db->inTransaction();
        try { if ($own) $this->db->exec('START TRANSACTION');$result=$operation();if ($own) $this->db->commit();return $result; }
        catch (\Throwable $failure) { if ($own&&$this->db->inTransaction()) $this->db->rollBack();$this->busy($failure);throw $failure; }
    }

    private function actor(bool $write=false): array
    {
        try { return Permissions::finance($this->db,$this->actorId,$write); }
        catch (DomainException $failure) { throw new DomainException($write?'Aktuelle Finanzschreibberechtigung erforderlich.':'Aktuelle Finanzleseberechtigung erforderlich.',403,$failure); }
    }

    public function assertAccess(bool $write=false): array { return $this->read(fn():array=>$this->actor($write)); }

    /** A real earlier canonical creation is shared financial history, never a backfilled personal confirmation. */
    public function legacyCreation(string $key,array $raw): ?array
    {
        $input=OutgoingInvoiceInput::capture($raw);
        if(!preg_match('/\A[a-f0-9]{32}\z/D',$key)||$input['operation']!=='save'||$input['invoice']!==''||$input['version']!=='0')throw new DomainException('Ursprüngliche Kennung des früheren Anlageformulars prüfen.',422);
        return $this->read(function()use($key):array{
            $actor=$this->actor();$invoice=Db::run($this->db,'SELECT * FROM outgoing_invoices WHERE club_id=? AND request_key=? FOR SHARE NOWAIT',[$actor['club_id'],$key])->fetch();
            if(!$invoice)return [];
            if($invoice['kind']!=='invoice')throw new DomainException('Diese ursprüngliche Kennung gehört zu einem anderen Rechnungsvorgang.',409);
            $original=Db::run($this->db,'SELECT * FROM outgoing_invoice_history WHERE club_id=? AND invoice_id=? AND version=1 AND action=? FOR SHARE NOWAIT',[$actor['club_id'],$invoice['id'],'saved'])->fetch();
            if(!$original)throw new DomainException('Tatsächlichen früheren Rechnungsentwurf bitte prüfen lassen.',503);
            return ['invoice'=>$invoice,'original'=>$original];
        })?:null;
    }

    private function available(): void
    {
        if (!Db::run($this->db,"SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='native_settings_requests'")->fetchColumn()) {
            throw new DomainException('Rechnungsbestätigungen benötigen das aktuelle installierte Paket.',503);
        }
    }

    private function invoice(string $club,string $id): ?array
    {
        if ($id==='') return null;
        if (!preg_match('/\A[a-f0-9]{32}\z/D',$id)) throw new DomainException('Eigene ganze Rechnung auswählen.',422);
        $row=Db::run($this->db,'SELECT * FROM outgoing_invoices WHERE club_id=? AND id=? FOR SHARE NOWAIT',[$club,$id])->fetch();
        if (!$row) throw new DomainException('Ausgangsrechnung nicht verfügbar.',404);
        return $row;
    }

    private function source(array $actor,string $id,bool $settings): array
    {
        $invoice=$this->invoice($actor['club_id'],$id);$rows=[];$issuer=[];
        if ($settings) {
            $rows=Db::run($this->db,"SELECT * FROM club_settings WHERE club_id=? AND section IN ('club','tax','bank','paper','texts_invoices') ORDER BY section FOR SHARE NOWAIT",[$actor['club_id']])->fetchAll();
            $stored=[];foreach ($rows as $row) $stored[$row['section']]=json_decode($row['payload'],true,64,JSON_THROW_ON_ERROR);
            foreach (['club'=>'club','tax'=>'tax','bank'=>'bank','paper'=>'paper','texts'=>'texts_invoices'] as $key=>$section) {
                $issuer[$key]=array_replace(SettingsSchema::defaults($section),$stored[$section]??[]);
            }
        }
        return ['invoice'=>$invoice,'settings'=>$rows,'issuer'=>$issuer];
    }

    public function state(string $id): array
    {
        return $this->read(function()use($id):array {
            $actor=$this->actor();$source=$this->source($actor,$id,false);$invoice=$source['invoice'];
            if ($invoice!==null&&$invoice['document_id']===null) $source=$this->source($actor,$id,true);
            if ($invoice!==null) $invoice['data']=json_decode($invoice['payload'],true,64,JSON_THROW_ON_ERROR);
            return ['actor'=>$actor,'invoice'=>$invoice,'source'=>$source];
        });
    }

    public function preview(string $id,int $version): array
    {
        return $this->read(function()use($id,$version):array {
            $actor=$this->actor();$source=$this->source($actor,$id,false);$row=$source['invoice'];
            if ($row!==null&&$row['document_id']===null) $source=$this->source($actor,$id,true);
            if (!$row||(int)$row['version']!==$version) throw new DomainException('Genau den gespeicherten Rechnungsstand erneut öffnen.',409);
            if ($row['state']==='reviewed'&&!hash_equals($row['reviewed_hash'],hash('sha256',$row['payload'].json_encode($source['issuer'],JSON_THROW_ON_ERROR)))) {
                throw new DomainException('Vereins- oder Rechnungsdaten geändert. Entwurf speichern und erneut prüfen.',409);
            }
            $pdf=(new OutgoingInvoices($this->db,$this->actorId))->currentPreview($id);
            return $pdf+['source'=>$source,'source_sha256'=>hash('sha256',self::json($source))];
        });
    }

    public function execute(array $raw,string $key,bool $approved=false): array
    {
        $input=OutgoingInvoiceInput::capture($raw);
        if (!preg_match('/\A[a-f0-9]{32}\z/D',$key)) throw new DomainException('Eigene ursprüngliche Rechnungskennung auswählen.',422);
        if ($input['operation']==='issue'&&!$approved) throw new DomainException('Genau diesen geprüften Rechnungsstand ausdrücklich ausgeben.',422);
        $club=Db::run($this->db,'SELECT club_id FROM actors WHERE id=?',[$this->actorId])->fetchColumn();
        if (!is_string($club)||$club==='') throw new DomainException('Eigenen aktiven Verein auswählen.',403);
        try { return Transactions::run($this->db,function()use($input,$key,$approved,$club):array {
            if (!Db::run($this->db,'SELECT id FROM clubs WHERE id=? FOR UPDATE NOWAIT',[$club])->fetchColumn()) throw new DomainException('Eigenen aktiven Verein auswählen.',403);
            $actor=$this->actor();if ($actor['club_id']!==$club) throw new DomainException('Vereinszuordnung inzwischen geändert.',403);
            $this->available();$json=self::json($input);$inputHash=hash('sha256',self::SECTION."\0".$json);
            $old=Db::run($this->db,'SELECT * FROM native_settings_requests WHERE club_id=? AND request_key=? FOR SHARE NOWAIT',[$club,$key])->fetch();
            if ($old) {
                if ($old['section']!==self::SECTION||$old['actor_id']!==$actor['id']||!hash_equals($old['input_sha256'],$inputHash)||$old['input_json']!==$json) {
                    throw new DomainException('Diese Rechnungskennung gehört zu anderen ursprünglichen Angaben.',409);
                }
                return $this->decode($old);
            }
            $this->actor(true);$source=$this->source($actor,$input['invoice'],$input['operation']!=='save');$previous=$source['invoice'];
            if ($input['operation']==='save'&&$input['invoice']===''
                &&Db::run($this->db,'SELECT id FROM outgoing_invoices WHERE club_id=? AND request_key=? FOR SHARE NOWAIT',[$club,$key])->fetchColumn()) {
                throw new DomainException('Diese Rechnungskennung gehört zu einem früheren gespeicherten Entwurf. Ursprüngliche Rechnung gezielt öffnen.',409);
            }
            if (($previous===null&&($input['operation']!=='save'||$input['version']!=='0'))
                || ($previous!==null&&(string)$previous['version']!==$input['version'])) throw new DomainException('Rechnungsstand inzwischen geändert. Ganze eigene Angaben bewusst erneut prüfen.',409);
            $service=new OutgoingInvoices($this->db,$this->actorId);$id=$input['invoice'];$normalized=null;
            if ($input['operation']==='save') {
                try { $normalized=OutgoingInvoices::normalize($input['data']); }
                catch (DomainException $failure) { throw new DomainException($failure->getMessage(),422,$failure); }
                $id=$service->save($id,(int)$input['version'],$input['data'],$key);
            } elseif ($input['operation']==='review') {
                try { $normalized=OutgoingInvoices::normalize($input['data']); }
                catch (DomainException $failure) { throw new DomainException($failure->getMessage(),422,$failure); }
                if ($normalized!==json_decode($previous['payload'],true,64,JSON_THROW_ON_ERROR)) {
                    throw new DomainException('Geänderte ganze Angaben zuerst als Entwurf speichern. Danach genau diesen gespeicherten Stand prüfen.',409);
                }
                $service->review($id,(int)$input['version']);
            } else {
                if ($previous['state']!=='reviewed'||!preg_match('/\A[a-f0-9]{64}\z/D',$input['data']['reviewed_hash'])
                    || !hash_equals($previous['reviewed_hash'],$input['data']['reviewed_hash'])) throw new DomainException('Genau den zuvor geprüften Rechnungsstand erneut öffnen.',409);
                if (!hash_equals($previous['reviewed_hash'],hash('sha256',$previous['payload'].json_encode($source['issuer'],JSON_THROW_ON_ERROR)))) {
                    throw new DomainException('Vereins- oder Rechnungsdaten geändert. Entwurf speichern und erneut prüfen.',409);
                }
                $service->issue($id,(int)$input['version']);
            }
            $result=$this->invoice($club,$id);$result['data']=json_decode($result['payload'],true,64,JSON_THROW_ON_ERROR);$file=null;
            $accountId=$result['data']['payment_account'];
            if($accountId===''&&$input['operation']!=='save')$accountId=(string)($source['issuer']['bank']['account_id']??'');
            $source['payment_account']=null;
            if($accountId!==''){
                // Retain the actual selected account label at acceptance; a later rename must not rewrite this original.
                $source['payment_account']=Db::run($this->db,'SELECT id,name,kind FROM finance_accounts WHERE club_id=? AND id=? FOR SHARE NOWAIT',[$club,$accountId])->fetch()?:null;
                if($source['payment_account']===null)throw new DomainException('Eigenes ausgewähltes Zahlungskonto nicht verfügbar.',422);
            }
            if ($result['document_id']!==null) {
                $original=(new Documents($this->db,$this->actorId))->currentFinancialOriginal($result['document_id'],1);
                $file=['document_id'=>$result['document_id']]+array_intersect_key($original['file'],array_flip(['revision','name','mime','sha256','size']));
            }
            $wantedState=match($input['operation']) {'save'=>'draft','review'=>'reviewed','issue'=>'issued'};
            if ($result['state']!==$wantedState||(int)$result['version']!==(int)$input['version']+1
                || ($normalized!==null&&$result['data']!==$normalized)) throw new DomainException('Tatsächliche ursprüngliche Rechnungsbestätigung bitte prüfen lassen.',503);
            $now=new DateTimeImmutable('now',new DateTimeZone('UTC'));
            $original=self::json(['format'=>'dvm-outgoing-invoice-original/1','request_key'=>$key,'club_id'=>$club,'actor_id'=>$actor['id'],
                'section'=>self::SECTION,'input'=>$input,'normalized'=>$normalized,'source'=>$source,'approved'=>$input['operation']==='issue'?$approved:false,
                'result'=>$result,'file'=>$file,'created_at'=>$now->format('Y-m-d\TH:i:s.u\Z')]);
            Db::run($this->db,'INSERT INTO native_settings_requests(club_id,request_key,actor_id,section,version,input_sha256,input_json,original_sha256,original_json,created_at)VALUES(?,?,?,?,?,?,?,?,?,?)',
                [$club,$key,$actor['id'],self::SECTION,(int)$result['version'],$inputHash,$json,hash('sha256',$original),$original,$now->format('Y-m-d H:i:s.u')]);
            return $this->decode(Db::run($this->db,'SELECT * FROM native_settings_requests WHERE club_id=? AND request_key=?',[$club,$key])->fetch());
        }); } catch (\Throwable $failure) { $this->busy($failure);throw $failure; }
    }

    private function decode(array $row): array
    {
        $input=json_decode($row['input_json'],true,64,JSON_THROW_ON_ERROR);$original=json_decode($row['original_json'],true,64,JSON_THROW_ON_ERROR);
        if (!is_array($input)||!is_array($original)||$row['section']!==self::SECTION
            || !hash_equals($row['input_sha256'],hash('sha256',self::SECTION."\0".$row['input_json']))
            || !hash_equals($row['original_sha256'],hash('sha256',$row['original_json']))
            || ($original['format']??null)!=='dvm-outgoing-invoice-original/1'||($original['section']??null)!==self::SECTION
            || ($original['request_key']??null)!==$row['request_key']||($original['club_id']??null)!==$row['club_id']||($original['actor_id']??null)!==$row['actor_id']
            || ($original['input']??null)!==$input||($original['result']['club_id']??null)!==$row['club_id']
            || (int)($original['result']['version']??0)!==(int)$row['version']||(int)$row['version']!==(int)$input['version']+1
            || ($input['invoice']!==''&&($original['result']['id']??null)!==$input['invoice'])
            || ($original['approved']??null)!==($input['operation']==='issue')
            || ($original['created_at']??null)!==(new DateTimeImmutable($row['created_at'],new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.u\Z')) {
            throw new DomainException('Ursprüngliche Rechnungsbestätigung bitte prüfen lassen.',503);
        }
        $expectedState=match($input['operation']) {'save'=>'draft','review'=>'reviewed','issue'=>'issued',default=>''};
        if (($original['result']['state']??null)!==$expectedState
            || (in_array($input['operation'],['save','review'],true)&&($original['result']['data']??null)!==($original['normalized']??null))) {
            throw new DomainException('Ursprüngliche Rechnungsbestätigung bitte prüfen lassen.',503);
        }
        return ['key'=>$row['request_key'],'actor_id'=>$row['actor_id'],'input'=>$input,'result'=>$original['result'],
            'source'=>$original['source'],'normalized'=>$original['normalized'],'file'=>$original['file'],
            'created_at'=>$original['created_at'],'original_json'=>$row['original_json'],'sha256'=>$row['original_sha256']];
    }

    public function receipt(string $key): array
    {
        if (!preg_match('/\A[a-f0-9]{32}\z/D',$key)) throw new DomainException('Eigene ursprüngliche Rechnungsbestätigung nicht verfügbar.',404);
        return $this->read(function()use($key):array {
            $actor=$this->actor();$this->available();$row=Db::run($this->db,'SELECT * FROM native_settings_requests WHERE club_id=? AND actor_id=? AND section=? AND request_key=? FOR SHARE NOWAIT',
                [$actor['club_id'],$actor['id'],self::SECTION,$key])->fetch();
            if (!$row) throw new DomainException('Eigene ursprüngliche Rechnungsbestätigung nicht verfügbar.',404);
            return $this->decode($row);
        });
    }

    public function history(string $invoice,array $query=[]): array
    {
        $search=$query['query']??'';$page=$query['page']??'1';
        if (!preg_match('/\A[a-f0-9]{32}\z/D',$invoice)||!is_string($search)||preg_match('//u',$search)!==1||mb_strlen($search,'UTF-8')>150
            || preg_match('/[\x00-\x1f\x7f]/',$search)||(!is_string($page)&&!is_int($page))||!preg_match('/\A[1-9][0-9]{0,8}\z/D',(string)$page)) {
            throw new DomainException('Ganze Rechnungsbestätigungssuche und Seite prüfen.',422);
        }
        $page=(string)$page;
        return $this->read(function()use($invoice,$search,$page):array {
            $actor=$this->actor();$this->available();$where=" WHERE club_id=? AND actor_id=? AND section=? AND JSON_UNQUOTE(JSON_EXTRACT(original_json,'$.result.id'))=?";
            $args=[$actor['club_id'],$actor['id'],self::SECTION,$invoice];
            if ($search!=='') { $where.=' AND LOCATE(LOWER(?),LOWER(CONCAT_WS(CHAR(10),request_key,created_at,input_json)))>0';$args[]=$search; }
            $total=(int)Db::run($this->db,'SELECT COUNT(*) FROM native_settings_requests'.$where.' FOR SHARE NOWAIT',$args)->fetchColumn();$pages=max(1,(int)ceil($total/25));
            $error=(int)$page>$pages?'Verfügbare ursprüngliche Rechnungsbestätigungsseite auswählen.':'';
            $rows=$error===''?Db::run($this->db,'SELECT request_key,created_at FROM native_settings_requests'.$where.' ORDER BY created_at DESC,request_key DESC LIMIT 25 OFFSET '.(((int)$page-1)*25).' FOR SHARE NOWAIT',$args)->fetchAll():[];
            return ['rows'=>$rows,'total'=>$total,'pages'=>$pages,'page_input'=>$page,'page'=>$error===''?(int)$page:null,'query'=>$search,'error'=>$error,'per_page'=>25];
        });
    }
}
