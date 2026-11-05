<?php declare(strict_types=1);
require_once __DIR__.'/../../src/Web/DashboardText.php';
$dashState=$dashboardState??(new \Kulturhus\Verein\Web\DashboardState($db,(string)$_SESSION['actor'],$websiteCalendar??null))->prepare($_GET);
$dashMessages=$managerLanguageMessages??\Kulturhus\Native\Language\ManagerMessages::messages('de');$dashLocale=$managerLanguageLocale??'de';
$dashI=static fn(string $source):string=>\Kulturhus\Verein\Web\DashboardText::text($source,$dashMessages);
$dashF=static fn(string $source,array $values):string=>\Kulturhus\Verein\Web\DashboardText::format($source,$values,$dashMessages);
$dashMoney=static fn(int $cents):string=>\Kulturhus\Verein\Web\DashboardText::money($cents,$dashLocale);
$dashDate=static fn(string $value,bool $time=false):string=>\Kulturhus\Verein\Web\DashboardText::date($value,$dashLocale,$time);
$dashBase=['view'=>'dashboard'];foreach(['period','setup','dashboard_reviews','dashboard_reviews_query','dashboard_reviews_page','intro_result']as$dashField)if(is_string($_GET[$dashField]??null))$dashBase[$dashField]=$_GET[$dashField];
$dashUrl=static fn(array $changes=[]):string=>url(array_replace($dashBase,$changes));
$dashError=static function(string $part)use($dashState,$dashI):void{if(isset($dashState['errors'][$part]))echo '<p class="dwf-alert dwf-error" role="alert">'.e($dashI($dashState['errors'][$part])).'</p>';};
?>
<link rel="stylesheet" href="document-workflows.css?v=2"><link rel="stylesheet" href="dashboard-native.css">
