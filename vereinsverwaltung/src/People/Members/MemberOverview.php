<?php declare(strict_types=1);
namespace Kulturhus\Verein;

use DateTimeImmutable;
use DateTimeZone;
use DomainException;

trait MemberOverview
{
    /** Current private dashboard reads own only their read transaction, never a caller's work. */
    private function overviewRead(callable $read):array
    {
        $owned=!$this->db->inTransaction();
        try{if($owned)$this->db->exec('START TRANSACTION');$result=$read();if($owned)$this->db->commit();return$result;}
        catch(\Throwable $failure){if($owned&&$this->db->inTransaction())$this->db->rollBack();if($failure instanceof \PDOException&&in_array((int)($failure->errorInfo[1]??0),[1205,3572],true))throw new DomainException('Mitgliederübersicht wird gerade bearbeitet. Bitte erneut öffnen.',503,$failure);throw$failure;}
    }
    private function overviewLock():string{return $this->db->inTransaction()?' FOR SHARE NOWAIT':'';}

    public function favouriteIds():array
    {
        $a=$this->actor();$q=$this->db->prepare("SELECT f.contact_id FROM contact_favourites f JOIN contacts c ON c.club_id=f.club_id AND c.id=f.contact_id WHERE c.archived_at IS NULL AND f.club_id=? AND f.actor_id=? AND (?='members' OR c.id=?)");$q->execute([$a['club_id'],$a['id'],$a['role'],$a['contact_id']]);return $q->fetchAll(\PDO::FETCH_COLUMN);
    }
    public function setFavourite(string $id,bool $enabled):void
    {
        $this->transaction(function()use($id,$enabled){$a=$this->actor(true);$this->contact($id);
            $q=$this->db->prepare($enabled?'INSERT IGNORE INTO contact_favourites(club_id,actor_id,contact_id) VALUES(?,?,?)':'DELETE FROM contact_favourites WHERE club_id=? AND actor_id=? AND contact_id=?');$q->execute([$a['club_id'],$a['id'],$id]);
            if($q->rowCount())$this->audit($a,$enabled?'favourite.added':'favourite.removed',$id,1);
        });
    }
    public function favouriteContacts(string $search=''):array
    {
        $a=$this->actor();if(strlen($search)>150)throw new DomainException('Suchtext ist zu lang.');
        $q=$this->db->prepare("SELECT c.* FROM contact_favourites f JOIN contacts c ON c.club_id=f.club_id AND c.id=f.contact_id WHERE c.archived_at IS NULL AND f.club_id=? AND f.actor_id=? AND (?='members' OR c.id=?) AND instr(lower(c.name),lower(?))>0 ORDER BY c.name,c.id LIMIT 1001");$q->execute([$a['club_id'],$a['id'],$a['role'],$a['contact_id'],trim($search)]);$rows=$q->fetchAll();if(count($rows)>1000)throw new DomainException('Bitte Favoritensuche eingrenzen.');return $rows;
    }
    public function anniversaryRules():array
    {
        return$this->overviewRead(function():array{
            $a=$this->actor();$q=$this->db->prepare('SELECT * FROM anniversary_settings WHERE club_id=?'.$this->overviewLock());$q->execute([$a['club_id']]);$row=$q->fetch();
            return $row?['version'=>(int)$row['version'],'rules'=>json_decode($row['rules'],true,512,JSON_THROW_ON_ERROR)]:['version'=>0,'rules'=>array_map(fn($y)=>['name'=>$y===1?'1 Jahr':$y.' Jahre','months'=>$y*12],[1,5,10,20,25,30,40,50])];
        });
    }
    public function saveAnniversaryRules(array $rules,int $version):void
    {
        $this->transaction(function()use($rules,$version){$a=$this->actor(true);
            if(count($rules)>24)throw new DomainException('Höchstens 24 Jubiläumsregeln sind möglich.');$clean=[];$durations=[];
            foreach($rules as $rule){
                if(!is_array($rule)||!is_string($rule['name']??null)||!is_int($rule['months']??null))throw new DomainException('Jubiläumsregel prüfen.');
                $name=trim($rule['name']);$months=$rule['months'];if($name===''||strlen($name)>100||$months<1||$months>1200||isset($durations[$months]))throw new DomainException('Name, Dauer (1–1200 Monate) und doppelte Zeiträume prüfen.');
                $durations[$months]=true;$clean[]=['name'=>$name,'months'=>$months];
            }
            usort($clean,fn($a,$b)=>$a['months']<=>$b['months']);$current=$this->anniversaryRules();if($current['version']!==$version)throw new DomainException('Jubiläumsregeln inzwischen geändert. Bitte neu laden.',409);
            $json=json_encode($clean,JSON_THROW_ON_ERROR);
            $this->db->prepare('INSERT INTO anniversary_settings(club_id,rules,version) VALUES(?,?,?) ON DUPLICATE KEY UPDATE rules=VALUES(rules),version=VALUES(version)')->execute([$a['club_id'],$json,$version+1]);$this->audit($a,'anniversary.rules.saved',$a['club_id'],$version+1);
        });
    }
    public static function rulesFromForm(array $names,array $durations,array $units):array
    {
        if(!array_is_list($names)||!array_is_list($durations)||!array_is_list($units)||count($names)!==count($durations)||count($names)!==count($units)||count($names)>24)throw new DomainException('Jubiläumsformular prüfen.');$rules=[];
        foreach($names as $i=>$name){if(!is_string($name)||!is_string($durations[$i])||!is_string($units[$i])||!mb_check_encoding($name,'UTF-8')||preg_match('/[\x00-\x1f\x7f]/',$name))throw new DomainException('Jubiläumsformular prüfen.',422);$duration=trim($durations[$i]);$unit=$units[$i];if(trim($name)===''&&$duration==='')continue;
            if(!preg_match('/^[0-9]{1,4}$/D',$duration)||!in_array($unit,['months','years'],true))throw new DomainException('Die Jubiläumsdauer muss eine ganze Zahl sein.');
            $rules[]=['name'=>$name,'months'=>(int)$duration*($unit==='years'?12:1)];
        }return $rules;
    }
    /** Clamp to the last valid day, so 29 February and month-end anniversaries never overflow. */
    public static function dateAfterMonths(string $date,int $months):string
    {
        self::date($date);$base=new DateTimeImmutable(substr($date,0,7).'-01',new DateTimeZone('Europe/Berlin'));$target=$base->modify('+'.$months.' months');
        return $target->format('Y-m-').str_pad((string)min((int)substr($date,8,2),(int)$target->format('t')),2,'0',STR_PAD_LEFT);
    }
    private function overviewProfiles():array
    {
        $a=$this->actor();$q=$this->db->prepare("SELECT c.id,c.name,c.kind,c.birthday,c.email,c.phone,
            m.member_number,m.status AS member_status,m.starts_on,m.ends_on,
            (SELECT MIN(s.starts_on) FROM memberships s WHERE s.club_id=c.club_id AND s.contact_id=c.id".$this->overviewLock().") AS anniversary_start,
            (SELECT s.starts_precision FROM memberships s WHERE s.club_id=c.club_id AND s.contact_id=c.id ORDER BY s.starts_on,s.id LIMIT 1".$this->overviewLock().") AS anniversary_precision,
            EXISTS(SELECT 1 FROM actors u WHERE u.club_id=c.club_id AND u.contact_id=c.id AND u.role='member' AND u.active=1".$this->overviewLock().") AS linked
            FROM contacts c LEFT JOIN memberships m ON m.club_id=c.club_id AND m.id=(SELECT s.id FROM memberships s WHERE s.club_id=c.club_id AND s.contact_id=c.id ORDER BY s.starts_on DESC,s.id DESC LIMIT 1".$this->overviewLock().")
            WHERE c.archived_at IS NULL AND c.club_id=? AND (?='members' OR c.id=?) ORDER BY c.name,c.id".$this->overviewLock());$q->execute([$a['club_id'],$a['role'],$a['contact_id']]);return $q->fetchAll();
    }
    public function birthdayAgenda(string $today,int $days=366):array
    {
        return$this->overviewRead(function()use($today,$days):array{
            self::date($today);if($days<0||$days>366)throw new DomainException('Geburtstagszeitraum prüfen.');$now=new DateTimeImmutable($today,new DateTimeZone('Europe/Berlin'));$rows=[];
            foreach($this->overviewProfiles() as $c){if($c['kind']!=='person'||!$c['birthday']||$c['birthday']>$today)continue;
                $year=(int)substr($today,0,4);$birthYear=(int)substr($c['birthday'],0,4);$date=self::dateAfterMonths($c['birthday'],($year-$birthYear)*12);if($date<$today){++$year;$date=self::dateAfterMonths($c['birthday'],($year-$birthYear)*12);}
                $offset=(int)$now->diff(new DateTimeImmutable($date,new DateTimeZone('Europe/Berlin')))->format('%r%a');if($offset<=$days)$rows[]=$c+['occasion_on'=>$date,'days'=>$offset,'age'=>$year-$birthYear];
            }
            usort($rows,fn($a,$b)=>[$a['occasion_on'],$a['name'],$a['id']]<=>[$b['occasion_on'],$b['name'],$b['id']]);return $rows;
        });
    }
    public function anniversaries(string $from,string $to,string $search=''):array
    {
        return$this->overviewRead(function()use($from,$to,$search):array{
            self::date($from);self::date($to);if($from>$to||strlen($search)>150)throw new DomainException('Jubiläumszeitraum oder Suche prüfen.');$rules=$this->anniversaryRules()['rules'];$rows=[];
            foreach($this->overviewProfiles() as $c){
                if($c['anniversary_precision']==='year'||!$c['starts_on']||!in_array($c['member_status'],['aktiv','passiv'],true)||($search!==''&&stripos($c['name'],$search)===false))continue;
                foreach($rules as $rule){$day=self::dateAfterMonths($c['anniversary_start'],$rule['months']);if($day<$from||$day>$to||($c['ends_on']&&$day>$c['ends_on']))continue;$rows[]=array_replace($c,['starts_on'=>$c['anniversary_start'],'occasion_on'=>$day,'anniversary'=>$rule['name'],'months'=>$rule['months']]);}
            }
            usort($rows,fn($a,$b)=>[$a['occasion_on'],$a['name'],$a['months']]<=>[$b['occasion_on'],$b['name'],$b['months']]);return $rows;
        });
    }
    public function memberOverview(string $today):array
    {
        return$this->overviewRead(function()use($today):array{
            self::date($today);$counts=['members'=>0,'active'=>0,'passive'=>0,'contacts'=>0,'linked'=>0,'average_age'=>null,'age_count'=>0];$ageTotal=0;$now=new DateTimeImmutable($today,new DateTimeZone('Europe/Berlin'));
            foreach($this->overviewProfiles() as $c){if($c['member_status']===null){++$counts['contacts'];continue;}++$counts['members'];$counts['active']+=(int)($c['member_status']==='aktiv');$counts['passive']+=(int)($c['member_status']==='passiv');$counts['linked']+=(int)$c['linked'];
                if($c['kind']==='person'&&$c['birthday']&&$c['birthday']<=$today){$ageTotal+=(new DateTimeImmutable($c['birthday'],new DateTimeZone('Europe/Berlin')))->diff($now)->y;++$counts['age_count'];}
            }
            if($counts['age_count'])$counts['average_age']=round($ageTotal/$counts['age_count'],1);return $counts;
        });
    }
}
