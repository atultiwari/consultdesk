<?php

declare(strict_types=1);

namespace ConsultDesk\Notify\Mail;

use InvalidArgumentException;

/**
 * Immutable builder that renders the same content as plain text and as simple, escaped HTML.
 */
final class EmailBody
{
    /**
     * @param list<array{string, mixed}> $blocks
     */
    public function __construct(private readonly array $blocks = [])
    {
    }

    public function heading(string $text): self
    {
        return $this->with('heading', $text);
    }

    public function paragraph(string $text): self
    {
        return $this->with('paragraph', $text);
    }

    /**
     * @param array<string, string> $rows label => value
     */
    public function details(array $rows): self
    {
        return $this->with('details', $rows);
    }

    public function button(string $label, string $url): self
    {
        if (preg_match('#^https?://#i', $url) !== 1) {
            throw new InvalidArgumentException('Email buttons must link to an http(s) URL.');
        }

        return $this->with('button', [$label, $url]);
    }

    public function note(string $text): self
    {
        return $this->with('note', $text);
    }

    public function toText(): string
    {
        $parts = [];
        foreach ($this->blocks as [$type, $value]) {
            $parts[] = match ($type) {
                'details' => implode("\n", array_map(
                    static fn (string $label, string $v): string => "{$label}: {$v}",
                    array_keys(self::rows($value)),
                    self::rows($value),
                )),
                'button' => sprintf('%s: %s', ...self::pair($value)),
                default => self::string($value),
            };
        }

        return $parts === [] ? '' : implode("\n\n", $parts) . "\n";
    }

    public function toHtml(): string
    {
        $html = '';
        foreach ($this->blocks as [$type, $value]) {
            $html .= match ($type) {
                'heading' => sprintf('<h1 style="font-size:20px;margin:0 0 16px">%s</h1>', self::e(self::string($value))),
                'paragraph' => sprintf('<p style="margin:0 0 16px">%s</p>', self::e(self::string($value))),
                'note' => sprintf('<p style="margin:0 0 16px;color:#555;font-size:14px">%s</p>', self::e(self::string($value))),
                'details' => self::detailsHtml(self::rows($value)),
                'button' => vsprintf(
                    '<p style="margin:0 0 16px"><a href="%2$s" style="display:inline-block;padding:10px 18px;background:#5b3f8c;color:#fff;border-radius:6px;text-decoration:none">%1$s</a></p>',
                    array_map(self::e(...), self::pair($value)),
                ),
                default => '',
            };
        }

        return '<!doctype html><html><body style="font-family:system-ui,sans-serif;line-height:1.5;color:#1d1a24;max-width:560px;margin:0 auto;padding:24px">'
            . $html . '</body></html>';
    }

    private function with(string $type, mixed $value): self
    {
        return new self([...$this->blocks, [$type, $value]]);
    }

    /**
     * @param array<string, string> $rows
     */
    private static function detailsHtml(array $rows): string
    {
        $cells = '';
        foreach ($rows as $label => $value) {
            $cells .= sprintf(
                '<tr><th style="text-align:left;padding:4px 12px 4px 0;color:#555;font-weight:normal;vertical-align:top">%s</th><td style="padding:4px 0">%s</td></tr>',
                self::e($label),
                self::e($value),
            );
        }

        return '<table style="border-collapse:collapse;margin:0 0 16px">' . $cells . '</table>';
    }

    private static function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    private static function string(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }

    /**
     * @return array<string, string>
     */
    private static function rows(mixed $value): array
    {
        $rows = [];
        foreach (is_array($value) ? $value : [] as $label => $v) {
            $rows[(string) $label] = is_scalar($v) ? (string) $v : '';
        }

        return $rows;
    }

    /**
     * @return array{string, string}
     */
    private static function pair(mixed $value): array
    {
        return is_array($value) && count($value) === 2 ? [self::string($value[0]), self::string($value[1])] : ['', ''];
    }
}
