<?php
/**
 * public/index.php — front controller, the ONLY PHP file nginx executes.
 *
 * The "front controller" pattern means every URL on the site (except real
 * files like /assets/css/site.css) is handled by this one script. Benefits:
 *   - All security checks happen in one place
 *   - Pretty URLs without filesystem mirroring
 *   - One bootstrap, one router, one place to add middleware
 *
 * The actual routing lives in src/router.php. This file stays trivial on
 * purpose — easier to audit.
 */

require __DIR__ . '/../src/router.php';
