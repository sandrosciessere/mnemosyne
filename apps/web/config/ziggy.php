<?php

return [
    // Route names exposed to the frontend bundle (resources/js/ziggy.js —
    // regenerate with `php artisan ziggy:generate resources/js/ziggy.js`
    // after changing routes). Internal dashboards are excluded: the SPA
    // never builds Horizon URLs.
    'except' => [
        'horizon.*',
    ],
];
