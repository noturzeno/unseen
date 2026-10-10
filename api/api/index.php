<?php

// Vercel entrypoint: the vercel-php runtime only serves functions under api/,
// so hand every request to Laravel's normal front controller.

// Present the request as if public/index.php ran it. Otherwise Laravel sees SCRIPT_NAME
// /api/index.php, treats /api as the base path and strips it: /api/v1/... becomes v1/...
// and every API route 404s.
$_SERVER['SCRIPT_FILENAME'] = __DIR__.'/../public/index.php';
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['PHP_SELF'] = '/index.php';

require __DIR__.'/../public/index.php';
