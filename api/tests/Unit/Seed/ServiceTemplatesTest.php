<?php

declare(strict_types=1);

namespace ConsultDesk\Tests\Unit\Seed;

use ConsultDesk\Domain\Catalog\QuestionSet;
use ConsultDesk\Seed\ServiceTemplates;
use PHPUnit\Framework\TestCase;

final class ServiceTemplatesTest extends TestCase
{
    public function testTemplatesMatchThePlanAndHaveValidQuestions(): void
    {
        $templates = ServiceTemplates::all();

        self::assertSame(
            ['intro-call' => 0, 'ai-career-guidance' => 149900, 'research-guidance' => 299900, 'code-review' => 349900,
                'ai-tools-hands-on' => 199900, 'startup-consult' => 449900, 'workshop-scoping' => 0],
            array_column($templates, 'price_minor', 'slug'),
        );
        foreach ($templates as $template) {
            $questions = QuestionSet::fromJson((string) json_encode($template['questions']));
            self::assertContains('goal', array_map(static fn($q) => $q->id, $questions->questions), $template['slug']);
            self::assertSame($template['price_minor'] === 0 ? ['free'] : ['upi'], $template['payment_methods']);
        }
    }
}
