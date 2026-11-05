<?php
declare(strict_types=1);

namespace Kulturhus\Verein\DocumentWorkflow;

require_once __DIR__ . '/RiskMatrix.php';

/** Versioned, closed configuration. Change a profile by adding a new ID, never by redefining an existing ID. */
final class RiskProfiles
{
    public const REFERENCE = 'fluix_reference_v1';
    public const RWE_DE = 'rwe_reference_de_v1';
    public const MINI_CSS = '.dwf-risk-composite-mini{grid-template-columns:minmax(0,20fr) minmax(0,28fr) minmax(0,28fr) minmax(0,24fr)}.dwf-risk-composite-mini .dwf-risk-composite-table{align-self:start}.dwf-risk-composite-mini .dwf-risk-composite-table th,.dwf-risk-composite-mini .dwf-risk-composite-table td{padding:2px;height:15px}.dwf-risk-axis-definitions{font-size:8px;line-height:1.15}.dwf-risk-axis-definitions p{margin:3px 0;font-size:8px;line-height:1.15}.dwf-risk-axis-definitions b{font-size:8px}';
    public const CSS = '.dwf-risk-compact{min-width:0;break-inside:avoid}.dwf-risk-composite-title{font-size:9px;font-weight:700;line-height:1.2;margin:0 0 3px}.dwf-risk-composite{display:grid;grid-template-columns:minmax(0,78fr) minmax(0,22fr);gap:5px;align-items:start}.dwf-risk-composite-table,.dwf-risk-composite-bands{border-collapse:collapse;width:100%;table-layout:fixed;font-size:8px;line-height:1.15}.dwf-risk-composite-table th,.dwf-risk-composite-table td,.dwf-risk-composite-bands th,.dwf-risk-composite-bands td{border:1px solid #869386;padding:3px;overflow-wrap:break-word;vertical-align:middle}.dwf-risk-composite-table thead th{text-align:center;vertical-align:bottom;font-weight:400}.dwf-risk-composite-table thead th:first-child{text-align:left}.dwf-risk-composite-table tbody th{text-align:left;font-weight:400}.dwf-risk-composite-table td{text-align:center;font-weight:700;font-size:9px}.dwf-risk-composite-table .dwf-risk-definition{display:block;font-weight:400;margin-top:2px}.dwf-risk-composite-bands th{text-align:left;font-weight:700}.dwf-risk-composite-bands td{vertical-align:top}.dwf-risk-composite-band-label{display:block;font-weight:700;margin-bottom:3px}.dwf-risk-reference-cell{-webkit-print-color-adjust:exact;print-color-adjust:exact}';

    /** JSON-safe configuration for shared server and browser rendering. Empty ID deliberately means neutral. */
    public static function get(string $profile = ''): array
    {
        $configuration = self::configuration($profile);
        $configuration['cells'] = [];
        foreach ($configuration['severity'] as $severity) {
            $cells = [];
            foreach ($configuration['likelihood'] as $likelihood) {
                $cells[] = self::decorate(['likelihood' => $likelihood['value'], 'severity' => $severity['value'], 'score' => $likelihood['value'] * $severity['value']], $configuration);
            }
            $configuration['cells'][] = $cells;
        }
        return $configuration;
    }

    /** Neutral results remain exactly the legacy three integers. A named profile adds only derived classification. */
    public static function calculate(array $value, string $profile = ''): array
    {
        return self::decorate(RiskMatrix::calculate($value), self::configuration($profile));
    }

    public static function renderLegend(string $profile): string
    {
        return self::renderCompactLegend($profile);
    }

    /** Complete composite legend: descriptions align with the matrix axes, without a second disclosure block. */
    public static function renderCompactLegend(string $profile): string
    {
        if ($profile === self::RWE_DE) return self::renderRweLegend();
        if ($profile === '') {
            return '';
        }
        $configuration = self::get($profile);
        $e = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $html = '<aside class="dwf-risk-compact"><p class="dwf-risk-composite-title">R = S × L (Produkt der beiden Zahlenwerte). S: A–E = 1–5; L: 1–5.</p><div class="dwf-risk-composite dwf-risk-composite-mini"><table class="dwf-risk-composite-table"><thead><tr><th scope="col">S / L</th>';
        foreach ($configuration['likelihood'] as $likelihood) {
            $html .= '<th scope="col">' . $likelihood['value'] . '</th>';
        }
        $html .= '</tr></thead><tbody>';
        foreach ($configuration['severity'] as $index => $severity) {
            $html .= '<tr><th scope="row">' . $severity['letter'] . '</th>';
            foreach ($configuration['cells'][$index] as $cell) {
                $band = self::band($configuration['bands'], $cell['score']);
                $html .= '<td class="dwf-risk-reference-cell" style="background:' . $band['background'] . ';color:' . $band['color'] . '">' . $cell['score'] . '</td>';
            }
            $html .= '</tr>';
        }
        $html .= '</tbody></table>';
        foreach (['severity' => 'Schadensschwere S', 'likelihood' => 'Wahrscheinlichkeit L'] as $axis => $title) {
            $html .= '<div class="dwf-risk-axis-definitions"><strong>' . $title . '</strong>';
            foreach ($configuration[$axis] as $level) {
                $label = $axis === 'severity' ? $level['letter'] . ' (' . $level['value'] . ')' : (string)$level['value'];
                $html .= '<p><b>' . $label . ' ' . $e($level['label']) . '</b> · ' . $e($level['description']) . '</p>';
            }
            $html .= '</div>';
        }
        $html .= '<table class="dwf-risk-composite-bands"><thead><tr><th scope="col">Risikobereich und Bedeutung</th></tr></thead><tbody>';
        foreach ($configuration['bands'] as $band) {
            $html .= '<tr><td class="dwf-risk-reference-cell" style="background:' . $band['background'] . ';color:' . $band['color'] . '"><span class="dwf-risk-composite-band-label">' . $e($band['label']) . ' ' . $band['min'] . '–' . $band['max'] . '</span>' . $e($band['description']) . '</td></tr>';
        }
        return $html . '</tbody></table></div></aside>';
    }

    /** Exposes a single trusted style pair without accepting CSS from documents or clients. */
    public static function bandFor(int $score, string $profile): ?array
    {
        $configuration = self::configuration($profile);
        if ($score < 1 || $score > 25) {
            throw new WorkflowException('Ein Risikoprodukt muss zwischen 1 und 25 liegen.');
        }
        return $profile === '' ? null : self::band($configuration['bands'], $score);
    }

    private static function decorate(array $calculation, array $configuration): array
    {
        if ($configuration['id'] === '') {
            return $calculation;
        }
        $band = self::band($configuration['bands'], $calculation['score']);
        return $calculation + ['profile' => $configuration['id'], 'band' => $band['key'], 'band_label' => $band['label']];
    }

    private static function band(array $bands, int $score): array
    {
        foreach ($bands as $band) {
            if ($score >= $band['min'] && $score <= $band['max']) {
                return $band;
            }
        }
        throw new WorkflowException('Das Risikoprodukt liegt außerhalb des Bewertungsprofils.');
    }

    private static function configuration(string $profile): array
    {
        if ($profile !== '' && $profile !== self::REFERENCE && $profile !== self::RWE_DE) {
            throw new WorkflowException('Das Bewertungsprofil ist unbekannt.');
        }
        $severity = [];
        $likelihood = [];
        for ($value = 5; $value >= 1; $value--) {
            $severity[] = ['value' => $value, 'letter' => chr(64 + $value), 'label' => 'Stufe ' . $value, 'description' => 'Betrieblich festzulegen'];
        }
        for ($value = 1; $value <= 5; $value++) {
            $likelihood[] = ['value' => $value, 'label' => 'Stufe ' . $value, 'description' => 'Betrieblich festzulegen'];
        }
        if ($profile === '') {
            return ['id' => '', 'label' => 'Neutrale Bewertungsskala', 'title' => 'Neutrale Bewertungsskala', 'source' => '', 'severity' => $severity, 'likelihood' => $likelihood, 'bands' => []];
        }
        if ($profile === self::RWE_DE) {
            $reference = self::configuration(self::REFERENCE);
            $reference['id'] = self::RWE_DE;
            $reference['label'] = $reference['title'] = 'Risikomatrix · deutsche RWE-Vorlage';
            $reference['severity'][0]['environment'] = 'Ökologische Schäden, erst nach 10 Jahren oder mehr reversibel, oder Verwüstung einer großen Fläche';
            $reference['severity'][1]['environment'] = 'Ökologische Schäden, erst nach einem Jahr oder mehr reversibel';
            $reference['severity'][2]['environment'] = 'Ökologische Schäden, erst nach Monaten reversibel; in der Regel externe Unterstützung erforderlich';
            $reference['severity'][3]['environment'] = 'Lokale Freisetzung oder Störung, meist mit eigenen Mitteln schnell behebbar';
            $reference['severity'][4]['environment'] = 'Lokale Freisetzung, die eingedämmt werden kann, oder Störung ohne nachteilige Auswirkungen';
            foreach (['< 1:100.000', '< 1:1.000', '< 1:10', '< 1:1', '> 1:1'] as $i => $frequency) $reference['likelihood'][$i]['frequency'] = $frequency;
            $reference['bands'][0]['description'] = 'Akzeptabel: Das Risiko ist akzeptabel; überwachen.';
            $reference['bands'][1]['description'] = 'Tolerierbar: Weitere risikomindernde Maßnahmen berücksichtigen und soweit vernünftigerweise praktikabel umsetzen.';
            $reference['bands'][2]['description'] = 'Nicht akzeptabel: Das Risiko ist nicht akzeptabel; risikomindernde Maßnahmen müssen umgesetzt werden.';
            $reference['bands'][0]['approver'] = $reference['bands'][1]['approver'] = 'Verantwortliche Leitung';
            $reference['bands'][2]['approver'] = 'Nicht zutreffend';
            return $reference;
        }
        return [
            'id' => self::REFERENCE,
            'label' => 'Bewertungsprofil aus bereitgestellter Referenz',
            'title' => 'Bewertungsprofil aus bereitgestellter Referenz',
            'source' => 'Vom Nutzer bereitgestellte Matrixlegende aus FS-RA-030 Business Travel.pdf; eigene deutsche Beschriftung.',
            'source_sha256' => 'd8634845499b7ee78ef838a277af8070c5ba3bfc8ceda721e0253f94ce03f16d',
            'severity' => [
                ['value' => 5, 'letter' => 'E', 'label' => 'Katastrophal', 'description' => 'Ein oder mehrere Todesfälle'],
                ['value' => 4, 'letter' => 'D', 'label' => 'Schwer', 'description' => 'Lebensverändernde Verletzung oder Behinderung; vollständige Erholung unwahrscheinlich'],
                ['value' => 3, 'letter' => 'C', 'label' => 'Ernst', 'description' => 'Längere Abwesenheit (Monate); vollständige Erholung'],
                ['value' => 2, 'letter' => 'B', 'label' => 'Begrenzt', 'description' => 'Kurze Abwesenheit (Tage), medizinische Behandlung; vollständige Erholung'],
                ['value' => 1, 'letter' => 'A', 'label' => 'Gering', 'description' => 'Erste Hilfe; sofortige Erholung'],
            ],
            'likelihood' => [
                ['value' => 1, 'label' => 'Sehr unwahrscheinlich', 'description' => 'In der Branche bisher nicht bekannt'],
                ['value' => 2, 'label' => 'Unwahrscheinlich', 'description' => 'In der Branche bekannt, in der Organisation nicht'],
                ['value' => 3, 'label' => 'Möglich', 'description' => 'Kann in der Organisation auftreten'],
                ['value' => 4, 'label' => 'Wahrscheinlich', 'description' => 'Einmal bis mehrmals jährlich in der Organisation'],
                ['value' => 5, 'label' => 'Sehr wahrscheinlich', 'description' => 'Einmal bis mehrmals jährlich am Standort'],
            ],
            'bands' => [
                ['key' => 'low', 'label' => 'Niedrig', 'description' => 'Bestehende Maßnahmen beibehalten und die Wirksamkeit überwachen.', 'min' => 1, 'max' => 4, 'background' => '#5ab88f', 'color' => '#000000'],
                ['key' => 'medium', 'label' => 'Mittel', 'description' => 'Weitere risikomindernde Maßnahmen prüfen und soweit praktikabel umsetzen.', 'min' => 5, 'max' => 9, 'background' => '#ffd744', 'color' => '#000000'],
                ['key' => 'high', 'label' => 'Hoch', 'description' => 'Risiko nicht akzeptabel; risikomindernde Maßnahmen vor Durchführung klären und erneut bewerten.', 'min' => 10, 'max' => 25, 'background' => '#e7343f', 'color' => '#000000'],
            ],
        ];
    }

    /** Translated axes and placement from the user-provided PDF, without its scripts, logo or signatures. */
    public static function renderRweLegend(): string
    {
        $config = self::get(self::RWE_DE);
        $e = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $html = '<aside class="dwf-rwe-legend"><div class="dwf-rwe-legend-main"><strong>Risikomatrix</strong><table class="dwf-rwe-matrix"><colgroup><col style="width:17.655%"><col style="width:19.368%"><col style="width:9.354%"><col style="width:3.03%">';
        for ($i=0;$i<5;$i++) $html.='<col style="width:10.119%">';
        $html.='</colgroup><thead><tr><th>UMWELT</th><th>SICHERHEIT</th><th>S</th><th></th>';
        foreach ($config['likelihood'] as $level) $html.='<th><b>'.$e($level['label']).'</b><span>'.$e($level['description']).'</span><b>'.$level['value'].'</b></th>';
        $html.='</tr></thead><tbody>';
        foreach ($config['severity'] as $index=>$level) {
            $html.='<tr><td>'.$e($level['environment']).'</td><td>'.$e($level['description']).'</td><td>'.$e($level['label']).'</td><th scope="row">'.$level['letter'].'</th>';
            foreach ($config['cells'][$index] as $cell) {
                $band=self::band($config['bands'],$cell['score']);
                $html.='<td class="dwf-rwe-score" style="background:'.$band['background'].'!important;color:#000!important">'.$cell['score'].'</td>';
            }
            $html.='</tr>';
        }
        $html.='</tbody><tfoot><tr><td colspan="4"></td>';
        foreach ($config['likelihood'] as $level) $html.='<td>'.$e($level['frequency']).'</td>';
        $html.='</tr></tfoot></table></div><table class="dwf-rwe-bands"><thead><tr><th>Risiko</th><th>Wert</th><th>Bedeutung</th><th>Freigabe</th></tr></thead><tbody>';
        foreach (array_reverse($config['bands']) as $band) $html.='<tr><th style="background:'.$band['background'].'!important;color:#000!important">'.$e($band['label']).'</th><td>'.$band['min'].'–'.$band['max'].'</td><td>'.$e($band['description']).'</td><td>'.$e($band['approver']).'</td></tr>';
        return $html.'</tbody></table></aside>';
    }
}
