'use strict';
// The URL was validated by the AIS client and is only kept briefly in this user's session.
const bankForward = document.getElementById('enable-bank-forward');
if (bankForward) window.location.replace(bankForward.href);
