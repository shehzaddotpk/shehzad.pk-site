<?php
// Copy to config.php on the server (api/config.php). Never commit real secrets.
return [
  'to' => 'write2me@shehzad.pk',
  'from' => 'no-reply@shehzad.pk',          // should be an address on shehzad.pk for deliverability
  'turnstile_secret' => '',             // paste the Cloudflare Turnstile SECRET key to enforce Turnstile
  'max_per_hour' => 5,
];
