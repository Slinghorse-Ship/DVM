<?php declare(strict_types=1);
namespace Kulturhus\Verein;
use PDO;use DomainException;
use Kulturhus\Verein\Infrastructure\Database\Transactions;
require_once dirname(__DIR__).'/Infrastructure/Database/Transactions.php';
final class Settings
{
    public function __construct(private PDO $db,private string $actorId){}
    public function actor():array{return Permissions::actor($this->db,$this->actorId);}
    public function canManage():bool{try{return (bool)$this->actor()['settings_access'];}catch(DomainException){return false;}}
    public function startViews():array
    {
        require_once dirname(__DIR__, 1).'/Auth/WorkspaceAccess.php';
        $access=new WorkspaceAccess($this->db,$this->actorId);$views=[];
        foreach(SettingsSchema::fields('account')['start_view'][2] as $view=>$label)if($access->allows(['view'=>$view]))$views[$view]=$label;
        return $views;
    }
    private function authorize(string $section,bool $write=false):array
    {
        $a=$this->actor();$finance=in_array($section,['bank','tax','numbers','catalog','finance_preferences'],true);
        if($section==='account')return $a;
        if($section==='mail'&&$write&&empty($a['is_superuser']))throw new DomainException('Nur der Superuser darf Mailabsender und Postfachangaben ändern.');
        if($finance){if(!in_array($a['finance_access'],$write?['write']:['read','write'],true))throw new DomainException('Finanzberechtigung für diese Einstellungen erforderlich.');}
        elseif(!$a['settings_access'])throw new DomainException('Keine Berechtigung für die Vereinseinstellungen.');
        return $a;
    }
    private function key(string $section):string{return $section==='account'?'account:'.$this->actorId:$section;}
    private function transaction(callable $fn):mixed{return Transactions::run($this->db,$fn);}
    private function revision(array $a,string $section,int $version,array $data):void
    {
        $json=json_encode($data,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);$time=gmdate('Y-m-d\TH:i:s\Z');
        $this->db->prepare('INSERT INTO settings_revisions VALUES(?,?,?,?,?,?)')->execute([$a['club_id'],$section,$version,$json,$a['id'],$time]);
        $this->db->prepare('INSERT INTO audit_events(club_id,actor_id,action,object_id,object_version,happened_at) VALUES(?,?,?,?,?,?)')->execute([$a['club_id'],$a['id'],'settings.updated',$section,$version,$time]);
    }
    public function section(string $section,bool $lock=false):array
    {
        $general=\Kulturhus\Verein\Settings\GeneralValues::supports($section)||\Kulturhus\Verein\Settings\TextInput::supports($section)||in_array($section,['numbers','bank','tax','paper',FinancePreferences::SECTION],true);
        if($general&&!$this->db->inTransaction())return$this->transaction(fn():array=>$this->section($section,$lock));
        $a=$this->authorize($section);$defaults=SettingsSchema::defaults($section);$q=$this->db->prepare('SELECT * FROM club_settings WHERE club_id=? AND section=?'.($this->db->inTransaction()?($lock?((\Kulturhus\Verein\Settings\TextInput::supports($section)||in_array($section,['bank','tax','paper',FinancePreferences::SECTION],true))?' FOR UPDATE NOWAIT':' FOR UPDATE'):($general?' FOR SHARE NOWAIT':'')):''));$q->execute([$a['club_id'],$this->key($section)]);$r=$q->fetch();
        $data=$r?json_decode($r['payload'],true,512,JSON_THROW_ON_ERROR):$defaults;
        if (!$r && in_array($section,['paper','mail'],true)) {
            $clubName=$this->section('club')['data']['name'];
            $data['sender_name']=$clubName;
            if ($section==='paper') $data['header_text']=$clubName;
        }
        // Handkassen are always available; remove the obsolete club-level switch
        // from the public settings shape while leaving old revisions untouched.
        if($section==='club')unset($data['cash_enabled']);
        if($section==='paper'&&!array_key_exists('sender_source',$data))$data['sender_source']=self::paperSenderSource($data);
        if($section==='numbers'){$q=$this->db->prepare('SELECT * FROM number_counters WHERE club_id=?'.($this->db->inTransaction()?' FOR SHARE NOWAIT':''));$q->execute([$a['club_id']]);foreach($q as $counter)$data[$counter['kind']]['next']=max($data[$counter['kind']]['next'],(int)$counter['next_number']);}
        return ['data'=>$data,'version'=>(int)($r['version']??0),'updated_at'=>$r['updated_at']??''];
    }
    /** Preserve separately saved legacy addresses; an empty old sender follows club data. */
    private static function paperSenderSource(array $paper):string
    {
        foreach(['sender_street','sender_address2','sender_city'] as $field)if(trim((string)($paper[$field]??''))!=='')return 'custom';
        $name=trim((string)($paper['sender_name']??''));return $name!==''&&!in_array($name,[SettingsSchema::defaults('paper')['sender_name'],'Kulturhus Jaderberg e. V.'],true)?'custom':'club';
    }
    /** Resolve only a new document's sender; never rewrite saved settings or document snapshots. */
    public function paperForDocument(?array $draft=null):array
    {
        $stored=$this->section('paper');$paper=$draft??$stored['data'];
        if(!array_key_exists('sender_source',$paper))$paper['sender_source']=$stored['version']>0?$stored['data']['sender_source']:self::paperSenderSource($paper);
        $paper=SettingsSchema::normalize('paper',$paper);
        if($paper['sender_source']==='club'){
            $club=$this->section('club')['data'];
            $paper=array_replace($paper,['sender_name'=>$club['name'],'sender_street'=>$club['street'],'sender_address2'=>$club['address2'],'sender_city'=>trim($club['postcode'].' '.$club['city'])]);
        }
        return $paper;
    }
    public function history(string $section):array
    {
        $a=$this->authorize($section);SettingsSchema::defaults($section);$q=$this->db->prepare('SELECT version,actor_id,happened_at FROM settings_revisions WHERE club_id=? AND section=? ORDER BY version DESC LIMIT 20');$q->execute([$a['club_id'],$this->key($section)]);return $q->fetchAll();
    }
    public function save(string $section,array $data,int $version,array $uploads=[]):void
    {
        try{$this->transaction(function()use($section,$data,$version,$uploads){
            $general=\Kulturhus\Verein\Settings\GeneralValues::supports($section)||\Kulturhus\Verein\Settings\TextInput::supports($section)||in_array($section,['numbers','bank','tax','paper',FinancePreferences::SECTION],true);$club=null;
            if($general){$q=$this->db->prepare('SELECT club_id FROM actors WHERE id=?');$q->execute([$this->actorId]);$club=$q->fetchColumn();$q=$this->db->prepare('SELECT id FROM clubs WHERE id=? FOR UPDATE NOWAIT');$q->execute([$club]);if(!$q->fetchColumn())throw new DomainException('Eigenen aktiven Verein auswählen.',403);}
            if($section==='account')$this->lockAccount();$a=$this->authorize($section,true);if($general&&$a['club_id']!==$club)throw new DomainException('Vereinszuordnung inzwischen geändert.',403);$old=$this->section($section,$section==='account'||$general);if($version!==$old['version'])throw new DomainException('Diese Einstellungen wurden inzwischen geändert. Bitte neu laden.',409);
            // Old profile forms preserve the separately selected appearance, without a backfill on read.
            if($section==='account')foreach(['admin_view','admin_color','admin_density']as$key)if(!array_key_exists($key,$data)&&array_key_exists($key,$old['data']))$data[$key]=$old['data'][$key];
            if($section==='paper'&&!array_key_exists('sender_source',$data))$data['sender_source']=$old['version']>0?$old['data']['sender_source']:self::paperSenderSource($data);
            $data=SettingsSchema::normalize($section,$data);
            if($section===FinancePreferences::SECTION)(new FinancePreferences($this->db,$this->actorId))->validateAccounts($data);
            if($section==='tax')\Kulturhus\Verein\Settings\FinanceTaxContext::read($this->db,$this->actorId,$data);
            if($section==='bank')\Kulturhus\Verein\Settings\FinanceBankContext::read($this->db,$this->actorId,$data);
            if($section==='account'&&!isset($this->startViews()[$data['start_view']]))throw new DomainException('Diese Startansicht ist für deinen Zugang nicht freigegeben. Bitte eine verfügbare Ansicht wählen.');
            if($section==='paper'){
                if((isset($uploads['signature'])||$data['signature']!==($old['data']['signature']??''))&&!(new BoardSignature($this->db,$this->actorId))->canUse())throw new DomainException('Die Unterschrift darf nur der erste Vorstand oder ein Superuser ändern.');
                foreach(['logo','signature'] as $kind){if(isset($uploads[$kind]))$data[$kind]=$this->storeAsset($a,$uploads[$kind]);if($data[$kind]!=='')\Kulturhus\Verein\Settings\PaperAssets::load($this->db,$a['club_id'],$data[$kind],$kind==='signature');}
            }elseif($uploads)throw new DomainException('Hier sind keine Bilder vorgesehen.');
            if($section==='numbers')foreach($data as $kind=>$rule){if($rule['next']<$old['data'][$kind]['next'])throw new DomainException('Der nächste Nummernwert darf nicht zurückgesetzt werden.');}
            $key=$this->key($section);$json=json_encode($data,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
            $this->db->prepare('INSERT INTO club_settings VALUES(?,?,?,?,?) ON DUPLICATE KEY UPDATE payload=VALUES(payload),version=VALUES(version),updated_at=VALUES(updated_at)')->execute([$a['club_id'],$key,$json,$version+1,gmdate('Y-m-d\TH:i:s\Z')]);$this->revision($a,$key,$version+1,$data);
        });}catch(\PDOException$failure){if(($section==='paper'||\Kulturhus\Verein\Settings\TextInput::supports($section))&&in_array((int)($failure->errorInfo[1]??0),[1205,3572],true))throw new DomainException(\Kulturhus\Verein\Settings\TextInput::supports($section)?'Textbausteine oder ihre Quellen werden gerade bearbeitet. Bitte erneut prüfen.':'Briefpapier wird gerade bearbeitet. Bitte erneut prüfen.',503,$failure);throw$failure;}
    }
    /** A deliberate appearance save updates the existing own profile revision only. */
    public function saveAppearance(array $data,int $version):void
    {
        require_once dirname(__DIR__,2).'/native/Appearance/Values.php';
        require_once dirname(__DIR__,2).'/native/Appearance/Conflict.php';
        $data=\Kulturhus\Native\Appearance\Values::normalize($data);
        $this->transaction(function()use($data,$version):void{
            $this->lockAccount();$a=$this->authorize('account',true);$old=$this->section('account',true);
            if($version<0||$version!==$old['version'])throw new \Kulturhus\Native\Appearance\Conflict('Deine Anzeigeeinstellungen wurden inzwischen geändert. Aktuellen Stand öffnen.');
            // Never normalize another field or silently reset an unavailable former start view.
            $next=array_replace($old['data'],$data);$key=$this->key('account');$json=json_encode($next,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
            $this->db->prepare('INSERT INTO club_settings VALUES(?,?,?,?,?) ON DUPLICATE KEY UPDATE payload=VALUES(payload),version=VALUES(version),updated_at=VALUES(updated_at)')->execute([$a['club_id'],$key,$json,$version+1,gmdate('Y-m-d\TH:i:s\Z')]);
            $this->revision($a,$key,$version+1,$next);
        });
    }
    /** Serialize first saves as well as changes made through old profile forms. */
    private function lockAccount():void
    {
        $q=$this->db->prepare('SELECT id FROM actors WHERE id=? AND active=1 FOR UPDATE');$q->execute([$this->actorId]);
        if(!$q->fetchColumn())throw new DomainException('Eigener Zugang nicht mehr verfügbar.');
    }
    private function storeAsset(array $a,string $bytes):string
    {
        if(strlen($bytes)>2*1024*1024)throw new DomainException('Bild darf höchstens 2 MB groß sein.');$size=@getimagesizefromstring($bytes);
        if(!$size||!in_array($size['mime'],['image/png','image/jpeg'],true)||$size[0]<10||$size[1]<10||$size[0]>4000||$size[1]>4000||$size[0]*$size[1]>8000000)throw new DomainException('PNG/JPEG mit 10 bis 4.000 Pixeln und höchstens 8 Megapixeln erforderlich.');
        $id=bin2hex(random_bytes(16));$q=$this->db->prepare('INSERT INTO settings_assets VALUES(?,?,?,?,?)');$q->bindValue(1,$a['club_id']);$q->bindValue(2,$id);$q->bindValue(3,$size['mime']);$q->bindValue(4,$bytes,PDO::PARAM_LOB);$q->bindValue(5,hash('sha256',$bytes));$q->execute();return $id;
    }
    public function asset(string $id):array
    {
        $own=!$this->db->inTransaction();
        try{
            if($own)$this->db->exec('START TRANSACTION');$a=$this->authorize('paper');
            $private=\Kulturhus\Verein\Settings\PaperAssets::signature($this->db,$a['club_id'],$id);
            if($private&&!(new BoardSignature($this->db,$this->actorId))->canUse())throw new DomainException('Aktuelle Vorstandsberechtigung für die private Unterschrift erforderlich.',403);
            $asset=\Kulturhus\Verein\Settings\PaperAssets::load($this->db,$a['club_id'],$id,$private);
            if($own)$this->db->commit();return$asset;
        }catch(\Throwable$failure){if($own&&$this->db->inTransaction())$this->db->rollBack();if($failure instanceof \PDOException&&in_array((int)($failure->errorInfo[1]??0),[1205,3572],true))throw new DomainException('Briefpapier wird gerade bearbeitet. Bitte erneut prüfen.',503,$failure);throw$failure;}
    }
    public function catalog(?string $kind=null):array
    {
        if(!$this->db->inTransaction())return$this->transaction(fn():array=>$this->catalog($kind));
        try{$a=$this->authorize('catalog');$q=$this->db->prepare('SELECT * FROM settings_catalog WHERE club_id=? ORDER BY kind,id FOR SHARE NOWAIT');$q->execute([$a['club_id']]);$rows=[];foreach($q as$r)if($kind===null||$r['kind']===$kind){$r['data']=json_decode($r['payload'],true,512,JSON_THROW_ON_ERROR);$rows[]=$r;}return$rows;}
        catch(\PDOException $failure){if(in_array((int)($failure->errorInfo[1]??0),[1205,3572],true))throw new DomainException('Tarife werden gerade bearbeitet. Bitte erneut prüfen.',503,$failure);throw$failure;}
    }
    public static function catalogDefaults(string $kind):array{return ['name'=>'','description'=>'','amount'=>'0.00','interval'=>$kind==='service'?'once':'year','billing'=>'current','tax_rate'=>'0','sphere'=>'','tax_account'=>'','tags'=>'','purpose'=>'{{BEZEICHNUNG}} {{ABRECHNUNGSZEITRAUM}}','pricing'=>'fixed','tiers'=>[],'active'=>true];}
    public function saveCatalog(string $id,int $version,string $kind,array $data):string
    {
        try{return $this->transaction(function()use($id,$version,$kind,$data){
            $club=$this->db->prepare('SELECT club_id FROM actors WHERE id=?');$club->execute([$this->actorId]);$club=$club->fetchColumn();
            if(!is_string($club)||$club==='')throw new DomainException('Eigenen aktiven Verein auswählen.',403);
            $lock=$this->db->prepare('SELECT id FROM clubs WHERE id=? FOR UPDATE NOWAIT');$lock->execute([$club]);if(!$lock->fetchColumn())throw new DomainException('Eigenen aktiven Verein auswählen.',403);
            $a=$this->authorize('catalog',true);if($a['club_id']!==$club)throw new DomainException('Vereinszuordnung inzwischen geändert.',403);
            $d=\Kulturhus\Verein\Settings\CatalogValues::normalize($kind,$data);$old=null;
            if($id!==''){$query=$this->db->prepare('SELECT * FROM settings_catalog WHERE club_id=? AND id=? FOR UPDATE NOWAIT');$query->execute([$club,$id]);$old=$query->fetch();if(!$old||$old['kind']!==$kind||(int)$old['version']!==$version)throw new DomainException('Tarif inzwischen geändert oder nicht verfügbar.',409);$old['data']=json_decode($old['payload'],true,512,JSON_THROW_ON_ERROR);}elseif($version!==0)throw new DomainException('Tarifversion prüfen.',422);
            $context=\Kulturhus\Verein\Settings\CatalogContext::read($this->db,$club,$id,$kind,$d);\Kulturhus\Verein\Settings\CatalogContext::assert($context,$d);
            if($id!==''&&$context['linked_membership_types']&&$old['data']['amount']!==$d['amount'])$this->db->prepare('UPDATE application_settings SET version=version+1 WHERE club_id=?')->execute([$club]);
            $id=$id?:bin2hex(random_bytes(16));$this->db->prepare('INSERT INTO settings_catalog VALUES(?,?,?,?,?,?) ON DUPLICATE KEY UPDATE payload=VALUES(payload),version=VALUES(version),active=VALUES(active)')->execute([$club,$id,$kind,json_encode($d,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE),$version+1,(int)$d['active']]);$this->revision($a,'catalog:'.$id,$version+1,$d+['kind'=>$kind]);return$id;
        });}catch(\PDOException $failure){if(in_array((int)($failure->errorInfo[1]??0),[1205,3572],true))throw new DomainException('Tarife werden gerade bearbeitet. Bitte erneut prüfen.',503,$failure);throw$failure;}
    }
    public static function price(array $data,int $years):int{$price=Finance::cents($data['amount']);if($data['pricing']!=='fixed')foreach($data['tiers'] as $tier)if($years>=$tier['from'])$price=Finance::cents($tier['amount']);return $price;}
    public function assignments():array
    {
        $a=$this->authorize('catalog');if($a['members_access']==='none')throw new DomainException('Mitgliederberechtigung für Zuordnungen erforderlich.');$q=$this->db->prepare('SELECT x.*,c.name FROM catalog_assignments x JOIN contacts c ON c.club_id=x.club_id AND c.id=x.contact_id WHERE x.club_id=? ORDER BY x.starts_on DESC');$q->execute([$a['club_id']]);return $q->fetchAll();
    }
    private function assignmentWrite(callable $operation):mixed
    {
        $club=$this->authorize('catalog',true)['club_id'];
        try{
            return$this->transaction(function()use($club,$operation):mixed{
                $q=$this->db->prepare('SELECT id FROM clubs WHERE id=? FOR UPDATE NOWAIT');$q->execute([$club]);
                if(!$q->fetchColumn())throw new DomainException('Eigenen aktiven Verein auswählen.',403);
                $a=$this->authorize('catalog',true);
                if($a['club_id']!==$club)throw new DomainException('Vereinszuordnung inzwischen geändert.',403);
                if($a['members_access']!=='write')throw new DomainException('Mitgliederverwaltung für die Zuweisung erforderlich.',403);
                return$operation($a);
            });
        }catch(\PDOException $failure){
            if(in_array((int)($failure->errorInfo[1]??0),[1205,3572],true))throw new DomainException('Tarifzuweisungen werden gerade bearbeitet. Bitte erneut prüfen.',503,$failure);
            throw$failure;
        }
    }
    public function assign(string $catalog,string $contact,string $start,string $end):string
    {
        return$this->assignmentWrite(function(array $a)use($catalog,$contact,$start,$end):string{
            SettingsSchema::date($start);
            if($end!==''){SettingsSchema::date($end);if($end<$start)throw new DomainException('Ende liegt vor Beginn.',422);}
            $q=$this->db->prepare('SELECT active FROM settings_catalog WHERE club_id=? AND id=? FOR SHARE NOWAIT');$q->execute([$a['club_id'],$catalog]);
            if((int)$q->fetchColumn()!==1)throw new DomainException('Aktiven Tarif auswählen.',422);
            $q=$this->db->prepare('SELECT id FROM contacts WHERE club_id=? AND id=? AND archived_at IS NULL FOR SHARE NOWAIT');$q->execute([$a['club_id'],$contact]);
            if(!$q->fetchColumn())throw new DomainException('Aktiven eigenen Kontakt auswählen.',404);
            $q=$this->db->prepare("SELECT 1 FROM catalog_assignments WHERE club_id=? AND catalog_id=? AND contact_id=? AND starts_on<=? AND COALESCE(ends_on,'9999-12-31')>=? FOR SHARE NOWAIT");
            $q->execute([$a['club_id'],$catalog,$contact,$end?:'9999-12-31',$start]);
            if($q->fetchColumn())throw new DomainException('Tarif ist in diesem Zeitraum bereits zugewiesen.',409);
            $id=bin2hex(random_bytes(16));
            $this->db->prepare('INSERT INTO catalog_assignments VALUES(?,?,?,?,?,?,1)')->execute([$a['club_id'],$id,$catalog,$contact,$start,$end?:null]);
            $this->revision($a,'assignment:'.$id,1,['catalog'=>$catalog,'contact'=>$contact,'start'=>$start,'end'=>$end]);
            return$id;
        });
    }
    public function endAssignment(string $id,int $version,string $end):void
    {
        $this->assignmentWrite(function(array $a)use($id,$version,$end):void{
            SettingsSchema::date($end);
            $q=$this->db->prepare('SELECT * FROM catalog_assignments WHERE club_id=? AND id=? FOR UPDATE NOWAIT');$q->execute([$a['club_id'],$id]);$row=$q->fetch(PDO::FETCH_ASSOC);
            if(!$row||(int)$row['version']!==$version||$row['ends_on']!==null)throw new DomainException('Zuweisung inzwischen geändert oder Enddatum unzulässig.',409);
            if($end<$row['starts_on'])throw new DomainException('Ende liegt vor Beginn.',422);
            $q=$this->db->prepare('SELECT id FROM contacts WHERE club_id=? AND id=? AND archived_at IS NULL FOR SHARE NOWAIT');$q->execute([$a['club_id'],$row['contact_id']]);
            if(!$q->fetchColumn())throw new DomainException('Aktiven eigenen Kontakt auswählen.',404);
            $q=$this->db->prepare('UPDATE catalog_assignments SET ends_on=?,version=version+1 WHERE club_id=? AND id=? AND version=? AND ends_on IS NULL');$q->execute([$end,$a['club_id'],$id,$version]);
            if($q->rowCount()!==1)throw new DomainException('Zuweisung inzwischen geändert oder Enddatum unzulässig.',409);
            $this->revision($a,'assignment:'.$id,$version+1,['end'=>$end]);
        });
    }
    public function roles():array{$a=$this->authorize('roles');$q=$this->db->prepare('SELECT * FROM settings_roles WHERE club_id=? ORDER BY name');$q->execute([$a['club_id']]);return $q->fetchAll();}
    public function users():array{$a=$this->authorize('roles');$q=$this->db->prepare('SELECT a.id,a.role,a.active,a.contact_id,x.role_id,COALESCE(x.version,0) AS version FROM actors a LEFT JOIN settings_role_assignments x ON x.actor_id=a.id AND x.club_id=a.club_id WHERE a.club_id=? ORDER BY a.id');$q->execute([$a['club_id']]);return $q->fetchAll();}
    public function saveRole(string $id,int $version,array $data):string
    {
        $club=$this->authorize('roles',true)['club_id'];
        return $this->transaction(function()use($id,$version,$data,$club){RoleAssignments::lockClub($this->db,$club);$a=$this->authorize('roles',true);if($a['club_id']!==$club)throw new DomainException('Vereinszuordnung inzwischen geändert.');$q=$this->db->prepare('SELECT * FROM settings_roles WHERE club_id=? ORDER BY id FOR UPDATE');$q->execute([$club]);$roles=$q->fetchAll();$old=null;foreach($roles as $r)if($r['id']===$id)$old=$r;if(($id!==''&&(!$old||(int)$old['version']!==$version))||($id===''&&$version!==0))throw new DomainException('Rolle inzwischen geändert.');
            $name=\Kulturhus\Verein\Settings\RoleText::name($data['name']??'');$desc=\Kulturhus\Verein\Settings\RoleText::description($data['description']??'');$members=SettingsSchema::choice($data['members_access']??'',['none','read','write']);$finance=SettingsSchema::choice($data['finance_access']??'',['none','read','write']);$settings=SettingsSchema::bool($data['settings_access']??false);
            foreach($roles as $r)if($r['id']!==$id&&$r['name']===$name)throw new DomainException('Rollenname bereits vergeben.');$id=$id?:bin2hex(random_bytes(16));
            $inventory=SettingsSchema::choice($data['inventory_access']??$members,['none','read','write']);$loans=SettingsSchema::choice($data['loans_access']??$members,['none','read','write']);$documents=SettingsSchema::choice($data['documents_access']??$members,['none','read','write']);
            $press=SettingsSchema::choice($data['press_access']??($old['press_access']??'none'),['none','read','write']);
            $minutes=SettingsSchema::choice($data['minutes_access']??($old['minutes_access']??'none'),['none','read','write']);
            $website=$old['website_access']??null;
            if(array_key_exists('website_custom',$data)||array_key_exists('website_levels',$data)){
                if(!array_key_exists('website_custom',$data))throw new DomainException('Bitte ausdrücklich auswählen, ob eigene Website-Bereichsfreigaben verwendet werden sollen.');
                $custom=SettingsSchema::bool($data['website_custom']??false);$matrix=[];
                if(array_key_exists('website_levels',$data)&&!is_array($data['website_levels']))throw new DomainException('Website-Freigaben prüfen.');
                if($custom||array_key_exists('website_levels',$data))foreach(\Kulturhus\Verein\EditorialPolicy::AREAS as $area=>$label){
                    $matrix[$area]=SettingsSchema::choice($data['website_levels'][$area]??'', ['none','read','write']);
                    $baseline=\Kulturhus\Verein\EditorialPolicy::level(['settings_access'=>$settings,'press_access'=>$press],$area);
                    if(!$custom&&$matrix[$area]!==$baseline)throw new DomainException('Die Website-Freigaben wurden geändert. Eigene Bereichsfreigaben aktivieren oder die angezeigten Voreinstellungen wiederherstellen.');
                }
                $website=$custom?json_encode($matrix,JSON_THROW_ON_ERROR):null;
            }
            $this->db->prepare('INSERT INTO settings_roles(club_id,id,name,description,members_access,finance_access,settings_access,version,inventory_access,loans_access,documents_access,press_access,minutes_access) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE name=VALUES(name),description=VALUES(description),members_access=VALUES(members_access),finance_access=VALUES(finance_access),settings_access=VALUES(settings_access),version=VALUES(version),inventory_access=VALUES(inventory_access),loans_access=VALUES(loans_access),documents_access=VALUES(documents_access),press_access=VALUES(press_access),minutes_access=VALUES(minutes_access)')->execute([$a['club_id'],$id,$name,$desc,$members,$finance,(int)$settings,$version+1,$inventory,$loans,$documents,$press,$minutes]);$this->db->prepare('UPDATE settings_roles SET website_access=? WHERE club_id=? AND id=?')->execute([$website,$a['club_id'],$id]);$this->ensureManager($a['club_id']);$this->revision($a,'role:'.$id,$version+1,['name'=>$name,'description'=>$desc,'members'=>$members,'finance'=>$finance,'settings'=>$settings,'inventory'=>$inventory,'loans'=>$loans,'documents'=>$documents,'press'=>$press,'minutes'=>$minutes,'website_access'=>$website]);return $id;});
    }
    private function ensureManager(string $club):void{$q=$this->db->prepare("SELECT id FROM actors WHERE club_id=? AND active=1 AND role!='editor'");$q->execute([$club]);foreach($q as $r)if(Permissions::actor($this->db,$r['id'])['settings_access'])return;throw new DomainException('Mindestens eine aktive Vereinsverwaltung muss die Einstellungen verwalten können.');}
    public function assignRole(string $user,string $role,int $version,bool $defaults=false):void
    {
        $club=$this->authorize('roles',true)['club_id'];
        $this->transaction(function()use($user,$role,$version,$defaults,$club){RoleAssignments::lockClub($this->db,$club);$a=$this->authorize('roles',true);if($a['club_id']!==$club)throw new DomainException('Vereinszuordnung inzwischen geändert.');$target=(new RoleCatalogue($this->db,$this->actorId))->account($user);if(!$target['active'])throw new DomainException('Eigenes Konto nicht aktiv.');if((int)$target['version']!==$version)throw new DomainException('Rollenzuordnung inzwischen geändert. Aktuellen Stand neu öffnen.');
            RoleAssignments::write($this->db,$club,$user,$role,$version,$defaults);$this->ensureManager($club);$this->revision($a,'role-assignment:'.$user,$version+1,['role'=>$defaults?'':$role,'defaults'=>$defaults]);});
    }
    public function previewValues():array
    {
        $a=$this->authorize('paper');$club=$this->section('club')['data'];$bank=[];if($a['finance_access']!=='none')$bank=$this->section('bank')['data'];
        return ['VEREINSNAME'=>$club['name'],'VORNAME'=>'Alex','NACHNAME'=>'Muster','BRIEFANREDE'=>'Guten Tag Alex Muster,','ANREDE'=>'','TITEL'=>'','ZAHLUNG_FUER'=>'Alex Muster','ZAHLBETRAG'=>'120,00 €','VEREINS_IBAN'=>$bank['iban']??'','VEREINS_BIC'=>$bank['bic']??'','KONTOINHABER'=>$bank['holder']??'','GLAEUBIGER_ID'=>$bank['creditor']??'','PAYPAL_LINK'=>$bank['paypal']??'','BEARBEITER'=>$this->section('account')['data']['display_name'],'MITGLIEDSNUMMER'=>'M-000001','VERWENDUNGSZWECK'=>'VORSCHAU','RECHNUNGSNUMMER'=>'VORSCHAU','RECHNUNGSDATUM'=>date('d.m.Y'),'DATUM'=>date('d.m.Y'),'LIEFERDATUM'=>date('d.m.Y'),'FAELLIGKEIT'=>date('d.m.Y',strtotime('+14 days')),'ABRECHNUNGSZEITRAUM'=>date('Y'),'BEZEICHNUNG'=>'Vereinsbeitrag · Muster','NOTIZ'=>'','ZAHLUNGSART'=>'Überweisung','MANDATSREFERENZ'=>'MUSTER-MANDAT','LASTSCHRIFT_DATUM'=>date('d.m.Y',strtotime('+14 days')),'MITGLIED_SEIT'=>'01.01.'.date('Y'),'MITGLIED_BIS'=>''];
    }
    public function setupStatus():array
    {
        $a=$this->actor();$out=['rates'=>false,'assigned'=>false,'services'=>false,'texts'=>false,'signature'=>false,'numbers'=>false];
        if($a['finance_access']!=='none'){$rows=$this->catalog();foreach($rows as $r)if($r['active'])$out[$r['kind']==='service'?'services':'rates']=true;$out['numbers']=$this->section('numbers')['version']>0;if($a['members_access']!=='none')$out['assigned']=count($this->assignments())>0;}
        if($a['settings_access']){$out['texts']=$this->section('texts_contributions')['version']>0||$this->section('texts_invoices')['version']>0||$this->section('texts_donations')['version']>0;$out['signature']=$this->section('paper')['data']['signature']!=='';}return $out;
    }
}
