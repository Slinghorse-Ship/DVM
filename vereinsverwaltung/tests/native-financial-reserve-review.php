<?php declare(strict_types=1);
require_once __DIR__.'/../src/Finance/Accounting/FinancialReserveReview.php';
require_once __DIR__.'/../src/Finance/Accounting/FinancialReserveXml.php';
require_once __DIR__.'/../native/Language/TaxFilingMessages.php';
require_once __DIR__.'/../native/Language/BalanceFilingMessages.php';

use Kulturhus\Verein\{FinancialReserveCalculation,FinancialReserveReview,FinancialReserveXml};
use Kulturhus\Native\Language\BalanceFilingMessages;

$checks=0;
function check(bool $yes,string $label):void
{global $checks;if(!$yes)throw new RuntimeException($label);$checks++;echo 'PASS '.$label.PHP_EOL;}
function denied(callable $call,string $label):void
{try{$call();}catch(DomainException|TypeError){check(true,$label);return;}throw new RuntimeException('Expected refusal: '.$label);}
function e(mixed $value):string{return htmlspecialchars((string)$value,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
function url(array $values):string{return '/index.php?'.http_build_query($values);}

$original=['schema'=>1,'year'=>2025,'taxpayer_kind'=>'natural_person_sole_business','sale_year'=>2023,
    'held_since'=>'2016-01-01','sold_on'=>'2023-04-01','sale_price_cents'=>20000,'sale_cost_cents'=>0,
    'sold_tax_book_cents'=>5000,'formed_cents'=>10000,'available_cents'=>8000,'reduction_cents'=>2000,
    'acquired_on'=>'2025-02-01','replacement_kind'=>'corporation_shares','cost_cents'=>15000]
    +array_fill_keys(FinancialReserveCalculation::FLAGS,true);
$stored=['schema'=>1,'status'=>'pending_tax_review','year'=>2025,'journal_sha256'=>hash('sha256','synthetic original journal'),
    'account'=>'original-reserve','skr_code'=>'72500','original_taxpayer'=>'Synthetic creator <&> ä🙂',
    'original'=>$original,'receiver_legal_status'=>'GMBH','transfer_kind'=>'whole_business_contribution_UmwStG20',
    'transfer_value'=>'book_value','transferred_on'=>'2024-09-01','transferred_reserve_cents'=>8000,
    'confirmations'=>array_fill_keys(['original_personal_scope_reviewed','whole_business_transfer_reviewed',
        'reserve_continuity_reviewed','no_deadline_restart_reviewed','no_earlier_reinvestment_reviewed'],true),
    'documents'=>[],'calculation'=>['schema'=>1,'original_gain_cents'=>15000,'original_gain_limit_cents'=>50000000,
        'tax_basis_cents'=>13000,'reserve_closing_cents'=>6000,'reserve_release_cents'=>2000,
        'activation_ready'=>false,'native_xml_qualified'=>false,'original_share_reinvestment_deadline'=>'2025-12-31'],
    'recorded_by'=>'synthetic-actor','recorded_at'=>'2026-11-05T10:00:00+00:00',
    'activation_ready'=>false,'native_xml_qualified'=>false];
foreach(['original','transfer','continuity','election']as$purpose)$stored['documents'][$purpose]=[
    'document_id'=>substr(hash('sha256','own-document-'.$purpose),0,32),'revision'=>1,
    'sha256'=>hash('sha256','own synthetic PDF '.$purpose)];
$originalBytes=json_encode($stored,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);

$review=FinancialReserveReview::fromIntake($stored);
check($review['calendar']['share_reinvestment_deadline']==='2025-12-31','original2023 share deadline remains2025');
check($review['calendar']['building_reinvestment_deadline']==='2027-12-31'
    &&$review['calendar']['mandatory_release_on']==='2027-12-31','building and final release deadlines are2027, not2025');
check($review['calendar']['mandatory_release_due']===false,'two-year share deadline is no mandatory reserve release');
check($review['intended_reduction_cents']===2000&&$review['remaining_if_intended_use_cents']===6000,
    '80 less intended20 yields a conditional60 without inventing earlier annual movements');
check($review['year_end_balance_cents']===null&&$review['surcharge_cents']===null,
    'incomplete source history creates no certified closing balance or assumed surcharge');
check($review['corporate_transfer_qualified']===false&&$review['activation_ready']===false&&$review['native_xml_qualified']===false,
    'calendar arithmetic does not qualify a GmbH transfer or XML');
check($review['intended_purchase_scope']==='excluded_corporate_purchase_after_transfer',
    'recorded new GmbH purchase after the tax-effective contribution date is specifically excluded');
check($review['source_sha256']==='1535062247e5c6fc4de309e577f1fe6d7daf48b9770a36cfced75b29b3b99259',
    'new scope explanation leaves the existing original source hash unchanged');
check($review['original_intended_basis_cents']===13000
    &&$review['purchase_cost_without_intended_reduction_cents']===15000
    &&$review['excluded_intended_reduction_cents']===2000,
    'excluded20does not turn the recorded150cost into the old intended130tax basis');
check($stored['calculation']['tax_basis_cents']===13000&&$stored['calculation']['reserve_closing_cents']===6000,
    'old130and60remain literal original intentions rather than amended tax values');
check(array_column($review['calendar_rows'],'year')===[2023,2024,2025,2026,2027],
    'the original and all four subsequent calendar years remain separate');
$future=FinancialReserveReview::fromIntake($stored,2026);
check($future['calendar']['share_reinvestment_in_year']===false&&$future['calendar']['building_reinvestment_in_year']===true,
    'following2026 year closes share reinvestment but keeps the original building deadline');
check($future['calendar']['original_gain_limit_cents']===50000000,'following years do not enlarge the original500k formation ceiling');
$final=FinancialReserveReview::fromIntake($stored,2027);
check($final['calendar']['mandatory_release_due']===true&&$final['remaining_if_intended_use_cents']===6000
    &&$final['year_end_balance_cents']===null,'deadline year does not silently post a release or turn60 into a closing value');
$overdue=FinancialReserveReview::fromIntake($stored,2028);
check($overdue['calendar']['mandatory_release_due']===true&&!$overdue['calendar']['building_reinvestment_in_year']
    &&end($overdue['calendar_rows'])['year']===2028,'expired deadline is visible without renewed annual release');
check(json_encode($stored,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE)===$originalBytes,
    'all current and following-year reads retain the complete original source bytes');
$reordered=$stored;$reordered['calculation']=array_reverse($reordered['calculation'],true);
check(FinancialReserveReview::fromIntake($reordered)['source_sha256']===$review['source_sha256'],
    'JSON key ordering does not create a different semantic source review');
$changed=$stored;$changed['recorded_at']='2026-11-05T11:00:00+00:00';
check(FinancialReserveReview::fromIntake($changed)['source_sha256']!==$review['source_sha256'],
    'the calendar source hash remains bound to its own complete stored record');
$otherCode=$stored;$otherCode['skr_code']='72550';
check(FinancialReserveReview::fromIntake($otherCode)['remaining_if_intended_use_cents']===6000,
    '72550 receives the same unqualified original-reserve calendar, no72040 alias');
foreach(['72500','72550']as$code)foreach(['GMBH','gGMBH']as$form){
    $case=$stored;$case['skr_code']=$code;$case['receiver_legal_status']=$form;
    $before=json_encode($case,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
    $laterPurchase=FinancialReserveReview::fromIntake($case);
    check($laterPurchase['intended_purchase_scope']==='excluded_corporate_purchase_after_transfer'
        &&!$laterPurchase['corporate_transfer_qualified']&&!$laterPurchase['native_xml_qualified'],
        'new post-contribution purchase is excluded only within the checked source family '.$code.' '.$form);
    $case['transferred_on']=$case['original']['acquired_on'];
    $sameDay=FinancialReserveReview::fromIntake($case);
    check($sameDay['intended_purchase_scope']==='chronology_review_required'
        &&!$sameDay['corporate_transfer_qualified']&&!$sameDay['activation_ready'],
        'same-day purchase does not invent an intraday sequence '.$code.' '.$form);
    check($sameDay['purchase_cost_without_intended_reduction_cents']===null
        &&$sameDay['excluded_intended_reduction_cents']===null,
        'same-day chronology infers neither an excluded deduction nor a zero amount '.$code.' '.$form);
    check($sameDay['calendar']===$laterPurchase['calendar']&&$sameDay['remaining_if_intended_use_cents']===6000,
        'same-day chronology does not change original deadlines or saved arithmetic '.$code.' '.$form);
    $case['transferred_on']=$stored['transferred_on'];
    check(json_encode($case,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE)===$before,
        'scope review leaves the complete source and calculation untouched '.$code.' '.$form);
    $case['transferred_on']='2025-02-02';
    denied(fn()=>FinancialReserveReview::fromIntake($case),
        'pre-contribution purchases remain outside this intake family, not broadly declared illegal '.$code.' '.$form);
}

foreach(['schema','status','year','skr_code','receiver_legal_status','transfer_kind','transfer_value',
    'transferred_reserve_cents','transferred_on','activation_ready','native_xml_qualified','calculation','confirmations','documents']as$key){
    $bad=$stored;
    $bad[$key]=match($key){'schema'=>2,'status'=>'approved','year'=>2026,'skr_code'=>'72040',
        'receiver_legal_status'=>'EV','transfer_kind'=>'private_gift','transfer_value'=>'market_value',
        'transferred_reserve_cents'=>7999,'transferred_on'=>'2024-02-30',
        'activation_ready','native_xml_qualified'=>true,default=>[]};
    denied(fn()=>FinancialReserveReview::fromIntake($bad),'changed original contract remains unqualified: '.$key);
}
foreach(['tax_basis_cents','reserve_closing_cents','reserve_release_cents','original_gain_limit_cents',
    'original_share_reinvestment_deadline','native_xml_qualified']as$key){
    $bad=$stored;$bad['calculation'][$key]=match($key){'original_share_reinvestment_deadline'=>'2027-12-31',
        'native_xml_qualified'=>true,default=>$bad['calculation'][$key]+1};
    denied(fn()=>FinancialReserveReview::fromIntake($bad),'stored calculation cannot be silently recomputed: '.$key);
}
foreach(['revision','sha256','document_id']as$key){$bad=$stored;$bad['documents']['original'][$key]=$key==='revision'?true:'wrong';
    denied(fn()=>FinancialReserveReview::fromIntake($bad),'immutable document reference remains typed: '.$key);}
$bad=$stored;$bad['documents']['election']=$bad['documents']['original'];
denied(fn()=>FinancialReserveReview::fromIntake($bad),'separate original evidence remains distinct');
$bad=$stored;$bad['confirmations']['reserve_continuity_reviewed']='1';
denied(fn()=>FinancialReserveReview::fromIntake($bad),'source confirmations stay literal booleans');
$bad=$stored;$bad['period']=['start'=>'2023-07-01','end'=>'2024-06-30'];
denied(fn()=>FinancialReserveReview::fromIntake($bad),'dated originals are not silently read as calendar years');
denied(fn()=>FinancialReserveReview::fromIntake($stored,2022),'review does not predate its original formation');

$reserveLabels=['sale_price'=>'Ursprünglicher Verkaufspreis','sale_cost'=>'Verkaufskosten',
    'sold_tax_book'=>'Steuerbuchwert der verkauften Anteile','formed'=>'Ursprünglich gebildete Rücklage',
    'available'=>'Unverbrauchter ursprünglicher Restbetrag','reduction'=>'Beabsichtigte Anschaffungskostenkürzung',
    'cost'=>'Anschaffungskosten der Ersatzanteile'];
$reserveChecks=['same_taxpayer'=>'Ursprünglicher Gewinn und Rücklage gehören zum selben Rücklagenbildner.'];
$money=static fn(int$n):string=>number_format($n/100,2,',','.').' €';
foreach(['de','en']as$locale){
    $messages=BalanceFilingMessages::messages($locale);
    $bt=static fn(string$text):string=>BalanceFilingMessages::text($text,$messages);
    $reserveRecord=$stored;ob_start();require __DIR__.'/../views/Finance/Balance/balance-tax-financial-reserve-source.php';$html=ob_get_clean();
    check(str_contains($html,$locale==='en'?'Deadline for replacement shares':'Frist für Ersatzanteile'),
        'read-only source labels the two-year share deadline explicitly '.$locale);
    check(substr_count($html,'2027-12-31')===2&&str_contains($html,'data-reserve-year-review'),
        'source renders separate building and mandatory-release dates '.$locale);
    check(str_contains($html,$locale==='en'?'Share deadline has passed; the building deadline remains open':
        'Anteilsfrist abgelaufen; Gebäudefrist läuft'),'following year2026 is not treated as a forced release '.$locale);
    check(str_contains($html,$locale==='en'?'Calculated remainder if the intended use were made':'Rechnerischer Rest bei beabsichtigter Verwendung')
        &&str_contains($html,'60,00 €'),'source identifies60only as the conditional remainder of the intended use '.$locale);
    check(str_contains($html,e($bt('Ursprünglich beabsichtigte Ersatzbasis')).'</dt><dd>130,00 €</dd>')
        &&str_contains($html,e($bt('Erfasste Anschaffungskosten ohne diesen Abzug, vor weiteren Wertänderungen')).'</dt><dd>150,00 €</dd>')
        &&str_contains($html,e($bt('Hier nicht anwendbarer beabsichtigter Abzug')).'</dt><dd>20,00 €</dd>'),
        'source distinguishes recorded150cost, excluded20and original intended130basis '.$locale);
    check(str_contains($html,$locale==='en'?'not confirmed tax or year-end values':'keine bestätigten Steuer- oder Jahresendwerte')
        &&str_contains($html,'data-reserve-purchase-scope="excluded_corporate_purchase_after_transfer"')
        &&str_contains($html,$locale==='en'?'the GmbH cannot transfer the personal reserve':'darf die GmbH die persönliche Rücklage nicht')
        &&str_contains($html,$locale==='en'?'No tax postings are derived':'keine Steuerbuchungen abgeleitet'),
        'calendar view explains the excluded new purchase and derives no corporate tax posting '.$locale);
    check(str_contains($html,e($stored['original_taxpayer']))&&!str_contains($html,'<form')&&!str_contains($html,'<input'),
        'the read-only calendar keeps original Unicode escaped and adds no action '.$locale);
    foreach($stored['documents']as$reference)check(str_contains($html,$reference['sha256'])
        &&str_contains($html,'document_file='.$reference['document_id'].'&amp;revision=1'),
        'original evidence download and byte hash remain visible '.$locale);
    check(json_encode($reserveRecord,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE)===$originalBytes,
        'source rendering preserves the whole original '.$locale);
    $reserveRecord=$stored;$reserveRecord['transferred_on']=$reserveRecord['original']['acquired_on'];
    $sameDayBytes=json_encode($reserveRecord,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
    ob_start();require __DIR__.'/../views/Finance/Balance/balance-tax-financial-reserve-source.php';$sameDayHtml=ob_get_clean();
    check(str_contains($sameDayHtml,'data-reserve-purchase-scope="chronology_review_required"')
        &&str_contains($sameDayHtml,$locale==='en'?'Their actual sequence requires a separate review':'Die tatsächliche Reihenfolge ist gesondert zu prüfen')
        &&!str_contains($sameDayHtml,'data-reserve-purchase-scope="excluded_corporate_purchase_after_transfer"'),
        'same-day view requests chronology without assuming purchase after contribution '.$locale);
    check(!str_contains($sameDayHtml,'data-reserve-excluded-purchase-values'),
        'same-day source displays no inferred replacement value or zero deduction '.$locale);
    check(json_encode($reserveRecord,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE)===$sameDayBytes,
        'same-day source rendering also preserves every original field '.$locale);
    $reserveRecord=$stored;$reserveRecord['calculation']['reserve_closing_cents']=6001;
    ob_start();require __DIR__.'/../views/Finance/Balance/balance-tax-financial-reserve-source.php';$invalid=ob_get_clean();
    check(str_contains($invalid,'data-reserve-review-unavailable')&&!str_contains($invalid,'data-reserve-year-review'),
        'mismatched saved calculation does not produce a new calendar review '.$locale);
}
foreach(['72500','72550']as$code)foreach([1,-1,6000000]as$amount)
    denied(fn()=>FinancialReserveXml::assertExportable(['source'=>['skr_code'=>$code,'amount_cents'=>$amount]]),
        'existing nonzero export guard remains effective '.$code.' '.$amount);
echo json_encode(['suite'=>'native-financial-reserve-review','checks'=>$checks,'database_writes'=>0,
    'external_actions'=>0,'activation_ready'=>false,'native_xml_qualified'=>false],JSON_THROW_ON_ERROR),PHP_EOL;
