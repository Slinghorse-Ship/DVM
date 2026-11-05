<?php declare(strict_types=1);
// Full MySQL workflow: zero book value, positive tax value; never a fake book posting.
define('TAX_ASSET_SCRAP','zero');
require __DIR__.'/balance-tax-assets.php';
