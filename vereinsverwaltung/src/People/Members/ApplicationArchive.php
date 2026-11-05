<?php declare(strict_types=1);
namespace Kulturhus\Verein;
use PDO;

/** Called only inside the public submission transaction. No staff impersonation. */
final class ApplicationArchive
{
    public static function create(PDO $db,string $club,string $id):void
    {
        $r=DocumentCatalog::raw($db,$club,'application',$id);if(!$r)throw new \DomainException('Antrag fehlt.');
        $a=json_decode($r['answers'],true,512,JSON_THROW_ON_ERROR);$consent=json_decode($r['consent_snapshot'],true,512,JSON_THROW_ON_ERROR);
        $rows=[];foreach(MembershipApplications::FIELDS as $key=>$label)if(isset($a[$key])&&$a[$key]!=='')$rows[]=[$label,$a[$key]];
        $rows[]=['Mitgliedschaft',ContributionRates::applicationLabel($a)];
        if(!empty($a['family_reference']))$rows[]=['Bestehende Familie / Beitragszahler zur Prüfung',$a['family_reference']];
        if(!empty($a['family_members']))$rows[]=['Weitere Familienangehörige zur Prüfung',$a['family_members']];
        $rows[]=['Zahlungsart','Rechnung'];$rows[]=['Eingangsweg',($a['source']??'online')==='manual'?'Manuell erfasst; Originalunterlagen separat beifügen.':'Digitaler Antrag über das Online-Formular'];
        $snapshot=['record'=>['compact'=>true,'title'=>'Mitgliedsantrag','club'=>'Kulturhus Jaderberg e. V.','reference'=>'Antrag '.$id,'created_at'=>$r['submitted_at'],'warning'=>'Eingangsnachweis: Die Aufnahme erfolgt erst durch Beschluss des Vorstandes. Die eingegangenen Angaben und die bestätigte Hinweisfassung sind nachfolgend dokumentiert.','sections'=>[
            ['title'=>'Antragsangaben','columns'=>['Angabe','Inhalt'],'rows'=>$rows],
            ['title'=>'Erklärung und Hinweise','columns'=>['Angabe','Inhalt'],'rows'=>[['Hinweise · Fassung '.$r['configuration_version'],$consent['title']."\n".$consent['text']],['Zustimmung',$consent['accepted']?'Erteilt am '.$consent['at']:'Nicht erteilt'],['Jahresbeitrag',ContributionRates::applicationLabel($a).'. Laut Satzung § 5 ist bei Eintritt im laufenden Jahr der volle Jahresbeitrag fällig.'],['Aufnahme','Schriftlicher Antrag und Beschluss des Vorstandes gemäß Satzung § 4. Weitere Erwachsene erklären ihren Beitritt selbst; bei Minderjährigen ist die gesetzliche Vertretung zu prüfen.']]]
        ]]];
        $bytes=DocumentPreview::pdf($snapshot);$source=DocumentCatalog::register($db,$club,'application',$id);$doc=bin2hex(random_bytes(16));$now=$r['submitted_at'];
        DocumentCatalog::query($db,"INSERT INTO documents(club_id,id,folder_id,title,kind,document_date,scopes,origin,created_at,created_by,admission_only) VALUES(?,?,?,?,'membership',?,'[\"members\"]','generated',?,NULL,1)",[$club,$doc,$source['folder_id'],'Mitgliedsantrag · '.$a['first_name'].' '.$a['last_name'],substr($now,0,10),$now]);
        $q=$db->prepare('INSERT INTO document_files(club_id,document_id,revision,name,mime,bytes,sha256,created_at,created_by,snapshot) VALUES(?,?,1,?,?,?,?,?,NULL,?)');
        $q->bindValue(1,$club);$q->bindValue(2,$doc);$q->bindValue(3,'mitgliedsantrag-'.substr($id,0,8).'.pdf');$q->bindValue(4,'application/pdf');$q->bindValue(5,$bytes,PDO::PARAM_LOB);$q->bindValue(6,hash('sha256',$bytes));$q->bindValue(7,$now);$q->bindValue(8,json_encode($snapshot,JSON_THROW_ON_ERROR));$q->execute();
        DocumentCatalog::query($db,"INSERT INTO document_links VALUES(?,?,'application',?)",[$club,$doc,$id]);
        DocumentCatalog::query($db,"INSERT INTO document_history(club_id,document_id,actor_id,action,version,happened_at,details) VALUES(?,?,NULL,'application.submitted',1,?,?)",[$club,$doc,$now,json_encode(['application'=>$id,'sha256'=>hash('sha256',$bytes)],JSON_THROW_ON_ERROR)]);
    }
}
