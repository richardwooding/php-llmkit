<?php

declare(strict_types=1);

namespace LlmKit\Cli;

/**
 * Flags is a small argv parser: "-m value", "--model value", "--model=value"
 * and boolean switches, with everything else kept as an operand.
 *
 * @internal
 */
final readonly class Flags
{
    /**
     * @param array<string,string> $values
     * @param list<string>         $switches
     * @param list<string>         $operands
     */
    private function __construct(
        private array $values,
        private array $switches,
        private array $operands,
    ) {}

    /**
     * parse reads $args; names in $booleans take no value.
     *
     * @param list<string> $args
     * @param list<string> $booleans
     */
    public static function parse(array $args, array $booleans = []): self
    {
        $values = [];
        $switches = [];
        $operands = [];
        for ($i = 0; $i < count($args); ++$i) {
            $arg = $args[$i];
            if ($arg === '--') {
                $operands = [...$operands, ...array_slice($args, $i + 1)];
                break;
            }
            if (!str_starts_with($arg, '-') || $arg === '-') {
                $operands[] = $arg;
                continue;
            }
            $name = ltrim($arg, '-');
            $equals = strpos($name, '=');
            if ($equals !== false) {
                $values[substr($name, 0, $equals)] = substr($name, $equals + 1);
                continue;
            }
            if (in_array($name, $booleans, true)) {
                $switches[] = $name;
                continue;
            }
            $values[$name] = $args[$i + 1] ?? '';
            ++$i;
        }

        return new self($values, $switches, $operands);
    }

    public function string(string $name, string $default = ''): string
    {
        $value = $this->values[$name] ?? '';

        return $value === '' ? $default : $value;
    }

    public function int(string $name, int $default = 0): int
    {
        $value = $this->values[$name] ?? '';

        return is_numeric($value) ? (int) $value : $default;
    }

    public function float(string $name): ?float
    {
        $value = $this->values[$name] ?? '';

        return is_numeric($value) ? (float) $value : null;
    }

    public function bool(string $name): bool
    {
        return in_array($name, $this->switches, true);
    }

    /** @return list<string> */
    public function operands(): array
    {
        return $this->operands;
    }
}
