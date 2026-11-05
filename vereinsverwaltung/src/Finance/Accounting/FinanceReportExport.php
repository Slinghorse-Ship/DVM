<?php declare(strict_types=1);
namespace Kulturhus\Verein;

use DomainException;
use ZipArchive;

/** A value-only XLSX snapshot of an already authorized and filtered FinanceReports result. */
final class FinanceReportExport
{
    public const MIME='application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

    public static function xlsx(array $report):string
    {
        if(!class_exists(ZipArchive::class))throw new DomainException('Für den Excel-Export muss die PHP-ZIP-Erweiterung aktiviert sein.');
        [$title,$headers,$widths,$rows,$total]=match($report['type']??''){
            'income'=>self::income($report),
            'journal'=>self::journal($report),
            default=>throw new DomainException('Unbekannter Finanzbericht.'),
        };
        $filters=$report['filters']??[];
        $from=self::date($filters['from']??'');$to=self::date($filters['to']??'');
        if($from>$to)throw new DomainException('Berichtszeitraum prüfen.');
        $period=$from->format('d.m.Y').' bis '.$to->format('d.m.Y');
        $resolution=['week'=>'Woche','month'=>'Monat','year'=>'Jahr'][$filters['resolution']??'month']??'Zeitraum';
        $scope='Zeitraum: '.$period;
        $scope.=' · '.(empty($filters['account'])?'Alle Konten':'Kontoauswahl aktiv');
        $tags=$filters['tags']??[];
        if(!is_array($tags))throw new DomainException('Berichtsfilter prüfen.');
        $scope.=' · '.($tags?'Schlagwörter: '.implode(', ',array_map(self::plain(...),$report['selected_tag_names']??$tags)):'Alle Schlagwörter');
        $sheet=self::sheet($title,$period,$scope.($report['type']==='income'?' · Gliederung: '.$resolution:''),$headers,$widths,$rows,$total);
        $files=[
            '[Content_Types].xml'=>self::xml().'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/><Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/><Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/></Types>',
            '_rels/.rels'=>self::xml().'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/><Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/extended-properties" Target="docProps/app.xml"/></Relationships>',
            'xl/workbook.xml'=>self::xml().'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><bookViews><workbookView/></bookViews><sheets><sheet name="'.self::escape($title).'" sheetId="1" r:id="rId1"/></sheets></workbook>',
            'xl/_rels/workbook.xml.rels'=>self::xml().'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>',
            'xl/worksheets/sheet1.xml'=>$sheet,
            'xl/styles.xml'=>self::styles(),
            'docProps/core.xml'=>self::xml().'<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"><dc:title>'.self::escape($title.' · '.$period).'</dc:title><dc:creator>Kulturhus Vereinsmanager</dc:creator><dcterms:created xsi:type="dcterms:W3CDTF">'.gmdate('Y-m-d\TH:i:s\Z').'</dcterms:created></cp:coreProperties>',
            'docProps/app.xml'=>self::xml().'<Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties"><Application>Kulturhus Vereinsmanager</Application></Properties>',
        ];
        $path=tempnam(sys_get_temp_dir(),'khxlsx-');if($path===false)throw new DomainException('Temporäre Excel-Datei konnte nicht angelegt werden.');
        $zip=new ZipArchive();$opened=false;
        try{
            if($zip->open($path,ZipArchive::CREATE|ZipArchive::OVERWRITE)!==true)throw new DomainException('Excel-Datei konnte nicht geöffnet werden.');$opened=true;
            foreach($files as $name=>$bytes)if(!$zip->addFromString($name,$bytes))throw new DomainException('Excel-Inhalt konnte nicht geschrieben werden.');
            if(!$zip->close())throw new DomainException('Excel-Datei konnte nicht abgeschlossen werden.');$opened=false;
            $bytes=file_get_contents($path);if($bytes===false)throw new DomainException('Excel-Datei konnte nicht gelesen werden.');return $bytes;
        }finally{if($opened)$zip->close();if(is_file($path))unlink($path);}
    }

    private static function income(array $report):array
    {
        $compare=(bool)($report['filters']['compare']??false);
        $headers=['Zeitraum','Von','Bis','Einnahmen EUR','Ausgaben EUR','Ergebnis EUR'];$widths=[25,14,14,20,20,20];
        if($compare){$headers=array_merge($headers,['Vorjahr von','Vorjahr bis','Vorjahr Einnahmen EUR','Vorjahr Ausgaben EUR','Vorjahr Ergebnis EUR']);$widths=array_merge($widths,[14,14,24,24,24]);}
        $rows=[];$sum=[0,0,0];$previousSum=[0,0,0];
        foreach(self::rows($report) as $row){
            $amounts=self::amounts($row);foreach($amounts as $i=>$value)$sum[$i]+=$value;
            $cells=[self::text($row['label']??$row['key']??''),self::day($row['from']??''),self::day($row['to']??''),...array_map(self::money(...),$amounts)];
            if($compare){$previous=$row['previous']??null;$amounts=$previous===null?[0,0,0]:self::amounts($previous);foreach($amounts as $i=>$value)$previousSum[$i]+=$value;$cells=array_merge($cells,[$previous===null||($previous['from']??null)===null?self::text(''):self::day($previous['from']),$previous===null||($previous['to']??null)===null?self::text(''):self::day($previous['to']),...array_map(self::money(...),$amounts)]);}
            if(isset($report['tag_catalogue']))$cells[]=self::text(implode(', ',$row['tags']??[]));
            $rows[]=$cells;
        }
        if($sum!==self::amounts($report['totals']??[]))throw new DomainException('Berichtssummen stimmen nicht mit den gefilterten Zeilen überein.');
        $total=[self::text('Summe'),self::text(''),self::text(''),...array_map(self::money(...),$sum)];
        if($compare){if($previousSum!==self::amounts($report['previous_totals']??[]))throw new DomainException('Vorjahressummen stimmen nicht mit den Berichtszeilen überein.');$total=array_merge($total,[self::text(''),self::text(''),...array_map(self::money(...),$previousSum)]);}
        if(isset($report['tag_catalogue'])){$headers[]='Tags der Zahlungen im Zeitraum';$widths[]=42;$total[]=self::text('');}
        return ['Einnahmen und Ausgaben',$headers,$widths,$rows,$total];
    }
    private static function journal(array $report):array
    {
        $headers=['Zahlungsdatum','Transaktionsnummer','Belegnummer','Sollkonto','Sollkonto Bezeichnung','Habenkonto','Habenkonto Bezeichnung','Sphäre','Buchungsschlüssel','Steuersatz','Beschreibung','Name','Information','Betrag EUR','Mit Vorzeichen EUR','Storno zu'];
        $widths=[16,23,23,15,28,15,28,22,20,14,48,28,48,20,22,24];$rows=[];$sum=0;
        foreach(self::rows($report) as $row){
            $signed=self::cents($row['signed_cents']??null);$amount=self::cents($row['amount_cents']??null);if($amount<0||abs($signed)!==$amount)throw new DomainException('Journalbetrag prüfen.');$sum+=$signed;
            $tax=$row['tax_rate']??null;if($tax!==null&&!in_array($tax,[0,7,19],true))throw new DomainException('Steuersatz prüfen.');
            $rows[]=[self::day($row['payment_date']??''),self::text($row['transaction_number']??''),self::text($row['receipt_number']??''),self::text($row['debit_account_number']??''),self::text($row['debit_account_name']??''),self::text($row['credit_account_number']??''),self::text($row['credit_account_name']??''),self::text($row['sphere']??''),self::text($row['booking_key']??''),$tax===null?self::text(''):[self::decimal($tax),6],self::text($row['description']??''),self::text($row['counterparty']??''),self::text($row['info']??''),self::money($amount),self::money($signed),self::text($row['reversal_of']??'')];
        }
        if(isset($report['tag_catalogue'])){foreach($rows as$i=>&$cells)$cells[]=self::text(implode(', ',$report['rows'][$i]['tags']??[]));unset($cells);$headers[]='Zahlungstags';$widths[]=42;}
        if($sum!==self::cents($report['signed_total_cents']??null)||(int)($report['count']??-1)!==count($rows))throw new DomainException('Journalsumme oder Zeilenzahl stimmt nicht mit der Auswahl überein.');
        $total=array_fill(0,count($headers),self::text(''));$total[0]=self::text('Saldo');$total[14]=self::money($sum);
        return ['Buchungsjournal',$headers,$widths,$rows,$total];
    }
    private static function rows(array $report):array
    {
        $rows=$report['rows']??null;if(!is_array($rows)||count($rows)>1048569)throw new DomainException('Berichtszeilen für Excel prüfen.');return $rows;
    }
    private static function amounts(array $row):array
    {
        $a=[self::cents($row['income_cents']??null),self::cents($row['expense_cents']??null),self::cents($row['result_cents']??null)];
        if($a[0]-$a[1]!==$a[2])throw new DomainException('Berichtsergebnis stimmt nicht mit Einnahmen und Ausgaben überein.');return $a;
    }
    private static function cents(mixed $value):int
    {
        if(!is_int($value)&&(!is_string($value)||!preg_match('/^-?[0-9]{1,15}$/D',$value)))throw new DomainException('Ganzzahlige Centbeträge erforderlich.');
        $value=(int)$value;if(abs($value)>999999999999999)throw new DomainException('Betrag überschreitet die exakte Excel-Zahlengenauigkeit.');return $value;
    }
    private static function decimal(int $cents):string{return ($cents<0?'-':'').intdiv(abs($cents),100).'.'.str_pad((string)(abs($cents)%100),2,'0',STR_PAD_LEFT);}
    private static function money(int $cents):array{return [self::decimal(self::cents($cents)),2];}
    private static function text(mixed $value):array{return [self::plain($value),0];}
    private static function plain(mixed $value):string{if(!is_scalar($value)&&$value!==null)throw new DomainException('Textwert im Bericht prüfen.');return (string)$value;}
    private static function date(mixed $value):\DateTimeImmutable
    {
        if(!is_string($value))throw new DomainException('Berichtsdatum prüfen.');$date=\DateTimeImmutable::createFromFormat('!Y-m-d',$value,new \DateTimeZone('UTC'));
        if(!$date||$date->format('Y-m-d')!==$value||$value<'1900-01-01')throw new DomainException('Berichtsdatum prüfen.');return $date;
    }
    private static function day(mixed $value):array
    {
        $date=self::date($value);$origin=new \DateTimeImmutable($date->format('Y-m-d')<'1900-03-01'?'1899-12-31':'1899-12-30',new \DateTimeZone('UTC'));
        return [(string)$origin->diff($date)->days,3];
    }
    private static function xml():string{return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';}
    private static function escape(string $value):string
    {
        $escaped=htmlspecialchars($value,ENT_XML1|ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
        return preg_replace('/[^\x{0009}\x{000A}\x{000D}\x{0020}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u','',$escaped)??'';
    }
    private static function column(int $index):string{$name='';for($index++;$index>0;$index=intdiv($index-1,26))$name=chr(65+($index-1)%26).$name;return $name;}
    private static function sheet(string $title,string $period,string $scope,array $headers,array $widths,array $rows,array $total):string
    {
        $last=self::column(count($headers)-1);$lastData=5+count($rows);$lastRow=$lastData+1;
        $out=self::xml().'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><dimension ref="A1:'.$last.$lastRow.'"/><sheetViews><sheetView workbookViewId="0"><pane ySplit="5" topLeftCell="A6" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews><sheetFormatPr defaultRowHeight="18"/><cols>';
        foreach($widths as $i=>$width)$out.='<col min="'.($i+1).'" max="'.($i+1).'" width="'.$width.'" customWidth="1"/>';$out.='</cols><sheetData>';
        $out.=self::row(1,[[self::plain($title),4]]).self::row(2,[self::text($period)]).self::row(3,[self::text($scope)]).self::row(5,array_map(static fn($h)=>[$h,1],$headers));
        foreach($rows as $i=>$cells)$out.=self::row($i+6,$cells);
        $out.=self::row($lastRow,array_map(static fn($cell)=>[$cell[0],$cell[1]===2?5:7],$total));
        return $out.'</sheetData><autoFilter ref="A5:'.$last.max(5,$lastData).'"/><mergeCells count="3"><mergeCell ref="A1:'.$last.'1"/><mergeCell ref="A2:'.$last.'2"/><mergeCell ref="A3:'.$last.'3"/></mergeCells><pageMargins left="0.3" right="0.3" top="0.5" bottom="0.5" header="0.2" footer="0.2"/><pageSetup orientation="landscape" paperSize="9" fitToWidth="1" fitToHeight="0"/></worksheet>';
    }
    private static function row(int $number,array $cells):string
    {
        $out='<row r="'.$number.'"'.($number===5?' ht="34" customHeight="1"':'').'>';
        foreach($cells as $i=>[$value,$style]){$ref=self::column($i).$number;$numeric=in_array($style,[2,3,5,6],true);$out.='<c r="'.$ref.'" s="'.$style.'"'.($numeric?'><v>'.self::escape($value).'</v>':' t="inlineStr"><is><t xml:space="preserve">'.self::escape($value).'</t></is>').'</c>';}
        return $out.'</row>';
    }
    private static function styles():string
    {
        return self::xml().'<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><numFmts count="2"><numFmt numFmtId="164" formatCode="#,##0.00 &quot;€&quot;"/><numFmt numFmtId="165" formatCode="dd.mm.yyyy"/></numFmts><fonts count="3"><font><sz val="11"/><name val="Calibri"/></font><font><b/><color rgb="FFFFFFFF"/><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts><fills count="4"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FF245640"/><bgColor indexed="64"/></patternFill></fill><fill><patternFill patternType="solid"><fgColor rgb="FFE7EFEB"/><bgColor indexed="64"/></patternFill></fill></fills><borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders><cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs><cellXfs count="8"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"><alignment vertical="top" wrapText="1"/></xf><xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFill="1" applyFont="1"><alignment vertical="center" wrapText="1"/></xf><xf numFmtId="164" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/><xf numFmtId="165" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/><xf numFmtId="0" fontId="2" fillId="0" borderId="0" xfId="0" applyFont="1"/><xf numFmtId="164" fontId="2" fillId="3" borderId="0" xfId="0" applyNumberFormat="1" applyFill="1" applyFont="1"/><xf numFmtId="9" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/><xf numFmtId="0" fontId="2" fillId="3" borderId="0" xfId="0" applyFill="1" applyFont="1"/></cellXfs><cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>';
    }
}
