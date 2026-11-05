<?php declare(strict_types=1);
if (!$canFinance || !$finance->canWrite()) return;
?>
<section class="content" aria-labelledby="bank-connection-title">
 <div class="card">
  <div class="section-heading"><h2 id="bank-connection-title">Onlinebanking</h2><span class="status">Noch nicht verbunden</span></div>
<p>FinTS ist für den produktiven Leseabruf eingerichtet. Bis zur ersten Anmeldung lassen sich Konten und Handkassen manuell führen und vorhandene Zahlungen zuordnen.</p>
  <p>Nach Erhalt der FinTS-Registrierung werden Konten und Umsätze gelesen. Freigegebene SEPA-Überweisungen und Lastschriftaufträge können anschließend mit der Bank-TAN oder Banking-App direkt eingereicht werden; der tatsächliche Belegstatus folgt weiterhin erst dem importierten Umsatz.</p>
 </div>
</section>
