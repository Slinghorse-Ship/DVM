<?php declare(strict_types=1);
namespace Kulturhus\Verein;

use DomainException;
use PDO;
require_once dirname(__DIR__, 1).'/Integration/Website/WebsiteTechnicalAccess.php';
require_once dirname(__DIR__, 1).'/Infrastructure/Runtime/ProductionRuntime.php';

/** Current role policy and historical private draft records for the shared manager. */
final class WebsiteWorkspace
{
    public const HOME='/site/start';
    private const PRESS_OPERATIONS=['press/save','press/remove','press/prepare','press/send','press/status','press/draft-save','press/campaign-send'];

    public function __construct(private PDO $db,private string $actorId) {}

    /** Role-level menu matrix. Central account/editor overrides are shown separately. */
    public static function roleMenuMatrix(array $role):array
    {
        $result=[];foreach(\Kulturhus\Verein\EditorialPolicy::AREAS as $area=>$label)$result['Website: '.$label]=\Kulturhus\Verein\EditorialPolicy::level($role,$area);$result['Verein: Einstellungen und Rollen']=!empty($role['settings_access'])?'write':'none';$result['Website-Technik und zentrale Zugangsverwaltung']='technical';return $result;
    }

    /** Effective rights use the current actor and the configured authentication authority. */
    public static function effectiveLevel(PDO $db,array $actor,string $section):string
    {
        if(ProductionRuntime::enabled()&&!ProductionRuntime::nativeManager())return 'none';
        try{
            $current=Permissions::actor($db,(string)($actor['id']??''));
            if(($actor['club_id']??null)!==$current['club_id'])return 'none';
            return EditorialPolicy::level($current,$section);
        }catch(DomainException){return 'none';}
    }

    public function canRead():bool
    {
        try{$a=Permissions::actor($this->db,$this->actorId);foreach(\Kulturhus\Verein\EditorialPolicy::AREAS as $section=>$label)if(self::effectiveLevel($this->db,$a,$section)!=='none')return true;return $this->canManage();}
        catch(DomainException){return false;}
    }

    public function canManage():bool
    {
        return WebsiteTechnicalAccess::allowed($this->db,$this->actorId);
    }

    private static function fullWebsite(array $actor):bool{return $actor['role']==='editor'||(bool)$actor['settings_access']||(bool)$actor['is_superuser'];}

    public function canWrite(string $section):bool
    {
        try{
            $a=Permissions::actor($this->db,$this->actorId);
            if($section==='texts')$section='pages';if(!isset(self::catalog()[$section]))return false;
            if($section==='technical')return $this->canManage();
            return self::effectiveLevel($this->db,$a,$section)==='write';
        }catch(DomainException){return false;}
    }

    private function actor(bool $allowPress=false):array
    {
        $a=Permissions::actor($this->db,$this->actorId);
        if(!$this->canRead()||(!$allowPress&&self::effectiveLevel($this->db,$a,'texts')==='none'))throw new DomainException('Keine Berechtigung für den Website-Bereich.');
        return $a;
    }

    public function connectionStatus():array
    {
        if(!$this->canManage())throw new DomainException('Website-Technik ist ausschließlich für die technische Administration freigegeben.');
        $this->actor(true);
        return ['connected'=>ProductionRuntime::nativeManager(),'status'=>null,'message'=>'Die Website wird im nativen CMS verwaltet.'];
    }

    public function localRequest(string $action,array $input=[]):array
    {
        $this->actor();
        if(in_array($action,['page/save','news/save','news/create'],true)&&!$this->canWrite('pages'))throw new DomainException('Keine Schreibberechtigung für Seiten und Aktuelles.');
        if(!in_array($action,['pages/list','page/get','page/save','news/list','news/get','news/create','news/save'],true))throw new DomainException('Unbekannter Website-Vorgang.');
        throw new DomainException('Bitte die native Website-Verwaltung verwenden.');
    }

    public function editorialRead(string $section,int $id=0,string $query='',int $page=1,array $filters=[]):array
    {
        $this->actor(true);
        if(!isset($this->areas()[$section]))throw new DomainException('Keine Berechtigung für diesen Website-Bereich.');
        throw new DomainException('Bitte die native Redaktion verwenden.');
    }

    public function editorialWrite(string $operation,array $data,string $version,string $requestKey):array
    {
        if(str_starts_with($operation,'press/')&&!in_array($operation,self::PRESS_OPERATIONS,true))throw new DomainException('Unbekannte Presseaktion.');
        $a=$this->actor(true);
        $section=\Kulturhus\Verein\EditorialPolicy::operation($operation);if($section==='image'){if(!$this->canWrite('pages')&&!$this->canWrite('gallery')&&!$this->canWrite('events'))throw new DomainException('Keine Bildbearbeitung erlaubt.');}elseif(!$this->canWrite($section))throw new DomainException('Keine Schreibberechtigung für diesen Redaktionsvorgang.');
        if((str_starts_with($operation,'settings/')||str_starts_with($operation,'fixtures/'))&&!$this->canManage())throw new DomainException('Technische Einstellungen benötigen Administrationsrechte.');
        throw new DomainException('Bitte die native Redaktion verwenden.');
    }

    public static function publicUrl():string
    {
        return self::HOME;
    }

    public const PAGES=[
        'home'=>['Startseite','/'], 'club'=>['Verein','/verein/'],
        'membership'=>['Mitglied werden','/mitglied-werden/'], 'contact'=>['Kontakt','/kontakt/'],
        'imprint'=>['Impressum','/impressum/'], 'privacy'=>['Datenschutz','/datenschutz/'],
        'deletion'=>['Datenlöschung','/datenloeschung/'], 'terms'=>['Nutzungsbedingungen','/nutzungsbedingungen/'],
    ];

    public function drafts(string $kind,string $state='active'):array
    {
        $a=$this->actor();
        if(!in_array($kind,['page','news'],true)||!in_array($state,['active','archived'],true))throw new DomainException('Website-Filter prüfen.');
        $q=$this->db->prepare("SELECT * FROM website_drafts WHERE club_id=? AND kind=? AND status ".($state==='archived'?'=':'!=')." 'archived' ORDER BY updated_at DESC,id");
        $q->execute([$a['club_id'],$kind]);return $q->fetchAll();
    }

    public function draft(string $id):array
    {
        $a=$this->actor();$q=$this->db->prepare('SELECT * FROM website_drafts WHERE club_id=? AND id=?');$q->execute([$a['club_id'],$id]);
        return $q->fetch()?:throw new DomainException('Website-Entwurf nicht verfügbar.');
    }

    public function pageDraft(string $key):?array
    {
        $a=$this->actor();if(!isset(self::PAGES[$key]))throw new DomainException('Unbekannte Website-Seite.');
        $q=$this->db->prepare('SELECT * FROM website_drafts WHERE club_id=? AND page_key=?');$q->execute([$a['club_id'],$key]);return $q->fetch()?:null;
    }

    public function revisions(string $id):array
    {
        $draft=$this->draft($id);$q=$this->db->prepare('SELECT version,payload,happened_at FROM website_revisions WHERE club_id=? AND draft_id=? ORDER BY version DESC');$q->execute([$draft['club_id'],$id]);return $q->fetchAll();
    }

    public function save(?string $id,int $version,array $input):string
    {
        $this->db->beginTransaction();
        try{
            $a=$this->actor();if(!$this->canWrite('pages'))throw new DomainException('Keine Schreibberechtigung für Website-Entwürfe.');$old=$id!==null?$this->draft($id):null;
            if($version!==($old?(int)$old['version']:0))throw new DomainException('Der Entwurf wurde inzwischen geändert. Bitte neu laden.');
            $data=[];foreach(['kind'=>10,'page_key'=>30,'title'=>200,'summary'=>2000,'body'=>100000,'status'=>10] as $key=>$max){
                $value=$input[$key]??'';
                if(!is_string($value)||strlen($value)>$max||preg_match('//u',$value)!==1||preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/',$value))throw new DomainException('Website-Feld prüfen: '.$key);
                $data[$key]=trim($value);
            }
            if(!in_array($data['kind'],['page','news'],true)||!in_array($data['status'],['draft','review','archived'],true)||$data['title']==='')throw new DomainException('Art, Titel und Entwurfsstatus prüfen.');
            if($data['status']==='review'&&$data['body']==='')throw new DomainException('Zur Prüfung ist ein Inhalt erforderlich.');
            if($data['kind']==='page'&&!isset(self::PAGES[$data['page_key']]))throw new DomainException('Unbekannte Website-Seite.');
            if($data['kind']==='news'&&$data['page_key']!=='')throw new DomainException('Aktuelles-Beiträge erhalten keine feste Inhaltsseite.');
            if($old&&($old['kind']!==$data['kind']||($old['page_key']??'')!==$data['page_key']))throw new DomainException('Die Zuordnung eines Entwurfs bleibt erhalten.');
            if(!$old&&$data['kind']==='page'&&$this->pageDraft($data['page_key'])!==null)throw new DomainException('Für diese Seite existiert bereits ein Entwurf. Bitte neu laden.');
            $id??=bin2hex(random_bytes(16));$time=gmdate('Y-m-d\TH:i:s\Z');++$version;
            if($old)$this->db->prepare('UPDATE website_drafts SET title=?,summary=?,body=?,status=?,version=?,updated_at=? WHERE club_id=? AND id=?')->execute([$data['title'],$data['summary'],$data['body'],$data['status'],$version,$time,$a['club_id'],$id]);
            else $this->db->prepare('INSERT INTO website_drafts VALUES(?,?,?,?,?,?,?,?,?,?)')->execute([$id,$a['club_id'],$data['kind'],$data['page_key']?:null,$data['title'],$data['summary'],$data['body'],$data['status'],$version,$time]);
            $this->db->prepare('INSERT INTO website_revisions VALUES(?,?,?,?,?,?)')->execute([$a['club_id'],$id,$version,json_encode($data,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE),$a['id'],$time]);
            $this->db->prepare('INSERT INTO audit_events(club_id,actor_id,action,object_id,object_version,happened_at) VALUES(?,?,?,?,?,?)')->execute([$a['club_id'],$a['id'],'website.draft.saved',$id,$version,$time]);
            $this->db->commit();return $id;
        }catch(\Throwable $e){$this->db->rollBack();throw $e;}
    }

    public static function catalog():array
    {
        return [
            'navigation'=>['Menü','Namen, Reihenfolge und sichtbare Menüpunkte auf kulturhus.de bearbeiten.','list','#menue'],
            'pages'=>['Seiten','Texte für Startseite, Verein, Kontakt und weitere Inhaltsseiten bearbeiten.','home','/vereinsverwaltung/?view=website&tab=pages'],
            'news'=>['Aktuelles','Neuigkeiten schreiben, bearbeiten und auf der Website ansehen.','file','#aktuelles'],
            'gallery'=>['Fotogalerie','Alben anlegen, Bilder hinzufügen und Bildbeschreibungen pflegen.','folder','#fotogalerie'],
            'events'=>['Veranstaltungen','Programm prüfen, Presseentwürfe vorbereiten und Eventbrite abgleichen.','calendar','#programm'],
            'newsletter'=>['Newsletter','Newsletter gestalten, Entwürfe bearbeiten und den Versand verwalten.','mail','#newsletter-editor'],
            'recipients'=>['Empfänger & Kontakte','Anmeldungen, offene Bestätigungen und schriftliche Einwilligungen verwalten.','users','#newsletter-empfaenger'],
            'social'=>['Social Media','Facebook-Beiträge und Stories sowie Instagram-Feed und Stories verwalten.','chat','#facebook'],
            'press'=>['Presse','Pressemitteilungen aus Veranstaltungen oder frei schreiben, Empfänger und Versandverlauf verwalten.','file','#presseverteiler'],
            'technical'=>['Technik','Verbindungen und die Grundlage für die Website-Anbindung prüfen.','settings',''],
        ];
    }

    public function areas():array
    {
        $a=$this->actor(true);
        return array_filter(self::catalog(),fn($info,$key)=>$key==='technical'?$this->canManage():self::effectiveLevel($this->db,$a,$key)!=='none',ARRAY_FILTER_USE_BOTH);
    }

    public static function destination(string $key):string
    {
        $home=self::HOME;
        if($key==='home')return $home;
        if($key==='instagram')return $home.'#instagram';
        if($key==='qr')return $home.'#newsletter-qr';
        $item=self::catalog()[$key]??throw new DomainException('Unbekannter Website-Bereich.');
        return str_starts_with($item[3],'/vereinsverwaltung/')?$item[3]:'/site/'.trim($item[3],'/');
    }
}
