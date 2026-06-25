<?php

namespace App\Services\Notifications;

/**
 * Mustache-lite renderer: replaces {{ key }} / {{ nested.key }} in a template
 * with values from a payload array. No control flow, no PHP — safe to expose
 * to admin template editing.
 *
 * Missing keys render as the literal placeholder so admins notice typos.
 */
class TemplateRenderer
{
    public function render(string $template, array $data): string
    {
        return preg_replace_callback('/\{\{\s*([\w.]+)\s*\}\}/', function ($m) use ($data) {
            $value = $this->resolve($m[1], $data);
            if ($value === null) {
                return $m[0];
            }
            return is_scalar($value) ? (string) $value : json_encode($value);
        }, $template);
    }

    public function variables(string $template): array
    {
        preg_match_all('/\{\{\s*([\w.]+)\s*\}\}/', $template, $matches);
        return array_values(array_unique($matches[1] ?? []));
    }

    private function resolve(string $path, array $data): mixed
    {
        $parts = explode('.', $path);
        $cursor = $data;
        foreach ($parts as $p) {
            if (is_array($cursor) && array_key_exists($p, $cursor)) {
                $cursor = $cursor[$p];
            } elseif (is_object($cursor) && isset($cursor->$p)) {
                $cursor = $cursor->$p;
            } else {
                return null;
            }
        }
        return $cursor;
    }
}
