<?php declare(strict_types=1);
namespace Kulturhus\Verein;
use DomainException;

/** Plain text with a deliberately small formatting/variable vocabulary. No executable template language. */
final class SettingsSchema
{
    public const SECTIONS=['club'=>'Vereinsdaten','salutations'=>'Briefanreden & Titel','bank'=>'Bankverbindung','tax'=>'Steuereinstellungen & DATEV','finance_preferences'=>'Finanz-Vorauswahl & Kontenanzeige','mail'=>'E-Mail-Einstellungen','structure'=>'Vereinsstruktur','fields'=>'Individuelle Felder','paper'=>'Briefpapier','numbers'=>'Nummernkreise','texts_contributions'=>'Texte für Beiträge','texts_invoices'=>'Texte für Rechnungen','texts_donations'=>'Texte für Spenden','account'=>'Mein Account'];
    public const VARIABLES=[
      'VEREINSNAME'=>'Vereinsname','VORNAME'=>'Vorname','NACHNAME'=>'Nachname','ANREDE'=>'Anrede','TITEL'=>'Titel','BRIEFANREDE'=>'Briefanrede',
      'ZAHLUNG_FUER'=>'Zahlung für','ZAHLBETRAG'=>'Zahlbetrag','VEREINS_IBAN'=>'Vereins-IBAN','VEREINS_BIC'=>'Vereins-BIC','KONTOINHABER'=>'Kontoinhaber','GLAEUBIGER_ID'=>'Gläubiger-ID','PAYPAL_LINK'=>'PayPal.Me-Link','BEARBEITER'=>'Bearbeiter',
      'MITGLIEDSNUMMER'=>'Mitglieds-/Kontaktnummer','VERWENDUNGSZWECK'=>'Verwendungszweck (Rechnungsnummer)','RECHNUNGSNUMMER'=>'Rechnungsnummer','RECHNUNGSDATUM'=>'Rechnungsdatum','LIEFERDATUM'=>'Lieferdatum','DATUM'=>'Datum','FAELLIGKEIT'=>'Fälligkeit','ABRECHNUNGSZEITRAUM'=>'Abrechnungszeitraum','BEZEICHNUNG'=>'Bezeichnung','NOTIZ'=>'Notiz','ZAHLUNGSART'=>'Zahlungsart','MANDATSREFERENZ'=>'Mandatsreferenz','LASTSCHRIFT_DATUM'=>'Lastschriftdatum','MITGLIED_SEIT'=>'Mitglied seit','MITGLIED_BIS'=>'Mitglied bis'
    ];
    public static function fields(string $section):array
    {
        // label, input type, maximum bytes (or enum values), default, required
        return match($section){
         'club'=>[
          'name'=>['Vereinsname','text',180,'Mein Verein',true],'registered'=>['Eingetragener Verein','checkbox',1,true],
          'alternate'=>['Abweichende Bezeichnung','text',180,''],'type'=>['Art des Vereins','text',100,'Verein'],'category'=>['Vereinskategorie','text',100,''],'register'=>['Vereinsregisternummer','text',80,''],
          'street'=>['Straße & Hausnummer','text',180,''],'address2'=>['Zweite Adresszeile','text',180,''],'postcode'=>['PLZ','text',20,''],'city'=>['Ort','text',120,''],
          'phone'=>['Telefon','text',60,''],'email'=>['E-Mail','email',254,''],'website'=>['Website','url',300,''],
          'show_phone'=>['Telefon im Vereinsprofil anzeigen','checkbox',1,false],'show_email'=>['E-Mail im Vereinsprofil anzeigen','checkbox',1,false]
         ],
         'bank'=>['account_id'=>['Zugehöriges Finanzkonto','text',100,''],'holder'=>['Kontoinhaber','text',180,''],'creditor'=>['Gläubiger-ID','text',35,''],'iban'=>['IBAN','iban',50,''],'bic'=>['BIC','bic',11,''],'paypal'=>['PayPal.Me-Link','url',300,'']],
         'tax'=>['office'=>['Zuständiges Finanzamt','text',180,''],'number'=>['Steuernummer','text',80,''],'vat_id'=>['Umsatzsteuer-ID','text',20,''],'assessment'=>['Veranlagungszeitraum','text',100,''],'purpose'=>['Förderungszweck','textarea',2000,''],
          'notice_type'=>['Art des Bescheids','select',[''=>'Bitte wählen','exemption'=>'Freistellungsbescheid','corporate'=>'Anlage zum Körperschaftsteuerbescheid','assessment'=>'Feststellungsbescheid (§ 60a AO)'],''],
          'exemption_date'=>['Datum Freistellungsbescheid / Anlage zum Körperschaftsteuerbescheid','date',10,''],'assessment_date'=>['Datum Feststellungsbescheid','date',10,''],
          'assessment_no_prior_notice'=>['Nur § 60a: Bisher wurde kein Freistellungsbescheid und keine Anlage zum Körperschaftsteuerbescheid erteilt','checkbox',1,false],
          'notice_document_id'=>['Hinterlegter Steuerbescheid (PDF aus Dokumente)','text',100,''],'receipt_signatory'=>['Zeichnungsberechtigte Person für Zuwendungsbescheinigungen','text',180,''],'receipt_authority_reviewed'=>['Aktueller Bescheid und Zeichnungsberechtigung geprüft','checkbox',1,false],
          'membership_deductible'=>['Mitgliedsbeiträge laut steuerlicher Einordnung abziehbar','select',[''=>'Noch nicht geprüft','no'=>'Nein','yes'=>'Ja'],''],
          'accounting_basis'=>['Jahresabschlussart','select',['euer'=>'Einnahmenüberschussrechnung (EÜR)','balance'=>'Bilanzierung'],'euer'],
          'small_business'=>['Kleinunternehmerregelung','checkbox',1,false],
          'depreciation_enabled'=>['Abschreibungen auch bei EÜR hier verwalten','checkbox',1,false],
          'input_tax_eligibility'=>['Vorsteuerabzugsberechtigung des Vereins','select',[''=>'Noch nicht geprüft','no'=>'Nein – Verein ist nicht vorsteuerabzugsberechtigt','yes'=>'Ja – Verein ist vorsteuerabzugsberechtigt'],''],
          'input_tax_default'=>['Vorsteuerabzug: Vorauswahl für neue Ausgaben (je Position prüfbar)','select',InputTaxTreatment::OPTIONS,'unknown'],
          'electronic_receipts'=>['Anzeige des elektronischen Bescheinigungsverfahrens beim Finanzamt dokumentiert','checkbox',1,false],
          'receipt_declaration'=>['Erklärung zur maschinellen Bescheinigung','textarea',2000,''],'advisor'=>['DATEV-Beraternummer','text',20,''],'client'=>['DATEV-Mandantennummer','text',20,''],'fiscal_start'=>['Beginn Wirtschaftsjahr','date',10,'']],
         'mail'=>['sender_name'=>['Absendername','text',180,'Mein Verein'],'sender_email'=>['Absender-E-Mail','email',254,''],'reply_to'=>['Antwortadresse','email',254,'']],
         'account'=>['display_name'=>['Anzeigename','text',120,'Vereinsverwaltung'],'reply_email'=>['Persönliche Antwortadresse','email',254,''],'start_view'=>['Startansicht','select',['dashboard'=>'Dashboard','members'=>'Mitglieder','finance'=>'Finanzen','calendar'=>'Kalender'],'dashboard'],'page_size'=>['Zeilen pro Seite','select',['25'=>'25','40'=>'40','100'=>'100'],'25'],
          'admin_view'=>['Verwaltungsansicht','select',['studio'=>'Studio','focus'=>'Fokus','compact'=>'Kompakt'],'studio'],'admin_color'=>['Farbmodus','select',['system'=>'Wie auf diesem Gerät','light'=>'Hell','dark'=>'Dunkel'],'system'],'admin_density'=>['Abstände','select',['comfortable'=>'Großzügig','compact'=>'Kompakt'],'comfortable']],
         'paper'=>[
          'header_text'=>['Text in der Kopfzeile','textarea',450,'Mein Verein'],'header_font'=>['Schriftart','select',['sans'=>'Sans Serif','serif'=>'Serif'],'sans'],'header_size'=>['Schriftgröße (pt)','integer',[9,30],18],'header_color'=>['Textfarbe','color',7,'#102a4c'],
          'header_bold'=>['Fett','checkbox',1,true],'header_italic'=>['Kursiv','checkbox',1,false],'header_underline'=>['Unterstrichen','checkbox',1,false],
          'text_x'=>['Text: Abstand links (mm)','integer',[0,155],60],'text_y'=>['Text: Abstand oben (mm)','integer',[0,25],5],
          'logo_x'=>['Logo: Abstand links (mm)','integer',[0,155],0],'logo_y'=>['Logo: Abstand oben (mm)','integer',[0,25],0],'logo_width'=>['Logobreite (mm)','integer',[10,70],32],
          'sender_source'=>['Welche Absenderadresse verwenden?','select',['club'=>'Vereinsdaten verwenden','custom'=>'Abweichende Absenderadresse verwenden'],'club'],
          'sender_name'=>['Vereinsname in Absenderzeile','text',180,'Mein Verein'],'sender_street'=>['Straße & Hausnummer','text',180,''],'sender_address2'=>['Zweite Adresszeile','text',180,''],'sender_city'=>['PLZ & Ort','text',150,''],
          'show_contact'=>['Mitgl./Kontakt-Nr. anzeigen','checkbox',1,true],'show_delivery'=>['Lieferdatum anzeigen','checkbox',1,true],
          'footer_left'=>['Fußzeile links','textarea',650,"**Anschrift**\n{{VEREINSNAME}}"],'footer_middle'=>['Fußzeile Mitte','textarea',650,"**Kontakt**"],'footer_right'=>['Fußzeile rechts','textarea',650,"**Bankverbindung**\n{{KONTOINHABER}}\nIBAN: {{VEREINS_IBAN}}\nBIC: {{VEREINS_BIC}}"],
          'logo'=>['Logo','asset',32,''],'signature'=>['Unterschrift','asset',32,'']
         ], default=>[]
        };
    }
    public static function defaults(string $section):array
    {
        if(!isset(self::SECTIONS[$section]))throw new DomainException('Unbekannter Einstellungsbereich.');
        if($section===FinancePreferences::SECTION)return FinancePreferences::defaults();
        if(str_starts_with($section,'texts_'))return self::templates($section);
        if($section==='numbers'){ $r=[];foreach(['invoice'=>'RE-','expense'=>'AU-','member'=>'M-'] as $k=>$prefix)$r[$k]=['prefix'=>$prefix,'date'=>'year','width'=>6,'suffix'=>'','next'=>1];return $r; }
        if($section==='salutations')return ['mode'=>'personal','rows'=>[
          ['name'=>'Keine Angabe','personal'=>'Guten Tag,','formal'=>'Sehr geehrte Damen und Herren,'],['name'=>'Frau','personal'=>'Liebe {{VORNAME}},','formal'=>'Sehr geehrte Frau {{NACHNAME}},'],['name'=>'Herr','personal'=>'Lieber {{VORNAME}},','formal'=>'Sehr geehrter Herr {{NACHNAME}},'],['name'=>'Familie','personal'=>'Liebe Familie {{NACHNAME}},','formal'=>'Sehr geehrte Familie {{NACHNAME}},']], 'titles'=>"Dr.\nProf.\nProf. Dr.\nDipl.-Ing."];
        if(in_array($section,['structure','fields'],true))return ['rows'=>[]];
        return array_map(fn($f)=>$f[3],self::fields($section));
    }
    /** Display names only; persisted template keys and user-authored text stay unchanged. */
    public static function templateLabels(string $section):array
    {
        return match($section){
            'texts_contributions'=>['invoice'=>'Beitragsrechnung','reminder'=>'Beitragserinnerung','debit'=>'Beitrags-Lastschrift'],
            'texts_invoices'=>['invoice'=>'Rechnung','reminder'=>'Rechnungserinnerung','debit'=>'Lastschriftvorankündigung'],
            'texts_donations'=>['donation'=>'Spendenbegleitschreiben','collective'=>'Sammelspendenbegleitschreiben'],
            default=>throw new DomainException('Unbekannte Textbausteingruppe.')
        };
    }
    public static function templates(string $section):array
    {
        $donations=$section==='texts_donations';$contribution=$section==='texts_contributions';$result=[];
        foreach(self::templateLabels($section) as $key=>$label){
            $subject=$donations?($key==='collective'?'Eure Sammelzuwendungsbescheinigung':'Danke für eure Spende'):($key==='debit'?'Lastschriftvorankündigung':($key==='reminder'?'Erinnerung an eure Rechnung':'Eure Beitragsrechnung'));
            if(!$contribution&&!$donations&&$key==='invoice')$subject='Eure Rechnung';
            $message=$donations?'Vielen Dank für eure Unterstützung von {{VEREINSNAME}}. Die Zuwendung beträgt {{ZAHLBETRAG}}.':($key==='debit'?'Wir kündigen den Einzug von {{ZAHLBETRAG}} am {{LASTSCHRIFT_DATUM}} an. Mandat: {{MANDATSREFERENZ}}, Gläubiger-ID: {{GLAEUBIGER_ID}}.':($key==='reminder'?'Wir erinnern an den offenen Betrag von {{ZAHLBETRAG}}.':'Bitte überweist {{ZAHLBETRAG}} bis {{FAELLIGKEIT}} auf unser Vereinskonto.')."\nIBAN: {{VEREINS_IBAN}}\nBIC: {{VEREINS_BIC}}");
            $result[$key]=['subject'=>$subject,'message'=>"{{BRIEFANREDE}}\n\n$message\n\nFreundliche Grüße\n{{BEARBEITER}}\n{{VEREINSNAME}}",'intro'=>$key==='invoice'?'Für die folgenden Positionen berechnen wir:':'','closing'=>$key==='debit'?'Der Einzug von {{ZAHLBETRAG}} ist für {{LASTSCHRIFT_DATUM}} vorgesehen.':($donations?'':'Bitte überweist {{ZAHLBETRAG}} bis {{FAELLIGKEIT}}. Vielen Dank!'),'attach_pdf'=>false];
        }return $result;
    }
    public static function text(mixed $v,int $max,bool $required=false):string
    {
        if(!is_string($v)&&!is_int($v))throw new DomainException('Textfeld enthält ungültige Daten.');
        $s=trim(str_replace("\r\n","\n",(string)$v));
        if(strlen($s)>$max||!preg_match('//u',$s)||preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/',$s)||($required&&$s===''))throw new DomainException('Pflichtfeld oder zulässige Textlänge prüfen.');
        return $s;
    }
    public static function integer(mixed $v,int $min,int $max):int
    {
        if((!is_int($v)&&!is_string($v))||!preg_match('/^\d{1,10}$/D',(string)$v)||(int)$v<$min||(int)$v>$max)throw new DomainException("Zahl zwischen $min und $max erforderlich.");return (int)$v;
    }
    public static function choice(mixed $v,array $allowed):string{$v=self::text($v,100);if(!in_array($v,$allowed,true))throw new DomainException('Ungültige Auswahl.');return $v;}
    public static function date(string $v):string{$d=\DateTimeImmutable::createFromFormat('!Y-m-d',$v);if(!$d||$d->format('Y-m-d')!==$v)throw new DomainException('Ungültiges Datum.');return $v;}
    public static function formatted(mixed $v,int $max=6000):string
    {
        $s=self::text($v,$max);preg_match_all('/\{\{([^{}]+)\}\}/u',$s,$m);
        foreach($m[1] as $name)if(!isset(self::VARIABLES[$name]))throw new DomainException('Unbekannte Variable: '.$name);
        $without=preg_replace('/\{\{[^{}]+\}\}/u','',$s);if(str_contains($without,'{{')||str_contains($without,'}}'))throw new DomainException('Unvollständige Variable.');return $s;
    }
    public static function normalize(string $section,array $data):array
    {
        if(\Kulturhus\Verein\Settings\TextInput::supports($section))return \Kulturhus\Verein\Settings\TextInput::canonical($section,$data);
        if($section==='paper')return \Kulturhus\Verein\Settings\PaperInput::canonical($data);
        if($section==='numbers')return \Kulturhus\Verein\Settings\NumberingRules::normalize($data);
        if($section==='bank')return \Kulturhus\Verein\Settings\FinanceBankInput::canonical($data);
        if($section==='tax')return \Kulturhus\Verein\Settings\FinanceTaxInput::canonical($data);
        if(\Kulturhus\Verein\Settings\GeneralValues::supports($section))return \Kulturhus\Verein\Settings\GeneralValues::normalize($section,$data);
        $defaults=self::defaults($section);if($section===FinancePreferences::SECTION)return FinancePreferences::normalize($data);
        // Older installations posted this club switch. It is intentionally ignored now:
        // handkasses are a permanent bookkeeping option and must not disappear on save.
        if($section==='club')unset($data['cash_enabled']);
        if(array_diff(array_keys($data),array_keys($defaults)))throw new DomainException('Unerwartetes Einstellungsfeld.');$out=[];
        if(str_starts_with($section,'texts_')){foreach($defaults as $key=>$template){$row=$data[$key]??null;if(!is_array($row)||array_diff(array_keys($row),array_keys($template)))throw new DomainException('Textbaustein unvollständig.');foreach($template as $field=>$v)$out[$key][$field]=$field==='attach_pdf'?self::bool($row[$field]??false):self::formatted($row[$field]??'',$field==='subject'?200:6000);}return $out;}
        if($section==='numbers'){foreach($defaults as $key=>$v){$row=$data[$key]??null;if(!is_array($row)||array_diff(array_keys($row),array_keys($v)))throw new DomainException('Nummernkreis unvollständig.');$out[$key]=['prefix'=>self::text($row['prefix']??'',30),'date'=>self::choice($row['date']??'',['none','year','month']),'width'=>self::integer($row['width']??'',1,9),'suffix'=>self::text($row['suffix']??'',20),'next'=>self::integer($row['next']??'',1,999999999)];foreach(['prefix','suffix'] as $f)if(!preg_match('/^[\pL\pN ._\/-]*$/uD',$out[$key][$f]))throw new DomainException('Präfix/Suffix enthält unzulässige Zeichen.');}return $out;}
        if($section==='salutations'){
            $out=['mode'=>self::choice($data['mode']??'',['personal','formal']),'titles'=>self::text($data['titles']??'',2000),'rows'=>[]];
            foreach(self::rows($data['rows']??[],30) as $row){$name=self::text($row['name']??'',60);if($name==='')continue;$out['rows'][]=['name'=>$name,'personal'=>self::formatted($row['personal']??'',300),'formal'=>self::formatted($row['formal']??'',300)];}if(!$out['rows'])throw new DomainException('Mindestens eine Briefanrede ist erforderlich.');return $out;
        }
        if(in_array($section,['structure','fields'],true)){
            $out=['rows'=>[]];$names=[];foreach(self::rows($data['rows']??[],50) as $row){$name=self::text($row['name']??'',100);if($name==='')continue;if(isset($names[$name]))throw new DomainException('Bezeichnung doppelt.');$names[$name]=true;
            $out['rows'][]=$section==='structure'?['name'=>$name,'parent'=>self::text($row['parent']??'',100)]:['name'=>$name,'type'=>self::choice($row['type']??'text',['text','number','date','checkbox','select']),'options'=>self::text($row['options']??'',1000),'required'=>self::bool($row['required']??false)];}
            if($section==='fields'){foreach($out['rows'] as $i=>&$field){$source=array_values(array_filter($data['rows']??[],fn($r)=>is_array($r)&&trim((string)($r['name']??''))!==''))[$i];$field['id']=trim((string)($source['id']??''));if($field['id']==='')$field['id']=array_key_exists('id',$source)?bin2hex(random_bytes(16)):MemberCustomFields::id($field);if(!preg_match('/^[a-f0-9]{32}$/D',$field['id']))throw new DomainException('Feldkennung prüfen.');if($field['type']==='select'&&!MemberCustomFields::options($field))throw new DomainException($field['name'].': Mindestens einen Auswahlwert eintragen.');}unset($field);if(count(array_unique(array_column($out['rows'],'id')))!==count($out['rows']))throw new DomainException('Feldkennung doppelt.');}
            if($section==='structure')foreach($out['rows'] as $row){$seen=[$row['name']];$parent=$row['parent'];while($parent!==''){if(in_array($parent,$seen,true)||!isset($names[$parent]))throw new DomainException('Übergeordnete Abteilung fehlt oder erzeugt einen Kreis.');$seen[]=$parent;$parent=array_values(array_filter($out['rows'],fn($r)=>$r['name']===$parent))[0]['parent'];}}
            return $out;
        }
        foreach(self::fields($section) as $key=>$field){[$label,$type,$limit,$default]=$field;$value=$data[$key]??($type==='checkbox'?false:$default);
            try{
              $v=match($type){'checkbox'=>self::bool($value),'integer'=>self::integer($value,...$limit),'select'=>self::choice($value,array_map('strval',array_keys($limit))),default=>self::text($value,$limit,$field[4]??false)};
              if($v!==''&&$type==='email'&&!filter_var($v,FILTER_VALIDATE_EMAIL))throw new DomainException('E-Mail-Adresse prüfen.');
              if($v!==''&&$type==='url'&&(!filter_var($v,FILTER_VALIDATE_URL)||strtolower((string)parse_url($v,PHP_URL_SCHEME))!=='https'||parse_url($v,PHP_URL_USER)||parse_url($v,PHP_URL_PASS)))throw new DomainException('HTTPS-Adresse ohne Zugangsdaten erforderlich.');
              if($v!==''&&$type==='date')self::date($v);
              if($type==='color'&&!preg_match('/^#[0-9a-fA-F]{6}$/D',$v))throw new DomainException('Farbe prüfen.');
              if($type==='asset'&&$v!==''&&!preg_match('/^[a-f0-9]{32}$/D',$v))throw new DomainException('Ungültiges Bild.');
              if($type==='iban')$v=Iban::normalize($v);
              if($type==='bic'&&$v!==''){$v=strtoupper($v);if(!preg_match('/^[A-Z]{6}[A-Z0-9]{2}(?:[A-Z0-9]{3})?$/D',$v))throw new DomainException('BIC prüfen.');}
              if($section==='paper'&&str_starts_with($key,'footer_'))$v=self::formatted($v,$limit);
              $out[$key]=$v;
            }catch(DomainException $e){throw new DomainException($label.': '.$e->getMessage());}
        }
        if($section==='tax'&&($out['input_tax_eligibility']??'')==='no')$out['input_tax_default']='none';
        return $out;
    }
    /** Display an unsuccessful form submission without restoring old switches or nested values. Never used for persistence. */
    public static function formValues(string $section,array $data,array $saved):array
    {
        $defaults=self::defaults($section);
        if($section===FinancePreferences::SECTION)return FinancePreferences::formValues($data,$saved);
        $scalar=static fn(mixed $value,mixed $fallback=''):mixed=>is_string($value)||is_int($value)?$value:$fallback;
        $row=static function(array $posted,array $shape)use($scalar):array{
            $out=[];foreach($shape as $key=>$fallback)$out[$key]=is_bool($fallback)?in_array($posted[$key]??false,[true,1,'1'],true):$scalar($posted[$key]??$fallback,$fallback);return $out;
        };
        if(str_starts_with($section,'texts_')||$section==='numbers'){
            $out=[];foreach($defaults as $key=>$shape)$out[$key]=$row(is_array($data[$key]??null)?$data[$key]:[],$shape);return $out;
        }
        if(in_array($section,['salutations','structure','fields'],true)){
            $shape=match($section){'salutations'=>['name'=>'','personal'=>'','formal'=>''],'structure'=>['name'=>'','parent'=>''],default=>['id'=>'','name'=>'','type'=>'text','options'=>'','required'=>false]};
            $out=['rows'=>[]];foreach(array_slice(is_array($data['rows']??null)?$data['rows']:[],0,$section==='salutations'?30:50) as $posted)if(is_array($posted))$out['rows'][]=$row($posted,$shape);
            if($section==='salutations'){$out['mode']=$scalar($data['mode']??'personal','personal');$out['titles']=$scalar($data['titles']??'');}return $out;
        }
        $out=$saved;foreach(self::fields($section) as $key=>$field){
            if($field[1]==='asset')continue;
            $out[$key]=$field[1]==='checkbox'?in_array($data[$key]??false,[true,1,'1'],true):$scalar($data[$key]??($saved[$key]??$field[3]),$saved[$key]??$field[3]);
        }return $out;
    }
    public static function bool(mixed $v):bool{if(!in_array($v,[true,false,1,0,'1','0'],true))throw new DomainException('Ungültiger Schalter.');return in_array($v,[true,1,'1'],true);}
    public static function rows(mixed $v,int $limit):array{if(!is_array($v)||count($v)>$limit||count(array_filter($v,'is_array'))!==count($v))throw new DomainException('Zu viele oder ungültige Zeilen.');return array_values($v);}
    public static function interpolate(string $text,array $values):string{return preg_replace_callback('/\{\{([A-Z_]+)\}\}/',fn($m)=>(string)($values[$m[1]]??'['.(self::VARIABLES[$m[1]]??$m[1]).' fehlt]'),$text);}
    public static function html(string $text):string{
        $s=htmlspecialchars($text,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');$s=preg_replace('/\*\*([^*\n]+)\*\*/u','<strong>$1</strong>',$s);$s=preg_replace('/__([^_\n]+)__/u','<u>$1</u>',$s);$s=preg_replace('/(?<!\*)\*([^*\n]+)\*(?!\*)/u','<em>$1</em>',$s);return nl2br($s);
    }
}
