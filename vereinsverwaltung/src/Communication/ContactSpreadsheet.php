<?php declare(strict_types=1);
namespace Kulturhus\Verein;
use DomainException;use ZipArchive;

/** Small, value-only Excel list. Identity, phone and formula-looking values stay text. */
final class ContactSpreadsheet
{
 public static function create(array $headers,#[\SensitiveParameter] array $rows):string{return self::build($headers,$rows,100);}
 /** The member workbook has one header and at most 999 people. Existing exports keep their 100-row boundary. */
 public static function memberList(array $headers,#[\SensitiveParameter] array $rows):string{return self::build($headers,$rows,999,17);}
 private static function build(array $headers,#[\SensitiveParameter] array $rows,int $limit,int $columns=10):string
 {
  if(!class_exists(ZipArchive::class))throw new DomainException('Für Excel-Dateien muss die PHP-ZIP-Erweiterung aktiviert sein.');
  if(!array_is_list($headers)||!$headers||count($headers)>$columns||!array_is_list($rows)||count($rows)>$limit)throw new DomainException('Excel-Auswahl prüfen.');
  $last=chr(64+count($headers));$lastRow=count($rows)+1;$xml='<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
  $sheet=$xml.'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><dimension ref="A1:'.$last.$lastRow.'"/><sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews><sheetFormatPr defaultRowHeight="30"/><cols>';
  foreach($headers as $i=>$header)$sheet.='<col min="'.($i+1).'" max="'.($i+1).'" width="'.($header==='Anschrift'?48:30).'" customWidth="1"/>';
  $sheet.='</cols><sheetData>';
  foreach([$headers,...$rows] as $i=>$row){if(!is_array($row)||!array_is_list($row)||count($row)!==count($headers))throw new DomainException('Excel-Zeilen prüfen.');$sheet.='<row r="'.($i+1).'">';foreach($row as $j=>$value){if(!is_string($value))throw new DomainException('Textwerte im Profilexport prüfen.');$sheet.='<c r="'.chr(65+$j).($i+1).'" s="'.($i===0?1:0).'" t="inlineStr"><is><t xml:space="preserve">'.self::escape($value).'</t></is></c>';}$sheet.='</row>';}
  $sheet.='</sheetData><autoFilter ref="A1:'.$last.$lastRow.'"/><pageMargins left="0.3" right="0.3" top="0.5" bottom="0.5" header="0.2" footer="0.2"/><pageSetup orientation="landscape" paperSize="9" fitToWidth="1" fitToHeight="0"/></worksheet>';
  $files=[
   '[Content_Types].xml'=>$xml.'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/></Types>',
   '_rels/.rels'=>$xml.'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>',
   'xl/workbook.xml'=>$xml.'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><bookViews><workbookView/></bookViews><sheets><sheet name="Mitglieder und Kontakte" sheetId="1" r:id="rId1"/></sheets></workbook>',
   'xl/_rels/workbook.xml.rels'=>$xml.'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>',
   'xl/worksheets/sheet1.xml'=>$sheet,
   'xl/styles.xml'=>$xml.'<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/><color rgb="FFFFFFFF"/></font></fonts><fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FF245640"/><bgColor indexed="64"/></patternFill></fill></fills><borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders><cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs><cellXfs count="2"><xf numFmtId="49" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1" applyAlignment="1"><alignment vertical="top" wrapText="1"/></xf><xf numFmtId="49" fontId="1" fillId="2" borderId="0" xfId="0" applyAlignment="1"><alignment vertical="center" wrapText="1"/></xf></cellXfs><cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>',
  ];
  $path=tempnam(sys_get_temp_dir(),'khcontacts-');if($path===false)throw new DomainException('Temporärer Excel-Speicher fehlt.');@chmod($path,0600);$zip=new ZipArchive();$opened=false;
  try{if($zip->open($path,ZipArchive::CREATE|ZipArchive::OVERWRITE)!==true)throw new DomainException('Excel-Datei konnte nicht angelegt werden.');$opened=true;foreach($files as $name=>$bytes)if(!$zip->addFromString($name,$bytes))throw new DomainException('Excel-Inhalt konnte nicht gespeichert werden.');if(!$zip->close())throw new DomainException('Excel-Datei konnte nicht abgeschlossen werden.');$opened=false;$bytes=file_get_contents($path);if($bytes===false)throw new DomainException('Excel-Datei ist nicht lesbar.');return $bytes;}finally{if($opened)$zip->close();if(is_file($path))unlink($path);}
 }
 private static function escape(string $value):string
 {
  $value=htmlspecialchars($value,ENT_XML1|ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');return preg_replace('/[^\x{0009}\x{000A}\x{000D}\x{0020}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u','',$value)??'';
 }
}
