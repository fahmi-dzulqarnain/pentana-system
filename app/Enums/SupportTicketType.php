<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum SupportTicketType: string implements HasLabel
{
    case Question = 'question';
    case Bug = 'bug';
    case Suggestion = 'suggestion';

    public function getLabel(): string
    {
        return match ($this) {
            self::Question => __('Question'),
            self::Bug => __('Bug'),
            self::Suggestion => __('Suggestion'),
        };
    }
}
