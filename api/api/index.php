<?php

// Vercel entrypoint: the vercel-php runtime only serves functions under api/,
// so hand every request to Laravel's normal front controller.
require __DIR__.'/../public/index.php';
