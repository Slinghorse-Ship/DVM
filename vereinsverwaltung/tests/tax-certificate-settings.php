<?php declare(strict_types=1);
// Synthetic MySQL data and offline ERiC fixture only; never touches production.
require __DIR__.'/tax-certificate-vault.php';
use Kulturhus\Verein\{Settings,SettingsSchema};

$settings=new Settings($db,'board');
$tax=$settings->section('tax');$settings->save('tax',array_replace(SettingsSchema::defaults('tax'),$tax['data']),$tax['version']);
$_GET=['view'=>'settings','tab'=>'','section'=>'tax'];$_POST=[];$_SESSION=['actor'=>'board'];$tab='';
$beforeSettings=$settings->section('tax');
ob_start();require __DIR__.'/../views/Settings/settings.php';$settingsHtml=(string)ob_get_clean();
$dom=new DOMDocument();$previous=libxml_use_internal_errors(true);$dom->loadHTML('<?xml encoding="UTF-8">'.$settingsHtml);libxml_clear_errors();libxml_use_internal_errors($previous);$xpath=new DOMXPath($dom);
$certNode=$xpath->query('//*[@id="elster-certificate"]')->item(0);
check($certNode!==null,'certificate manager rendered exactly under tax settings');
check($xpath->query('//*[@id="elster-certificate"]/ancestor::form')->length===0,'certificate forms are outside the tax data form');
check($xpath->query('//*[@id="elster-certificate"]/preceding-sibling::form[@data-settings-form]')->length===1,'certificate follows the tax settings form');
check($xpath->query('//*[@id="elster-certificate"]/following-sibling::details[contains(@class,"settings-history")]')->length===1,'certificate is above optional settings history');
check($xpath->query('//*[@id="elster-certificate"]//input[@name="certificate_return" and @value="tax-settings"]')->length===2,'replace and remove both retain settings return target');
check($xpath->query('//*[@id="elster-certificate"]//input[@name="csrf" and @value="synthetic-csrf"]')->length===2,'both certificate actions include CSRF protection');
check($xpath->query('//*[@id="elster-certificate"]//input[@name="certificate_pin" and @type="password" and not(@value)]')->length===1,'password field is empty and masked');
check(!str_contains($settingsHtml,'Zurück zur Steuererklärung'),'embedded certificate has no misleading return link');
check($settings->section('tax')===$beforeSettings&&$section==='tax'&&$saved===$beforeSettings,'rendering preserves tax data and parent view variables');

final class CertificateSettingsRedirect extends RuntimeException {public function __construct(public array $route){parent::__construct('test-only redirect');}}
function redirectTo(array $params):never{throw new CertificateSettingsRedirect($params);}
$dispatch=static function(array $post,string $actor='board')use($db):array{
    $_POST=$post;$_FILES=[];$_SESSION=['actor'=>$actor];$action=input($_POST,'action');
    try{require __DIR__.'/../src/Finance/Tax/TaxFilingActions.php';}catch(CertificateSettingsRedirect $r){return $r->route;}
    throw new RuntimeException('Missing redirect');
};
$status=$vault->status();
$route=$dispatch(['action'=>'tax-filing-certificate-remove','remove_confirmed'=>'1','certificate_revision'=>$status['revision'],'certificate_return'=>'tax-settings','filing'=>'ignored','section'=>'ignored','form_page'=>'ignored']);
check($route===['view'=>'settings','tab'=>'','section'=>'tax'],'successful certificate removal returns precisely to tax settings');
check(!$vault->status()['saved']&&$settings->section('tax')===$beforeSettings,'certificate removal does not change tax settings');
$emptyRevision=$vault->status()['revision'];
$vault->save('synthetic-org',$pin,$emptyRevision,true);
$route=$dispatch(['action'=>'tax-filing-certificate-remove','remove_confirmed'=>'1','certificate_revision'=>$vault->status()['revision'],'filing'=>'existing-filing','tax_year'=>'2025']);
check($route===['view'=>'finance','tab'=>'tax','tax_filings'=>'1','tax_year'=>'2025','tax_certificate'=>'1','filing'=>'existing-filing'],'original tax workspace retains its return route');
$vault->save('synthetic-org',$pin,$vault->status()['revision'],true);$status=$vault->status();
denied(fn()=>$dispatch(['action'=>'tax-filing-certificate-remove','remove_confirmed'=>'1','certificate_revision'=>$status['revision'],'certificate_return'=>'tax-settings'],'finance'),'settings return flag never broadens certificate authorization');
denied(fn()=>$dispatch(['action'=>'tax-filing-certificate-save','certificate_return'=>'tax-settings','certificate_pin'=>'private test secret','organization_confirmed'=>'1']),'missing upload is rejected safely');
check(!isset($_POST['certificate_pin'],$_FILES['tax_certificate'])&&$vault->status()===$status,'failed upload clears password and leaves stored certificate untouched');
check($_GET===['view'=>'settings','tab'=>'','section'=>'tax'],'failed certificate action remains on requested settings page');
echo "$checks checks passed including certificate placement and return routing; offline fixtures only\n";
