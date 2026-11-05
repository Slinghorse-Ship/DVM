<?php declare(strict_types=1);$inventoryCategories=$inventory->canRead()?$inventory->categories():[]; ?>
<datalist id="inventory-categories"><?php foreach($inventoryCategories as $inventoryCategory): ?><option value="<?= e($inventoryCategory) ?>"></option><?php endforeach ?></datalist>

<script defer src="inventory-category-hints.js?v=4"></script>
