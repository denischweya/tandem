<?php

declare(strict_types=1);

// No web routes: this is a bearer-token-only API host (ADR 0004 puts the web app in a
// separate repo; ADR 0002 keeps it there). The file is kept, empty, only because
// bootstrap/app.php's withRouting(web: ...) wiring expects it to exist - deleting the
// framework's generated welcome route and view (spec §8 lists only /api/v1/*, /health,
// /ready) is the point: with APP_DEBUG=false it served Laravel's exact version number,
// unauthenticated, to anyone (CWE-200). See tests/Feature/WelcomePageTest.php.
