<?php declare(strict_types=1);
namespace Kulturhus\Verein\Documents;

/** Ordinary GET navigation, including jump-to-page without an unbounded list of page options. */
final class WorkflowPager
{
    public static function render(?array $page,string $parameter,array $query,callable $text,?string $labelOverride=null):string
    {
        if($page===null)return '';
        $escape=static fn(mixed $value):string=>htmlspecialchars((string)$value,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
        $format=static function(string $source,array $values)use($text):string{$arguments=[];foreach($values as$key=>$value)$arguments['{'.$key.'}']=(string)$value;return strtr($text($source),$arguments);};
        $href=static fn(int $number):string=>'?'.http_build_query(array_replace($query,[$parameter=>$number]),'','&',PHP_QUERY_RFC3986);
        $label=['voucher_event_page'=>'Persönliche Veranstaltungsseiten','voucher_cards_page'=>'Persönliche Gutscheinkartenseiten','voucher_history_page'=>'Persönliche Gutscheinverlaufsseiten','volunteer_person_page'=>'Personenseiten','personal_invoice_page'=>'Persönliche Rechnungsseiten','personal_shared_page'=>'Persönliche Freigabeseiten','personal_voucher_page'=>'Persönliche Gutscheinseiten','check_page'=>'Nachweisseiten','check_receipts_page'=>'Nachweis-Anlegevorgangsseiten','wf_signed_files_page'=>'Eigene Beurteilungsseiten','wf_signatures_page'=>'Persönliche Unterschriftsseiten','wf_document_signatures_page'=>'Unterschriftsseiten','wf_signature_members_page'=>'Personenauswahlseiten','wf_reminders_page'=>'Versandseiten','wf_preview_recipients_page'=>'Empfängerseiten','wf_requests_page'=>'Anlegevorgangsseiten','wf_page'=>'Dokumentseiten','wf_templates_page'=>'Vorlagenseiten','wf_versions_page'=>'Versionsseiten','wf_activities_page'=>'Aktivitätsseiten','wf_publications_page'=>'Veröffentlichungsseiten'][$parameter]??'Listenseiten';
        if($labelOverride!==null)$label=$labelOverride;
        $html='<nav class="dwf-pagination" aria-label="'.$escape($text($label)).'"><p role="status">'.$escape($format('{count} Einträge · Seite {page} von {pages}',['count'=>$page['total'],'page'=>$page['page'],'pages'=>$page['pages']])).'</p><div class="dwf-actions">';
        if($page['page']>1)$html.='<a class="btn btn-outline theme-secondary dwf-button dwf-secondary" href="'.$escape($href($page['page']-1)).'">'.$escape($text('Vorherige Seite')).'</a>';
        if($page['page']<$page['pages'])$html.='<a class="btn btn-outline theme-secondary dwf-button dwf-secondary" href="'.$escape($href($page['page']+1)).'">'.$escape($text('Nächste Seite')).'</a>';
        if($page['pages']>1){
            $html.='<form method="get" class="dwf-page-jump">';foreach($query as$key=>$value)if($key!==$parameter&&is_scalar($value))$html.='<input type="hidden" name="'.$escape($key).'" value="'.$escape($value).'">';
            $html.='<label>'.$escape($text('Seite')).'<input class="form-control" type="number" name="'.$escape($parameter).'" min="1" max="'.$escape($page['pages']).'" value="'.$escape($page['page']).'" required></label><button class="btn btn-outline theme-secondary dwf-button">'.$escape($text('Seite öffnen')).'</button></form>';
        }
        return $html.'</div></nav>';
    }
}
