<?php

return [

    /*
     * The password every seeded demo/learning account gets.
     *
     * THIS LIVES IN A CONFIG FILE FOR A REASON. It used to be read as
     * `env('DEMO_USER_PASSWORD', 'password')` from inside the seeders — and `env()` returns null
     * for everything once `php artisan config:cache` has run, because a cached config means the
     * `.env` file is never loaded at all. `deploy.sh` caches the config on every release, so on
     * EVERY deployed box both seeders silently fell back to the literal string `password`, however
     * carefully the operator had set the variable.
     *
     * That is the exact control `.env.example` and STATUS §1.3 tell an operator to set *before the
     * URL is shareable*, so the failure was: they set it, the seeder ignored it, nothing said so,
     * and `admin@mall.test` — a super_admin — was reachable with the published password.
     *
     * Config files are the one place `env()` is guaranteed to work, because they are what gets
     * cached. Read this through `config()`, never `env()`, from anywhere else.
     */
    'user_password' => env('DEMO_USER_PASSWORD', 'password'),

];
