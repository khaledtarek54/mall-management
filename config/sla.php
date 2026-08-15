<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Service-level targets by priority
    |--------------------------------------------------------------------------
    |
    | The COLD-START defaults only — tier 3, for a fresh install with no settings
    | row and no per-property policy. `App\Support\SlaResolver` documents the full
    | chain and why these deliberately differ from the settings defaults.
    |
    | `respond_hours` is how long a job may sit before anybody accepts it;
    | `resolve_hours` is how long it may take once accepted (FR-CM-07).
    |
    | Read by BOTH work streams — tenant requests and facility work orders — which
    | is why this lives under `sla` and not under either module's name.
    |
    */
    'urgent' => ['resolve_hours' => 24, 'respond_hours' => 2],
    'high' => ['resolve_hours' => 72, 'respond_hours' => 8],
    'medium' => ['resolve_hours' => 168, 'respond_hours' => 48],   // 7 days / 2 days
    'low' => ['resolve_hours' => 336, 'respond_hours' => 96],      // 14 days / 4 days
];
