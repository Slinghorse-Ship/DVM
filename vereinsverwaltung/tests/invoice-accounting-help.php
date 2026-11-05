<?php declare(strict_types=1);
// Render only the real static help partial; no database, login, transport or live data.
ob_start();require __DIR__.'/../views/Finance/Invoices/invoice-accounting-help.php';$html=ob_get_clean();$dom=new DOMDocument();@$dom->loadHTML('<?xml encoding="UTF-8">'.$html);$xp=new DOMXPath($dom);$n=0;
function check(bool $ok,string $name):void{global $n;if(!$ok)throw new RuntimeException($name);$n++;echo "PASS $name\n";}
check($xp->query('//details')->length===1&&$xp->query('//details[@data-accounting-help]')->length===1,'one expandable inline help exposes all guidance without a second hidden section');
check($xp->evaluate('string(//details/summary)')==='Kleine Hilfe: Sphäre, Steuerkonto und BU','existing help entry remains recognizable');
$expected=[
 '1 · Ideeller Bereich'=>'Gemeinsame Vereinsarbeit ohne Verkauf einer Leistung, zum Beispiel echte Mitgliedsbeiträge und Spenden.',
 '2 · Vermögensverwaltung'=>'Zum Beispiel Zinsen oder eine längerfristige Vermietung; die konkrete Tätigkeit entscheidet.',
 '3 · Zweckbetrieb'=>'Wirtschaftliche Tätigkeit, die unter den steuerlichen Voraussetzungen unmittelbar dem gemeinnützigen Zweck dient. Eine Kulturveranstaltung ist nicht allein wegen ihres Namens automatisch ein Zweckbetrieb.',
 '4 · Wirtschaftlicher Geschäftsbetrieb'=>'Wirtschaftliche Tätigkeit außerhalb eines Zweckbetriebs, zum Beispiel gesonderte Werbung für einen Sponsor.',
 '9 · Sammelposten'=>'Technische Sammelzuordnung im Kontenplan. Sie ersetzt keine noch ungeklärte Sphäre.',
];
$reference=file_get_contents(__DIR__.'/../views/Finance/Accounting/accounting-help.php');foreach($expected as $label=>$text){$description=$xp->evaluate('string(//dt[text()="'.$label.'"]/following-sibling::dd[1])');check($description===$text,'complete explanation for '.$label);check(str_contains($reference,$text),'same explanation as detailed finance help for '.$label);}
check(str_contains($html,'Das Steuerkonto ist ein Buchhaltungskonto, keine IBAN.')&&str_contains($html,'Aufwand oder Anlagevermögen'),'existing tax-account meaning and acquisition guidance retained');
check(str_contains($html,'BU bedeutet Buchungsschlüssel.')&&str_contains($html,'„19 %“ auf einer Rechnung ist kein Grund')&&str_contains($html,'„Ohne gesonderten Schlüssel“'),'existing BU and no-automatic-input-tax guidance retained');
check(str_contains($html,'Prüfen Sie die Positionen einzeln.')&&str_contains($html,'Lassen Sie Sphäre und Steuerkonto gemeinsam offen.'),'mixed use and unresolved accounting remain explained');
check($xp->query('//a[contains(@href,"tab=help#hilfe-kontierung")]')->length===1,'detailed finance help remains linked');
echo "$n inline invoice help checks passed; static partial only\n";
