<?php declare(strict_types=1);
namespace Kulturhus\Verein;

/** Presentation only. Never changes answers, filing scope or approval checks. */
final class TaxUsability
{
    public static function scopeGroups():array
    {
        return [
            'club'=>['Verein und Jahresabschluss',['nonprofit','accounting']],
            'activity'=>['Was ist im Jahr passiert?',['economic','employees','mini_jobs','foreign_services','eu_sales','foreign_artists','new_activity']],
            'office'=>['Angaben aus Finanzamt und Buchhaltung',['kst_due','profit_return','vat','vat_requested','vat_advance']],
            'confirm'=>['Bescheide geprüft?',['notices_checked']],
        ];
    }
    public static function scopeHelp(string $key):string
    {
        return match($key){
            'accounting'=>'Die Abschlussart aus dem bisherigen Jahresabschluss übernehmen. Bei Unsicherheit „Weiß ich nicht – prüfen lassen“ wählen.',
            'nonprofit'=>'Im Freistellungsbescheid oder Körperschaftsteuerbescheid nachsehen.',
            'profit_return'=>'Diese Angabe aus dem Bescheid oder mit der Steuerberatung klären. Einnahmen aus Veranstaltungen allein entscheiden das nicht.',
            'vat'=>'Die geltende Regelung aus den Steuerunterlagen übernehmen. Kein Vorsteuerabzug bedeutet nicht automatisch Steuerfreiheit.',
            'employees'=>'Gemeint sind Beschäftigte, für die Lohnsteuer nach persönlichen Steuermerkmalen abgerechnet wurde. Pauschal versteuerte Minijobs folgen in der nächsten Frage.',
            'notices_checked'=>'Prüfen, welche Jahre und Unterlagen das Finanzamt verlangt.',
            default=>'',
        };
    }
    public static function scopeLabel(string $key):string
    {
        return match($key){
            'profit_return'=>'Ist laut Finanzamt oder Steuerberatung eine eigene steuerliche Gewinnermittlung nötig?',
            'kst_due'=>'Ist die Körperschaftsteuer- oder Gemeinnützigkeitserklärung für diesen Zeitraum fällig?',
            'employees'=>'Gab es Beschäftigte mit persönlicher Lohnsteuerabrechnung?',
            'mini_jobs'=>'Gab es Minijobs mit pauschaler Steuer?',
            'vat_advance'=>'Muss der Verein für diesen Zeitraum Umsatzsteuer-Voranmeldungen abgeben?',
            default=>TaxFilingScope::questions()[$key]['label'],
        };
    }
    public static function autoGroups():array
    {
        return [
            'data'=>['Vereinsdaten und Unterlagen',['tax_number','seat','management','statute_date','statute_filed','dues_date','dues_filed']],
            'tax'=>['Steuerliche Angaben prüfen lassen',['purpose','fully_exempt','domestic']],
            'review'=>['Ergebnis bestätigen',['source_reviewed','rounding']],
        ];
    }
    /** Group and deduplicate messages for display; original blocking issues remain untouched. */
    public static function issueGroups(array $issues):array
    {
        $groups=[];
        foreach($issues as $raw){
            $text=trim((string)$raw);$target='tax-step-review';$title='Weitere Prüfung';$id='other';
            $canonical=preg_replace('/^Steuerprofil prüfen: /u','',$text);
            $canonical=preg_replace('/ – Noch zu klären\.$/u','',$canonical);
            foreach(TaxFilingScope::questions() as $key=>$q)if(str_contains($text,$q['label'])){
                $id='profile';$title='Verein und Steuerangaben';$target='tax-scope-'.$key;$canonical=$q['label'];break;
            }
            if($id==='other')foreach(TaxAutoMapping2025::questions() as $key=>[$label])if(str_contains($text,$label)||($key==='tax_number'&&str_contains($text,'13-stellige ELSTER-Steuernummer'))){
                $id='data';$title='Vereinsdaten und Bestätigungen';$target='tax-auto-'.$key;$canonical=$key==='tax_number'?$label:$text;break;
            }
            if($id==='other')foreach(TaxDeclarationGuide::questions() as $key=>$q)if(str_contains($text,$q['question'])){
                $id='activity';$title='Vereinsarbeit und Nachweise';$target='tax-guide-'.$key;break;
            }
            if($id==='other'&&preg_match('/^(20\d{2}):/u',$text,$match)){
                $id='records';$title='Buchführung und Jahreswerte';$target='tax-notes-'.$match[1];
            }
            if($id==='other'&&preg_match('/Vorleistung|Sachspenden|Nachweise|Tätigkeitsbericht|Rücklagen/u',$text)){
                $id='records';$title='Buchführung und Nachweise';$target='tax-step-records';
            }
            $groups[$id]??=['title'=>$title,'items'=>[]];
            $groups[$id]['items'][$canonical]=['text'=>$canonical,'target'=>$target];
        }
        return array_values($groups);
    }
}
