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
            self::assertSame(match (true) {
                $template['price_minor'] === 0 => ['free'],
                $template['requires_approval'] => ['upi'],
                default => ['upi', 'razorpay_link'],
            }, $template['payment_methods']);
        }
    }

    public function testEveryStarterSetHasUniqueKeysAndValidQuestions(): void
    {
        $keys = [];
        foreach (ServiceTemplates::sets() as $set) {
            self::assertNotSame([], $set['templates'], $set['key']);
            foreach ($set['templates'] as $template) {
                $keys[] = $template['key'];
                self::assertStringStartsWith($set['key'] . '/', $template['key']);
                QuestionSet::fromJson((string) json_encode($template['questions']));
                self::assertSame($template, ServiceTemplates::find($template['key']));
            }
        }
        self::assertSame($keys, array_values(array_unique($keys)));
        self::assertNull(ServiceTemplates::find('nope/nothing'));
    }
}
