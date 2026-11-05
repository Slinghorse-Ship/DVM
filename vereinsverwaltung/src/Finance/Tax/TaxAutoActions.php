<?php declare(strict_types=1);
// Only reached after central CSRF validation; no tax transmission in this controller.
$auto=new \Kulturhus\Verein\TaxAutoPreparation($db,(string)$_SESSION['actor']);
$year=(int)input($_POST,'tax_year');$version=(int)input($_POST,'version');
$route=['view'=>'finance','tab'=>'tax','tax_auto'=>'1','tax_year'=>(string)$year];
switch($action){
case 'tax-auto-save':
    $values=array_intersect_key($_POST,array_flip(['from_year','to_year','activity_report','tax_documents','tax_year_notes','tax_guide','auto_answers']));
    if(!is_array($_POST['answers']??null))throw new DomainException('Steuerprofil prüfen.');
    $auto->save($year,$version,$values,$_POST['answers'],(int)input($_POST,'profile_version'));
    $step=input($_POST,'tax_step');if(in_array($step,['tax-step-basics','tax-step-records','tax-step-review'],true))$route['tax_step']=$step;
    $_SESSION['notice']='Angaben gespeichert. Jahreszahlen wurden neu berechnet.';break;
case 'tax-auto-create':
    $id=$auto->createFiling($year,$version,input($_POST,'preview_hash'),input($_POST,'environment'),input($_POST,'synthetic')==='1');
    $route=['view'=>'finance','tab'=>'tax','tax_filings'=>'1','filing'=>$id];
    $_SESSION['notice']='KSt 1 und Anlage Gem vorbelegt. Noch nicht beim Finanzamt eingereicht.';break;
case 'tax-auto-package':
    $id=$auto->savePackage($year,$version,input($_POST,'preview_hash'));
    $route=['view'=>'finance','tab'=>'tax','tax_saved'=>$id];break;
default:throw new DomainException('Unbekannter Vorgang.');
}
redirectTo($route);
