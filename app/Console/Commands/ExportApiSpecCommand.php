<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;

/**
 * Exports the mobile API (/api/v1) OpenAPI spec to docs/api/openapi.json.
 *
 * Scramble introspects the backend, which works in snake_case — but the live
 * API speaks camelCase to clients (KeyCase::camelKeys via the response/request
 * middleware). So we post-process the generated spec, camelCasing the data
 * property names with the SAME Str::camel transform the middleware uses, so the
 * spec a client codes against matches what the wire actually carries.
 */
class ExportApiSpecCommand extends Command
{
    protected $signature = 'api:export-spec {--path=docs/api/openapi.json}';

    protected $description = 'Generate the /api/v1 OpenAPI spec (camelCase, client-accurate)';

    public function handle(): int
    {
        $raw = storage_path('app/openapi-raw.json');
        Artisan::call('scramble:export', ['--path' => $raw]);

        if (! is_file($raw)) {
            $this->error('Scramble did not produce a spec.');

            return self::FAILURE;
        }

        $spec = json_decode((string) file_get_contents($raw), true);
        @unlink($raw);

        $spec = $this->camelize($spec);

        $out = base_path($this->option('path'));
        if (! is_dir(dirname($out))) {
            mkdir(dirname($out), 0755, true);
        }
        file_put_contents($out, json_encode($spec, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");

        $this->info('OpenAPI spec written to '.$this->option('path').' ('.count($spec['paths'] ?? []).' paths).');

        return self::SUCCESS;
    }

    /**
     * Recursively camelCase the DATA names in the spec (schema property names,
     * required[] entries, query-parameter names, and example object keys) while
     * leaving OpenAPI keywords, $refs, enum values and path templates untouched.
     */
    private function camelize(mixed $node): mixed
    {
        if (! is_array($node)) {
            return $node;
        }

        if (array_is_list($node)) {
            return array_map(fn ($v) => $this->camelize($v), $node);
        }

        // A query parameter object: camelCase its public name (per_page → perPage).
        if (($node['in'] ?? null) === 'query' && isset($node['name']) && is_string($node['name'])) {
            $node['name'] = Str::camel($node['name']);
        }

        $out = [];
        foreach ($node as $key => $val) {
            if ($key === 'properties' && is_array($val) && ! array_is_list($val)) {
                $props = [];
                foreach ($val as $propName => $propSchema) {
                    $props[is_string($propName) ? Str::camel($propName) : $propName] = $this->camelize($propSchema);
                }
                $out['properties'] = $props;
            } elseif ($key === 'required' && is_array($val) && array_is_list($val)) {
                $out['required'] = array_map(fn ($v) => is_string($v) ? Str::camel($v) : $v, $val);
            } elseif ($key === 'example' || $key === 'default') {
                $out[$key] = $this->camelizeData($val);
            } else {
                $out[$key] = $this->camelize($val);
            }
        }

        return $out;
    }

    /** Example/default VALUES are real payloads → camelCase their object keys. */
    private function camelizeData(mixed $val): mixed
    {
        if (! is_array($val)) {
            return $val;
        }
        if (array_is_list($val)) {
            return array_map(fn ($v) => $this->camelizeData($v), $val);
        }
        $out = [];
        foreach ($val as $k => $v) {
            $out[is_string($k) ? Str::camel($k) : $k] = $this->camelizeData($v);
        }

        return $out;
    }
}
