<?php
declare(strict_types=1);

namespace Kulturhus\Verein\DocumentWorkflow;

require_once __DIR__ . '/RiskMatrix.php';
require_once __DIR__ . '/RiskProfiles.php';
require_once __DIR__ . '/WorkflowDeadlines.php';

/** A repeatable table of assessed hazards, independent of any CMS or proprietary document. */
final class RiskRegister
{
    public const MAX_ROWS = 30;
    public const TEXT_LIMITS = ['category' => 200, 'hazard' => 2000, 'impact' => 2000, 'controls' => 6000, 'responsible' => 200, 'effectiveness' => 2000];
    public const COLUMN_LABELS = ['category' => 'Kategorie', 'hazard' => 'Gefährdung', 'impact' => 'Mögliche Folgen', 'initial' => 'Ausgangsbewertung', 'controls' => 'Maßnahmen', 'residual' => 'Restbewertung', 'responsible' => 'Verantwortlich', 'effectiveness' => 'Wirksamkeitsprüfung'];
    public const DATE_LABELS = ['action_deadline' => 'Maßnahmenfrist', 'action_completed_on' => 'Erledigt am'];
    public const CSS = '.dwf-register-document{max-width:1400px}.dwf-register-header h1{font-size:18px;margin:0 0 3px}.dwf-register-header .meta{font-size:9px;margin:0 0 6px;line-height:1.2}.dwf-register-intro{display:grid;grid-template-columns:minmax(0,38fr) minmax(0,62fr);gap:7px;align-items:start;break-inside:avoid}.dwf-register-intro.dwf-register-no-profile{display:block}.dwf-register-meta{border-collapse:collapse;width:100%;font-size:8px;line-height:1.2;table-layout:fixed}.dwf-register-meta th,.dwf-register-meta td{border:1px solid #a4afa4;padding:3px;vertical-align:top;overflow-wrap:anywhere}.dwf-register-meta th{width:20%;text-align:left;font-weight:600;background:#f1f4f1}.dwf-register-meta td{width:30%;white-space:pre-wrap}.dwf-register-meta small{display:block;font-size:8px;font-weight:400;color:#465346}.dwf-register-section{break-inside:auto;padding:6px 0}.dwf-register-section h2{font-size:12px;margin:0}.dwf-register-section .help{font-size:9px;line-height:1.2;margin:3px 0}.dwf-register-evidence{font-size:10px;line-height:1.3}.dwf-register-evidence h2{font-size:13px}.dwf-register-scroll{overflow-x:auto}.dwf-register-table{width:100%;table-layout:fixed;border-collapse:collapse;font-size:10px;line-height:1.25;margin:5px 0}.dwf-register-table caption{text-align:left;font-weight:600;padding:0 0 3px}.dwf-register-table th,.dwf-register-table td{border:1px solid #778477;padding:3px;vertical-align:top;overflow-wrap:anywhere;white-space:pre-wrap}.dwf-register-table thead th{text-align:center;font-weight:700;background:#f1f4f1}.dwf-register-table tbody th{text-align:left;font-weight:400}.dwf-register-table .dwf-register-score{text-align:center;white-space:normal}.dwf-register-table thead{display:table-header-group}.dwf-register-table tr{break-inside:avoid}@media print{body.dwf-register-document{font-size:10px;padding:0}.dwf-register-scroll{overflow:visible}.dwf-register-evidence{break-before:page}}';
    public const LANDSCAPE_CSS = '@page{size:A4 landscape;margin:12mm}';

    /** Rows may be incomplete in a draft, but provided risk objects must always be fully valid. */
    public static function validate(mixed $rows, bool $submit = false, bool $required = false): array
    {
        if (!is_array($rows) || !array_is_list($rows) || count($rows) > self::MAX_ROWS) {
            throw new WorkflowException('Ein Gefährdungsregister benötigt eine geordnete Liste mit höchstens 30 Zeilen.');
        }
        if ($submit && $required && $rows === []) {
            throw new WorkflowException('Das erforderliche Gefährdungsregister benötigt mindestens eine vollständige Zeile.');
        }
        $result = [];
        $keys = array_keys(self::COLUMN_LABELS);
        foreach ($rows as $index => $row) {
            if (!is_array($row) || array_diff($keys, array_keys($row)) !== [] || array_diff(array_keys($row), [...$keys, ...array_keys(self::DATE_LABELS)]) !== []) {
                throw new WorkflowException('Registerzeile ' . ($index + 1) . ' muss die sechs Textspalten sowie Ausgangs- und Restbewertung enthalten; zusätzlich sind Maßnahmenfrist und Erledigungsdatum möglich.');
            }
            $item = [];
            foreach ($keys as $key) {
                $label = 'Registerzeile ' . ($index + 1) . ': ' . self::COLUMN_LABELS[$key];
                if ($key === 'initial' || $key === 'residual') {
                    if ($row[$key] === null) {
                        if ($submit) {
                            throw new WorkflowException($label . ' muss vor dem Einreichen vollständig bewertet sein.');
                        }
                        $item[$key] = null;
                    } else {
                        try {
                            $item[$key] = RiskMatrix::validate($row[$key]);
                        } catch (WorkflowException $exception) {
                            throw new WorkflowException($label . ': ' . $exception->getMessage(), 0, $exception);
                        }
                    }
                    continue;
                }
                $max = self::TEXT_LIMITS[$key];
                $value = $row[$key];
                if (!is_string($value) || preg_match('//u', $value) !== 1 || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', $value) || preg_match('/\A.{0,' . $max . '}\z/usD', $value) !== 1 || preg_match('/<\s*(?:\/?[a-zA-Z][^>]*>|!|\?)/u', $value)) {
                    throw new WorkflowException($label . ' muss reiner Text mit höchstens ' . $max . ' Zeichen sein.');
                }
                $item[$key] = trim($value);
                if ($submit && $item[$key] === '') {
                    throw new WorkflowException($label . ' darf beim Einreichen nicht leer sein.');
                }
            }
            foreach (self::DATE_LABELS as $key => $label) {
                if (!array_key_exists($key, $row)) continue;
                if ($row[$key] !== '' && !WorkflowDeadlines::validDate($row[$key])) {
                    throw new WorkflowException('Registerzeile ' . ($index + 1) . ': ' . $label . ' muss ein gültiges Datum im Format JJJJ-MM-TT sein.');
                }
                $item[$key] = $row[$key];
            }
            $result[] = $item;
        }
        return $result;
    }

    /** Returned entries retain their row positions, including empty draft assessments. */
    public static function calculate(array $rows, string $profile = ''): array
    {
        RiskProfiles::get($profile);
        $rows = self::validate($rows);
        return array_map(static fn(array $row): array => [
            'initial' => $row['initial'] === null ? null : RiskProfiles::calculate($row['initial'], $profile),
            'residual' => $row['residual'] === null ? null : RiskProfiles::calculate($row['residual'], $profile),
        ], $rows);
    }

    /** Original semantic table layout, with neutral severity letters and no acceptance thresholds. */
    public static function renderHtml(array $rows, string $profile = ''): string
    {
        $profile=$profile!==''?$profile:RiskProfiles::RWE_DE;
        RiskProfiles::get($profile);
        $rows = self::validate($rows);
        $e = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $explanation = $profile === '' ? '<p class="help">S = Schadensschwere, A–E entsprechen den Stufen 1–5. L = Wahrscheinlichkeit, Stufen 1–5. R = S × L (Produkt der beiden Zahlenwerte). ' . RiskMatrix::NOTE . '</p>' : '';
        $html = '<div class="dwf-register">' . $explanation . '<div class="dwf-register-scroll"><table class="dwf-register-table"><caption>Gefährdungsregister mit Ausgangs- und Restbewertung</caption><colgroup>';
        foreach ([8, 12, 10, 3, 3, 4, 18, 3, 3, 4, 10, 12] as $width) {
            $html .= '<col style="width:' . $width . '%">';
        }
        $html .= '</colgroup><thead><tr><th rowspan="2" scope="col">Kategorie</th><th rowspan="2" scope="col">Gefährdung</th><th rowspan="2" scope="col">Mögliche Folgen</th><th colspan="3" scope="colgroup">Ausgangsbewertung</th><th rowspan="2" scope="col">Maßnahmen</th><th colspan="3" scope="colgroup">Restbewertung</th><th rowspan="2" scope="col">Verantwortlich</th><th rowspan="2" scope="col">Wirksamkeitsprüfung</th></tr><tr><th scope="col"><abbr title="Schadensschwere">S</abbr></th><th scope="col"><abbr title="Wahrscheinlichkeit">L</abbr></th><th scope="col"><abbr title="Rechnerisches Produkt">R</abbr></th><th scope="col"><abbr title="Schadensschwere">S</abbr></th><th scope="col"><abbr title="Wahrscheinlichkeit">L</abbr></th><th scope="col"><abbr title="Rechnerisches Produkt">R</abbr></th></tr></thead><tbody>';
        if ($rows === []) {
            $html .= '<tr><td colspan="12">Noch keine Gefährdungen erfasst.</td></tr>';
        }
        foreach ($rows as $row) {
            $html .= '<tr><th scope="row">' . $e($row['category']) . '</th><td>' . $e($row['hazard']) . '</td><td>' . $e($row['impact']) . '</td>';
            $measures = $row['controls'];
            foreach (self::DATE_LABELS as $key => $label) {
                if (($row[$key] ?? '') !== '') $measures .= "\n" . $label . ': ' . $row[$key];
            }
            $html .= self::riskCells($row['initial'], $profile) . '<td>' . $e($measures) . '</td>' . self::riskCells($row['residual'], $profile);
            $html .= '<td>' . $e($row['responsible']) . '</td><td>' . $e($row['effectiveness']) . '</td></tr>';
        }
        return $html . '</tbody></table></div></div>';
    }

    private static function riskCells(?array $risk, string $profile): string
    {
        if ($risk === null) {
            return '<td class="dwf-register-score">—</td><td class="dwf-register-score">—</td><td class="dwf-register-score">—</td>';
        }
        $calculation = RiskProfiles::calculate($risk, $profile);
        $band = RiskProfiles::bandFor($calculation['score'], $profile);
        $scoreCell = $band === null
            ? '<td class="dwf-register-score">' . $calculation['score'] . '</td>'
            : '<td class="dwf-register-score dwf-risk-reference-cell" data-risk-band="' . $band['key'] . '" style="background:' . $band['background'] . ';color:' . $band['color'] . '"><strong>' . $calculation['score'] . '</strong><br>' . $band['label'] . '</td>';
        return '<td class="dwf-register-score">' . chr(64 + $calculation['severity']) . '</td><td class="dwf-register-score">' . $calculation['likelihood'] . '</td>' . $scoreCell;
    }
}
