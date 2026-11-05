<?php declare(strict_types=1);
$appearanceEnglish=($managerLanguageLocale??'de')==='en';
?>
<div class="content" id="account-appearance"><h1><?=e($appearanceEnglish?'Appearance':'Ansicht')?></h1><p><?=e($appearanceEnglish?'Choose layout, color and spacing for your account.':'Wähle Aufbau, Farben und Abstände für deinen Zugang.')?></p>
<?php if(($nativeManagerEnabled??false)&&isset($nativeAccess))echo \Kulturhus\Native\Appearance\View::render((new \Kulturhus\Native\Appearance\Preferences($db,$nativeAccess))->state(),(string)$_SESSION['csrf'],($_POST['action']??null)==='appearance-save'?$_POST:null,'',$managerLanguageLocale??'de','?view=appearance'); ?>
</div>
