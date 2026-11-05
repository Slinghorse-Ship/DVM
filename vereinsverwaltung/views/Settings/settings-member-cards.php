<?php declare(strict_types=1);
if($memberCardWalletState!==null)echo(new \Kulturhus\Verein\Web\MemberCardWalletPage((string)$_SESSION['csrf'],$memberCardMessages??[]))->render($memberCardWalletState,$memberCardDraft,$memberCardError);
else echo'<div class="content"><h1>'.e($memberCardText('Mitgliederausweise & Wallet')).'</h1><p role="alert">'.e($memberCardText($memberCardError?:'Einrichtung derzeit nicht verfügbar.')).'</p></div>';
