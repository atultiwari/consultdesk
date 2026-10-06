<?php

declare(strict_types=1);

namespace ConsultDesk\Http\Validation;

use DateTimeImmutable;
use DateTimeZone;
use Exception;

/**
 * Typed reader over decoded request data. Each accessor returns the value or null and records a
 * field error; call assertValid() once everything has been read to fail with all errors at once.
 */
final class Input
{
    private const ISO_DATETIME = '/^(\d{4})-(\d{2})-(\d{2})T\d{2}:\d{2}(:\d{2}(\.\d{1,6})?)?(Z|[+-]\d{2}:\d{2})$/';
    private const ISO_DATE = '/^(\d{4})-(\d{2})-(\d{2})$/';

    /**
     * @param array<array-key, mixed> $data
     */
    public function __construct(
        private readonly array $data,
        private readonly string $prefix = '',
        private readonly ErrorBag $errors = new ErrorBag(),
    ) {}

    public function string(string $field, bool $required = true, int $max = 255): ?string
    {
        $value = $this->raw($field);
        if ($value === null || $value === '') {
            return $this->missing($field, $required);
        }
        if (!is_string($value)) {
            return $this->fail($field, 'Must be text.');
        }

        $value = trim($value);
        if ($value === '') {
            return $this->missing($field, $required);
        }
        if (mb_strlen($value) > $max) {
            return $this->fail($field, sprintf('Must be at most %d characters.', $max));
        }

        return $value;
    }

    public function email(string $field, bool $required = true): ?string
    {
        $value = $this->string($field, $required, 254);
        if ($value !== null && filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
            return $this->fail($field, 'Enter a valid email address.');
        }

        return $value;
    }

    /**
     * @param list<string> $allowed
     */
    public function oneOf(string $field, array $allowed, bool $required = true): ?string
    {
        $value = $this->string($field, $required, 64);
        if ($value !== null && !in_array($value, $allowed, true)) {
            return $this->fail($field, sprintf('Must be one of: %s.', implode(', ', $allowed)));
        }

        return $value;
    }

    public function dateTime(string $field, bool $required = true): ?DateTimeImmutable
    {
        $value = $this->string($field, $required, 40);
        if ($value === null) {
            return null;
        }
        if (preg_match(self::ISO_DATETIME, $value, $m) !== 1 || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return $this->fail($field, 'Must be an ISO 8601 date-time with a timezone, e.g. 2026-10-07T04:30:00Z.');
        }

        try {
            return (new DateTimeImmutable($value))->setTimezone(new DateTimeZone('UTC'));
        } catch (Exception) {
            return $this->fail($field, 'Must be a valid date-time.');
        }
    }

    public function date(string $field, bool $required = true): ?string
    {
        $value = $this->string($field, $required, 10);
        if ($value !== null && (preg_match(self::ISO_DATE, $value, $m) !== 1 || !checkdate((int) $m[2], (int) $m[3], (int) $m[1]))) {
            return $this->fail($field, 'Must be a date like 2026-10-07.');
        }

        return $value;
    }

    /**
     * A person's name: no links, markup or control characters, so it cannot carry a phishing
     * message into emails sent to whatever address was entered.
     */
    public function personName(string $field, int $max = 120): ?string
    {
        $value = $this->string($field, true, $max);
        if ($value !== null && preg_match('#://|www\.|[<>]|[\x00-\x1F\x7F]#i', $value) === 1) {
            return $this->fail($field, 'Enter just your name.');
        }

        return $value;
    }

    public function phone(string $field, bool $required = true): ?string
    {
        $value = $this->string($field, $required, 20);
        if ($value !== null && preg_match('/^\+?[0-9][0-9 ()-]{5,19}$/', $value) !== 1) {
            return $this->fail($field, 'Enter a phone number with country code, e.g. +91 98765 43210.');
        }

        return $value;
    }

    public function timezone(string $field, bool $required = true): ?string
    {
        $value = $this->string($field, $required, 64);
        if ($value !== null && !in_array($value, DateTimeZone::listIdentifiers(), true)) {
            return $this->fail($field, 'Unknown timezone.');
        }

        return $value;
    }

    /**
     * Records an error found outside this reader, e.g. by validating intake answers.
     */
    public function reject(string $field, string $message): void
    {
        $this->fail($field, $message);
    }

    /**
     * An object of string keys, e.g. intake answers. Missing means empty.
     *
     * @return array<string, mixed>
     */
    public function map(string $field): array
    {
        $value = $this->raw($field);
        if ($value === null) {
            return [];
        }
        if (!is_array($value) || ($value !== [] && array_is_list($value))) {
            $this->fail($field, 'Must be an object.');

            return [];
        }

        $map = [];
        foreach ($value as $key => $item) {
            $map[(string) $key] = $item;
        }

        return $map;
    }

    public function nested(string $field): self
    {
        $value = $this->raw($field);
        if ($value === null) {
            $this->fail($field, 'This field is required.');
        } elseif (!is_array($value)) {
            $this->fail($field, 'Must be an object.');
        }

        return new self(is_array($value) ? $value : [], $this->path($field) . '.', $this->errors);
    }

    /**
     * @return array<string, string>
     */
    public function errors(): array
    {
        return $this->errors->all();
    }

    /**
     * @throws ValidationFailed
     */
    public function assertValid(): void
    {
        if ($this->errors->all() !== []) {
            throw new ValidationFailed($this->errors->all());
        }
    }

    private function raw(string $field): mixed
    {
        return $this->data[$field] ?? null;
    }

    /**
     * @return null
     */
    private function missing(string $field, bool $required): mixed
    {
        return $required ? $this->fail($field, 'This field is required.') : null;
    }

    /**
     * @return null
     */
    private function fail(string $field, string $message): mixed
    {
        $this->errors->add($this->path($field), $message);

        return null;
    }

    private function path(string $field): string
    {
        return $this->prefix . $field;
    }
}
