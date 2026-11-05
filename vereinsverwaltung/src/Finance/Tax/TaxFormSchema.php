<?php declare(strict_types=1);
namespace Kulturhus\Verein;
use DomainException;
use DOMDocument;
use DOMElement;

/** Exact, versioned eXML fields; no example values, float arithmetic or silent truncation. */
final class TaxFormSchema
{
    public const NS='http://www.elster.de/elsterxml/schema/v11';
    public function __construct(private array $profile){}
    public static function catalog():array
    {
        $config=TaxEricAdapter::configuration();$file=$config['catalog'];
        if(filesize($file)>16000000)throw new DomainException('Formularkatalog zu groß.');
        $data=json_decode((string)file_get_contents($file),true,64,JSON_THROW_ON_ERROR);
        if(($data['version']??null)!==1||!is_array($data['profiles']??null))throw new DomainException('Formularkatalog ungültig.');
        return $data;
    }
    public static function load(string $version):self
    {
        $catalog=self::catalog();$p=$catalog['profiles'][$version]??null;$runtime=TaxEricAdapter::configuration();$enabled=$runtime['profiles'][$version]??null;
        if(!$p||!$enabled||($p['schema_sha256']??null)!==($enabled['schema_sha256']??null))throw new DomainException('Der gewählte Formularstand ist nicht installiert oder wurde geändert.');
        return new self($p);
    }
    public function profile():array{return $this->profile;}
    public function sections():array{return $this->profile['types'][$this->profile['root_type']]['children']??[];}
    public static function key(string $path):string{return rtrim(strtr(base64_encode($path),'+/','-_'),'=');}
    public static function path(string $key):string
    {
        if(!preg_match('/^[A-Za-z0-9_-]{1,2000}$/D',$key))throw new DomainException('Formularfeld ungültig.');
        $path=base64_decode(strtr($key,'-_','+/'),true);if($path===false||self::key($path)!==$key)throw new DomainException('Formularfeld ungültig.');return $path;
    }
    public function fields(array $counts=[],?string $section=null):array
    {
        $result=[];$types=$this->profile['types'];
        $walk=function(string $type,string $path,int $depth)use(&$walk,&$result,$types,$counts):void{
            if($depth>30||count($result)>12000)throw new DomainException('Formular zu umfangreich.');
            foreach($types[$type]['children']??[] as $node){
                $group=$path===''?$node['name']:$path.'/'.$node['name'];
                $max=(int)$node['max'];$count=$max>1?($counts[self::key($group)]??1):1;
                if(!is_int($count)&&!(is_string($count)&&ctype_digit($count)))throw new DomainException('Anzahl der Formularzeilen prüfen.');
                $count=(int)$count;if($count<1||$count>min($max,100))throw new DomainException('Zu viele Formularzeilen.');
                for($i=0;$i<$count;$i++){$p=$group.($max>1?'['.$i.']':'');$t=$types[$node['type']]??null;if(!$t)throw new DomainException('Formulartyp fehlt.');
                    if($t['simple'])$result[self::key($p)]=$node+$t+['path'=>$p,'group'=>$path,'repeat_group'=>$max>1?$group:null];
                    else $walk($node['type'],$p,$depth+1);
                }
            }
        };
        if($section!==null){$nodes=array_column($this->sections(),null,'name');if(!isset($nodes[$section]))throw new DomainException('Formularabschnitt fehlt.');
            // Top-level repeating forms are handled by the same tree walk.
        }
        $walk($this->profile['root_type'],'',0);
        if($section!==null)$result=array_filter($result,static fn($f)=>strtok($f['path'],'/[')===$section);
        return $result;
    }
    public function normalize(array $input,array $counts=[]):array
    {
        $groups=$this->repeatGroups($counts);if(array_diff_key($counts,$groups))throw new DomainException('Unbekannte Wiederholungsgruppe.');
        $allowed=$this->fields($counts);$out=[];
        if(count($input)>12000)throw new DomainException('Zu viele Formularfelder.');
        foreach($input as $key=>$value){
            if(!is_string($key)||!isset($allowed[$key])||!is_string($value)||!mb_check_encoding($value,'UTF-8')||preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/',$value))throw new DomainException('Formularfeld oder Inhalt ungültig.');
            $value=trim($value);if($value==='')continue;$field=$allowed[$key];
            if(mb_strlen($value)>min(40000,(int)($field['maxLength']??40000)))throw new DomainException($field['label'].': Eingabe zu lang.');
            if(isset($field['minLength'])&&mb_strlen($value)<(int)$field['minLength'])throw new DomainException($field['label'].': Eingabe zu kurz.');
            if(isset($field['enum'])&&!in_array($value,array_column($field['enum'],'value'),true))throw new DomainException($field['label'].': Auswahl ungültig.');
            $out[$key]=$value;
        }
        ksort($out,SORT_STRING);return $out;
    }
    public function repeatGroups(array $counts=[]):array
    {
        $result=[];$types=$this->profile['types'];$visited=0;
        $walk=function(string $type,string $path,int $depth)use(&$walk,&$result,&$visited,$types,$counts):void{
            if($depth>30||++$visited>12000)throw new DomainException('Formular zu umfangreich.');
            foreach($types[$type]['children']??[] as $node){
                $group=$path===''?$node['name']:$path.'/'.$node['name'];$max=min(100,(int)$node['max']);$key=self::key($group);$count=$max>1?($counts[$key]??1):1;
                if(!is_int($count)||$count<1||$count>$max)throw new DomainException('Anzahl der Formularzeilen prüfen.');
                if($max>1)$result[$key]=['path'=>$group,'label'=>$node['label'],'count'=>$count,'max'=>$max];
                if(!($types[$node['type']]['simple']??true))for($i=0;$i<$count;$i++)$walk($node['type'],$group.($max>1?'['.$i.']':''),$depth+1);
            }
        };$walk($this->profile['root_type'],'',0);return $result;
    }
    public function xml(array $fields,array $counts,array $header,string $environment,string $manufacturer,string $ticket,array $attachments=[]):string
    {
        $values=$this->normalize($fields,$counts);$p=$this->profile;$types=$p['types'];
        if(!in_array($environment,['test','production'],true)||!preg_match('/^[0-9]{5}$/D',$manufacturer)||!preg_match('/^[a-f0-9]{32}$/D',$ticket))throw new DomainException('ELSTER-Auftragskopf ungültig.');
        if(in_array($p['family'],['ustva','lsta'],true))foreach($this->fields($counts) as $key=>$field)if($field['name']==='Kz09'){
            // Do not activate unused alternative forms (UStVA / DF / Sondervorauszahlung).
            $active=false;foreach($values as $other=>$value)if($other!==$key&&str_starts_with(self::path($other),$field['group'].'/')){$active=true;break;}
            if($active)$values[$key]=$manufacturer;else unset($values[$key]);
        }
        if(!is_string($header['office']??null)||!preg_match('/^[0-9]{4}$/D',$header['office']))throw new DomainException('Vierstellige Finanzamtsnummer eingeben.');
        if(!is_string($header['supplier']??null)||trim($header['supplier'])===''||mb_strlen($header['supplier'])>1000||preg_match('/[\x00-\x1F]/',$header['supplier']))throw new DomainException('Name und Anschrift des Datenlieferanten eingeben.');
        $d=new DOMDocument('1.0','UTF-8');$d->formatOutput=true;
        $add=static function(DOMElement $parent,string $name,?string $text=null,string $ns=self::NS)use($d):DOMElement{$e=$d->createElementNS($ns,$name);if($text!==null)$e->appendChild($d->createTextNode($text));$parent->appendChild($e);return $e;};
        $root=$d->createElementNS(self::NS,'Elster');$d->appendChild($root);
        $th=$add($root,'TransferHeader');$th->setAttribute('version','11');
        $add($th,'Verfahren',$p['verfahren']);$add($th,'DatenArt',$p['data_art']);$add($th,'Vorgang',$p['vorgang']);
        if($environment==='test')$add($th,'Testmerker',$p['test_marker']);
        $add($th,'HerstellerID',$manufacturer);$add($th,'DatenLieferant',trim($header['supplier']));
        $file=$add($th,'Datei');$add($file,'Verschluesselung','CMSEncryptedData');$add($file,'Kompression','GZIP');
        $block=$add($add($root,'DatenTeil'),'Nutzdatenblock');$nh=$add($block,'NutzdatenHeader');$nh->setAttribute('version','11');
        $add($nh,'NutzdatenTicket',$ticket);$add($nh,'Empfaenger',$header['office'])->setAttribute('id','F');
        $data=$add($block,'Nutzdaten');$payload=$add($data,$p['root_name'],null,$p['namespace']);
        foreach($types[$p['root_type']]['attributes']??[] as $name=>$value)$payload->setAttribute($name,$value);
        $walk=function(DOMElement $parent,string $type,string $path)use(&$walk,$add,$types,$values,$counts,$p):void{
            foreach($types[$type]['children']??[] as $node){$group=$path===''?$node['name']:$path.'/'.$node['name'];$max=(int)$node['max'];$count=$max>1?(int)($counts[self::key($group)]??1):1;
                for($i=0;$i<$count;$i++){$keyPath=$group.($max>1?'['.$i.']':'');$t=$types[$node['type']];
                    if($t['simple']){if(isset($values[self::key($keyPath)]))$add($parent,$node['name'],$values[self::key($keyPath)],$p['namespace']);}
                    else{$e=$add($parent,$node['name'],null,$p['namespace']);foreach($t['attributes']??[] as $name=>$value)$e->setAttribute($name,$value);$walk($e,$node['type'],$keyPath);if(!$e->hasChildNodes())$parent->removeChild($e);}
                }
            }
        };
        $walk($payload,$p['root_type'],'');
        if($attachments){
            if(!in_array($p['attachment_version']??0,[3,4],true)||count($attachments)>20)throw new DomainException('Anhänge sind für dieses Formular nicht zulässig.');
            $ns='http://finkonsens.de/elster/anhaenge/simple/v'.$p['attachment_version'];$files=$add($data,'Anhaenge',null,$ns);$files->setAttribute('version',(string)$p['attachment_version']);$total=0;
            $feedback=$add($files,'Rueckmeldung',null,$ns);$add($feedback,'RueckmeldungGewuenscht','true',$ns);
            $email=$header['feedback_email']??'';
            if($email!==''){
                if(!is_string($email)||strlen($email)>128||!filter_var($email,FILTER_VALIDATE_EMAIL))throw new DomainException('E-Mail für Anlagen-Rückmeldungen prüfen.');
                $add($feedback,'EmailRueckmeldung',$email,$ns);
            }
            foreach($attachments as $i=>$attachment){$bytes=$attachment['bytes']??null;$name=$attachment['name']??null;
                if(!is_string($bytes)||!str_starts_with($bytes,'%PDF-')||!is_string($name)||!preg_match('/^[A-Za-z0-9_.-]{1,50}$/D',$name))throw new DomainException('Anlage muss ein PDF mit gültiger Bezeichnung sein.');
                $total+=strlen($bytes);if($total>2097152)throw new DomainException('Anhänge zusammen höchstens 2 MB.');
                $file=$add($files,'Anhang',null,$ns);$add($file,'Dateibezeichnung',$name,$ns);$add($file,'Dateityp','application/pdf',$ns);$add($file,'Dateiinhalt',base64_encode($bytes),$ns);
            }
        }
        $xml=$d->saveXML();if(!$xml||strlen($xml)>4194304)throw new DomainException('Erklärung zu groß.');return $xml;
    }
}
