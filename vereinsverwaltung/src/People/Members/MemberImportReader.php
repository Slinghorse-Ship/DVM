<?php declare(strict_types=1);
namespace Kulturhus\Verein;
use DomainException;
final class MemberImportReader
{
 public static function read(string $name,string $bytes):array
 {
  if(strlen($bytes)>5242880||$bytes==='')throw new DomainException('Importdatei leer oder größer als 5 MB.');
  if(strtolower(pathinfo($name,PATHINFO_EXTENSION))==='csv'){
   $bytes=preg_replace('/^\xEF\xBB\xBF/','',$bytes);if(!mb_check_encoding($bytes,'UTF-8'))$bytes=mb_convert_encoding($bytes,'UTF-8','Windows-1252');
   $first=strtok($bytes,"\r\n");$delimiter=';';$count=0;foreach([';',',',"\t"] as $candidate){$n=count(str_getcsv($first?:'',$candidate,'"',''));if($n>$count){$count=$n;$delimiter=$candidate;}}
   $file=fopen('php://temp','w+');fwrite($file,$bytes);rewind($file);$rows=[];try{while(($row=fgetcsv($file,0,$delimiter,'"',''))!==false){if(count($rows)>=1000||count($row)>64)throw new DomainException('Höchstens 1.000 Zeilen und 64 Spalten importieren.');$rows[]=array_map(fn($v)=>(string)$v,$row);}}finally{fclose($file);}return ['sheets'=>[['name'=>'CSV','rows'=>$rows,'formulas'=>[]]],'date1904'=>false];
  }
  if(strtolower(pathinfo($name,PATHINFO_EXTENSION))!=='xlsx')throw new DomainException('Bitte XLSX oder CSV verwenden.');
  $path=tempnam(sys_get_temp_dir(),'member-import-');if($path===false)throw new DomainException('Importdatei nicht lesbar.');file_put_contents($path,$bytes);$zip=new \ZipArchive();$opened=false;
  try{
   if($zip->open($path)!==true)throw new DomainException('XLSX-Datei ungültig.');$opened=true;$size=0;if($zip->numFiles>1000)throw new DomainException('XLSX enthält zu viele Bestandteile.');for($i=0;$i<$zip->numFiles;$i++){$part=$zip->statIndex($i);$size+=$part['size'];if($size>33554432)throw new DomainException('Entpackte XLSX größer als 32 MB.');}
   $xml=static function(string $name)use($zip):\SimpleXMLElement{$raw=$zip->getFromName($name);if($raw===false||strlen($raw)>12582912||stripos($raw,'<!DOCTYPE')!==false||stripos($raw,'<!ENTITY')!==false)throw new DomainException('XLSX-Bestandteil ungültig.');$prior=libxml_use_internal_errors(true);try{$x=simplexml_load_string($raw,'SimpleXMLElement',LIBXML_NONET);if(!$x)throw new DomainException('XLSX-XML ungültig.');return $x;}finally{libxml_clear_errors();libxml_use_internal_errors($prior);}};
   $strings=[];if($zip->locateName('xl/sharedStrings.xml')!==false)foreach($xml('xl/sharedStrings.xml')->si as $si){$parts=$si->xpath('.//*[local-name()="t"]');$strings[]=implode('',array_map('strval',$parts));}
   $zeroStyles=[];$dateStyles=[];if($zip->locateName('xl/styles.xml')!==false){
    $styles=$xml('xl/styles.xml');$formats=[];$dateFormats=[];
    foreach($styles->numFmts->numFmt??[]as$format){$code=(string)$format['formatCode'];$id=(string)$format['numFmtId'];if(preg_match('/^0{1,20}$/D',$code))$formats[$id]=strlen($code);
     $plain='';$quoted=false;$bracket=false;$escaped=false;foreach(str_split($code)as$char){if($escaped){$escaped=false;continue;}if($char==='\\'){$escaped=true;continue;}if($char==='"'){$quoted=!$quoted;continue;}if(!$quoted&&$char==='['){$bracket=true;continue;}if(!$quoted&&$char===']'){$bracket=false;continue;}if(!$quoted&&!$bracket)$plain.=$char;}if(preg_match('/[dy]/i',$plain))$dateFormats[$id]=true;
    }
    $i=0;foreach($styles->cellXfs->xf??[]as$style){$id=(string)$style['numFmtId'];if(isset($formats[$id]))$zeroStyles[$i]=$formats[$id];if(in_array((int)$id,[14,15,16,17,22,27,28,29,30,31,32,33,34,35,36,50,51,52,53,54,55,56,57,58],true)||isset($dateFormats[$id]))$dateStyles[$i]=true;++$i;}
   }
   $rels=[];foreach($xml('xl/_rels/workbook.xml.rels')->Relationship as $rel){if((string)$rel['TargetMode']==='External')continue;$target=(string)$rel['Target'];$target=str_starts_with($target,'/')?ltrim($target,'/'):'xl/'.$target;if(preg_match('~^xl/worksheets/[^/]+\.xml$~D',$target))$rels[(string)$rel['Id']]=$target;}
   $book=$xml('xl/workbook.xml');$sheets=[];foreach($book->sheets->sheet as $sheet){if(count($sheets)>=20)throw new DomainException('Höchstens 20 Tabellenblätter.');$rid=(string)$sheet->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'];if(!isset($rels[$rid]))continue;$rows=[];$formulas=[];$dateCells=[];
    foreach($xml($rels[$rid])->sheetData->row as $row){$r=(int)$row['r']-1;if($r<0||$r>=1000)throw new DomainException('Höchstens 1.000 Tabellenzeilen pro Blatt.');$cells=[];foreach($row->c as $cell){if(!preg_match('/^([A-Z]{1,3})[0-9]+$/D',(string)$cell['r'],$m))throw new DomainException('Zelladresse ungültig.');$column=0;foreach(str_split($m[1]) as $letter)$column=$column*26+ord($letter)-64;--$column;if($column>=64)throw new DomainException('Höchstens 64 Tabellenspalten.');$type=(string)$cell['t'];$value=(string)$cell->v;
      if($type==='s'){if(!ctype_digit($value)||!isset($strings[(int)$value]))throw new DomainException('XLSX-Textverweis ungültig.');$value=$strings[(int)$value];}elseif($type==='inlineStr')$value=implode('',array_map('strval',$cell->is->xpath('.//*[local-name()="t"]')));
      elseif(isset($zeroStyles[(int)$cell['s']])&&ctype_digit($value))$value=str_pad($value,$zeroStyles[(int)$cell['s']],'0',STR_PAD_LEFT);
      if(in_array($type,['','n'],true)&&isset($dateStyles[(int)$cell['s']])&&preg_match('/^[0-9]{1,5}(?:\.0+)?$/D',$value))$dateCells[$r][$column]=true;
      if(strlen($value)>10000)throw new DomainException('Ein Zellinhalt ist zu lang.');$cells[$column]=$value;if(isset($cell->f)||$type==='e')$formulas[$r][$column]=true;
    }$rows[$r]=$cells;}
    $sheets[]=['name'=>(string)$sheet['name'],'rows'=>$rows,'formulas'=>$formulas,'date_cells'=>$dateCells];
   }
   if(!$sheets)throw new DomainException('Keine lesbaren Tabellenblätter.');$memberTemplate=false;foreach($book->definedNames->definedName??[]as$defined)if((string)$defined['name']==='_DVM_MembersTemplate'&&in_array((string)$defined,['"DVM-Members-v1"','"DVM-Members-v2"'],true))$memberTemplate=true;
   if($memberTemplate){$inputs=array_values(array_filter($sheets,static fn(array$sheet):bool=>$sheet['name']==='Mitglieder'));if(count($inputs)!==1)throw new DomainException('Das auszufüllende Blatt Mitglieder fehlt oder ist nicht eindeutig.');$sheets=$inputs;}
   return ['sheets'=>$sheets,'date1904'=>in_array((string)$book->workbookPr['date1904'],['1','true'],true),'member_template'=>$memberTemplate];
  }finally{if($opened)$zip->close();unlink($path);}
 }
}
