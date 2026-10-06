<?php

declare(strict_types=1);

namespace ConsultDesk\Tests\Unit\Seed;

use ConsultDesk\Domain\Catalog\QuestionSet;
use ConsultDesk\Seed\ServiceTemplates;
use PHPUnit\Framework\TestCase;

/**
 * The starter sessions follow docs/research/session-pricing.md.
 */
final class ServiceTemplatesTest extends TestCase
{
    public function testThreeSetsForAnyTeacherMedicalAiAndInstitutions(): void
    {
        self::assertSame(['general', 'medical-ai', 'institutional'], array_column(ServiceTemplates::sets(), 'key'));
    }

    public function testMedicalPricesAreTheResearchedStudentRates(): void
    {
        self::assertSame(
            ['intro-call' => 0, 'ai-career-roadmap' => 99900, 'thesis-clinic' => 149900, 'statistics-review' => 199900, 'manuscript-review' => 299900,
                'code-review' => 149900, 'career-guidance' => 69900, 'startup-advice' => 499900],
            array_column(ServiceTemplates::all(), 'price_minor', 'slug'),
        );
    }

    public function testEveryTemplateIsValidAndPaysTheRightWay(): void
    {
        $keys = [];
        foreach (ServiceTemplates::sets() as $set) {
            self::assertNotSame([], $set['templates'], $set['key']);
            foreach ($set['templates'] as $template) {
                $keys[] = $template['key'];
                self::assertStringStartsWith($set['key'] . '/', $template['key']);
                QuestionSet::fromJson((string) json_encode($template['questions']));
                self::assertNotSame('', $template['price_note']);
                self::assertSame($template, ServiceTemplates::find($template['key']));
                self::assertSame(match (true) {
                    $template['price_minor'] === 0 => ['free'],
                    $template['requires_approval'] => ['upi'],
                    default => ['upi', 'razorpay_link'],
                }, $template['payment_methods'], $template['key']);
            }
        }
        self::assertSame($keys, array_values(array_unique($keys)));
        self::assertNull(ServiceTemplates::find('nope/nothing'));
    }

    public function testMedicalSessionsSayTheyAreNotClinicalAdvice(): void
    {
        foreach (ServiceTemplates::all() as $template) {
            $ids = array_column($template['questions'], 'id');
            if (in_array($template['slug'], ['ai-career-roadmap', 'thesis-clinic', 'statistics-review', 'manuscript-review', 'code-review'], true)) {
                self::assertContains('no_patient_data', $ids, $template['slug']);
            }
        }
    }

    public function testInstitutionalRequestsAlwaysNeedApprovalAndAreFreeToSend(): void
    {
        $institutional = array_values(array_filter(ServiceTemplates::sets(), static fn(array $s): bool => $s['key'] === 'institutional'))[0]['templates'];
        foreach ($institutional as $template) {
            self::assertTrue($template['requires_approval']);
            self::assertSame(0, $template['price_minor']);
        }
    }
}
