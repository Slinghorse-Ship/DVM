<?php declare(strict_types=1);
namespace Kulturhus\Verein;
use PDO;
use DomainException;
use Kulturhus\Verein\Infrastructure\Database\PreparedQueries;
require_once dirname(__DIR__).'/Infrastructure/Database/PreparedQueries.php';

/** Logical paths only. User folder names are never operating-system paths. */
final class DocumentCatalog
{
    public const TYPES=['entry'=>'Finanzbeleg / Geldspende','goods'=>'Sachspende','contact'=>'Mitglied / Kontakt','item'=>'Inventargegenstand','loan'=>'Ausleihe','account'=>'Bank / Kasse','appointment'=>'Termin','application'=>'Mitgliedsantrag'];
    public const SCOPES=['orga'=>'Organisation','finance'=>'Finanzen & Spenden','members'=>'Mitglieder','inventory'=>'Inventar & Ausleihe','settings'=>'Einstellungen'];
    public static function query(PDO $db,string $sql,array $args=[]): \PDOStatement {return PreparedQueries::run($db,$sql,$args);}
    public static function segment(string $value): string
    {
        $value=preg_replace('/[\\\\\/:*?"<>|\x00-\x1f]/u',' ',trim($value))??'';$value=trim(preg_replace('/\s+/u',' ',$value)??''," .");
        return implode('',array_slice(preg_split('//u',$value,-1,PREG_SPLIT_NO_EMPTY)?:[],0,90))?:'Ohne Bezeichnung';
    }
    public static function folder(PDO $db,string $club,array $parts,string $scope): string
    {
        $parent=null;$prefix=$scope;
        foreach($parts as $name){$prefix.='/'.$name;$key='auto:'.hash('sha256',$prefix);$row=self::query($db,'SELECT id FROM document_folders WHERE club_id=? AND system_key=?',[$club,$key])->fetch();
            if($row){$parent=$row['id'];continue;}$name=self::segment((string)$name);
            $same=self::query($db,'SELECT id FROM document_folders WHERE club_id=? AND parent_id <=> ? AND name=? AND scope=?',[$club,$parent,$name,$scope])->fetchColumn();
            if($same){$parent=$same;continue;}$id=bin2hex(random_bytes(16));self::query($db,'INSERT INTO document_folders(club_id,id,parent_id,name,scope,system_key,admission_only) VALUES(?,?,?,?,?,?,?)',[$club,$id,$parent,$name,$scope,$key,(int)($parent!==null&&DocumentProtection::folderRestricted($db,$club,$parent))]);$parent=$id;
        }return $parent??throw new DomainException('Ordnerpfad fehlt.');
    }
    public static function defaults(PDO $db,string $club): void
    {
        foreach(['orga'=>'Orga','finance'=>'Finanzen','members'=>'Mitglieder','inventory'=>'Inventar','settings'=>'Einstellungen'] as $scope=>$name)self::folder($db,$club,[$name],$scope);
        self::folder($db,$club,['Spenden'],'finance');
        foreach(['Bankkonten','Kassen','Steuern'] as $name)self::folder($db,$club,['Finanzen',$name],'finance');
        foreach(['Einnahmen','Ausgaben','Beiträge'] as $name)self::folder($db,$club,['Finanzen',date('Y'),$name],'finance');
        foreach(['Geldspenden','Sachspenden','Zuwendungsbescheinigungen'] as $name)self::folder($db,$club,['Spenden',date('Y'),$name],'finance');
        foreach(['Genehmigungen','Versicherungen','Erste Hilfe'] as $name)self::folder($db,$club,['Orga',$name],'orga');
        foreach(['Hygiene','Erste Hilfe'] as $name)self::folder($db,$club,['Mitglieder','Nachweise',$name],'members');
        self::folder($db,$club,['Kontakte'],'members');
        self::folder($db,$club,['Orga','Termine',date('Y')],'orga');
        foreach(['Gegenstände','Ausleihen'] as $name)self::folder($db,$club,['Inventar',$name],'inventory');
    }
    public static function raw(PDO $db,string $club,string $type,string $id): ?array
    {
        // Verified at invoice assignment time; retain the original event identity even if the website later changes.
        if($type==='appointment'&&str_starts_with($id,'website:')){
            if(!Database::tableExists($db,'finance_event_assignments'))return null;
            $r=self::query($db,'SELECT club_id,event_id AS id,event_title AS title,starts_at,location FROM finance_event_assignments WHERE club_id=? AND event_id=? ORDER BY created_at LIMIT 1',[$club,$id])->fetch();return $r?:null;
        }
        $table=match($type){'entry'=>'finance_entries','goods'=>'goods_donations','contact'=>'contacts','item'=>'inventory_items','loan'=>'inventory_loans','account'=>'finance_accounts','appointment'=>'appointments','application'=>'membership_applications',default=>throw new DomainException('Unbekannte Dokumentzuordnung.')};
        $row=self::query($db,"SELECT * FROM $table WHERE club_id=? AND id=?",[$club,$id])->fetch()?:null;return $type==='item'&&!empty($row['deleted_at'])?null:$row;
    }
    public static function register(PDO $db,string $club,string $type,string $id): ?array
    {
        $r=self::raw($db,$club,$type,$id);if(!$r||($type==='contact'&&!empty($r['archived_at']))){self::query($db,'UPDATE document_sources SET available=0 WHERE club_id=? AND source_type=? AND source_id=?',[$club,$type,$id]);return null;}
        $old=self::query($db,'SELECT * FROM document_sources WHERE club_id=? AND source_type=? AND source_id=?',[$club,$type,$id])->fetch();$today=date('Y-m-d');$short=str_starts_with($id,'website:')?'web-'.substr($id,8):substr($id,0,8);
        switch($type){
            case 'entry':$date=$r['document_date'];$number=$r['display_number']?:sprintf('KH-%05d',$r['number']);$label=$number.' · '.$r['description'];$scope='finance';$parts=$r['kind']==='donation'?['Spenden',substr($date,0,4),'Geldspenden',$number]:['Finanzen',substr($date,0,4),['expense'=>'Ausgaben','income'=>'Einnahmen','contribution'=>'Beiträge'][$r['kind']],$number];break;
            case 'goods':$date=$r['received_on'];$label=($r['designation']?:$r['description']).' · '.$short;$scope='finance';$parts=['Spenden',substr($date,0,4),'Sachspenden',self::segment($label)];break;
            case 'contact':$date=$old['document_date']??$today;$label=$r['name'];$scope='members';$hasMembership=(bool)self::query($db,'SELECT 1 FROM memberships WHERE club_id=? AND contact_id=? LIMIT 1',[$club,$id])->fetchColumn();$parts=[$hasMembership?'Mitglieder':'Kontakte',self::segment($label).' · '.$short];break;
            case 'item':$date=$r['acquired_on']?:($old['document_date']??$today);$label=$r['code'].' · '.$r['name'];$scope='inventory';$parts=['Inventar','Gegenstände',self::segment($r['code'])];break;
            case 'loan':$date=$r['starts_on'];$label=Inventory::number((int)$r['number']).' · '.$r['purpose'];$scope='inventory';$parts=['Inventar','Ausleihen',substr($date,0,4),Inventory::number((int)$r['number'])];break;
            case 'account':$date=$r['opened_on'];$label=$r['name'];$scope='finance';$parts=['Finanzen',$r['kind']==='cash'?'Kassen':'Bankkonten',self::segment($label).' · '.$short];break;
            case 'appointment':$date=substr($r['starts_at'],0,10);$label=$r['title'];$scope='orga';$parts=['Orga','Termine',substr($date,0,4),self::segment($label).' · '.$short];break;
            case 'application':$date=substr($r['submitted_at'],0,10);$label='Mitgliedsantrag · '.$short;$scope='members';$parts=['Mitglieder','Anträge',substr($date,0,4),$short];break;
        }
        $folder=$old['folder_id']??self::folder($db,$club,$parts,$scope);
        if($type==='contact'&&$old){$parent=self::folder($db,$club,[$parts[0]],$scope);self::query($db,'UPDATE document_folders SET parent_id=?,name=? WHERE club_id=? AND id=?',[$parent,self::segment($parts[1]),$club,$folder]);}
        if($type==='application'&&in_array('admission_only',Database::columns($db,'document_folders'),true)){
            $cursor=$folder;
            while($cursor){$f=self::query($db,'SELECT id,parent_id FROM document_folders WHERE club_id=? AND id=?',[$club,$cursor])->fetch();if(!$f||!$f['parent_id'])break;DocumentProtection::protectFolder($db,$club,$cursor);$cursor=$f['parent_id'];}
        }
        self::query($db,'INSERT INTO document_sources(club_id,source_type,source_id,label,document_date,scope,folder_id,available) VALUES(?,?,?,?,?,?,?,1) ON DUPLICATE KEY UPDATE label=VALUES(label),document_date=VALUES(document_date),available=1',[$club,$type,$id,$label,$date,$scope,$folder]);
        return ['source_type'=>$type,'source_id'=>$id,'label'=>$label,'document_date'=>$date,'scope'=>$scope,'folder_id'=>$folder,'available'=>1];
    }
    public static function backfill(PDO $db): void
    {
        foreach($db->query('SELECT id FROM clubs')->fetchAll() as $club){$c=$club['id'];self::defaults($db,$c);
            foreach(['entry'=>'finance_entries','goods'=>'goods_donations','contact'=>'contacts','item'=>'inventory_items','loan'=>'inventory_loans','account'=>'finance_accounts','appointment'=>'appointments','application'=>'membership_applications'] as $type=>$table)
                foreach(self::query($db,"SELECT id FROM $table WHERE club_id=?",[$c])->fetchAll() as $r)self::register($db,$c,$type,$r['id']);
        }
        foreach($db->query('SELECT * FROM inventory_assets')->fetchAll() as $r){
            if(self::query($db,'SELECT 1 FROM documents WHERE club_id=? AND id=?',[$r['club_id'],$r['id']])->fetchColumn())continue;
            $source=self::register($db,$r['club_id'],'item',$r['item_id']);if(!$source)throw new DomainException('Inventardatei ohne Gegenstand.');
            self::query($db,"INSERT INTO documents(club_id,id,folder_id,title,kind,document_date,scopes,origin,created_at) VALUES(?,?,?,?,?,?,?,'migrated',?)",[$r['club_id'],$r['id'],$source['folder_id'],$r['name'],str_starts_with($r['mime'],'image/')?'photo':'inventory',substr($r['created_at'],0,10),'["inventory"]',$r['created_at']]);
            $q=$db->prepare('INSERT INTO document_files(club_id,document_id,revision,name,mime,bytes,sha256,created_at,snapshot) VALUES(?,?,1,?,?,?,?,?,\'{}\')');$q->bindValue(1,$r['club_id']);$q->bindValue(2,$r['id']);$q->bindValue(3,$r['name']);$q->bindValue(4,$r['mime']);$q->bindValue(5,$r['bytes'],PDO::PARAM_LOB);$q->bindValue(6,hash('sha256',$r['bytes']));$q->bindValue(7,$r['created_at']);$q->execute();
            self::query($db,'INSERT INTO document_links VALUES(?,?,?,?)',[$r['club_id'],$r['id'],'item',$r['item_id']]);
            self::query($db,"INSERT INTO document_history(club_id,document_id,action,version,happened_at,details) VALUES(?,?,'migrated',1,?,?)",[$r['club_id'],$r['id'],gmdate('Y-m-d\TH:i:s\Z'),json_encode(['from'=>'inventory_assets'],JSON_THROW_ON_ERROR)]);
        }
    }
    public static function route(string $type,string $id): array
    {
        return match($type){'entry'=>['view'=>'finance','tab'=>'receipts','entry'=>$id],'goods'=>['view'=>'donations','tab'=>'goods','goods'=>$id],'contact'=>['view'=>'members','id'=>$id],'item'=>['view'=>'inventory','tab'=>'items','item'=>$id],'loan'=>['view'=>'inventory','tab'=>'loans','loan'=>$id],'account'=>['view'=>'finance','tab'=>'bank','account'=>$id],'appointment'=>['view'=>'calendar','event'=>$id],'application'=>['view'=>'portal','tab'=>'application','application'=>$id],default=>['view'=>'documents']};
    }
}
