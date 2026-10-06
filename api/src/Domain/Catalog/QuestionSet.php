<?php

declare(strict_types=1);

namespace ConsultDesk\Domain\Catalog;

use InvalidArgumentException;
use JsonException;

final class QuestionSet
{
    /**
     * @param list<Question> $questions
     */
    public function __construct(public readonly array $questions) {}

    public static function fromJson(string $json): self
    {
        try {
            $decoded = json_decode($json, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new InvalidArgumentException('Questions must be valid JSON.', 0, $e);
        }
        if (!is_array($decoded) || !array_is_list($decoded)) {
            throw new InvalidArgumentException('Questions must be a JSON array.');
        }

        return new self(array_map(self::question(...), $decoded));
    }

    /**
     * Checks answers against the questions, keeping only known question ids.
     *
     * @param array<string, mixed> $input
     */
    public function validate(array $input): AnswerResult
    {
        $answers = [];
        $errors = [];
        foreach ($this->questions as $question) {
            [$value, $error] = $question->check($input[$question->id] ?? null);
            if ($error !== null) {
                $errors[$question->id] = $error;
            } elseif ($value !== null) {
                $answers[$question->id] = $value;
            }
        }

        return new AnswerResult($answers, $errors);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function toArray(): array
    {
        return array_map(static fn(Question $q): array => $q->toArray(), $this->questions);
    }

    private static function question(mixed $raw): Question
    {
        if (!is_array($raw)) {
            throw new InvalidArgumentException('Each question must be an object.');
        }
        $type = QuestionType::tryFrom(is_string($raw['type'] ?? null) ? $raw['type'] : '')
            ?? throw new InvalidArgumentException('Unknown question type.');
        $options = $raw['options'] ?? [];

        return new Question(
            id: is_string($raw['id'] ?? null) ? $raw['id'] : '',
            label: is_string($raw['label'] ?? null) ? $raw['label'] : '',
            type: $type,
            required: ($raw['required'] ?? false) === true,
            options: is_array($options) ? array_values(array_filter($options, 'is_string')) : [],
        );
    }
}
