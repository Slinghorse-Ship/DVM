<?php
declare(strict_types=1);

namespace Kulturhus\Verein\DocumentWorkflow;

require_once __DIR__ . '/WorkflowException.php';

/** Validated arithmetic and the visible reference matrix; workflow approval stays separate. */
final class RiskMatrix
{
    public const MIN = 1;
    public const MAX = 5;
    public const NOTE = 'R = S × L. Grün: 1–4, Gelb: 5–9, Rot: 10–25. Die Freigabe erfolgt gesondert.';
    public const CSS = '.dwf-risk-scroll{overflow-x:auto}.dwf-risk-table{border-collapse:collapse;width:100%;margin:12px 0;font-size:13px}.dwf-risk-table caption{text-align:left;font-weight:600;padding:0 0 8px}.dwf-risk-table th,.dwf-risk-table td{border:1px solid #778477;padding:8px;text-align:center}.dwf-risk-table th{font-weight:600}.dwf-risk-table .dwf-risk-selected{border:3px solid #202820;font-weight:700}.dwf-risk-selected small{display:block;font-size:11px}';

    /** Client-calculated scores and every other extra key are forbidden. */
    public static function validate(mixed $value): array
    {
        if (!is_array($value) || count($value) !== 2 || !array_key_exists('likelihood', $value) || !array_key_exists('severity', $value)) {
            throw new WorkflowException('Eine Risikomatrix benötigt genau Wahrscheinlichkeit und Schadensschwere als ganze Stufen von 1 bis 5.');
        }
        foreach (['likelihood', 'severity'] as $key) {
            if (!is_int($value[$key]) || $value[$key] < self::MIN || $value[$key] > self::MAX) {
                throw new WorkflowException('Wahrscheinlichkeit und Schadensschwere müssen jeweils ganze Stufen von 1 bis 5 sein.');
            }
        }
        return ['likelihood' => $value['likelihood'], 'severity' => $value['severity']];
    }

    /** The product is derived on demand; it is never persisted as a client-supplied value. */
    public static function calculate(array $value): array
    {
        $value = self::validate($value);
        return $value + ['score' => $value['likelihood'] * $value['severity']];
    }

    public static function referenceCss():string{return file_get_contents(dirname(__DIR__,2).'/public/risk-matrix.css');}

    public static function band(int $score):array
    {
        require_once __DIR__.'/RiskProfiles.php';
        return RiskProfiles::bandFor($score,RiskProfiles::RWE_DE);
    }

    /** The same coloured axes and score ranges as the supplied reference form. */
    public static function renderHtml(?array $value): string
    {
        $calculation=$value===null?null:self::calculate($value);
        $summary=$calculation===null?'Bewertung noch offen.':'Schadensschwere '.chr(64+$calculation['severity']).' × Wahrscheinlichkeit '.$calculation['likelihood'].' = Risiko '.$calculation['score'].'.';
        $html='<div class="dwf-risk"><p>'.$summary.'</p><p class="help">'.self::NOTE.'</p><div class="dwf-risk-scroll"><table class="dwf-risk-table"><caption>Risikomatrix · S × L</caption><thead><tr><th scope="col">S / L</th>';
        for($l=1;$l<=5;$l++)$html.='<th scope="col">'.$l.'</th>';
        $html.='</tr></thead><tbody>';
        for($s=5;$s>=1;$s--){$html.='<tr><th scope="row">'.chr(64+$s).'</th>';for($l=1;$l<=5;$l++){$selected=$calculation!==null&&$calculation['likelihood']===$l&&$calculation['severity']===$s;$band=self::band($l*$s);$html.='<td'.($selected?' class="dwf-risk-selected"':'').' style="background:'.$band['background'].'!important;color:#000!important"><strong>'.($l*$s).'</strong>'.($selected?'<small>ausgewählt</small>':'').'</td>';}$html.='</tr>';}
        return$html.'</tbody></table></div></div>';
    }
}
