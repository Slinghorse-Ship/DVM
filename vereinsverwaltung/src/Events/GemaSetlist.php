<?php declare(strict_types=1);
namespace Kulturhus\Verein;
use DomainException;

/** Writes the original GEMA Excel upload template; no submission is performed. */
final class GemaSetlist
{
    public const FIELDS=['A'=>'Werknummer / Werkfassungsnummer','B'=>'Titel','C'=>'Satzangabe / sonstiger Titel','D'=>'Anzahl Musiker / Sänger','E'=>'Spieldauer (MM:SS)','F'=>'Interpret / Komponist · Nachname oder Band','G'=>'Vorname','H'=>'Textdichter · Nachname','I'=>'Textdichter · Vorname','J'=>'Verlag','K'=>'Live / Tonträger','L'=>'Veröffentlichtes Werk','M'=>'Großes Recht','N'=>'Fragment / Potpourri','O'=>'ISWC','P'=>'Bearbeiter · Nachname','Q'=>'Bearbeiter · Vorname','R'=>'2. Komponist · Nachname','S'=>'2. Komponist · Vorname','T'=>'2. Textdichter · Nachname','U'=>'2. Textdichter · Vorname','V'=>'3. Textdichter · Nachname','W'=>'3. Textdichter · Vorname','X'=>'Sonstige Person · Nachname','Y'=>'Sonstige Person · Vorname'];
    public const CHOICES=['K'=>['Live','Tonträger'],'L'=>['Ja','Nein'],'M'=>['Ja','Nein'],'N'=>['F','P','F/P']];
    public static function normalize(array $songs,bool $complete=false):array
    {
        if(!array_is_list($songs)||count($songs)>200)throw new DomainException('Höchstens 200 Titel pro Setlist.');
        $out=[];
        foreach($songs as $song){
            if(!is_array($song)||array_diff_key($song,self::FIELDS))throw new DomainException('Titelfelder prüfen.');
            $row=[];foreach(self::FIELDS as $key=>$label){$value=$song[$key]??'';if(!is_string($value)||strlen($value)>500||!mb_check_encoding($value,'UTF-8')||preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/',$value))throw new DomainException('Titelfeld ungültig: '.$label);$row[$key]=trim($value);}
            if(!array_filter($row,fn($v)=>$v!==''))continue;
            if($complete&&($row['B']===''||$row['F']===''))throw new DomainException('Jeder Titel benötigt Titel und Interpret / Komponist.');
            if($row['D']!==''&&!preg_match('/^[1-9][0-9]{0,3}$/D',$row['D']))throw new DomainException('Anzahl Musiker / Sänger prüfen.');
            if($row['E']!==''&&!preg_match('/^[0-9]{1,3}:[0-5][0-9]$/D',$row['E']))throw new DomainException('Spieldauer als Minuten:Sekunden eingeben, z. B. 03:45.');
            foreach(self::CHOICES as $key=>$values)if($row[$key]!==''&&!in_array($row[$key],$values,true))throw new DomainException('Auswahl prüfen: '.self::FIELDS[$key]);
            $out[]=$row;
        }
        if($complete&&!$out)throw new DomainException('Zuerst mindestens einen Titel speichern.');
        return $out;
    }
    public static function xlsx(array $songs):string
    {
        $songs=self::normalize($songs,true);$path=tempnam(sys_get_temp_dir(),'gema-');
        if($path===false)throw new DomainException('Temporärer Speicher nicht verfügbar.');
        $zip=new \ZipArchive();$opened=false;
        try{
            if(!copy(dirname(__DIR__, 1).'/../resources/gema-setlist-template.xlsx',$path)||$zip->open($path)!==true)throw new DomainException('GEMA-Vorlage nicht verfügbar.');$opened=true;
            $doc=new \DOMDocument();$xml=$zip->getFromName('xl/worksheets/sheet1.xml');
            if($xml===false||!$doc->loadXML($xml,LIBXML_NONET))throw new DomainException('GEMA-Vorlage ungültig.');
            $ns='http://schemas.openxmlformats.org/spreadsheetml/2006/main';$xp=new \DOMXPath($doc);$xp->registerNamespace('s',$ns);
            foreach($songs as $i=>$song){$n=21+$i;$row=$xp->query('//s:sheetData/s:row[@r="'.$n.'"]')->item(0);if(!$row)throw new DomainException('Vorlagenzeile fehlt.');
                foreach($song as $col=>$value){if($value==='')continue;$cell=$xp->query('s:c[@r="'.$col.$n.'"]',$row)->item(0);
                    if(!$cell){$cell=$doc->createElementNS($ns,'c');$cell->setAttribute('r',$col.$n);$cell->setAttribute('s','3');$before=null;foreach($row->childNodes as $next)if($next instanceof \DOMElement&&strcmp(preg_replace('/[0-9]/','',$next->getAttribute('r')),$col)>0){$before=$next;break;}$row->insertBefore($cell,$before);}
                    while($cell->firstChild)$cell->removeChild($cell->firstChild);
                    // Explicit strings prevent spreadsheet formula execution from imported titles.
                    $cell->setAttribute('t','inlineStr');$is=$doc->createElementNS($ns,'is');$t=$doc->createElementNS($ns,'t');$t->appendChild($doc->createTextNode($value));$is->appendChild($t);$cell->appendChild($is);
                }
            }
            if(!$zip->addFromString('xl/worksheets/sheet1.xml',$doc->saveXML())||!$zip->close())throw new DomainException('Setlist konnte nicht erzeugt werden.');$opened=false;
            $bytes=file_get_contents($path);if($bytes===false)throw new DomainException('Setlist konnte nicht gelesen werden.');return $bytes;
        }finally{if($opened)$zip->close();unlink($path);}
    }
}
