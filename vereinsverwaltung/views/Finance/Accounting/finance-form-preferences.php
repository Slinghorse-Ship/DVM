<?php declare(strict_types=1);
$formPreferenceData=(new \Kulturhus\Verein\FinancePreferences($db,(string)$_SESSION['actor']))->data();
?>
<span hidden data-finance-form-preferences="<?= e(json_encode($formPreferenceData['category_spheres'],JSON_THROW_ON_ERROR)) ?>" data-preserve-post="<?= ($_SERVER['REQUEST_METHOD']??'GET')==='POST'?'1':'0' ?>" data-existing-positions="<?= isset($positionId)?'1':'0' ?>"></span>
<script defer src="finance-form-preferences.js?v=1"></script>
