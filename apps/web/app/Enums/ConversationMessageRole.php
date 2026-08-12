<?php

namespace App\Enums;

enum ConversationMessageRole: string
{
    case User = 'user';
    case Assistant = 'assistant';
}
