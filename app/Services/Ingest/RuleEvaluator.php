<?php

namespace App\Services\Ingest;

use App\Models\Endpoint;
use Illuminate\Contracts\Database\Query\Builder;

/**
 * Evaluates a user-defined rule (a set of conditions) against the endpoints in
 * scope and returns how many match. Powers custom stat/gauge widgets such as
 * "endpoints last seen more than 2 days ago" or "Windows AND non-compliant".
 */
class RuleEvaluator
{
    public const FIELDS = [
        'hostname', 'os_platform', 'os_version', 'agent_version', 'health_status',
        'compliance_status', 'ip_address', 'mac_address', 'external_id',
        'is_isolated', 'last_seen_days',
    ];

    /** Ops that require a (non-empty) value. */
    private const NEEDS_VALUE = ['eq', 'neq', 'contains', 'not_contains', 'in'];

    /**
     * @param  array<int, int>  $snapshotIds
     * @param  array<string, mixed>  $rule  ['match' => 'all'|'any', 'conditions' => [['field','op','value'], ...]]
     * @return array{count:int,total:int,pct:float}
     */
    public function count(array $snapshotIds, array $rule): array
    {
        if (empty($snapshotIds)) {
            return ['count' => 0, 'total' => 0, 'pct' => 0];
        }

        $total = Endpoint::whereIn('snapshot_id', $snapshotIds)->count();
        $query = $this->buildQuery($snapshotIds, $rule);

        if (! $query) {
            return ['count' => 0, 'total' => $total, 'pct' => 0];
        }

        $matched = $query->count();

        return [
            'count' => $matched,
            'total' => $total,
            'pct' => $total > 0 ? round($matched / $total * 100, 1) : 0,
        ];
    }

    /** Build the query for endpoints matching a rule; returns null if rule is empty. */
    public function buildQuery(array $snapshotIds, array $rule)
    {
        $conditions = array_values(array_filter($rule['conditions'] ?? [], fn ($c) => $this->valid($c)));
        if (empty($conditions) || empty($snapshotIds)) {
            return null;
        }

        $useOr = (($rule['match'] ?? 'all') === 'any');

        return Endpoint::whereIn('snapshot_id', $snapshotIds)
            ->where(function ($w) use ($conditions, $useOr) {
                foreach ($conditions as $c) {
                    $this->applyCondition($w, $c, $useOr ? 'orWhere' : 'where');
                }
            });
    }

    private function valid(array $c): bool
    {
        $field = $c['field'] ?? null;
        $op = $c['op'] ?? null;

        if (! $op || ! $this->isAllowedField($field)) {
            return false;
        }

        // Value-requiring ops must have a value.
        if (in_array($op, self::NEEDS_VALUE, true) && ($c['value'] ?? '') === '') {
            return false;
        }

        return true;
    }

    /** Standard column, or a custom mapped field stored in the `extra` JSON (extra.<slug>). */
    private function isAllowedField(?string $field): bool
    {
        if ($field === null) {
            return false;
        }

        return in_array($field, self::FIELDS, true)
            || (bool) preg_match('/^extra\.[A-Za-z0-9_]+$/', $field);
    }

    private function applyCondition(Builder $w, array $c, string $method): void
    {
        $field = $c['field'];
        $op = $c['op'];
        $value = $c['value'] ?? null;
        $orInMethod = $method === 'orWhere' ? 'orWhereIn' : 'whereIn';

        // Derived: days since last check-in.
        if ($field === 'last_seen_days') {
            $cutoff = now()->subDays((int) $value);
            if ($op === 'gt') {
                // Seen longer ago than N days — or never seen at all.
                $w->{$method}(function ($x) use ($cutoff) {
                    $x->where('last_seen_at', '<', $cutoff)->orWhereNull('last_seen_at');
                });
            } else {
                $w->{$method}('last_seen_at', '>=', $cutoff);
            }

            return;
        }

        if ($field === 'is_isolated') {
            $w->{$method}('is_isolated', filter_var($value, FILTER_VALIDATE_BOOLEAN));

            return;
        }

        // Custom mapped field stored in the `extra` JSON column (extra.<slug>).
        if (str_starts_with($field, 'extra.')) {
            $this->applyExtra($w, 'extra->'.substr($field, 6), $op, $value, $method);

            return;
        }

        switch ($op) {
            case 'eq':
                $w->{$method}($field, '=', $value);
                break;
            case 'neq':
                $w->{$method}(function ($x) use ($field, $value) {
                    $x->where($field, '!=', $value)->orWhereNull($field);
                });
                break;
            case 'contains':
                $w->{$method}($field, 'like', '%'.$value.'%');
                break;
            case 'not_contains':
                $w->{$method}($field, 'not like', '%'.$value.'%');
                break;
            case 'in':
                $w->{$orInMethod}($field, array_map('trim', explode(',', (string) $value)));
                break;
            case 'is_empty':
                $w->{$method}(function ($x) use ($field) {
                    $x->whereNull($field)->orWhere($field, '');
                });
                break;
            case 'not_empty':
                $w->{$method}(function ($x) use ($field) {
                    $x->whereNotNull($field)->where($field, '!=', '');
                });
                break;
            default:
                $w->{$method}($field, '=', $value);
        }
    }

    /** Same operators as standard fields, but against a JSON path in `extra`. */
    private function applyExtra(Builder $w, string $col, string $op, mixed $value, string $method): void
    {
        $orInMethod = $method === 'orWhere' ? 'orWhereIn' : 'whereIn';

        switch ($op) {
            case 'eq':
                $w->{$method}($col, '=', $value);
                break;
            case 'neq':
                $w->{$method}(function ($x) use ($col, $value) {
                    $x->where($col, '!=', $value)->orWhereNull($col);
                });
                break;
            case 'contains':
                $w->{$method}($col, 'like', '%'.$value.'%');
                break;
            case 'not_contains':
                $w->{$method}($col, 'not like', '%'.$value.'%');
                break;
            case 'gt':
                $w->{$method}($col, '>', $value);
                break;
            case 'lt':
                $w->{$method}($col, '<', $value);
                break;
            case 'in':
                $w->{$orInMethod}($col, array_map('trim', explode(',', (string) $value)));
                break;
            case 'is_empty':
                $w->{$method}(function ($x) use ($col) {
                    $x->whereNull($col)->orWhere($col, '');
                });
                break;
            case 'not_empty':
                $w->{$method}(function ($x) use ($col) {
                    $x->whereNotNull($col)->where($col, '!=', '');
                });
                break;
            default:
                $w->{$method}($col, '=', $value);
        }
    }
}
