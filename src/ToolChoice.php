<?php

declare(strict_types=1);

namespace LlmKit;

/** ToolChoice selects a ToolChoiceMode; name applies to ToolChoiceMode::Named. */
final readonly class ToolChoice
{
    public function __construct(public ToolChoiceMode $mode, public string $name = '') {}

    /** auto lets the model decide. */
    public static function auto(): self
    {
        return new self(ToolChoiceMode::Auto);
    }

    /** none forbids tool calls. */
    public static function none(): self
    {
        return new self(ToolChoiceMode::None);
    }

    /** required forces at least one tool call. */
    public static function required(): self
    {
        return new self(ToolChoiceMode::Required);
    }

    /** named forces a call to one tool. */
    public static function named(string $name): self
    {
        return new self(ToolChoiceMode::Named, $name);
    }
}
