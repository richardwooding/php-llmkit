<?php

declare(strict_types=1);

namespace LlmKit;

/** ToolChoiceMode controls whether the model may, must, or must not call tools. */
enum ToolChoiceMode: string
{
    case Auto = 'auto';
    case None = 'none';
    case Required = 'required';
    case Named = 'named';
}
