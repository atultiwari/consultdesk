<?php

declare(strict_types=1);

namespace ConsultDesk\Domain\Catalog;

use InvalidArgumentException;

/**
 * One intake question on a service (services.questions JSON, edited in the admin question builder).
 */
final class Question
{
    private const ID_PATTERN = '/^[a-z][a-z0-9_]{0,39}$/';

    /**
     * @param list<string> $options choices for select questions
     */
    public function __construct(
        public readonly string $id,
        public readonly string $label,
        public readonly QuestionType $type,
        public readonly bool $required = false,
        public readonly array $options = [],
    ) {
        if (preg_match(self::ID_PATTERN, $id) !== 1) {
            throw new InvalidArgumentException(sprintf('Invalid question id "%s".', $id));
        }
        if (trim($label) === '' || mb_strlen($label) > 200) {
            throw new InvalidArgumentException('Question label must be 1–200 characters.');
        }
        if ($type === QuestionType::Select && $options === []) {
            throw new InvalidArgumentException(sprintf('Select question "%s" needs options.', $id));
        }
    }

    /**
     * Validates one answer: returns [normalised value, null] or [null, error message].
     *
     * @return array{mixed, ?string}
     */
    public function check(mixed $answer): array
    {
        if ($this->type === QuestionType::Checkbox) {
            $checked = $answer === true;
            if ($answer !== null && !is_bool($answer)) {
                return [null, 'Must be true or false.'];
            }

            return $this->required && !$checked ? [null, 'Please confirm this to continue.'] : [$checked, null];
        }

        $text = is_string($answer) ? trim($answer) : null;
        if ($answer !== null && $text === null) {
            return [null, 'Must be text.'];
        }
        if ($text === null || $text === '') {
            return $this->required ? [null, 'This field is required.'] : [null, null];
        }

        return match (true) {
            mb_strlen($text) > $this->type->maxLength() => [null, sprintf('Must be at most %d characters.', $this->type->maxLength())],
            $this->type === QuestionType::Select && !in_array($text, $this->options, true) => [null, 'Choose one of the options.'],
            $this->type === QuestionType::Url && (preg_match('#^https?://\S+$#i', $text) !== 1 || filter_var($text, FILTER_VALIDATE_URL) === false) => [null, 'Enter a link starting with https://.'],
            default => [$text, null],
        };
    }

    /**
     * @return array{id: string, label: string, type: string, required: bool, options?: list<string>}
     */
    public function toArray(): array
    {
        $out = ['id' => $this->id, 'label' => $this->label, 'type' => $this->type->value, 'required' => $this->required];
        if ($this->options !== []) {
            $out['options'] = $this->options;
        }

        return $out;
    }
}
