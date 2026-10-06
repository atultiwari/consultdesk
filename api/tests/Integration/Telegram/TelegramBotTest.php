<?php

declare(strict_types=1);

namespace ConsultDesk\Tests\Integration\Telegram;

use ConsultDesk\Domain\Booking\Actor;
use ConsultDesk\Infra\FrozenClock;
use ConsultDesk\Telegram\LinkCodes;
use ConsultDesk\Tests\Integration\Http\ApiTestCase;
use ConsultDesk\Tests\Integration\Support\Fixtures;
use ConsultDesk\Tests\Support\FakeRazorpayApi;
use ConsultDesk\Tests\Support\FakeTelegramApi;

final class TelegramBotTest extends ApiTestCase
{
    private const SECRET = 'telegram-webhook-secret-placeholder-0123456789';
    private const PROVIDER_CHAT = '1001';
    private const OWNER_CHAT = '9001';
    private const ADMIN_CHAT = '9002';
    private const OTHER_PROVIDER_CHAT = '2002';

    private int $providerId;
    private int $adminId;
    private int $update = 1;

    protected function setUp(): void
    {
        parent::setUp();
        $this->telegram = new FakeTelegramApi();
        $this->providerId = Fixtures::provider($this->pdo, ['slug' => 'demo', 'name' => 'Dr. Demo', 'telegram_chat_id' => self::PROVIDER_CHAT]);
        Fixtures::service($this->pdo, $this->providerId, ['slug' => 'thesis', 'payment_methods' => '["upi"]']);
        Fixtures::service($this->pdo, $this->providerId, ['slug' => 'intro', 'price_minor' => 0, 'payment_methods' => '["free"]', 'requires_approval' => 1]);
        Fixtures::provider($this->pdo, ['slug' => 'other', 'telegram_chat_id' => self::OTHER_PROVIDER_CHAT]);
        $owner = Fixtures::user($this->pdo, 'owner', email: 'owner@example.test');
        $this->adminId = Fixtures::user($this->pdo, 'admin', email: 'admin@example.test');
        $this->pdo->exec("UPDATE users SET telegram_chat_id = '" . self::OWNER_CHAT . "' WHERE id = {$owner}");
        $this->pdo->exec("UPDATE users SET telegram_chat_id = '" . self::ADMIN_CHAT . "' WHERE id = {$this->adminId}");
    }

    protected function extraEnv(): array
    {
        return [
            'TELEGRAM_BOT_TOKEN' => '123456789:' . str_repeat('A', 35),
            'TELEGRAM_WEBHOOK_SECRET' => self::SECRET,
            'TELEGRAM_BOT_USERNAME' => 'ConsultDeskTestBot',
        ];
    }

    public function testUtrSubmissionAlertsTheProviderChatWithButtons(): void
    {
        $booking = $this->bookAndPay();

        self::assertCount(1, $this->telegram()->sent);
        $alert = $this->telegram()->sent[0];
        self::assertSame(self::PROVIDER_CHAT, $alert['chat']);
        self::assertStringContainsString('Verify UPI payment', $alert['text']);
        self::assertStringContainsString('412345678901', $alert['text']);
        self::assertSame(["c:v:{$booking['id']}", "r:v:{$booking['id']}"], $alert['buttons']);
        self::assertCount(2, $this->mailer->sent, 'email still goes out alongside Telegram');
    }

    public function testTheOwnerGetsAlertsForProvidersWithoutTelegram(): void
    {
        $this->pdo->exec("UPDATE providers SET telegram_chat_id = NULL WHERE id = {$this->providerId}");
        $this->bookAndPay();

        self::assertSame([self::OWNER_CHAT], array_column($this->telegram()->sent, 'chat'), 'owner only, not admins');
    }

    public function testFreeRequestsNeedingApprovalAreAlerted(): void
    {
        [, $created] = $this->call('POST', '/api/bookings', $this->booking(['service' => 'intro', 'payment_method' => 'free']));
        $this->runCron();

        $alert = $this->telegram()->sent[0];
        self::assertStringContainsString('Booking request to approve', $alert['text']);
        $id = $this->bookingId($created['data']['ref']);
        self::assertSame(["c:a:{$id}", "r:a:{$id}"], $alert['buttons']);
    }

    public function testConfirmButtonConfirmsTheBooking(): void
    {
        $booking = $this->bookAndPay();
        $alert = $this->telegram()->sent[0];

        [$status] = $this->press(self::PROVIDER_CHAT, $alert['id'], "c:v:{$booking['id']}");

        self::assertSame(200, $status);
        self::assertSame('confirmed', $this->bookingStatus($booking['id']));
        $edit = $this->lastEdit();
        self::assertStringStartsWith('✅ Confirmed', $edit['text']);
        self::assertSame([], $edit['buttons']);
        self::assertSame('Confirmed ✅', $this->lastAnswer());

        $this->runCron();
        $confirmations = array_filter($this->mailer->subjects(), static fn(string $s): bool => str_starts_with($s, 'Confirmed: ') && str_ends_with($s, "({$booking['ref']})"));
        self::assertCount(1, $confirmations, 'the customer is emailed the confirmation');
        self::assertCount(1, $this->telegram()->sent, 'the alert is updated in place; no second message');
    }

    public function testAFreeBookingWithoutApprovalSendsANoticeWithoutButtons(): void
    {
        Fixtures::service($this->pdo, $this->providerId, ['slug' => 'hello', 'title' => 'Hello call', 'price_minor' => 0, 'payment_methods' => '["free"]']);
        [, $created] = $this->call('POST', '/api/bookings', $this->booking(['service' => 'hello', 'payment_method' => 'free']));
        $this->runCron();

        $notice = $this->telegram()->sent[0];
        self::assertSame(self::PROVIDER_CHAT, $notice['chat']);
        self::assertStringContainsString('New booking', $notice['text']);
        self::assertStringContainsString($created['data']['ref'], $notice['text']);
        self::assertStringContainsString('Free', $notice['text']);
        self::assertSame([], $notice['buttons']);
    }

    public function testAnOnlinePaymentSendsANoticeWithThePaymentId(): void
    {
        $id = $this->onlineBooking();
        $this->services()->bookingService()->confirmPaid($id, 'pay_placeholder1', Actor::webhook());
        $this->runCron();

        $notice = $this->telegram()->sent[0];
        self::assertStringContainsString('New booking', $notice['text']);
        self::assertStringContainsString('paid online', $notice['text']);
        self::assertStringContainsString('pay_placeholder1', $notice['text']);
        self::assertSame([], $notice['buttons']);
    }

    public function testALatePaymentSendsARefundNotice(): void
    {
        $id = $this->onlineBooking();
        $this->at('2026-10-05T01:00Z');
        $this->services()->bookingService()->confirmPaid($id, 'pay_placeholder2', Actor::webhook());
        $this->runCron();

        $texts = array_column($this->telegram()->sent, 'text');
        $refund = array_values(array_filter($texts, static fn(string $t): bool => str_contains($t, 'Refund needed')));
        self::assertCount(1, $refund);
        self::assertStringContainsString('pay_placeholder2', $refund[0]);
        self::assertStringNotContainsString('New booking', implode("\n", $texts));
    }

    /**
     * A held online-payment booking (the Razorpay link itself is not needed here).
     */
    private function onlineBooking(): int
    {
        $this->services()->gatewayKeys()->save(null, 'rzp_test_' . str_repeat('O', 14), bin2hex(random_bytes(12)), bin2hex(random_bytes(16)));
        $this->razorpay = new FakeRazorpayApi();
        Fixtures::service($this->pdo, $this->providerId, ['slug' => 'online', 'title' => 'Online session', 'payment_methods' => '["razorpay_link"]']);
        [$status, $created] = $this->call('POST', '/api/bookings', $this->booking(['service' => 'online', 'payment_method' => 'razorpay_link']));
        self::assertSame(201, $status, json_encode($created) ?: '');
        $this->runCron();
        $this->telegram()->sent = [];

        return $this->bookingId($created['data']['ref']);
    }

    public function testRejectNeedsASecondTap(): void
    {
        $booking = $this->bookAndPay();
        $alertId = $this->telegram()->sent[0]['id'];

        $this->press(self::PROVIDER_CHAT, $alertId, "r:v:{$booking['id']}");
        self::assertSame('awaiting_verification', $this->bookingStatus($booking['id']));
        self::assertSame(["x:v:{$booking['id']}", "b:v:{$booking['id']}"], $this->lastEdit()['buttons']);
        self::assertStringContainsString('Reject ' . $booking['ref'] . '?', $this->lastEdit()['text']);

        $this->press(self::PROVIDER_CHAT, $alertId, "b:v:{$booking['id']}");
        self::assertSame(["c:v:{$booking['id']}", "r:v:{$booking['id']}"], $this->lastEdit()['buttons']);

        $this->press(self::PROVIDER_CHAT, $alertId, "x:v:{$booking['id']}");
        self::assertSame('rejected', $this->bookingStatus($booking['id']));
        self::assertStringStartsWith('❌ Rejected', $this->lastEdit()['text']);
    }

    public function testOnlyTheProvidersChatOrOwnerAndAdminChatsMayAct(): void
    {
        $booking = $this->bookAndPay();
        $alertId = $this->telegram()->sent[0]['id'];

        $this->press(self::OTHER_PROVIDER_CHAT, $alertId, "c:v:{$booking['id']}");
        self::assertSame('awaiting_verification', $this->bookingStatus($booking['id']));
        self::assertStringContainsString('not allowed', strtolower($this->lastAnswer()));

        $this->press('5555', $alertId, "c:v:{$booking['id']}");
        self::assertSame('awaiting_verification', $this->bookingStatus($booking['id']));

        $this->press(self::ADMIN_CHAT, $alertId, "c:v:{$booking['id']}");
        self::assertSame('confirmed', $this->bookingStatus($booking['id']));
        self::assertSame($this->adminId, (int) $this->scalar("SELECT confirmed_by FROM bookings WHERE id = {$booking['id']}"));
    }

    public function testPressingASettledBookingExplainsWhatHappened(): void
    {
        $booking = $this->bookAndPay();
        $alertId = $this->telegram()->sent[0]['id'];

        $this->press(self::PROVIDER_CHAT, $alertId, "c:v:{$booking['id']}");
        $this->press(self::OWNER_CHAT, $alertId, "x:v:{$booking['id']}");

        self::assertSame('confirmed', $this->bookingStatus($booking['id']));
        self::assertStringContainsString('already confirmed', strtolower($this->lastAnswer()));
        self::assertStringStartsWith('✅ Confirmed', $this->lastEdit()['text']);
    }

    public function testSettlingABookingElsewhereUpdatesTheAlert(): void
    {
        $booking = $this->bookAndPay();
        $this->servicesNow()->bookingService()->confirm($booking['id'], Actor::user($this->adminId));

        $this->runCron();

        $edit = $this->lastEdit();
        self::assertSame($this->telegram()->sent[0]['id'], $edit['id']);
        self::assertStringStartsWith('✅ Confirmed', $edit['text']);
        self::assertSame([], $edit['buttons']);
        self::assertSame(0, (int) $this->scalar('SELECT COUNT(*) FROM telegram_messages'));
    }

    public function testAFailingChatIsRetriedWithoutBreakingEmail(): void
    {
        $this->telegram()->failingChats = [self::PROVIDER_CHAT];
        $this->bookAndPay();

        self::assertSame([], $this->telegram()->sent);
        self::assertCount(2, $this->mailer->sent);
        self::assertSame('pending', $this->scalar("SELECT status FROM outbox_jobs WHERE type = 'telegram.alert'"));
    }

    public function testADeadAlertDoesNotStopTheOthersBeingUpdated(): void
    {
        $booking = $this->bookAndPay();
        $first = $this->telegram()->sent[0]['id'];
        $this->pdo->exec("INSERT INTO telegram_messages (booking_id, chat_id, message_id, created_at) VALUES ({$booking['id']}, '4040', 1, '2026-10-05 00:00:00')");
        $this->telegram()->failingEdits = ['4040'];

        $this->servicesNow()->bookingService()->confirm($booking['id'], Actor::user($this->adminId));
        $this->runCron();

        self::assertContains($first, array_column($this->telegram()->edits, 'id'));
        self::assertSame('0', $this->scalar('SELECT COUNT(*) FROM telegram_messages'), 'the dead message is forgotten too');
        self::assertSame('done', $this->scalar("SELECT status FROM outbox_jobs WHERE type = 'telegram.resolve'"));
    }

    public function testAnAlertForAnAlreadySettledBookingIsNotSent(): void
    {
        [, $created] = $this->call('POST', '/api/bookings', $this->booking());
        $ref = $created['data']['ref'];
        $this->call('POST', "/api/bookings/{$ref}/utr", ['token' => $created['data']['token'], 'utr' => '412345678901']);
        $this->servicesNow()->bookingService()->confirm($this->bookingId($ref), Actor::user($this->adminId));

        $this->runCron();

        self::assertSame([], $this->telegram()->sent);
    }

    public function testGroupChatsCannotActOrLink(): void
    {
        $booking = $this->bookAndPay();
        $this->pdo->exec("UPDATE providers SET telegram_chat_id = '-100777' WHERE id = {$this->providerId}");

        $this->press('-100777', 5, "c:v:{$booking['id']}", chatType: 'supergroup', from: '31337');
        self::assertSame('awaiting_verification', $this->bookingStatus($booking['id']));
        self::assertStringContainsString('private chat', $this->lastAnswer());

        $code = (new LinkCodes($this->pdo, new FrozenClock(self::NOW)))->create('user', $this->adminId);
        $this->say('-100888', "/start {$code}", chatType: 'group');
        self::assertNull($this->scalar("SELECT id FROM users WHERE telegram_chat_id = '-100888'"));
    }

    public function testThePresserMustBeTheChatItself(): void
    {
        $booking = $this->bookAndPay();

        $this->press(self::PROVIDER_CHAT, 5, "c:v:{$booking['id']}", from: '31337');

        self::assertSame('awaiting_verification', $this->bookingStatus($booking['id']));
    }

    public function testMalformedCallbacksAndOtherBotsCommandsAreIgnored(): void
    {
        $this->call('POST', '/api/webhooks/telegram', ['update_id' => 99, 'callback_query' => ['id' => 'cbx', 'data' => 'c:v:1']], headers: ['X-Telegram-Bot-Api-Secret-Token' => self::SECRET]);
        self::assertStringContainsString('no longer valid', $this->lastAnswer());

        $this->say('7777', '/start@SomeOtherBot');
        $this->say('7777', '/start@ConsultDeskTestBot');
        self::assertCount(1, array_filter($this->telegram()->sent, static fn(array $m): bool => $m['chat'] === '7777'));
    }

    public function testOwnerAndAdminLinkCodesExpireQuickly(): void
    {
        $code = (new LinkCodes($this->pdo, new FrozenClock('2026-10-04T23:44Z')))->create('user', $this->adminId);

        $this->say('7777', "/start {$code}");

        self::assertStringContainsString('expired or was already used', $this->lastSent('7777'));
    }

    public function testCodesForDeletedTargetsDoNotClaimSuccess(): void
    {
        $other = Fixtures::provider($this->pdo, ['slug' => 'gone']);
        $code = (new LinkCodes($this->pdo, new FrozenClock(self::NOW)))->create('provider', $other);
        $this->pdo->exec("DELETE FROM providers WHERE id = {$other}");

        $this->say('7777', "/start {$code}");

        self::assertStringContainsString('expired or was already used', $this->lastSent('7777'));
    }

    public function testWebhookRequiresTheSecretHeader(): void
    {
        self::assertSame(403, $this->call('POST', '/api/webhooks/telegram', ['update_id' => 1])[0]);
        self::assertSame(403, $this->call('POST', '/api/webhooks/telegram', ['update_id' => 1], headers: ['X-Telegram-Bot-Api-Secret-Token' => 'wrong'])[0]);
        self::assertSame(200, $this->call('POST', '/api/webhooks/telegram', ['update_id' => 1], headers: ['X-Telegram-Bot-Api-Secret-Token' => self::SECRET])[0]);
    }

    public function testStartWithALinkCodeLinksTheChat(): void
    {
        $codes = new LinkCodes($this->pdo, new FrozenClock(self::NOW));
        $code = $codes->create('provider', $this->providerId);

        $this->say('7777', "/start {$code}");
        self::assertSame('7777', $this->scalar("SELECT telegram_chat_id FROM providers WHERE id = {$this->providerId}"));
        self::assertStringContainsString('Dr. Demo', $this->lastSent('7777'));

        $this->say('8888', "/start {$code}");
        self::assertStringContainsString('expired or was already used', $this->lastSent('8888'));

        $this->say('8888', '/start');
        self::assertStringContainsString('8888', $this->lastSent('8888'), 'shows the chat id for manual setup');
    }

    public function testLinkCodesExpire(): void
    {
        $code = (new LinkCodes($this->pdo, new FrozenClock('2026-10-01T00:00Z')))->create('user', $this->adminId);

        $this->say('7777', "/start {$code}");

        self::assertStringContainsString('expired or was already used', $this->lastSent('7777'));
    }

    public function testStopUnlinksTheChat(): void
    {
        $this->say(self::PROVIDER_CHAT, '/stop');

        self::assertSame('1', $this->scalar("SELECT telegram_chat_id IS NULL FROM providers WHERE id = {$this->providerId}"));
        self::assertStringContainsString('unlinked', strtolower($this->lastSent(self::PROVIDER_CHAT)));
    }

    /**
     * @return array{id: int, ref: string}
     */
    private function bookAndPay(): array
    {
        [, $created] = $this->call('POST', '/api/bookings', $this->booking());
        $ref = $created['data']['ref'];
        $this->call('POST', "/api/bookings/{$ref}/utr", ['token' => $created['data']['token'], 'utr' => '412345678901']);
        $this->runCron();

        return ['id' => $this->bookingId($ref), 'ref' => $ref];
    }

    /**
     * @return array{int, array<string, mixed>}
     */
    private function press(string $chat, int $messageId, string $data, string $chatType = 'private', ?string $from = null): array
    {
        [$status, $body] = $this->call('POST', '/api/webhooks/telegram', [
            'update_id' => $this->update++,
            'callback_query' => [
                'id' => 'cb' . $this->update,
                'from' => ['id' => (int) ($from ?? $chat)],
                'message' => ['message_id' => $messageId, 'chat' => ['id' => (int) $chat, 'type' => $chatType]],
                'data' => $data,
            ],
        ], headers: ['X-Telegram-Bot-Api-Secret-Token' => self::SECRET]);

        return [$status, $body];
    }

    private function say(string $chat, string $text, string $chatType = 'private'): void
    {
        $this->call('POST', '/api/webhooks/telegram', [
            'update_id' => $this->update++,
            'message' => ['message_id' => 1, 'chat' => ['id' => (int) $chat, 'type' => $chatType], 'from' => ['id' => (int) $chat], 'text' => $text],
        ], headers: ['X-Telegram-Bot-Api-Secret-Token' => self::SECRET]);
    }

    private function runCron(): void
    {
        $this->servicesNow()->cronRunner()->run();
    }

    private function servicesNow(): \ConsultDesk\Bootstrap\AppServices
    {
        return $this->services();
    }

    private function telegram(): FakeTelegramApi
    {
        self::assertNotNull($this->telegram);

        return $this->telegram;
    }

    /**
     * @return array{chat: string, id: int, text: string, buttons: list<string>}
     */
    private function lastEdit(): array
    {
        $edits = $this->telegram()->edits;
        self::assertNotSame([], $edits);

        return $edits[count($edits) - 1];
    }

    private function lastAnswer(): string
    {
        $answers = $this->telegram()->answers;
        self::assertNotSame([], $answers);

        return $answers[count($answers) - 1]['text'];
    }

    private function lastSent(string $chat): string
    {
        $texts = array_column(array_filter($this->telegram()->sent, static fn(array $m): bool => $m['chat'] === $chat), 'text');
        self::assertNotSame([], $texts, "nothing sent to {$chat}");

        return (string) end($texts);
    }

    private function scalar(string $sql): ?string
    {
        return self::column($this->pdo, $sql)[0] ?? null;
    }

    private function bookingStatus(int $id): string
    {
        return (string) $this->scalar("SELECT status FROM bookings WHERE id = {$id}");
    }

    private function bookingId(string $ref): int
    {
        $statement = $this->pdo->prepare('SELECT id FROM bookings WHERE ref = ?');
        $statement->execute([$ref]);

        return (int) $statement->fetchColumn();
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function booking(array $overrides = []): array
    {
        return array_merge([
            'provider' => 'demo',
            'service' => 'thesis',
            'start' => '2026-10-07T04:30:00Z',
            'customer' => ['name' => 'Asha Placeholder', 'email' => 'asha@example.test', 'phone' => '+910000000000', 'timezone' => 'Asia/Kolkata'],
        ], $overrides);
    }
}
