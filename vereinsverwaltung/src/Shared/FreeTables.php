<?php declare(strict_types=1);
namespace Kulturhus\Verein;
use PDO;use DomainException;

final class FreeTables
{
    public const TYPES=['text'=>'Text','number'=>'Zahl','date'=>'Datum','boolean'=>'Ja/Nein','unit'=>'Einheit','formula'=>'Formel','link'=>'Zellverknüpfung'];
    public const DEFAULT_COLUMNS=[['name'=>'Aufgabe','type'=>'text'],['name'=>'Wer','type'=>'text'],['name'=>'Bis wann','type'=>'date'],['name'=>'Erledigt','type'=>'boolean'],['name'=>'Menge','type'=>'number'],['name'=>'Einheit','type'=>'unit']];
    private bool $writing=false;
    public function __construct(private PDO $db,private string $actorId){}
    private function actor():array{return Permissions::actor($this->db,$this->actorId);}
    private function query(string $sql,array $args=[]):\PDOStatement{$q=$this->db->prepare($sql);$q->execute($args);return $q;}
    private function transaction(callable $fn):mixed{$this->db->beginTransaction();$this->writing=true;try{$this->query('SELECT id FROM actors WHERE id=? FOR UPDATE',[$this->actorId]);$v=$fn();$this->db->commit();return $v;}catch(\Throwable $e){$this->db->rollBack();throw $e;}finally{$this->writing=false;}}
    private function audit(array $a,string $action,string $id,int $version):void{$this->query('INSERT INTO audit_events(club_id,actor_id,action,object_id,object_version,happened_at) VALUES(?,?,?,?,?,?)',[$a['club_id'],$a['id'],$action,$id,$version,gmdate('c')]);}
    public function listing(bool $archived=false):array
    {
        $a=$this->actor();return $this->query('SELECT t.*,CASE WHEN t.owner_id=? THEN ? ELSE x.access END AS access FROM free_tables t LEFT JOIN free_table_access x ON x.table_id=t.id AND x.actor_id=? WHERE t.club_id=? AND (t.owner_id=? OR x.actor_id IS NOT NULL) AND t.archived=? ORDER BY t.title,t.id',[$a['id'],'owner',$a['id'],$a['club_id'],$a['id'],(int)$archived])->fetchAll();
    }
    public function table(string $id,bool $write=false):array
    {
        $a=$this->actor();$t=$this->query('SELECT t.*,x.access FROM free_tables t LEFT JOIN free_table_access x ON x.table_id=t.id AND x.actor_id=? WHERE t.id=? AND t.club_id=?'.($this->writing?' FOR UPDATE':''),[$a['id'],$id,$a['club_id']])->fetch();
        if(!$t||($t['owner_id']!==$a['id']&&!in_array($t['access'],$write?['write']:['read','write'],true)))throw new DomainException('Keine Berechtigung für diese freie Liste.');
        if($write&&$t['archived'])throw new DomainException('Archivierte Liste zuerst wiederherstellen.');
        $t['access']=$t['owner_id']===$a['id']?'owner':$t['access'];$t['columns']=json_decode($t['columns_json'],true,32,JSON_THROW_ON_ERROR);return $t;
    }
    public function create(string $title,array $names,array $types):string
    {
        return $this->transaction(function()use($title,$names,$types){$a=$this->actor();
            if($a['members_access']!=='write')throw new DomainException('Zum Anlegen einer Liste ist Organisationsbearbeitung erforderlich.');
            $title=trim($title);if($title===''||mb_strlen($title)>120)throw new DomainException('Listentitel mit höchstens 120 Zeichen angeben.');
            $cols=[];foreach($names as $i=>$name){if(!is_string($name))throw new DomainException('Spalten prüfen.');$name=trim($name);if($name==='')continue;if(mb_strlen($name)>80||!isset(self::TYPES[$types[$i]??'']))throw new DomainException('Spaltenname oder Zelltyp prüfen.');$cols[]=['name'=>$name,'type'=>$types[$i]];}
            if(!$cols||count($cols)>20||count(array_unique(array_column($cols,'name')))!==count($cols))throw new DomainException('1 bis 20 eindeutig benannte Spalten erforderlich.');
            $id=bin2hex(random_bytes(16));$this->query('INSERT INTO free_tables(id,club_id,owner_id,title,columns_json) VALUES(?,?,?,?,?)',[$id,$a['club_id'],$a['id'],$title,json_encode($cols,JSON_THROW_ON_ERROR)]);$this->audit($a,'free.created',$id,1);return $id;
        });
    }
    private static function cells(array $cols,array $values):array
    {
        if(count($values)!==count($cols))throw new DomainException('Zellenzahl stimmt nicht mit den Spalten überein.');$out=[];
        foreach($cols as $i=>$col){$v=$values[$i]??null;if(!is_scalar($v))throw new DomainException('Zellwert prüfen.');$v=trim((string)$v);if(strlen($v)>2000)throw new DomainException('Zellen auf 2.000 Zeichen begrenzen.');
            if($v!==''&&$col['type']==='number'&&!preg_match('/^-?\d{1,12}(?:[.,]\d{1,6})?$/D',$v))throw new \Kulturhus\Verein\Tables\InputError('Zahl in {column} prüfen.',['{column}'=>$col['name']]);
            if($v!==''&&$col['type']==='date'){ $d=\DateTimeImmutable::createFromFormat('!Y-m-d',$v);if(!$d||$d->format('Y-m-d')!==$v)throw new \Kulturhus\Verein\Tables\InputError('Datum in {column} prüfen.',['{column}'=>$col['name']]);}
            if($col['type']==='boolean')$v=['Yes'=>'Ja','No'=>'Nein'][$v]??$v;
            if($v!==''&&$col['type']==='boolean'&&!in_array($v,['Ja','Nein'],true))throw new \Kulturhus\Verein\Tables\InputError('Ja oder Nein in {column} angeben.',['{column}'=>$col['name']]);$out[]=$v;
            if($v!==''&&in_array($col['type'],['formula','link'],true))\Kulturhus\Verein\Tables\Formula::parse($v,$col['type']==='link');
        }return $out;
    }
    public function configure(string $id,string $title,array $names,array $types,int $version):void
    {
        $this->transaction(function()use($id,$title,$names,$types,$version){$a=$this->actor();$t=$this->table($id,true);
            if($t['access']!=='owner'||(int)$t['version']!==$version)throw new DomainException('Spaltenänderungen benötigen den Eigentümer und einen aktuellen Listenstand.');
            $title=trim($title);if($title===''||mb_strlen($title)>120)throw new DomainException('Listentitel prüfen.');$cols=[];
            foreach($names as $i=>$name){if(!is_string($name))throw new DomainException('Spaltennamen prüfen.');$name=trim($name);
                if($name===''){if($i<count($t['columns']))throw new DomainException('Bestehende Spalten nicht leeren. Werte bleiben erhalten.');continue;}
                if(mb_strlen($name)>80||!isset(self::TYPES[$types[$i]??'']))throw new DomainException('Spaltenname oder Typ prüfen.');$cols[]=['name'=>$name,'type'=>$types[$i]];
            }
            if(count($cols)<count($t['columns'])||count($cols)>20||count(array_unique(array_column($cols,'name')))!==count($cols))throw new DomainException('Bis zu 20 eindeutige Spalten; bestehende Spalten bleiben erhalten.');
            foreach($this->query('SELECT * FROM free_table_rows WHERE table_id=?',[$id])->fetchAll() as $r){
                $values=json_decode($r['cells_json'],true,32,JSON_THROW_ON_ERROR);
                foreach($t['columns']as$i=>$old)if($values[$i]!==''&&$old['type']!==$cols[$i]['type']&&in_array($cols[$i]['type'],['formula','link'],true))throw new DomainException('Vorhandene Werte werden nicht in Formeln umgedeutet. Eine neue berechnete Spalte ergänzen.');
                $values=array_pad($values,count($cols),'');
                try{$values=self::cells($cols,$values);}catch(DomainException $e){throw new \Kulturhus\Verein\Tables\InputError('Vorhandene Werte passen nicht zum neuen Spaltentyp. Zuerst die betroffenen Zeilen anpassen. {detail}',['{detail}'=>$e->getMessage()],$e);}
                $this->query('UPDATE free_table_rows SET cells_json=?,version=version+1 WHERE id=?',[json_encode($values,JSON_THROW_ON_ERROR),$r['id']]);
            }
            $this->query('UPDATE free_tables SET title=?,columns_json=?,version=version+1 WHERE id=?',[$title,json_encode($cols,JSON_THROW_ON_ERROR),$id]);$this->audit($a,'free.columns.changed',$id,$version+1);
        });
    }
    public function rows(string $id,string $search='',bool $archived=false):array
    {
        $table=$this->table($id);$rows=$this->rawRows($id,$archived);
        $sourceTables=[];
        $values=new \Kulturhus\Verein\Tables\Values(function(string$source,string$row)use(&$sourceTables):array{
            $table=$sourceTables[$source]??=$this->table($source);if($table['archived'])throw new DomainException('Quelle nicht verfügbar.');
            $record=$this->query('SELECT * FROM free_table_rows WHERE table_id=? AND id=? AND archived=0',[$source,$row])->fetch();if($record)$record['cells']=json_decode($record['cells_json'],true,32,JSON_THROW_ON_ERROR);return ['columns'=>$table['columns'],'row'=>$record?:null];
        },$id,$table['columns'],$rows);
        $out=[];foreach($values->rows($rows)as$r)if($search===''||preg_match('/'.preg_quote($search,'/').'/iu',implode(' ',$r['display_cells']))===1)$out[]=$r;return $out;
    }
    private function rawRows(string $id,bool $archived=false):array
    {
        $rows=$this->query('SELECT * FROM free_table_rows WHERE table_id=? AND archived=? ORDER BY sequence LIMIT 2000',[$id,(int)$archived])->fetchAll();
        foreach($rows as&$row)$row['cells']=json_decode($row['cells_json'],true,32,JSON_THROW_ON_ERROR);unset($row);return $rows;
    }
    public function displayedRows(string $id,array $ids):array
    {
        if(count($ids)>101)throw new DomainException('Bis zu 101 angezeigte Zeilen prüfen.');$out=[];
        foreach($this->rows($id)as$row)if(in_array($row['id'],$ids,true))$out[$row['id']]=['values'=>$row['display_cells'],'errors'=>$row['cell_errors'],'types'=>$row['cell_types']];return $out;
    }
    public function cellReferences(string $id,string $row):array
    {
        $table=$this->table($id);if(!$this->query('SELECT 1 FROM free_table_rows WHERE table_id=? AND id=? AND archived=0',[$id,$row])->fetchColumn())throw new DomainException('Diese Zeile ist nicht mehr aktiv.');
        $out=[];foreach($table['columns']as$i=>$column)$out[]=['name'=>$column['name'],'letter'=>chr(65+$i),'reference'=>'=@{'.$id.':'.$row.':'.chr(65+$i).'}'];return $out;
    }
    public function saveRow(string $id,string $row,int $version,array $values,string $key=''):string
    {
        return $this->transaction(function()use($id,$row,$version,$values,$key){$a=$this->actor();$t=$this->table($id,true);$cells=self::cells($t['columns'],array_values($values));
            if($row===''){
                if($key!==''&&!preg_match('/^[a-f0-9]{32}$/D',$key))throw new DomainException('Ungültige Vorgangskennung.');
                $newId=$key!==''?substr(hash('sha256',$a['id'].':'.$id.':'.$key),0,32):bin2hex(random_bytes(16));
                $previous=$this->query('SELECT * FROM free_table_rows WHERE id=? AND table_id=?',[$newId,$id])->fetch();
                if($previous){if($version===0&&(int)$previous['version']===1&&!$previous['archived']&&json_decode($previous['cells_json'],true)===$cells)return $newId;throw new DomainException('Die neue Zeile wurde bereits gespeichert. Bitte neu laden und dort weiterbearbeiten.');}
                if($version!==0||(int)$this->query('SELECT COUNT(*) FROM free_table_rows WHERE table_id=? AND archived=0',[$id])->fetchColumn()>=2000)throw new DomainException('Maximal 2.000 aktive Zeilen je Liste.');
                $row=$newId;$this->query('INSERT INTO free_table_rows(id,table_id,cells_json) VALUES(?,?,?)',[$row,$id,json_encode($cells,JSON_THROW_ON_ERROR)]);
            }else{if($this->query('UPDATE free_table_rows SET cells_json=?,version=version+1 WHERE id=? AND table_id=? AND version=? AND archived=0',[json_encode($cells,JSON_THROW_ON_ERROR),$row,$id,$version])->rowCount()!==1)throw new DomainException('Zeile inzwischen geändert. Bitte neu laden.');}
            $this->audit($a,'free.row.saved',$row,$version+1);return $row;
        });
    }
    public function share(string $id,string $actor,string $access,int $version):void
    {
        $this->transaction(function()use($id,$actor,$access,$version){$a=$this->actor();$t=$this->table($id,true);if($t['owner_id']!==$a['id']||(int)$t['version']!==$version)throw new DomainException('Nur Eigentümer mit aktuellem Listenstand können freigeben.');
            $target=Permissions::actor($this->db,$actor);if($target['club_id']!==$a['club_id']||$actor===$a['id']||!in_array($access,['none','read','write'],true))throw new DomainException('Freigabe prüfen.');
            if($access==='none')$this->query('DELETE FROM free_table_access WHERE table_id=? AND actor_id=?',[$id,$actor]);
            else $this->query('INSERT INTO free_table_access VALUES(?,?,?) ON DUPLICATE KEY UPDATE access=VALUES(access)',[$id,$actor,$access]);
            $this->query('UPDATE free_tables SET version=version+1 WHERE id=?',[$id]);$this->audit($a,'free.share.'.$access,$id,$version+1);
        });
    }
    public function people(string $id):array{$t=$this->table($id);if($t['access']!=='owner')throw new DomainException('Nur Eigentümer dürfen Freigaben verwalten.');return $this->query('SELECT a.id,x.access FROM actors a LEFT JOIN free_table_access x ON x.actor_id=a.id AND x.table_id=? WHERE a.club_id=? AND a.active=1 AND a.id!=? ORDER BY a.id',[$id,$t['club_id'],$t['owner_id']])->fetchAll();}
    public function archive(string $id,int $version,bool $archived):void{$this->transaction(function()use($id,$version,$archived){$a=$this->actor();$t=$this->table($id);if($t['access']!=='owner'||(int)$t['version']!==$version)throw new DomainException('Eigentümer und aktueller Listenstand erforderlich.');$this->query('UPDATE free_tables SET archived=?,version=version+1 WHERE id=?',[(int)$archived,$id]);$this->audit($a,'free.archive',$id,$version+1);});}
    public function bulk(string $id,array $selected,string $mode,string $key):int
    {
        return $this->transaction(function()use($id,$selected,$mode,$key){$a=$this->actor();$this->table($id,true);
            if(!$selected||count($selected)>100||!in_array($mode,['copy','archive','restore'],true)||!preg_match('/^[a-f0-9]{32}$/D',$key))throw new DomainException('Bis zu 100 Zeilen und eine gültige Aktion auswählen.');
            $rows=[];foreach(array_unique($selected) as $selection){if(!is_string($selection)||!preg_match('/^([a-f0-9]{32}):([1-9][0-9]*)$/D',$selection,$m))throw new DomainException('Zeilenauswahl prüfen.');$r=$this->query('SELECT * FROM free_table_rows WHERE table_id=? AND id=?',[$id,$m[1]])->fetch();if(!$r||(int)$r['version']!==(int)$m[2]||(int)$r['archived']!==($mode==='restore'?1:0))throw new DomainException('Eine ausgewählte Zeile wurde inzwischen geändert. Bitte neu laden.');$rows[]=$r;}
            $active=(int)$this->query('SELECT COUNT(*) FROM free_table_rows WHERE table_id=? AND archived=0',[$id])->fetchColumn();if($mode!=='archive'&&$active+count($rows)>2000)throw new DomainException('Maximal 2.000 aktive Zeilen.');$n=0;
            foreach($rows as $r){if($mode==='copy'){$copy=substr(hash('sha256',$id.':'.$key.':'.$r['id']),0,32);$n+=$this->query('INSERT IGNORE INTO free_table_rows(id,table_id,cells_json) VALUES(?,?,?)',[$copy,$id,$r['cells_json']])->rowCount();$object=$copy;$v=1;}else{$this->query('UPDATE free_table_rows SET archived=?,version=version+1 WHERE id=?',[$mode==='archive'?1:0,$r['id']]);$object=$r['id'];$v=$r['version']+1;$n++;}$this->audit($a,'free.row.'.$mode,$object,$v);}return $n;
        });
    }
    public function duplicate(string $id,string $title,string $key):string
    {
        return $this->transaction(function()use($id,$title,$key){$a=$this->actor();$t=$this->table($id);if($a['members_access']!=='write'||!preg_match('/^[a-f0-9]{32}$/D',$key)||trim($title)===''||mb_strlen($title)>120)throw new DomainException('Organisationsbearbeitung und gültiger Listentitel erforderlich.');
            $copy=substr(hash('sha256',$a['id'].':'.$id.':'.$key),0,32);$old=$this->query('SELECT id FROM free_tables WHERE id=?',[$copy])->fetchColumn();if($old)return $copy;
            $this->query('INSERT INTO free_tables(id,club_id,owner_id,title,columns_json) VALUES(?,?,?,?,?)',[$copy,$a['club_id'],$a['id'],trim($title),$t['columns_json']]);
            foreach($this->rawRows($id) as $r)$this->query('INSERT INTO free_table_rows(id,table_id,cells_json) VALUES(?,?,?)',[bin2hex(random_bytes(16)),$copy,$r['cells_json']]);$this->audit($a,'free.copied',$copy,1);return $copy;
        });
    }
    public function export(string $id):string
    {
        $t=$this->table($id);$f=fopen('php://temp','r+');fwrite($f,"\xEF\xBB\xBF");$safe=static fn($v)=>preg_match('/^[=+@\-\t\r]/u',$v)?"'".$v:$v;
        fputcsv($f,array_map($safe,array_column($t['columns'],'name')),';', '"','');foreach($this->rows($id) as $r){$cells=[];foreach($r['display_cells']as$i=>$value)$cells[]=$r['cell_errors'][$i]!==null?'#FEHLER: '.$r['cell_errors'][$i]:($r['cell_types'][$i]==='number'?$value:$safe($value));fputcsv($f,$cells,';','"','');}rewind($f);$csv=stream_get_contents($f);fclose($f);return $csv;
    }
    public function importPreview(string $id,string $csv):array
    {
        $t=$this->table($id,true);if(strlen($csv)>1000000||!preg_match('//u',$csv))throw new DomainException('UTF-8-CSV bis 1 MB erforderlich.');
        $csv=preg_replace('/^\xEF\xBB\xBF/','',$csv);$f=fopen('php://temp','r+');fwrite($f,$csv);rewind($f);
        try{$header=fgetcsv($f,null,';','"','');if($header!==array_column($t['columns'],'name'))throw new DomainException('CSV-Kopfzeile muss genau den Listenspalten entsprechen (Trennzeichen Semikolon).');
            $rows=[];while(($r=fgetcsv($f,null,';','"',''))!==false){if($r===[null])continue;$rows[]=self::cells($t['columns'],$r);if(count($rows)>500)throw new DomainException('Höchstens 500 Zeilen pro Import.');}
        }finally{fclose($f);}
        if(!$rows)throw new DomainException('CSV enthält keine Datenzeilen.');
        return ['rows'=>$rows,'hash'=>hash('sha256',json_encode([$id,$t['version'],$rows],JSON_THROW_ON_ERROR))];
    }
    public function import(string $id,string $csv,string $expectedHash):int
    {
        return $this->transaction(function()use($id,$csv,$expectedHash){$a=$this->actor();$preview=$this->importPreview($id,$csv);
            if(!hash_equals($preview['hash'],$expectedHash))throw new DomainException('Import oder Liste geändert. Vorschau erneut prüfen.');
            $inserted=0;$count=(int)$this->query('SELECT COUNT(*) FROM free_table_rows WHERE table_id=? AND archived=0',[$id])->fetchColumn();
            // Stable row IDs make repeating this same file harmless, including after a lost response.
            $contentKey=hash('sha256',json_encode([$id,$preview['rows']],JSON_THROW_ON_ERROR));
            foreach($preview['rows'] as $i=>$cells){$row=substr(hash('sha256',$contentKey.':'.$i),0,32);
                if($this->query('SELECT 1 FROM free_table_rows WHERE id=?',[$row])->fetchColumn())continue;
                if($count+$inserted>=2000)throw new DomainException('Maximal 2.000 aktive Zeilen je Liste.');
                $this->query('INSERT INTO free_table_rows(id,table_id,cells_json) VALUES(?,?,?)',[$row,$id,json_encode($cells,JSON_THROW_ON_ERROR)]);$this->audit($a,'free.row.imported',$row,1);$inserted++;
            }return $inserted;
        });
    }
}
