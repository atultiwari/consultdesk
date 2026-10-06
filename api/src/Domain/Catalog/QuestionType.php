<?php

declare(strict_types=1);

namespace ConsultDesk\Domain\Catalog;

enum QuestionType: string
{
    case Text = 'text';
    case Textarea = 'textarea';
    case Select = 'select';
    case Url = 'url';
    case Checkbox = 'checkbox';

    public function maxLength(): int
    {
        return match ($this) {
            self::Textarea => 5000,
            default => 500,
        };
    }
}
