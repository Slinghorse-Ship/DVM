<?php declare(strict_types=1);
require __DIR__.'/settings-form-state.php';
$section='club';$tab='';$sd=Kulturhus\Verein\SettingsSchema::defaults('club');$sd['show_phone']=true;$sd['show_email']=false;$sv=1;
ob_start();require __DIR__.'/../views/Settings/settings-profile.php';$profileHtml=ob_get_clean();$xp=htmlDoc($profileHtml);
check($xp->query('//input[@name="data[show_phone]"][@type="hidden"][@value="1"]')->length===1,'phone visibility retained without visible setting');
check($xp->query('//input[@name="data[show_email]"][@type="hidden"][@value="0"]')->length===1,'email visibility retained without visible setting');
check(!str_contains($profileHtml,'Telefon im Vereinsprofil anzeigen')&&!str_contains($profileHtml,'E-Mail im Vereinsprofil anzeigen'),'obsolete visibility labels removed');
echo "$checks settings display checks passed\n";
