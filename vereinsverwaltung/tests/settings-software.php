<?php declare(strict_types=1);
$n=0;
function check(bool $ok,string $label):void{global $n;if(!$ok)throw new RuntimeException($label);++$n;echo "PASS $label\n";}
$render=static function(string $club):string{ob_start();require __DIR__.'/../views/Settings/settings-software.php';return ob_get_clean();};
$html=$render('Synthetic club A');
check(str_contains($html,'Vereinsmanager · © Jan Wehlage'),'author and copyright clearly identified');
check(str_contains($html,'Der Verein ist Nutzer der Software.'),'club identified as user, not software author');
check(str_contains($html,'Drittanbieter-Komponenten')&&str_contains($html,'Lizenz- und Urheberhinweise'),'third-party notices explicitly preserved');
check($render('Another association')===$html,'copyright does not follow tenant branding');
check(!preg_match('/<(?:input|select|textarea|button|form|script)\b/i',$html),'read-only notice contains no setting, action or script');
$dom=new DOMDocument();libxml_use_internal_errors(true);$dom->loadHTML('<?xml encoding="UTF-8">'.$html);$xp=new DOMXPath($dom);
check($xp->query('//footer[@aria-label="Software und Urheberrecht"]//h2')->length===1,'accessible named footer with one heading');
$layout=file_get_contents(__DIR__.'/../views/Shell/layout.php');
check(substr_count($layout,"require __DIR__.'/../Settings/settings-software.php'")===1,'single shared integration, no duplicate notice');
check(str_contains($layout,"if(\$view==='settings'&&!in_array(\$tab,['status','api','system'],true))require __DIR__.'/../Settings/settings-software.php'"),'shared notice is scoped to club settings while dedicated native pages retain their own notices');
check(strpos($layout,"require __DIR__.'/../Settings/settings-software.php'")>strpos($layout,"require __DIR__.'/module.php'"),'settings content precedes software notice');
echo "$n software attribution checks passed; no database or network access\n";
