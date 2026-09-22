<?php

declare(strict_types=1);

namespace LlmKit\Catalog;

/**
 * Catalog is a static table of model metadata: context windows, output
 * limits, list prices and capabilities, keyed by model ID. It needs no client
 * and makes no network calls. The data is a snapshot (see DATA_AS_OF);
 * register overrides a row and lookup returns an unknown model, rather than a
 * guess, for anything absent.
 */
final class Catalog
{
    /** DATA_AS_OF is the date the seed data was last checked against vendor pages. */
    public const string DATA_AS_OF = '2026-09-19';

    /** @var array<string,Model> */
    private static array $byId = [];

    /** @var array<string,string> */
    private static array $byAlias = [];

    private static bool $booted = false;

    /**
     * register adds or replaces a row by ID, marking it known. Aliases are
     * case-insensitive and override any earlier owner.
     */
    public static function register(Model $model): void
    {
        self::boot();
        $id = strtolower($model->id);
        foreach (self::$byAlias as $alias => $owner) {
            if ($owner === $id) {
                unset(self::$byAlias[$alias]);
            }
        }
        self::$byId[$id] = $model->asKnown();
        foreach ($model->aliases as $alias) {
            self::$byAlias[strtolower($alias)] = $id;
        }
    }

    /**
     * all returns every row, sorted by provider then ID.
     *
     * @return list<Model>
     */
    public static function all(): array
    {
        self::boot();
        $out = array_values(self::$byId);
        usort($out, static fn(Model $a, Model $b): int => [$a->provider, $a->id] <=> [$b->provider, $b->id]);

        return $out;
    }

    /**
     * lookup resolves a model name to its row: exact ID, then alias, then the
     * longest known ID that is a prefix followed by a dated or versioned
     * suffix ("-20251101" and "@20250929" are suffixes; "-mini" is not, so a
     * variant the catalog lacks stays unknown instead of borrowing a
     * sibling's prices). A leading "provider/" prefix and a Vertex "@version"
     * suffix are stripped. Matching is case-insensitive.
     */
    public static function lookup(string $model): Model
    {
        self::boot();
        $candidates = self::candidates($model);
        foreach ($candidates as $candidate) {
            $found = self::exact($candidate);
            if ($found !== null) {
                return $found;
            }
        }
        foreach ($candidates as $candidate) {
            $found = self::longestPrefix($candidate);
            if ($found !== null) {
                return $found;
            }
        }

        return Model::unknown($model);
    }

    /** reset restores the seed data, dropping every registered override. */
    public static function reset(): void
    {
        self::$byId = [];
        self::$byAlias = [];
        self::$booted = false;
        self::boot();
    }

    private static function boot(): void
    {
        if (self::$booted) {
            return;
        }
        self::$booted = true;
        foreach (Seed::models() as $model) {
            self::register($model);
        }
    }

    /**
     * candidates lists the name, then the name with each leading "x/" segment
     * removed, each also without any "@version" suffix. Groq-style IDs that
     * contain a slash themselves are tried whole first.
     *
     * @return list<string>
     */
    private static function candidates(string $model): array
    {
        $out = [];
        $name = strtolower(trim($model));
        while (true) {
            $out[] = $name;
            $at = strpos($name, '@');
            if ($at !== false && $at > 0) {
                $out[] = substr($name, 0, $at);
            }
            $slash = strpos($name, '/');
            if ($slash === false || $slash === strlen($name) - 1) {
                return $out;
            }
            $name = substr($name, $slash + 1);
        }
    }

    private static function exact(string $name): ?Model
    {
        if (isset(self::$byId[$name])) {
            return self::$byId[$name];
        }
        $owner = self::$byAlias[$name] ?? null;

        return $owner === null ? null : (self::$byId[$owner] ?? null);
    }

    private static function longestPrefix(string $name): ?Model
    {
        $best = null;
        foreach (self::$byId as $id => $model) {
            if (strlen($id) >= strlen($name) || !str_starts_with($name, $id)) {
                continue;
            }
            if (!self::isVersionSuffix(substr($name, strlen($id)))) {
                continue;
            }
            if ($best === null || strlen($id) > strlen($best->id)) {
                $best = $model;
            }
        }

        return $best;
    }

    /**
     * isVersionSuffix accepts a separator followed by a digit, the shape of
     * dated snapshots and pinned versions.
     */
    private static function isVersionSuffix(string $suffix): bool
    {
        return strlen($suffix) >= 2
            && str_contains('-@:', $suffix[0])
            && $suffix[1] >= '0'
            && $suffix[1] <= '9';
    }
}
