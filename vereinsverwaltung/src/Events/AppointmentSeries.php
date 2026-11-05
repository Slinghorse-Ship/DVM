<?php declare(strict_types=1);
namespace Kulturhus\Verein;

use DomainException;
use PDO;
use Kulturhus\Verein\Events\Calendar\Recurrence;

/** Finite canonical appointments, protected exceptions and durable human intentions. */
trait AppointmentSeries
{
    public function appointmentSeriesReady():bool
    {
        foreach(['appointment_series','appointment_series_occurrences','appointment_series_intents']as$table)if(!Database::tableExists($this->db,$table))return false;
        return true;
    }
    private function seriesWriter():array
    {
        if($this->db->inTransaction())DocumentCatalog::query($this->db,'SELECT c.id FROM clubs c JOIN actors a ON a.club_id=c.id WHERE a.id=? FOR UPDATE',[$this->actorId])->fetchColumn();
        if(!$this->appointmentSeriesReady())throw new DomainException('Serientermine benötigen das geprüfte Paketupgrade.');
        return $this->calendarWriter();
    }
    private static function seriesTemplate(array $data):array
    {
        $out=[];foreach(['title'=>'','start'=>'','end'=>'','location'=>'','description'=>'','category'=>'verein','activity_state'=>'planned','activity_kind'=>'event','report_summary'=>'','source_reference'=>'']as$key=>$default){if(isset($data[$key])&&!is_string($data[$key]))throw new DomainException('Serienvorlage prüfen.');$out[$key]=trim($data[$key]??$default);}
        if($out['activity_state']!=='planned'||$out['source_reference']!=='')throw new DomainException('Serien planen eigene Termine ohne gemeinsame Facebook-Originalkennung. Durchführung und Absagen je Einzeltermin erfassen.');
        if($out['title']===''||strlen($out['title'])>200||strlen($out['location'])>300||strlen($out['description'])>10000||strlen($out['report_summary'])>5000||!in_array($out['category'],['verein','vorstand','kultur'],true)||!in_array($out['activity_kind'],['event','external','assembly','board','other'],true))throw new DomainException('Titel, Art und Angaben der Serienvorlage prüfen.');
        return $out;
    }
    private function seriesExpansion(array $data,array $attendees,array $rule,?string $from=null):array
    {
        $data=self::seriesTemplate($data);$pattern=Recurrence::expand($data,$rule);$work=0;
        foreach($pattern['slots']as$slot)if(!$slot['excluded']&&($from===null||substr($slot['start'],0,10)>=$from)){
            if(self::localTime($slot['end'])<=self::localTime($slot['start']))throw new DomainException('Seriendauer und Zeitumstellung prüfen.');++$work;
        }
        if(!array_is_list($attendees)||count($attendees)>10000||$work*count($attendees)>10000)throw new DomainException('Höchstens 366 Termine und 10.000 Teilnehmerzuordnungen pro Serie; Zeitraum eingrenzen.');
        $actor=$this->calendarWriter();foreach($attendees as$id)if(!is_string($id)||$id===''||strlen($id)>128)throw new DomainException('Serienteilnehmer prüfen.');$ids=array_values(array_unique($attendees));
        if($ids&&(int)DocumentCatalog::query($this->db,'SELECT COUNT(*) FROM contacts WHERE club_id=? AND archived_at IS NULL AND id IN ('.implode(',',array_fill(0,count($ids),'?')).')',[$actor['club_id'],...$ids])->fetchColumn()!==count($ids))throw new DomainException('Serienteilnehmer nicht verfügbar.');
        if(in_array($data['activity_kind'],['assembly','board'],true)){BoardAccess::require($this->db,$this->actorId);if(array_diff($ids,array_column(CalendarInvitations::meetingEligibleContacts($this->db,$actor['club_id'],$data['activity_kind']),'id')))throw new DomainException('Aktuelle Mitglieder beziehungsweise bestätigten Vorstand als Serienteilnehmer auswählen.');}
        return ['template'=>$data]+$pattern;
    }
    public function previewAppointmentSeries(array $data,array $attendees,array $rule,?string $from=null):array
    {
        $this->seriesWriter();return $this->seriesExpansion($data,$attendees,$rule,$from);
    }
    private function seriesRecord(array $actor,string $id):array
    {
        $row=DocumentCatalog::query($this->db,'SELECT * FROM appointment_series WHERE club_id=? AND id=?'.($this->db->inTransaction()?' FOR UPDATE':''),[$actor['club_id'],$id])->fetch(PDO::FETCH_ASSOC);
        if(!$row)throw new DomainException('Serie nicht verfügbar.');
        foreach(['template','rule','attendees']as$key)$row[$key]=json_decode($row[$key.'_json'],true,64,JSON_THROW_ON_ERROR);
        return $row;
    }
    private function seriesRows(array $actor,string $id):array
    {
        $rows=DocumentCatalog::query($this->db,"SELECT o.*,e.version actual_version,e.title actual_title,e.starts_at actual_start,e.activity_state actual_state,
            (SELECT COUNT(*) FROM appointment_attendees t WHERE t.club_id=o.club_id AND t.appointment_id=o.appointment_id AND t.response<>'offen') answered
            FROM appointment_series_occurrences o LEFT JOIN appointments e ON e.club_id=o.club_id AND e.id=o.appointment_id WHERE o.club_id=? AND o.series_id=? ORDER BY o.ordinal LIMIT 367".($this->db->inTransaction()?' FOR UPDATE':''),[$actor['club_id'],$id])->fetchAll(PDO::FETCH_ASSOC);
        if(count($rows)>366)throw new DomainException('Serienumfang prüfen.');
        if($this->db->inTransaction())foreach($rows as&$row)if($row['actual_version']!==null){
            $responses=DocumentCatalog::query($this->db,'SELECT response FROM appointment_attendees WHERE club_id=? AND appointment_id=? FOR UPDATE',[$actor['club_id'],$row['appointment_id']])->fetchAll(PDO::FETCH_COLUMN);
            $row['answered']=count(array_filter($responses,static fn(string$response):bool=>$response!=='offen'));
        }unset($row);
        return $rows;
    }
    private static function seriesProtection(array $row):string
    {
        if($row['actual_version']===null)return 'Einzeltermin entfernt';
        if($row['is_exception'])return 'Bewusst geschützte Ausnahme';
        if((int)$row['managed_version']!==(int)$row['actual_version'])return 'Einzeln bearbeitet';
        if($row['answered'])return 'Rückmeldungen vorhanden';
        if($row['actual_start']<gmdate('Y-m-d\TH:i:s\Z'))return 'Vergangener Termin';
        return '';
    }
    public function appointmentSeries(string $id,int $page=1,int $historyPage=1):array
    {
        $actor=$this->seriesWriter();$series=$this->seriesRecord($actor,$id);$rows=$this->seriesRows($actor,$id);foreach($rows as&$row)$row['protection']=self::seriesProtection($row);unset($row);
        $window=Pagination::window(count($rows),max(1,$page),6);$series['rows']=array_slice($rows,$window['offset'],6);$series['total']=count($rows);$series['page']=$window['page'];$series['pages']=$window['pages'];
        $historyTotal=(int)DocumentCatalog::query($this->db,'SELECT COUNT(*) FROM appointment_series_intents WHERE club_id=? AND series_id=?',[$actor['club_id'],$id])->fetchColumn();$historyWindow=Pagination::window($historyTotal,max(1,$historyPage),6);
        $series['history']=DocumentCatalog::query($this->db,'SELECT request_key,operation,created_at,outcome_json FROM appointment_series_intents WHERE club_id=? AND series_id=? ORDER BY sequence DESC LIMIT 6 OFFSET '.$historyWindow['offset'],[$actor['club_id'],$id])->fetchAll(PDO::FETCH_ASSOC);$series['history_total']=$historyTotal;$series['history_page']=$historyWindow['page'];$series['history_pages']=$historyWindow['pages'];
        return $series;
    }
    public function appointmentSeriesFor(string $appointment):?string
    {
        if(!$this->canWriteCalendar()||!$this->appointmentSeriesReady())return null;$actor=$this->calendarWriter();
        $id=DocumentCatalog::query($this->db,'SELECT series_id FROM appointment_series_occurrences WHERE club_id=? AND appointment_id=?',[$actor['club_id'],$appointment])->fetchColumn();return$id===false?null:$id;
    }
    public function appointmentSeriesOriginal(string $id,string $key):string
    {
        $actor=$this->seriesWriter();$this->seriesRecord($actor,$id);
        if(!preg_match('/\A[a-f0-9]{32}\z/D',$key))throw new DomainException('Serienoriginal nicht verfügbar.');
        $original=DocumentCatalog::query($this->db,'SELECT actor_id,request_hash,operation,payload_json,outcome_json,created_at FROM appointment_series_intents WHERE club_id=? AND series_id=? AND request_key=?',[$actor['club_id'],$id,$key])->fetch(PDO::FETCH_ASSOC);
        if(!$original)throw new DomainException('Serienoriginal nicht verfügbar.');return json_encode($original,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT);
    }
    private function seriesSnapshot(array $actor,string $id):array
    {
        return ['appointment'=>DocumentCatalog::query($this->db,'SELECT * FROM appointments WHERE club_id=? AND id=?',[$actor['club_id'],$id])->fetch(PDO::FETCH_ASSOC),
            'attendees'=>DocumentCatalog::query($this->db,'SELECT * FROM appointment_attendees WHERE club_id=? AND appointment_id=? ORDER BY contact_id',[$actor['club_id'],$id])->fetchAll(PDO::FETCH_ASSOC)];
    }
    private function seriesRequest(array $actor,string $key,array $request):array
    {
        if(!preg_match('/\A[a-f0-9]{32}\z/D',$key))throw new DomainException('Serienvorgang neu öffnen.');$json=json_encode($request,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);$hash=hash('sha256',$actor['id'].'|'.$json);
        $old=DocumentCatalog::query($this->db,'SELECT request_hash,outcome_json FROM appointment_series_intents WHERE club_id=? AND request_key=? FOR UPDATE',[$actor['club_id'],$key])->fetch(PDO::FETCH_ASSOC);
        if($old&&!hash_equals($old['request_hash'],$hash))throw new DomainException('Diese Serienanfrage wurde bereits mit anderen Angaben angenommen. Den gespeicherten Stand prüfen.');
        return ['hash'=>$hash,'request'=>$request,'replay'=>$old?json_decode($old['outcome_json'],true,64,JSON_THROW_ON_ERROR):null];
    }
    private function saveSeriesIntent(array $actor,string $key,array $request,array $outcome,array $before=[]):void
    {
        $payload=json_encode(['request'=>$request['request'],'before'=>$before],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);if(strlen($payload)>12582912)throw new DomainException('Serienänderung ist zu groß; Zeitraum eingrenzen.');
        DocumentCatalog::query($this->db,'INSERT INTO appointment_series_intents(club_id,request_key,actor_id,series_id,request_hash,operation,payload_json,outcome_json,created_at) VALUES(?,?,?,?,?,?,?,?,?)',[$actor['club_id'],$key,$actor['id'],$outcome['id'],$request['hash'],$request['request']['operation'],$payload,json_encode($outcome,JSON_THROW_ON_ERROR),gmdate('Y-m-d\TH:i:s\Z')]);
        $this->audit($actor,'appointment-series.'.$request['request']['operation'],$outcome['id'],$outcome['version']);
    }
    public function createAppointmentSeries(array $data,array $attendees,array $rule,string $key):string
    {
        return $this->transaction(function()use($data,$attendees,$rule,$key):string{
            $actor=$this->seriesWriter();$request=$this->seriesRequest($actor,$key,['operation'=>'create','data'=>$data,'attendees'=>$attendees,'rule'=>$rule]);if($request['replay'])return$request['replay']['id'];
            $pattern=$this->seriesExpansion($data,$attendees,$rule);$id=bin2hex(random_bytes(16));$template=$pattern['template'];
            DocumentCatalog::query($this->db,'INSERT INTO appointment_series VALUES(?,?,?,?,?,?,?,?)',[$actor['club_id'],$id,json_encode($template,JSON_THROW_ON_ERROR),json_encode($pattern['rule'],JSON_THROW_ON_ERROR),json_encode($attendees,JSON_THROW_ON_ERROR),1,$actor['id'],gmdate('Y-m-d\TH:i:s\Z')]);$created=[];
            foreach($pattern['slots']as$ordinal=>$slot)if(!$slot['excluded']){
                if(self::localTime($slot['start'])<gmdate('Y-m-d\TH:i:s\Z'))throw new DomainException('Eine neue Serie mit einem künftigen Termin beginnen.');
                $event=$this->saveAppointment(null,0,array_replace($template,['start'=>$slot['start'],'end'=>$slot['end']]),$attendees);$snapshot=$this->seriesSnapshot($actor,$event);
                DocumentCatalog::query($this->db,"INSERT INTO appointment_series_occurrences VALUES(?,?,?,?,?,'active',0,?)",[$actor['club_id'],$id,$ordinal,$event,1,json_encode($snapshot,JSON_THROW_ON_ERROR)]);$created[$event]=1;
            }
            $this->saveSeriesIntent($actor,$key,$request,['id'=>$id,'version'=>1,'updated'=>count($created),'preserved'=>0,'events'=>$created]);return$id;
        });
    }
    public function changeAppointmentSeries(string $id,int $version,string $from,array $data,array $attendees,array $rule,string $key,bool $cancel=false,string $reason='',bool $revive=false):array
    {
        return $this->transaction(function()use($id,$version,$from,$data,$attendees,$rule,$key,$cancel,$reason,$revive):array{
            $actor=$this->seriesWriter();$input=$cancel?['reason'=>$reason]:['data'=>$data,'attendees'=>$attendees,'rule'=>$rule,'revive'=>$revive];
            $request=$this->seriesRequest($actor,$key,['operation'=>$cancel?'cancel':'update','id'=>$id,'version'=>$version,'from'=>$from]+$input);if($request['replay'])return$request['replay'];
            self::date($from);if($from<date('Y-m-d'))throw new DomainException('Serienänderungen frühestens ab heute planen.');if($cancel&&(trim($reason)===''||strlen($reason)>1000))throw new DomainException('Begründung für die Serienabsage angeben.');
            $series=$this->seriesRecord($actor,$id);if((int)$series['version']!==$version)throw new DomainException('Die Serie wurde inzwischen geändert. Aktuellen Stand und eigene Eingaben vergleichen.');
            if($cancel){$data=$series['template'];$attendees=$series['attendees'];$rule=$series['rule'];}
            $pattern=$this->seriesExpansion($data,$attendees,$rule,$from);$template=$pattern['template'];
            if(substr($template['start'],0,10)!==substr($series['template']['start'],0,10)||$pattern['rule']['frequency']!==$series['rule']['frequency']||$pattern['rule']['every']!==$series['rule']['every'])throw new DomainException('Für ein anderes Ankerdatum oder einen anderen Takt eine neue Serie anlegen und die bisherigen künftigen Standardtermine bewusst absagen.');
            $rows=[];foreach($this->seriesRows($actor,$id)as$row)$rows[(int)$row['ordinal']]=$row;$ordinals=array_unique([...array_keys($rows),...($cancel?[]:array_keys($pattern['slots']))]);sort($ordinals);
            $before=['series'=>$series,'events'=>[]];$changed=[];$preserved=0;
            foreach($ordinals as$ordinal){$row=$rows[$ordinal]??null;$slot=$pattern['slots'][$ordinal]??null;
                if($row&&self::seriesProtection($row)!==''){++$preserved;continue;}
                if($row&&$row['managed_state']==='retired'&&!$revive){++$preserved;continue;}
                if($row){$current=$this->appointment($row['appointment_id']);if(substr((new \DateTimeImmutable($current['starts_at']))->setTimezone(new \DateTimeZone('Europe/Berlin'))->format('Y-m-d'),0,10)<$from){++$preserved;continue;}}
                if(!$row&&(!$slot||$slot['excluded']||$cancel||substr($slot['start'],0,10)<$from))continue;
                $retire=$cancel||!$slot||$slot['excluded'];
                if($retire){if(!$row||$row['managed_state']==='retired')continue;$desired=[];foreach(array_keys($template)as$field)$desired[$field]=$current[$field]??$template[$field];$desired['start']=(new \DateTimeImmutable($current['starts_at']))->setTimezone(new \DateTimeZone('Europe/Berlin'))->format('Y-m-d\TH:i');$desired['end']=(new \DateTimeImmutable($current['ends_at']))->setTimezone(new \DateTimeZone('Europe/Berlin'))->format('Y-m-d\TH:i');$desired['activity_state']='cancelled';$people=array_column($current['attendees'],'contact_id');}
                else{$desired=array_replace($template,['start'=>$slot['start'],'end'=>$slot['end']]);$people=$attendees;if(self::localTime($slot['start'])<gmdate('Y-m-d\TH:i:s\Z'))throw new DomainException('Eine neue Uhrzeit würde einen künftigen Termin in die Vergangenheit verschieben. Beginn prüfen.');}
                if($row){$same=true;foreach($desired as$field=>$value){$oldValue=match($field){'start'=>(new \DateTimeImmutable($current['starts_at']))->setTimezone(new \DateTimeZone('Europe/Berlin'))->format('Y-m-d\TH:i'),'end'=>(new \DateTimeImmutable($current['ends_at']))->setTimezone(new \DateTimeZone('Europe/Berlin'))->format('Y-m-d\TH:i'),default=>$current[$field]??''};if($value!==$oldValue)$same=false;}$previousPeople=array_column($current['attendees'],'contact_id');$nextPeople=array_values(array_unique($people));sort($previousPeople);sort($nextPeople);if($same&&$previousPeople===$nextPeople){++$preserved;continue;}}
                if($row){$before['events'][$current['id']]=$this->seriesSnapshot($actor,$current['id']);$event=$this->saveAppointment($current['id'],(int)$current['version'],$desired,$people);$next=(int)$current['version']+1;
                    DocumentCatalog::query($this->db,'UPDATE appointment_series_occurrences SET managed_version=?,managed_state=? WHERE club_id=? AND series_id=? AND ordinal=?',[$next,$retire?'retired':'active',$actor['club_id'],$id,$ordinal]);
                }else{$event=$this->saveAppointment(null,0,$desired,$people);$next=1;DocumentCatalog::query($this->db,"INSERT INTO appointment_series_occurrences VALUES(?,?,?,?,?,'active',0,?)",[$actor['club_id'],$id,$ordinal,$event,1,json_encode($this->seriesSnapshot($actor,$event),JSON_THROW_ON_ERROR)]);}
                $changed[$event]=$next;
            }
            DocumentCatalog::query($this->db,'UPDATE appointment_series SET template_json=?,rule_json=?,attendees_json=?,version=version+1 WHERE club_id=? AND id=? AND version=?',[json_encode($template,JSON_THROW_ON_ERROR),json_encode($pattern['rule'],JSON_THROW_ON_ERROR),json_encode($attendees,JSON_THROW_ON_ERROR),$actor['club_id'],$id,$version]);
            $outcome=['id'=>$id,'version'=>$version+1,'updated'=>count($changed),'preserved'=>$preserved,'events'=>$changed];$this->saveSeriesIntent($actor,$key,$request,$outcome,$before);return$outcome;
        });
    }
    public function protectAppointmentSeriesOccurrence(string $id,int $version,int $ordinal,bool $protect,string $key):array
    {
        return $this->transaction(function()use($id,$version,$ordinal,$protect,$key):array{
            $actor=$this->seriesWriter();$request=$this->seriesRequest($actor,$key,['operation'=>$protect?'protect':'include','id'=>$id,'version'=>$version,'ordinal'=>$ordinal]);if($request['replay'])return$request['replay'];
            $series=$this->seriesRecord($actor,$id);if((int)$series['version']!==$version)throw new DomainException('Serie inzwischen geändert. Aktuelle Ausnahme erneut prüfen.');$found=null;foreach($this->seriesRows($actor,$id)as$row)if((int)$row['ordinal']===$ordinal)$found=$row;
            if(!$found||$found['actual_version']===null)throw new DomainException('Einzeltermin nicht mehr vorhanden. Kein ursprünglicher Termin wird automatisch neu erzeugt.');
            DocumentCatalog::query($this->db,'UPDATE appointment_series_occurrences SET is_exception=?,managed_version=?,managed_state=? WHERE club_id=? AND series_id=? AND ordinal=?',[(int)$protect,(int)$found['actual_version'],$protect?$found['managed_state']:($found['actual_state']==='cancelled'?'retired':'active'),$actor['club_id'],$id,$ordinal]);
            DocumentCatalog::query($this->db,'UPDATE appointment_series SET version=version+1 WHERE club_id=? AND id=?',[$actor['club_id'],$id]);$outcome=['id'=>$id,'version'=>$version+1,'updated'=>0,'preserved'=>1];$this->saveSeriesIntent($actor,$key,$request,$outcome,['series'=>$series,'occurrence'=>$found]);return$outcome;
        });
    }
}
