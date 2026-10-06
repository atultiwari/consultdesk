<?php

declare(strict_types=1);

namespace ConsultDesk\Tests\Unit\Domain\Catalog;

use ConsultDesk\Domain\Catalog\QuestionSet;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class QuestionSetTest extends TestCase
{
    private const JSON = '[
        {"id": "role", "label": "Your role", "type": "select", "required": true, "options": ["Student", "Resident", "Doctor"]},
        {"id": "goal", "label": "What do you want to walk away with?", "type": "textarea", "required": true},
        {"id": "repo", "label": "Repository link", "type": "url"},
        {"id": "institution", "label": "Institution", "type": "text"},
        {"id": "no_patient_data", "label": "I will not share identifiable patient data", "type": "checkbox", "required": true}
    ]';

    public function testValidAnswersAreNormalisedAndUnknownKeysDropped(): void
    {
        $result = QuestionSet::fromJson(self::JSON)->validate([
            'role' => 'Doctor',
            'goal' => '  Feedback on my thesis  ',
            'repo' => 'https://github.example.test/x',
            'no_patient_data' => true,
            'unexpected' => 'ignored',
        ]);

        self::assertSame([], $result->errors);
        self::assertSame([
            'role' => 'Doctor',
            'goal' => 'Feedback on my thesis',
            'repo' => 'https://github.example.test/x',
            'no_patient_data' => true,
        ], $result->answers);
    }

    public function testReportsEachInvalidAnswer(): void
    {
        $result = QuestionSet::fromJson(self::JSON)->validate([
            'role' => 'Astronaut',
            'goal' => '',
            'repo' => 'javascript:alert(1)',
            'institution' => str_repeat('x', 501),
            'no_patient_data' => false,
        ]);

        self::assertSame(['role', 'goal', 'repo', 'institution', 'no_patient_data'], array_keys($result->errors));
    }

    public function testExposesQuestionsForTheBookingForm(): void
    {
        $questions = QuestionSet::fromJson(self::JSON)->toArray();

        self::assertCount(5, $questions);
        self::assertSame(['id' => 'role', 'label' => 'Your role', 'type' => 'select', 'required' => true, 'options' => ['Student', 'Resident', 'Doctor']], $questions[0]);
        self::assertSame([], QuestionSet::fromJson('[]')->toArray());
    }

    public function testRejectsMalformedDefinitions(): void
    {
        foreach (['not json', '{"id": "x"}', '[{"id": "x", "label": "X", "type": "rocket"}]', '[{"id": "Bad Id", "label": "X", "type": "text"}]', '[{"id": "s", "label": "S", "type": "select"}]'] as $json) {
            try {
                QuestionSet::fromJson($json);
                self::fail("Expected {$json} to be rejected.");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
