<?php

declare(strict_types=1);

namespace ConsultDesk\Http\Action;

use ConsultDesk\Admin\AdminUser;
use ConsultDesk\Admin\CouponSettings;
use ConsultDesk\Domain\Booking\Actor;
use ConsultDesk\Domain\Coupon\Coupon;
use ConsultDesk\Http\ApiException;
use ConsultDesk\Http\JsonInput;
use ConsultDesk\Http\JsonResponse;
use ConsultDesk\Http\Validation\Input;
use ConsultDesk\Infra\AuditLog;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Discount coupons. Owners and admins make site-wide coupons or ones for a single teacher; a teacher
 * makes coupons for their own sessions only.
 */
final class AdminCouponActions
{
    private const CODE = '/^[A-Z0-9][A-Z0-9_-]{2,31}$/';
    /** ₹1 to ₹1,00,000 off, in paise. */
    private const MIN_AMOUNT = 100;
    private const MAX_AMOUNT = 10_000_000;
    private const MAX_SERVICES = 50;

    public function __construct(
        private readonly CouponSettings $coupons,
        private readonly AuditLog $audit,
    ) {}

    public function list(Request $request, Response $response): Response
    {
        return JsonResponse::success($response, $this->coupons->all(AdminScope::user($request)));
    }

    public function create(Request $request, Response $response): Response
    {
        $user = AdminScope::user($request);
        $input = new Input(JsonInput::decode($request));
        $values = $this->read($input, $user, null);
        $input->assertValid();

        $id = $this->coupons->create($values, $user->id);
        $this->audit->record(Actor::user($user->id), 'admin.coupon_created', 'coupon', $id, ['code' => $values['code']]);

        return JsonResponse::success($response, $this->presented($id, $user), status: 201);
    }

    /**
     * @param array<string, string> $args
     */
    public function update(Request $request, Response $response, array $args): Response
    {
        $user = AdminScope::user($request);
        $current = $this->editable($user, (int) ($args['id'] ?? 0));
        $input = new Input(JsonInput::decode($request));
        $values = $this->read($input, $user, $current);
        $input->assertValid();

        $this->coupons->update((int) $current['id'], $values);
        $this->audit->record(Actor::user($user->id), 'admin.coupon_updated', 'coupon', (int) $current['id'], ['fields' => array_keys($values)]);

        return JsonResponse::success($response, $this->presented((int) $current['id'], $user));
    }

    /**
     * @param array<string, string> $args
     */
    public function delete(Request $request, Response $response, array $args): Response
    {
        $user = AdminScope::user($request);
        $current = $this->editable($user, (int) ($args['id'] ?? 0));
        $this->coupons->delete((int) $current['id']);
        $this->audit->record(Actor::user($user->id), 'admin.coupon_deleted', 'coupon', (int) $current['id'], ['code' => $current['code']]);

        return JsonResponse::success($response, ['ok' => true]);
    }

    /**
     * A coupon this person can see but not change is 403; one they can't see at all is 404.
     *
     * @return array<string, mixed>
     */
    private function editable(AdminUser $user, int $id): array
    {
        $row = $this->coupons->find($id);
        $visible = $row !== null && ($user->isStaff() || $row['provider_id'] === null || (int) $row['provider_id'] === $user->providerId);
        if (!$visible) {
            throw ApiException::notFound();
        }
        if (!CouponSettings::canEdit($user, $row)) {
            throw ApiException::forbidden();
        }

        return $row;
    }

    /**
     * Reads the fields sent (all required ones when creating). A teacher's coupons are always their own.
     *
     * @param array<string, mixed>|null $current null when creating
     *
     * @return array<string, mixed>
     */
    private function read(Input $input, AdminUser $user, ?array $current): array
    {
        $creating = $current === null;
        $values = [];
        if ($creating || $input->has('code')) {
            $code = Coupon::normalise((string) $input->string('code', max: 64));
            if (preg_match(self::CODE, $code) !== 1) {
                $input->reject('code', 'Use 3–32 letters, digits, "-" or "_", no spaces.');
            } elseif ($this->coupons->codeTaken($code, $current === null ? null : (int) $current['id'])) {
                $input->reject('code', 'Another coupon already uses this code.');
            }
            $values['code'] = $code;
        }

        if (!$user->isStaff()) {
            $values['provider_id'] = $user->providerId;
        } elseif ($creating || $input->has('provider_id')) {
            $values['provider_id'] = $input->int('provider_id', required: false, min: 1);
            if ($values['provider_id'] !== null && !$this->coupons->providerExists($values['provider_id'])) {
                $input->reject('provider_id', 'Choose a teacher from the list.');
            }
        }
        $providerId = array_key_exists('provider_id', $values) ? $values['provider_id'] : ($current['provider_id'] ?? null);

        $kind = $creating || $input->has('kind') ? $input->oneOf('kind', [Coupon::PERCENT, Coupon::AMOUNT]) : (string) $current['kind'];
        if ($creating || $input->has('kind')) {
            $values['kind'] = $kind;
        }
        if ($creating || $input->has('value') || $input->has('kind')) {
            $values['value'] = $kind === Coupon::AMOUNT
                ? $input->int('value', min: self::MIN_AMOUNT, max: self::MAX_AMOUNT)
                : $input->int('value', min: 1, max: 100);
        }

        if ($creating || $input->has('service_ids')) {
            $ids = $input->list('service_ids', max: self::MAX_SERVICES);
            $ids = $ids === null || $ids === [] ? null : array_values(array_unique(array_map('intval', array_filter($ids, 'is_numeric'))));
            if ($ids !== null && !$this->coupons->servicesBelong($ids, $providerId === null ? null : (int) $providerId)) {
                $input->reject('service_ids', 'Choose sessions from the list (for a teacher’s coupon, only their sessions).');
            }
            $values['service_ids'] = $ids;
        }

        foreach (['valid_from', 'valid_until'] as $field) {
            if ($creating || $input->has($field)) {
                $values[$field] = $input->dateTime($field, required: false)?->format('Y-m-d H:i:s');
            }
        }
        $from = $values['valid_from'] ?? ($current['valid_from'] ?? null);
        $until = $values['valid_until'] ?? ($current['valid_until'] ?? null);
        if ($from !== null && $until !== null && $until < $from) {
            $input->reject('valid_until', 'The end date must be after the start date.');
        }

        if ($creating || $input->has('max_uses')) {
            $values['max_uses'] = $input->int('max_uses', required: false, min: 1, max: 1_000_000);
        }
        foreach (['once_per_email' => true, 'active' => true] as $flag => $default) {
            if ($creating || $input->has($flag)) {
                $values[$flag] = $input->bool($flag) ?? $default;
            }
        }
        if ($creating || $input->has('note')) {
            $values['note'] = $input->string('note', required: false, max: 200);
        }

        return $values;
    }

    /**
     * @return array<string, mixed>
     */
    private function presented(int $id, AdminUser $user): array
    {
        $row = $this->coupons->find($id) ?? throw ApiException::notFound();

        return $this->coupons->present($row, $user);
    }
}
