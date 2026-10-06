<?php

declare(strict_types=1);

namespace ConsultDesk\Seed;

/**
 * Starter sessions offered by the site setup wizard, in themed sets. Prices are in paise and come
 * from docs/research/session-pricing.md: each template's price is the lower (early-career or student)
 * end of what the Indian market pays, and its note gives the wider band. Owners change title, length
 * and price before adding them.
 */
final class ServiceTemplates
{
    private const NO_PATIENT_DATA = 'Educational guidance only, not medical advice or a teleconsultation. I won’t share identifiable patient data.';

    /**
     * @return list<array{key: string, label: string, description: string, templates: list<array<string, mixed>>}>
     */
    public static function sets(): array
    {
        return [
            self::set('general', 'Any teacher or consultant', 'One-to-one sessions that suit most subjects and coaches.', self::general()),
            self::set('medical-ai', 'Medical AI, research and careers', 'For doctors, researchers and educators. Educational guidance, never clinical advice.', self::medical()),
            self::set('institutional', 'Institutions', 'Talks, workshops and faculty development programmes. The fee is agreed after the request.', self::institutional()),
        ];
    }

    /**
     * The medical-AI set, used as-is by the local dev seed.
     *
     * @return list<array<string, mixed>>
     */
    public static function all(): array
    {
        return self::medical();
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
     * @return list<array<string, mixed>>
     */
    private static function general(): array
    {
        $stage = self::select('stage', 'Where are you now?', ['School student', 'College student', 'Working professional', 'Other'], true);

        return [
            self::service('intro-call', 'Free intro call', '15 minutes to see if we’re a good fit.', 'New clients', 15, 0, 'Free; senior providers sometimes charge for it', [
                self::text('goal', 'What would you like help with?', 'textarea', true),
                $stage,
                self::select('source', 'How did you hear about me?', ['Website', 'LinkedIn', 'Referral', 'Other'], false),
            ]),
            self::service('clarity-call', 'Quick clarity call', 'One focused question, one clear answer.', 'Anyone stuck on one issue', 30, 49900, 'Early-career ₹499 · established ₹1,499', [
                self::text('question', 'Your question', 'textarea', true),
                self::text('context_link', 'A link that gives context', 'url', false),
            ]),
            self::service('one-to-one', 'One-to-one session', 'An hour of focused, personalised guidance.', 'Students and professionals', 60, 99900, 'Early-career ₹999 · established ₹2,499', [
                self::text('goal', 'What do you want to walk away with?', 'textarea', true),
                self::text('background', 'A little about your background', 'textarea', true),
                $stage,
            ]),
            self::service('cv-review', 'CV and LinkedIn review', 'Line-by-line feedback before your next application.', 'Job seekers and freshers', 30, 49900, 'Early-career ₹499 · established ₹1,499', [
                self::text('cv_link', 'Link to your CV', 'url', true),
                self::text('linkedin', 'LinkedIn profile', 'url', false),
                self::text('target_role', 'Role you’re aiming for', 'text', true),
            ]),
            self::service('mock-interview', 'Mock interview with feedback', 'A realistic interview and a written scorecard.', 'Placement and job-switch candidates', 60, 149900, 'Early-career ₹1,499 · established ₹3,499', [
                self::text('role', 'Role', 'text', true),
                self::select('type', 'Interview type', ['Technical', 'Data', 'Product', 'HR', 'Case'], true),
                self::text('jd_link', 'Job description link', 'url', false),
            ]),
            self::service('tutoring', 'Tutoring session', 'Concepts, practice and doubt-clearing.', 'School and college students', 60, 44900, 'Early-career ₹449 · established ₹1,199 (average ₹410/hour)', [
                self::text('subject', 'Subject', 'text', true),
                self::select('class_level', 'Class or level', ['Class 6–8', 'Class 9–10', 'Class 11–12', 'Undergraduate', 'Postgraduate'], true),
                self::text('topics', 'Topics you want to cover', 'textarea', true),
            ]),
            self::service('career-roadmap', 'Career roadmap session', 'Leave with a clear 90-day plan.', 'Students and early-career professionals', 45, 79900, 'Early-career ₹799 · established ₹1,999', [
                self::text('current', 'Where you are now', 'textarea', true),
                self::text('target', 'Where you want to be', 'textarea', true),
                self::text('cv_link', 'Link to your CV', 'url', false),
            ]),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function medical(): array
    {
        $role = self::select('role', 'You are a…', ['MBBS student', 'PG resident', 'PhD scholar', 'Faculty', 'Industry', 'Other'], true);
        $consent = ['id' => 'no_patient_data', 'label' => self::NO_PATIENT_DATA, 'type' => 'checkbox', 'required' => true];

        return [
            self::service('intro-call', 'Free intro call', 'Tell me about your project or goal.', 'Everyone', 15, 0, 'Free', [
                $role,
                self::text('goal', 'What do you want to walk away with?', 'textarea', true),
            ]),
            self::service('ai-career-roadmap', 'Medical AI career roadmap', 'Where a doctor fits in AI, and how to get there.', 'Doctors and medical students', 45, 99900, 'Students ₹999 · professionals ₹1,999', [
                $role,
                self::select('coding_level', 'Coding experience', ['None', 'Basic', 'Intermediate', 'Advanced'], true),
                self::text('goal', 'What do you want to walk away with?', 'textarea', true),
                $consent,
            ]),
            self::service('thesis-clinic', 'Thesis and protocol design clinic', 'Research question, design and sample size, done right.', 'MD/MS/DNB/PhD scholars', 60, 149900, 'Students ₹1,499 · professionals ₹2,999. You remain the author', [
                self::select('degree', 'Degree', ['MD', 'MS', 'DNB', 'PhD', 'Other'], true),
                self::text('title', 'Working title', 'text', true),
                self::text('synopsis_link', 'Link to your synopsis or protocol', 'url', false),
                self::text('deadline', 'Deadline', 'text', false),
                $consent,
            ], requiresApproval: true),
            self::service('statistics-review', 'Statistics and results review', 'Check your analysis plan, tests and tables.', 'Scholars and faculty', 60, 199900, 'Students ₹1,999 · professionals ₹3,499', [
                self::text('design', 'Study design and what you’ve analysed', 'textarea', true),
                self::text('data_link', 'Link to your tables or analysis plan', 'url', false),
                $consent,
            ], requiresApproval: true),
            self::service('manuscript-review', 'Manuscript pre-submission review', 'Written comments plus a 45-minute walkthrough.', 'Authors', 45, 299900, 'Students ₹2,999 · professionals ₹5,999', [
                self::text('manuscript_link', 'Link to the manuscript', 'url', true),
                self::text('target_journal', 'Target journal', 'text', true),
                $consent,
            ], requiresApproval: true),
            self::service('code-review', 'ML project and code review', 'Your pipeline, validation and pitfalls, reviewed.', 'Clinician-coders and students', 60, 149900, 'Students ₹1,499 · professionals ₹2,999', [
                self::text('repo_link', 'Repository link', 'url', true),
                self::text('task', 'What the model does', 'textarea', true),
                self::select('stack', 'Stack', ['Python + PyTorch', 'Python + TensorFlow', 'R', 'Other'], false),
                $consent,
            ]),
            self::service('career-guidance', 'Medical career guidance', 'Honest options for PG, abroad or non-clinical paths.', 'MBBS students and interns', 30, 69900, 'Students ₹699 · professionals ₹1,499', [
                self::select('track', 'Track', ['NEET-PG', 'USMLE', 'UK / PLAB', 'Non-clinical', 'Research'], true),
                self::text('question', 'Your question', 'textarea', true),
            ]),
            self::service('startup-advice', 'Health-tech product and startup advice', 'A clinical reality-check for your product.', 'Founders and product managers', 60, 499900, 'Professionals ₹4,999', [
                self::text('company', 'Company or project', 'text', true),
                self::text('deck_link', 'Deck or demo link', 'url', false),
                self::text('ask', 'What you’d like from the session', 'textarea', true),
            ], requiresApproval: true),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function institutional(): array
    {
        $common = [
            self::text('institution', 'Institution', 'text', true),
            self::select('audience', 'Audience', ['Undergraduates', 'Postgraduates', 'Faculty', 'Mixed'], true),
            self::select('mode', 'Mode', ['Online', 'In person'], true),
            self::text('dates', 'Preferred dates', 'text', true),
            self::text('norms', 'Honorarium norms or budget', 'textarea', false),
        ];

        return [
            self::service('invited-talk', 'Invited talk or guest lecture', 'A 60–90 minute talk, online or in person.', 'Colleges, universities and conferences', 30, 0, 'Fee on request: government norms ₹1,500–₹5,000 per session; private ₹5,000+, travel extra', $common, requiresApproval: true),
            self::service('workshop', 'Hands-on workshop', 'A half- or full-day practical workshop.', 'Institutions and teams', 30, 0, 'Fee on request: private ₹15,000 half day / ₹25,000 full day; corporate ₹50,000+ a day', [
                ...$common,
                self::text('participants', 'Number of participants', 'text', true),
                ['id' => 'laptops', 'label' => 'Participants will have laptops', 'type' => 'checkbox', 'required' => false],
            ], requiresApproval: true),
            self::service('fdp', 'Faculty development programme', 'A multi-day programme for faculty.', 'Colleges and universities', 30, 0, 'Fee on request: sponsor norms (e.g. AICTE-ATAL ₹5,000 per session) or ₹25,000 a day', [
                ...$common,
                self::select('sponsor', 'Sponsor', ['AICTE-ATAL', 'UGC', 'Self-funded', 'Corporate'], true),
            ], requiresApproval: true),
        ];
    }

    /**
     * @param list<array<string, mixed>> $templates
     *
     * @return array{key: string, label: string, description: string, templates: list<array<string, mixed>>}
     */
    private static function set(string $key, string $label, string $description, array $templates): array
    {
        return [
            'key' => $key,
            'label' => $label,
            'description' => $description,
            'templates' => array_map(static fn(array $t): array => ['key' => $key . '/' . $t['slug'], ...$t], $templates),
        ];
    }

    /**
     * @param list<array<string, mixed>> $questions
     *
     * @return array<string, mixed>
     */
    private static function service(string $slug, string $title, string $tagline, string $audience, int $minutes, int $priceMinor, string $priceNote, array $questions, bool $requiresApproval = false): array
    {
        return [
            'slug' => $slug,
            'title' => $title,
            'tagline' => $tagline,
            'audience' => $audience,
            'duration_min' => $minutes,
            'price_minor' => $priceMinor,
            'price_note' => $priceNote,
            'currency' => 'INR',
            'requires_approval' => $requiresApproval,
            // Online payment shows only where Razorpay is set up; it can't skip an approval.
            'payment_methods' => $priceMinor === 0 ? ['free'] : ($requiresApproval ? ['upi'] : ['upi', 'razorpay_link']),
            'questions' => $questions,
        ];
    }

    /**
     * @return array{id: string, label: string, type: string, required: bool}
     */
    private static function text(string $id, string $label, string $type, bool $required): array
    {
        return ['id' => $id, 'label' => $label, 'type' => $type, 'required' => $required];
    }

    /**
     * @param list<string> $options
     *
     * @return array{id: string, label: string, type: string, required: bool, options: list<string>}
     */
    private static function select(string $id, string $label, array $options, bool $required): array
    {
        return ['id' => $id, 'label' => $label, 'type' => 'select', 'required' => $required, 'options' => $options];
    }
}
