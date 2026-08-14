<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Built handbook root
    |--------------------------------------------------------------------------
    |
    | Where `npm run docs:build` renders the visual handbook, and where
    | `HandbookController` serves it from. Deliberately OUTSIDE the webroot so
    | nginx cannot serve it directly and the `auth` middleware on `/handbook`
    | genuinely applies — it documents posting rules, GL mappings and approval
    | ladders. Keep it in step with `outDir` in `docs/visual/.vitepress/config.mts`.
    |
    | It is configurable for ONE reason, and it is not deployment flexibility:
    | the tests that exercise the route must write their fixtures somewhere that
    | is not the real build. Before this they wrote into the build itself, so
    | every run of the suite replaced the operator's handbook with three
    | one-line stubs — a passing test that broke the feature it was testing.
    |
    */

    'root' => storage_path('app/handbook'),

];
