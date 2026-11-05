<?php declare(strict_types=1);
namespace Kulturhus\Verein;
use DomainException;
use PDO;
/** Public submission is only routed by the loopback development front controller. */
final class MembershipApplications
{
    public const FIELDS=['first_name'=>'Vorname','last_name'=>'Nachname','birthday'=>'Geburtsdatum','salutation'=>'Anrede','gender'=>'Geschlecht','marital_status'=>'Familienstand','email'=>'E-Mail','communication_email'=>'Kommunikations-E-Mail','communication_channel'=>'Kommunikationsweg','phone'=>'Telefon','address'=>'Adresse'];
    public const MODES=['optional'=>'Abfrage überspringbar','required'=>'Abfrage ist Pflicht','hidden'=>'Nicht abfragen'];
    public function introCompleted():bool{return $this->introAcknowledgement()!==null;}
    /** The first club acknowledgement keeps its actual account and time; no earlier text is invented. */
    public function introAcknowledgement():?array
    {
        $owned=!$this->db->inTransaction();
        try{
            if($owned)$this->db->exec('START TRANSACTION');$this->setupStaff();
            $q=$this->db->prepare("SELECT club_id,step,actor_id,completed_at FROM setup_acknowledgements WHERE club_id=? AND step='intro' FOR SHARE NOWAIT");$q->execute([$this->clubId]);$row=$q->fetch(PDO::FETCH_ASSOC);
            if($owned)$this->db->commit();return$row?:null;
        }catch(\Throwable $failure){if($owned&&$this->db->inTransaction())$this->db->rollBack();if($failure instanceof \PDOException&&in_array((int)($failure->errorInfo[1]??0),[1205,3572],true))throw new DomainException('Einführungsstand wird gerade bearbeitet. Bitte erneut öffnen.',503,$failure);throw$failure;}
    }
    public function completeIntro():void
    {
        \Kulturhus\Verein\Infrastructure\Database\Transactions::run($this->db,function():void{
            $a=$this->setupStaff();$q=$this->db->prepare('SELECT id FROM clubs WHERE id=? FOR UPDATE');$q->execute([$this->clubId]);$q->fetchColumn();
            if($this->introAcknowledgement()!==null)return;
            $this->db->prepare("INSERT INTO setup_acknowledgements(club_id,step,actor_id,completed_at) VALUES(?,'intro',?,?)")->execute([$this->clubId,$a['id'],gmdate('Y-m-d\TH:i:s\Z')]);$this->audit($a,'setup.intro.completed',$this->clubId,1);
        });
    }
    public function __construct(private PDO $db,private string $clubId,private ?string $actorId=null){}
    private function staff():array{$a=BoardAccess::require($this->db,$this->actorId??'');if($a['club_id']!==$this->clubId)throw new DomainException('Antrag nicht verfügbar.');return $a;}
    private function setupStaff():array{$a=Permissions::actor($this->db,$this->actorId??'');if($a['club_id']!==$this->clubId||$a['members_access']!=='write')throw new DomainException('Mitgliederberechtigung erforderlich.');return $a;}
    private function transaction(callable $fn):mixed{return \Kulturhus\Verein\Infrastructure\Database\Transactions::run($this->db,$fn);}
    private function audit(array $a,string $action,string $id,int $version):void{if($action==='contact.created-from-application')DocumentCatalog::register($this->db,$this->clubId,'contact',$id);elseif(in_array($action,['application.accepted','application.rejected'],true))DocumentCatalog::register($this->db,$this->clubId,'application',$id);$this->db->prepare('INSERT INTO audit_events(club_id,actor_id,action,object_id,object_version,happened_at) VALUES(?,?,?,?,?,?)')->execute([$this->clubId,$a['id'],$action,$id,$version,gmdate('Y-m-d\TH:i:s\Z')]);}
    public function rates():array{return ContributionRates::all($this->db,$this->clubId);}
    public function settings():array
    {
        $q=$this->db->prepare('SELECT * FROM application_settings WHERE club_id=?');$q->execute([$this->clubId]);return self::settingsValue($q->fetch()?:null);
    }
    private static function settingsValue(?array $row):array
    {
        if($row){$config=json_decode($row['configuration'],true,512,JSON_THROW_ON_ERROR);$config['fields']['birthday']='required';$config['fields']['email']='required';$config['fields']['communication_channel']='hidden';if(($config['notice_literal']??false)!==true){$config['notice_text']=str_replace('(Einzelmitglied 48 €, Familie insgesamt 72 €)','(Einzelmitglied {{EINZELBEITRAG}}, Familie insgesamt {{FAMILIENBEITRAG}})',$config['notice_text']);$config['notice_text']=str_replace([' Lokale Testumgebung: nur synthetische Angaben verwenden.','Der Antrag wird mit diesen Hinweisen und dem Eingangszeitpunkt als PDF abgelegt.'],['','Nach der Bestätigung per E-Mail wird der Antrag zur Prüfung vorgelegt.'],$config['notice_text']);}return ['version'=>(int)$row['version'],'config'=>$config];}
        return ['version'=>0,'config'=>['fields'=>array_replace(array_fill_keys(array_keys(self::FIELDS),'optional'),['first_name'=>'required','last_name'=>'required','birthday'=>'required','email'=>'required','address'=>'required','communication_email'=>'hidden','communication_channel'=>'hidden','marital_status'=>'hidden']), 'payment'=>'invoice','notice_title'=>'Hinweise zum Mitgliedsantrag','notice_text'=>'Nach der Bestätigung per E-Mail wird dein Antrag dem Vorstand zur Prüfung vorgelegt. Eine Mitgliedschaft entsteht erst nach Prüfung und Annahme.','notice_required'=>true,'enabled'=>false]];
    }
    /** Called under the original club transaction, including the original tariff rows. */
    public function manualContext():array
    {
        if(!$this->db->inTransaction())throw new DomainException('Ursprüngliche Antragsbedingungen gemeinsam prüfen.',422);$this->staff();
        $q=$this->db->prepare('SELECT version,configuration FROM application_settings WHERE club_id=? FOR SHARE NOWAIT');$q->execute([$this->clubId]);$row=$q->fetch(PDO::FETCH_ASSOC)?:null;$settings=self::settingsValue($row);$rates=ContributionRates::all($this->db,$this->clubId,true);$rates=['single'=>$rates['single'],'family'=>$rates['family']];
        $source=['club_id'=>$this->clubId,'version'=>$settings['version'],'raw_configuration'=>$row['configuration']??null,'configuration'=>$settings['config'],'rates'=>$rates];$sourceJson=json_encode($source,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);$fingerprint=hash('sha256',$sourceJson);
        $notice=strtr($settings['config']['notice_text'],['{{EINZELBEITRAG}}'=>ContributionRates::label($rates['single']['amount_cents']),'{{FAMILIENBEITRAG}}'=>ContributionRates::label($rates['family']['amount_cents'])]);
        return ['club_id'=>$this->clubId,'version'=>$settings['version'],'configuration'=>$settings['config'],'rates'=>$rates,'notice'=>$notice,'source_json'=>$sourceJson,'fingerprint'=>$fingerprint];
    }
    public function resolvedSettings():array
    {
        $settings=$this->settings();$rates=$this->rates();
        $settings['config']['notice_text']=strtr($settings['config']['notice_text'],['{{EINZELBEITRAG}}'=>ContributionRates::label($rates['single']['amount_cents']),'{{FAMILIENBEITRAG}}'=>ContributionRates::label($rates['family']['amount_cents'])]);
        return $settings;
    }
    private const SETUP_HISTORY='membership-applications';
    private function setupOperation(bool $write,callable $operation):array
    {
        try{return $this->transaction(function()use($write,$operation):array{
            $actor=$this->staff();$q=$this->db->prepare('SELECT id FROM clubs WHERE id=? FOR '.($write?'UPDATE':'SHARE').' NOWAIT');$q->execute([$this->clubId]);if(!$q->fetchColumn())throw new DomainException('Antrag nicht verfügbar.',404);$actor=$this->staff();return$operation($actor);
        });}catch(\PDOException $failure){if(in_array((int)($failure->errorInfo[1]??0),[1062,1205,1213,3572],true))throw new DomainException('Die Antragseinrichtung wird gerade bearbeitet oder wurde inzwischen geändert. Bitte erneut prüfen.',409,$failure);throw new DomainException('Die Antragseinrichtung konnte nicht gespeichert werden. Deine Eingaben bleiben erhalten.',503,$failure);}
    }
    public function setupContext():array{return$this->setupOperation(false,fn():array=>$this->manualContext());}
    /** A conditional configuration write and its exact before/after originals commit together. */
    public function saveSettings(array $input,int $version,?string $fingerprint=null,?string $requestKey=null):array
    {
        if($version<0||($fingerprint!==null&&!preg_match('/\A[a-f0-9]{64}\z/D',$fingerprint))||($requestKey!==null&&($fingerprint===null||!preg_match('/\A[a-f0-9]{32}\z/D',$requestKey))))throw new DomainException('Ursprüngliche Einrichtung und Vorgangskennung prüfen.',422);
        return$this->setupOperation(true,function(array $actor)use($input,$version,$fingerprint,$requestKey):array{
            if($requestKey!==null){$q=$this->db->prepare("SELECT version FROM settings_revisions WHERE club_id=? AND section=? AND JSON_UNQUOTE(JSON_EXTRACT(payload,'$.request_key'))=? ORDER BY version DESC LIMIT 1");$q->execute([$this->clubId,self::SETUP_HISTORY,$requestKey]);if($saved=$q->fetchColumn())return['version'=>(int)$saved,'reused'=>true];}
            $q=$this->db->prepare('SELECT * FROM application_settings WHERE club_id=? FOR UPDATE NOWAIT');$q->execute([$this->clubId]);$beforeRow=$q->fetch(PDO::FETCH_ASSOC)?:null;$source=$this->manualContext();
            if($source['version']!==$version||($fingerprint!==null&&!hash_equals($source['fingerprint'],$fingerprint)))throw new DomainException('Einrichtung oder angezeigte Beiträge wurden inzwischen geändert. Prüfe die aktuelle Fassung vor einer neuen Speicherung.',409);
            if(!is_array($input['fields']??null)||array_diff(array_keys($input['fields']),array_keys(self::FIELDS)))throw new DomainException('Unbekannte Antragsfelder.',422);$fields=[];
            foreach(self::FIELDS as$key=>$label){$mode=$input['fields'][$key]??null;if(!is_string($mode)||!isset(self::MODES[$mode]))throw new DomainException('Feldoptionen prüfen.',422);$fields[$key]=$mode;}
            foreach(['first_name','last_name','birthday','email']as$key)if($fields[$key]!=='required')throw new DomainException('Vorname, Nachname, Geburtsdatum und E-Mail sind immer Pflichtfelder.',422);
            if($fields['communication_channel']!=='hidden')throw new DomainException('Der Kommunikationsweg wird in diesem Antrag nicht abgefragt.',422);
            $title=$input['notice_title']??null;$text=$input['notice_text']??null;
            if(!is_string($title)||!is_string($text)||preg_match('//u',$title)!==1||preg_match('//u',$text)!==1||trim($title)===''||mb_strlen($title,'UTF-8')>150||trim($text)===''||mb_strlen($text,'UTF-8')>10000||preg_match('/[\x00-\x1f\x7f]/',$title)||preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/',$text))throw new DomainException('Titel und Inhalt der Hinweise prüfen.',422);
            if(($input['payment']??null)!=='invoice')throw new DomainException('Für diesen Mitgliedsantrag Rechnung auswählen.',422);
            $boolean=static function(mixed $value):bool{if(!in_array($value,[true,false,0,1,'0','1'],true))throw new DomainException('Bestätigungen zur Antragseinrichtung prüfen.',422);return in_array($value,[true,1,'1'],true);};
            $config=array_replace($source['configuration'],['fields'=>$fields,'payment'=>'invoice','notice_title'=>$title,'notice_text'=>$text,'notice_required'=>$boolean($input['notice_required']??false),'enabled'=>$boolean($input['enabled']??false),'notice_literal'=>true]);
            $json=json_encode($config,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);$time=gmdate('Y-m-d\TH:i:s\Z');
            if($beforeRow===null){$q=$this->db->prepare('INSERT INTO application_settings(club_id,version,configuration,updated_by,updated_at) VALUES(?,?,?,?,?)');$q->execute([$this->clubId,$version+1,$json,$actor['id'],$time]);}
            else{$q=$this->db->prepare('UPDATE application_settings SET version=?,configuration=?,updated_by=?,updated_at=? WHERE club_id=? AND version=?');$q->execute([$version+1,$json,$actor['id'],$time,$this->clubId,$version]);if($q->rowCount()!==1)throw new DomainException('Einrichtung oder angezeigte Beiträge wurden inzwischen geändert. Prüfe die aktuelle Fassung vor einer neuen Speicherung.',409);}
            $after=$this->manualContext();$q=$this->db->prepare('SELECT * FROM application_settings WHERE club_id=?');$q->execute([$this->clubId]);$afterRow=$q->fetch(PDO::FETCH_ASSOC);
            $original=['kind'=>'membership-application-setup','request_key'=>$requestKey,'before_row'=>$beforeRow,'after_row'=>$afterRow,'before'=>json_decode($source['source_json'],true,64,JSON_THROW_ON_ERROR),'after'=>json_decode($after['source_json'],true,64,JSON_THROW_ON_ERROR)];
            $q=$this->db->prepare('INSERT INTO settings_revisions(club_id,section,version,payload,actor_id,happened_at) VALUES(?,?,?,?,?,?)');$q->execute([$this->clubId,self::SETUP_HISTORY,$version+1,json_encode($original,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE),$actor['id'],$time]);$this->audit($actor,'application.settings.saved',$this->clubId,$version+1);return['version'=>$version+1,'reused'=>false];
        });
    }
    public function setupHistory(int $page=1,?int $revision=null):array
    {
        if($page<1||$page>1000000||($revision!==null&&$revision<1))throw new DomainException('Verlaufsseite und gespeicherte Fassung prüfen.',422);
        return$this->transaction(function()use($page,$revision):array{
            $this->staff();$q=$this->db->prepare('SELECT COUNT(*) FROM settings_revisions WHERE club_id=? AND section=?');$q->execute([$this->clubId,self::SETUP_HISTORY]);$total=(int)$q->fetchColumn();$pages=max(1,(int)ceil($total/5));$page=min($page,$pages);
            $q=$this->db->prepare('SELECT version,happened_at FROM settings_revisions WHERE club_id=? AND section=? ORDER BY version DESC LIMIT 5 OFFSET '.(($page-1)*5));$q->execute([$this->clubId,self::SETUP_HISTORY]);$rows=$q->fetchAll(PDO::FETCH_ASSOC);$selected=null;
            if($revision!==null){$q=$this->db->prepare('SELECT version,payload,happened_at FROM settings_revisions WHERE club_id=? AND section=? AND version=?');$q->execute([$this->clubId,self::SETUP_HISTORY,$revision]);$selected=$q->fetch(PDO::FETCH_ASSOC);if(!$selected)throw new DomainException('Gespeicherte Einrichtung nicht verfügbar.',404);try{$selected['original']=json_decode($selected['payload'],true,64,JSON_THROW_ON_ERROR);self::validateSetupHistory($selected['original']);}catch(\JsonException){throw new DomainException('Gespeicherte Fassung prüfen.',422);}unset($selected['payload']);}
            $this->staff();return compact('rows','selected','total','pages','page');
        });
    }
    /** Incomplete history must not turn into invented original field choices. */
    private static function validateSetupHistory(mixed $original):void
    {
        $invalid=static function():never{throw new DomainException('Gespeicherte Fassung prüfen.',422);};
        if(!is_array($original)||($original['kind']??null)!=='membership-application-setup')$invalid();
        foreach(['before','after']as$which){
            $source=$original[$which]??null;if(!is_array($source)||!array_key_exists($which.'_row',$original))$invalid();$row=$original[$which.'_row'];$config=$source['configuration']??null;
            if(!is_array($config)||!is_string($config['notice_title']??null)||!is_string($config['notice_text']??null)||($config['payment']??null)!=='invoice'||!is_bool($config['enabled']??null)||!is_bool($config['notice_required']??null)||!is_array($config['fields']??null))$invalid();
            foreach(self::FIELDS as$key=>$label)if(!is_string($config['fields'][$key]??null)||!isset(self::MODES[$config['fields'][$key]]))$invalid();
            foreach(['single','family']as$type)if(!is_int($source['rates'][$type]['amount_cents']??null)||$source['rates'][$type]['amount_cents']<0)$invalid();
            if($row===null){if($which==='after')$invalid();continue;}
            if(!is_array($row)||!is_string($row['configuration']??null))$invalid();$raw=json_decode($row['configuration'],true,64,JSON_THROW_ON_ERROR);if(!is_array($raw)||!is_string($raw['notice_title']??null)||!is_string($raw['notice_text']??null))$invalid();
        }
    }
    public function submit(array $input,string $key,int $configVersion,bool $consent,bool $manual=false,bool $awaitEmail=false,bool $newsletter=false,?bool $accessMail=null):string
    {
        return $this->transaction(function()use($input,$key,$configVersion,$consent,$manual,$awaitEmail,$newsletter,$accessMail){
            if($manual)$this->staff();
            if(!preg_match('/^[a-f0-9]{32}$/D',$key))throw new DomainException('Antragsformular neu öffnen.');
            $q=$this->db->prepare('SELECT id FROM membership_applications WHERE club_id=? AND request_key=?');$q->execute([$this->clubId,$key]);if($id=$q->fetchColumn())return $id;
            $settings=$this->resolvedSettings();$config=$settings['config'];if(!$manual&&!$config['enabled'])throw new DomainException('Der lokale Mitgliedsantrag ist noch nicht aktiviert.');if($settings['version']!==$configVersion)throw new DomainException('Die Antragsbedingungen wurden geändert. Bitte das Formular neu laden und erneut prüfen.');
            if(array_diff(array_keys($input),array_merge(array_keys(self::FIELDS),['membership_type','family_members','family_reference'])))throw new DomainException('Unbekannte Antragsangaben.');$answers=[];
            foreach($config['fields'] as $field=>$mode){$value=$input[$field]??'';if(!is_string($value))throw new DomainException('Antragsangaben prüfen.');$value=trim($value);
                if($mode==='hidden'){if($value!=='')throw new DomainException('Nicht abgefragte Angaben werden nicht gespeichert.');continue;}
                if(($mode==='required'&&$value===''&&(!$manual||in_array($field,['first_name','last_name'],true)))||strlen($value)>($field==='address'?1000:200))throw new DomainException('Bitte '.self::FIELDS[$field].' prüfen.');
                if($value!==''&&in_array($field,['email','communication_email'],true)&&!filter_var($value,FILTER_VALIDATE_EMAIL))throw new DomainException('E-Mail-Adresse prüfen.');
                if($field==='birthday'&&$value!==''){$d=\DateTimeImmutable::createFromFormat('!Y-m-d',$value);if(!$d||$d->format('Y-m-d')!==$value||$value>date('Y-m-d'))throw new DomainException('Geburtstag prüfen.');}
                $answers[$field]=$value;
            }
            if(!$manual&&$config['notice_required']&&!$consent)throw new DomainException('Bitte die erforderliche Zustimmung zu den Hinweisen erteilen.');
            $type=$input['membership_type']??'single';if(!in_array($type,['single','family','family_member'],true))throw new DomainException('Mitgliedschaft auswählen.');
            $familyNames=$input['family_members']??'';if(!is_string($familyNames)||strlen($familyNames)>2000)throw new DomainException('Familienangehörige prüfen.');
            if($type==='single'&&trim($familyNames)!=='')throw new DomainException('Für Familienangehörige bitte Familienmitgliedschaft auswählen.');
            $reference=$input['family_reference']??'';if(!is_string($reference)||strlen($reference)>200||($type==='family_member'&&trim($reference)===''))throw new DomainException('Bitte den Namen des Familienmitglieds oder Beitragszahlers angeben.');
            if($awaitEmail&&!$manual){$review=\Kulturhus\Verein\People\Members\ConfirmationMail::capture($this->db,$this->clubId,$input,$config,$this->rates(),$configVersion,$consent,$newsletter);return ApplicationConfirmation::enqueue($this->db,$this->clubId,$input,$key,$configVersion,$consent,$newsletter,$review);}
            $answers['membership_type']=$type;$answers['annual_fee_cents']=$this->rates()[$type==='single'?'single':'family']['amount_cents'];$answers['family_members']=trim($familyNames);$answers['family_reference']=$type==='family_member'?trim($reference):'';
            $answers['source']=$manual?'manual':'online';$answers['recorded_by']=$manual?$this->actorId:null;$answers['payment']='invoice';$id=bin2hex(random_bytes(16));$now=gmdate('Y-m-d\TH:i:s\Z');
            $this->db->prepare("INSERT INTO membership_applications(id,club_id,request_key,status,answers,configuration,consent_snapshot,configuration_version,submitted_at) VALUES(?,?,?,'pending',?,?,?,?,?)")->execute([$id,$this->clubId,$key,json_encode($answers,JSON_THROW_ON_ERROR),json_encode($config,JSON_THROW_ON_ERROR),json_encode(['title'=>$config['notice_title'],'text'=>$config['notice_text'],'accepted'=>$consent,'required'=>$config['notice_required'],'at'=>$now],JSON_THROW_ON_ERROR),$configVersion,$now]);DocumentCatalog::register($this->db,$this->clubId,'application',$id);ApplicationArchive::create($this->db,$this->clubId,$id);if($manual&&$accessMail!==null)DocumentCatalog::query($this->db,'UPDATE membership_applications SET access_mail=? WHERE club_id=? AND id=?',[(int)$accessMail,$this->clubId,$id]);if(!$manual)ApplicationNotifications::enqueue($this->db,$this->clubId,$id);return $id;
        });
    }
    public function confirmEmail(string $token):string{return ApplicationConfirmation::confirm($this->db,$this->clubId,$token,$this);}
    public function applications(string $status='pending'):array{$this->staff();if(!in_array($status,['pending','accepted','rejected',''],true))throw new DomainException('Antragsstatus prüfen.');$q=$this->db->prepare('SELECT * FROM membership_applications WHERE club_id=? AND (?=\'\' OR status=?) ORDER BY submitted_at DESC,id LIMIT 501');$q->execute([$this->clubId,$status,$status]);$rows=$q->fetchAll();if(count($rows)>500)throw new DomainException('Mehr als 500 Anträge: Zeitraumfilter muss erweitert werden.');return $rows;}
    public function application(string $id):array{$this->staff();$q=$this->db->prepare('SELECT * FROM membership_applications WHERE club_id=? AND id=?');$q->execute([$this->clubId,$id]);$row=$q->fetch();if(!$row)throw new DomainException('Antrag nicht verfügbar.');return $row;}
    /** Only the admission reviewers see the available family/contact identities. */
    public function admissionChoicePage(string $kind,array $input=[]):array
    {
        $this->staff();
        if(!in_array($kind,['family','contact'],true))throw new DomainException('Aufnahmezuordnung prüfen.');
        $query=$input['query']??'';$page=$input['page']??1;$selected=$input['selected']??'';
        if(!is_string($query)||strlen($query)>150||preg_match('//u',$query)!==1||preg_match('/[\x00-\x1f\x7f]/',$query)||(!is_string($page)&&!is_int($page))||!preg_match('/\A[1-9][0-9]{0,8}\z/D',(string)$page)||!is_string($selected)||strlen($selected)>64||preg_match('//u',$selected)!==1||preg_match('/[\x00-\x1f\x7f]/',$selected))throw new DomainException('Zuordnung, Suche und Seite prüfen.');
        $query=trim($query);$held=null;$selectedId='';
        if($selected!==''&&!($kind==='family'&&in_array($selected,['single','new'],true))){
            if($kind==='family'){
                if(!preg_match('/\A([a-f0-9]{32}):([1-9][0-9]{0,9})\z/D',$selected,$parts))throw new DomainException('Familienzuordnung prüfen.');
                $selectedId=$parts[1];
            }else{$selectedId=$selected;}
        }
        if($kind==='family'){
            $from=' FROM member_families f JOIN contacts c ON c.club_id=f.club_id AND c.id=f.payer_contact_id WHERE f.club_id=?';
            $fields='f.id,f.name,f.version,c.name payer_name';$search="CONCAT(f.name,CHAR(10 USING utf8mb4),c.name)";$order='f.name,f.id';$idColumn='f.id';
        }else{
            $from=" FROM contacts c WHERE c.club_id=? AND c.kind='person'";
            $fields='c.id,c.name,c.version';$search="CONCAT(c.name,CHAR(10 USING utf8mb4),c.email)";$order='c.name,c.id';$idColumn='c.id';
        }
        if($selectedId!==''){
            $held=DocumentCatalog::query($this->db,'SELECT '.$fields.$from.' AND '.$idColumn.'=?',[$this->clubId,$selectedId])->fetch(PDO::FETCH_ASSOC);
            if(!$held)throw new DomainException('Gewählte Zuordnung nicht mehr verfügbar.');
        }
        $arguments=[$this->clubId];$where=$from;
        if($query!==''){$where.=' AND LOCATE(LOWER(?),LOWER('.$search.'))>0';$arguments[]=$query;}
        $total=(int)DocumentCatalog::query($this->db,'SELECT COUNT(*)'.$where,$arguments)->fetchColumn();
        $pages=max(1,(int)ceil($total/6));$page=min((int)$page,$pages);
        $rows=DocumentCatalog::query($this->db,'SELECT '.$fields.$where.' ORDER BY '.$order.' LIMIT 6 OFFSET '.(($page-1)*6),$arguments)->fetchAll(PDO::FETCH_ASSOC);
        return ['kind'=>$kind,'query'=>$query,'page'=>$page,'pages'=>$pages,'total'=>$total,'rows'=>$rows,'selected'=>$held,'value'=>$selected];
    }

    public function familyChoices():array
    {
        $this->staff();return DocumentCatalog::query($this->db,'SELECT f.*,c.name payer_name FROM member_families f JOIN contacts c ON c.club_id=f.club_id AND c.id=f.payer_contact_id WHERE f.club_id=? ORDER BY f.name,f.id',[$this->clubId])->fetchAll();
    }
    public function contactChoices():array
    {
        $this->staff();return DocumentCatalog::query($this->db,"SELECT id,name FROM contacts WHERE club_id=? AND kind='person' ORDER BY name,id",[$this->clubId])->fetchAll();
    }
    public function linkedFamily(string $contactId):?array
    {
        $this->staff();return DocumentCatalog::query($this->db,'SELECT f.*,c.name payer_name FROM member_family_people p JOIN member_families f ON f.club_id=p.club_id AND f.id=p.family_id JOIN contacts c ON c.club_id=f.club_id AND c.id=f.payer_contact_id WHERE p.club_id=? AND p.contact_id=?',[$this->clubId,$contactId])->fetch()?:null;
    }
    /** The immutable answers and consent participate in the reviewed source identity. */
    public static function fingerprint(array $application):string{ksort($application);return hash('sha256',json_encode(array_map(static fn($value)=>$value===null?null:(string)$value,$application),JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE));}
    public function review(string $id,int $version,string $decision,string $note,string $number='',string $start='',string $existingContact='',bool $externalDecision=false,string $decisionDate='',string $familyTarget='',?string $expectedFingerprint=null):?string
    {
        return $this->transaction(function()use($id,$version,$decision,$note,$number,$start,$existingContact,$externalDecision,$decisionDate,$familyTarget,$expectedFingerprint){$a=$this->staff();
            try{$q=$this->db->prepare('SELECT * FROM membership_applications WHERE club_id=? AND id=? FOR UPDATE NOWAIT');$q->execute([$this->clubId,$id]);$r=$q->fetch(PDO::FETCH_ASSOC);}
            catch(\PDOException $failure){if(in_array((int)($failure->errorInfo[1]??0),[1205,1213,3572],true))throw new DomainException('Antrag wird gerade entschieden. Bitte erneut prüfen.',409,$failure);throw$failure;}
            if(!$r)throw new DomainException('Antrag nicht verfügbar.',404);$note=trim($note);
            if($r['status']!=='pending'||(int)$r['version']!==$version||($expectedFingerprint!==null&&!hash_equals(self::fingerprint($r),$expectedFingerprint)))throw new DomainException('Antrag bereits bearbeitet oder inzwischen geändert.',409);
            if(!in_array($decision,['accepted','rejected'],true)||$note===''||strlen($note)>2000)throw new DomainException('Entscheidung und Prüfvermerk angeben.');$contactId=null;
            $ballot=AdmissionVotes::result($this->db,$this->clubId,$id);
            if($externalDecision){if(!(new AdmissionVotes($this->db,$a['id']))->canManage())throw new DomainException('Berechtigung zur Dokumentation des Vorstandsbeschlusses fehlt.');$d=\DateTimeImmutable::createFromFormat('!Y-m-d',$decisionDate);if(!$d||$d->format('Y-m-d')!==$decisionDate||$decisionDate>date('Y-m-d'))throw new DomainException('Beschlussdatum prüfen.');$note='Vorstandsbeschluss außerhalb der Online-Abstimmung vom '.$decisionDate."\n".$note;}
            elseif(!$ballot||$ballot['result']!==$decision)throw new DomainException('Es liegt kein entsprechender einstimmiger Online-Beschluss vor. Einen bereits außerhalb der Abstimmung gefassten Vorstandsbeschluss ausdrücklich dokumentieren.');
            if($decision==='accepted'){
                $d=\DateTimeImmutable::createFromFormat('!Y-m-d',$start);if(!$d||$d->format('Y-m-d')!==$start)throw new DomainException('Eintrittsdatum prüfen.');$membershipId=bin2hex(random_bytes(16));
                if($number==='')$number=Numbering::allocate($this->db,$this->clubId,'member',$start,$membershipId);
                if(!preg_match('/^[\pL\pN ._\/-]{1,80}$/uD',$number))throw new DomainException('Mitgliedsnummer prüfen.');
                $q=$this->db->prepare('SELECT 1 FROM memberships WHERE club_id=? AND member_number=?');$q->execute([$this->clubId,$number]);if($q->fetchColumn())throw new DomainException('Mitgliedsnummer bereits vergeben.');
                $answers=json_decode($r['answers'],true,512,JSON_THROW_ON_ERROR);
                $family=null;
                if(!in_array($familyTarget,['single','new'],true)){
                    if(!preg_match('/^([a-f0-9]{32}):([1-9][0-9]*)$/D',$familyTarget,$target))throw new DomainException('Bitte vor der Aufnahme die Beitragszuordnung wählen: Einzelmitglied, neue oder bestehende Familie.');
                    $family=DocumentCatalog::query($this->db,'SELECT f.*,c.name payer_name FROM member_families f JOIN contacts c ON c.club_id=f.club_id AND c.id=f.payer_contact_id WHERE f.club_id=? AND f.id=?',[$this->clubId,$target[1]])->fetch();
                    if(!$family||(int)$family['version']!==(int)$target[2])throw new DomainException('Familie oder Beitragszahler inzwischen geändert. Bitte die Zuordnung neu auswählen.');
                }
                if($familyTarget==='single'&&in_array($answers['membership_type']??'single',['family','family_member'],true))throw new DomainException('Für einen Familienantrag bitte eine neue oder bestehende Familie zuordnen.');
                if(($answers['membership_type']??'')==='family_member'&&!$family)throw new DomainException('Für das Mitglied einer bestehenden Familie bitte die vorhandene Familie auswählen.');
                $name=$answers['first_name'].' '.$answers['last_name'];if(strlen($name)>200)throw new DomainException('Der vollständige Name ist zu lang für das Mitgliederprofil.');
                if($existingContact!==''){$q=$this->db->prepare("SELECT 1 FROM contacts WHERE club_id=? AND id=? AND kind='person'");$q->execute([$this->clubId,$existingContact]);if(!$q->fetchColumn())throw new DomainException('Kontakt nicht verfügbar.');$contactId=$existingContact;
                    $q=$this->db->prepare('SELECT 1 FROM memberships WHERE club_id=? AND contact_id=? AND (ends_on IS NULL OR ends_on>=?)');$q->execute([$this->clubId,$contactId,$start]);if($q->fetchColumn())throw new DomainException('Für diesen Kontakt besteht bereits eine überlappende Mitgliedschaft.');
                }else{
                    $matches=DocumentCatalog::query($this->db,'SELECT c.id,c.name,p.family_id FROM contacts c LEFT JOIN member_family_people p ON p.club_id=c.club_id AND p.contact_id=c.id WHERE c.club_id=? AND (lower(c.name)=lower(?) OR (?!=\'\' AND lower(trim(c.email))=lower(trim(?))))',[$this->clubId,$name,$answers['email']??'',$answers['email']??''])->fetchAll();
                    foreach($matches as $match)if(strcasecmp($match['name'],$name)===0||!$family||$match['family_id']!==$family['id'])throw new DomainException('Mögliches vorhandenes Profil. Bitte den passenden Kontakt auswählen oder die Angaben prüfen. Eine gemeinsame E-Mail ist innerhalb der ausgewählten Familie möglich.');
                    $contactId=bin2hex(random_bytes(16));$this->db->prepare("INSERT INTO contacts(id,club_id,kind,name,email,address,phone,birthday) VALUES(?,?,'person',?,?,?,?,?)")->execute([$contactId,$this->clubId,$name,$answers['email']??'',$answers['address']??'',$answers['phone']??'',($answers['birthday']??'')?:null]);$this->audit($a,'contact.created-from-application',$contactId,1);
                }
                $this->db->prepare("INSERT INTO memberships(id,club_id,contact_id,member_number,status,starts_on) VALUES(?,?,?,?,'aktiv',?)")->execute([$membershipId,$this->clubId,$contactId,$number,$start]);$this->audit($a,'membership.created-from-application',$membershipId,1);
                $previousFamily=$this->linkedFamily($contactId);
                if($previousFamily&&(!$family||$previousFamily['id']!==$family['id']))throw new DomainException('Das Kontaktprofil gehört bereits zu einer Familie. Bitte diese Familie auswählen; einen Wechsel unter Familie & Beitrag prüfen.');
                if($familyTarget==='new'){
                    $fid=bin2hex(random_bytes(16));$family=['id'=>$fid,'name'=>'Familie '.$answers['last_name'],'payer_name'=>$name,'payer_contact_id'=>$contactId];
                    DocumentCatalog::query($this->db,'INSERT INTO member_families(club_id,id,name,payer_contact_id) VALUES(?,?,?,?)',[$this->clubId,$fid,$family['name'],$contactId]);$this->audit($a,'family.created-from-application',$fid,1);
                }
                if($family&&!$previousFamily){
                    DocumentCatalog::query($this->db,'INSERT INTO member_family_people(club_id,contact_id,family_id) VALUES(?,?,?)',[$this->clubId,$contactId,$family['id']]);
                    DocumentCatalog::query($this->db,'UPDATE member_families SET version=version+1 WHERE club_id=? AND id=?',[$this->clubId,$family['id']]);
                    $this->audit($a,'family.assigned-from-application.'.$family['id'],$contactId,1);
                }
                $note.="\nBeitragszuordnung: ".($family?$family['name'].' · Beitragszahler: '.$family['payer_name'].' · '.ContributionRates::label($this->rates()['family']['amount_cents']).' jährlich für die gesamte Familie.':'Einzelmitglied · '.ContributionRates::label($this->rates()['single']['amount_cents']).' jährlich.');
                $this->db->prepare("INSERT IGNORE INTO document_links(club_id,document_id,source_type,source_id) SELECT club_id,document_id,'contact',? FROM document_links WHERE club_id=? AND source_type='application' AND source_id=?")->execute([$contactId,$this->clubId,$id]);
            }
            $updated=$this->db->prepare("UPDATE membership_applications SET status=?,version=version+1,reviewed_at=?,reviewed_by=?,review_note=?,contact_id=? WHERE club_id=? AND id=? AND version=? AND status='pending'");$updated->execute([$decision,gmdate('Y-m-d\TH:i:s\Z'),$a['id'],$note,$contactId,$this->clubId,$id,$version]);if($updated->rowCount()!==1)throw new DomainException('Antrag bereits bearbeitet oder inzwischen geändert.',409);$this->audit($a,'application.'.$decision,$id,$version+1);
            if($decision==='accepted')MembershipWelcome::enqueue($this->db,$a,$id,$membershipId);
            return $contactId;
        });
    }
}
