<?php

declare(strict_types=1);

namespace LlmKit;

/** EmbedInputType hints whether inputs are search queries or documents. */
enum EmbedInputType: string
{
    case Query = 'query';
    case Document = 'document';
}
