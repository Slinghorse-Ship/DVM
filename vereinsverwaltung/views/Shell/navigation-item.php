<?php declare(strict_types=1); ?>
<a class="nav-link <?= $entry['id']===$menuKey?'active':'' ?>" <?= $entry['id']===$menuKey?'aria-current="page"':'' ?> href="<?= e(url($entry['route'])) ?>"><?= e($entry['label']) ?></a>
