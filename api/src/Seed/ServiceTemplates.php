<?php

declare(strict_types=1);

namespace ConsultDesk\Seed;

/**
 * Starter services offered by the site setup wizard (and used as-is by the dev seed). Prices are in
 * paise; see docs/research/session-pricing.md for how they were chosen.
 */
final class ServiceTemplates
{
    /**
     * Questions every service asks (name, email and WhatsApp are customer fields).
     *
     * @return list<array<string, mixed>>
     */
    public static function commonQuestions(): array
    {
        return [
            ['id' => 'role', 'label' => 'Your role', 'type' => 'select', 'required' => true, 'options' => ['Student', 'Resident', 'Doctor', 'Researcher', 'Educator', 'Founder / industry', 'Other']],
            ['id' => 'institution', 'label' => 'Institution or organisation', 'type' => 'text', 'required' => false],
            ['id' => 'goal', 'label' => 'What do you want to walk away with?', 'type' => 'textarea', 'required' => true],
            ['id' => 'links', 'label' => 'Links (CV, paper, repo or slides)', 'type' => 'url', 'required' => false],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function all(): array
    {
        $medical = ['id' => 'no_patient_data', 'label' => 'I will not share identifiable patient data. This is educational guidance, not clinical advice.', 'type' => 'checkbox', 'required' => true];

        return [
            self::service('intro-call', 'Intro / fit call', 'A short call to see if a full session is right for you.', 15, 0, requiresApproval: true),
            self::service('ai-career-guidance', 'AI-in-Medicine career guidance', 'Students, residents and doctors planning a path into medical AI.', 30, 149900),
            self::service('research-guidance', 'Research & thesis guidance', 'AI/ML study design, datasets, methodology and paper review.', 60, 299900, [
                ['id' => 'stage', 'label' => 'Stage', 'type' => 'select', 'required' => true, 'options' => ['Idea', 'Protocol', 'Data collection', 'Analysis', 'Writing', 'Revision']],
                ['id' => 'data_type', 'label' => 'Data type (e.g. WSI, radiology, EHR)', 'type' => 'text', 'required' => false],
                ['id' => 'ethics_status', 'label' => 'Ethics approval status', 'type' => 'select', 'required' => false, 'options' => ['Not needed', 'Planned', 'Submitted', 'Approved']],
                ['id' => 'deadline', 'label' => 'Deadline', 'type' => 'text', 'required' => false],
                $medical,
            ]),
            self::service('code-review', 'Project / code review', 'Deep-learning pathology or imaging projects and AI tool builds.', 60, 349900, [
                ['id' => 'repo', 'label' => 'Repository link', 'type' => 'url', 'required' => true],
                ['id' => 'framework', 'label' => 'Framework', 'type' => 'select', 'required' => false, 'options' => ['PyTorch', 'TensorFlow / Keras', 'JAX', 'scikit-learn', 'Other']],
                $medical,
            ]),
            self::service('ai-tools-hands-on', 'AI tools for clinicians & educators', 'Hands-on session with practical AI tools for your work.', 45, 199900),
            self::service('startup-consult', 'Health-AI startup / product consult', 'Product, validation and go-to-market questions for health-AI teams.', 60, 449900),
            self::service('workshop-scoping', 'Institutional workshop / FDP / invited talk', 'A scoping call for workshops, faculty development programmes and talks.', 30, 0, [
                ['id' => 'audience', 'label' => 'Audience', 'type' => 'text', 'required' => true],
                ['id' => 'headcount', 'label' => 'Expected headcount', 'type' => 'text', 'required' => false],
                ['id' => 'format', 'label' => 'Format', 'type' => 'select', 'required' => true, 'options' => ['In person', 'Online', 'Hybrid']],
                ['id' => 'dates', 'label' => 'Preferred dates', 'type' => 'text', 'required' => false],
            ], requiresApproval: true),
        ];
    }

    /**
     * Starter sessions offered by the setup wizard, in themed sets. Each template has a key
     * "set/slug" and everything a service needs; the owner can change title, length and price.
     *
     * @return list<array{key: string, label: string, description: string, templates: list<array<string, mixed>>}>
     */
    public static function sets(): array
    {
        $general = [
            ['id' => 'about', 'label' => 'What would you like help with?', 'type' => 'textarea', 'required' => true],
            ['id' => 'level', 'label' => 'Where are you now?', 'type' => 'select', 'required' => false, 'options' => ['School student', 'College student', 'Working professional', 'Other']],
        ];
        $set = static fn(string $key, string $label, string $description, array $templates): array => [
            'key' => $key,
            'label' => $label,
            'description' => $description,
            'templates' => array_values(array_map(static fn(array $t): array => ['key' => $key . '/' . $t['slug'], ...$t], $templates)),
        ];
        $simple = static fn(string $slug, string $title, string $tagline, int $minutes, int $price, bool $approval = false): array => [
            ...self::service($slug, $title, $tagline, $minutes, $price, requiresApproval: $approval),
            'questions' => $general,
        ];

        return [
            $set('general', 'Any teacher or consultant', 'Simple one-to-one sessions that suit most subjects.', [
                $simple('intro-call', 'Free intro call', 'A short call to see whether working together is a good fit.', 15, 0),
                $simple('one-to-one', 'One-to-one session', 'Focused help with your questions, at your pace.', 45, 99900),
                $simple('deep-dive', 'Extended deep-dive', 'More time for a bigger problem or a full review.', 90, 179900),
                $simple('review', 'Review and feedback', 'Send your work beforehand and get detailed feedback.', 30, 79900),
            ]),
            $set('medical-ai', 'Medical AI, research and careers', 'For doctors, researchers and educators in medical AI.', self::all()),
        ];
    }

    /**
     * One template by its "set/slug" key.
     *
     * @return array<string, mixed>|null
     */
    public static function find(string $key): ?array
    {
        foreach (self::sets() as $set) {
            foreach ($set['templates'] as $template) {
                if ($template['key'] === $key) {
                    return $template;
                }
            }
        }

        return null;
    }

    /**
     * @param list<array<string, mixed>> $extraQuestions
     *
     * @return array<string, mixed>
     */
    private static function service(string $slug, string $title, string $tagline, int $minutes, int $priceMinor, array $extraQuestions = [], bool $requiresApproval = false): array
    {
        return [
            'slug' => $slug,
            'title' => $title,
            'tagline' => $tagline,
            'duration_min' => $minutes,
            'price_minor' => $priceMinor,
            'currency' => 'INR',
            'requires_approval' => $requiresApproval,
            // Online payment shows only where Razorpay is set up; it can't skip an approval.
            'payment_methods' => $priceMinor === 0 ? ['free'] : ($requiresApproval ? ['upi'] : ['upi', 'razorpay_link']),
            'questions' => [...self::commonQuestions(), ...$extraQuestions],
        ];
    }
}
