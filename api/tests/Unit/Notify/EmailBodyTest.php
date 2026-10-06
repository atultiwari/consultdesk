<?php

declare(strict_types=1);

namespace ConsultDesk\Tests\Unit\Notify;

use ConsultDesk\Notify\Mail\EmailBody;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class EmailBodyTest extends TestCase
{
    public function testRendersTheSameContentAsTextAndEscapedHtml(): void
    {
        $empty = new EmailBody();
        $body = $empty
            ->heading('Hello <Asha>')
            ->paragraph('Your booking & payment.')
            ->details(['Ref' => 'CD-7F3K', 'Amount' => '₹1,499'])
            ->button('Open status page', 'https://book.example.test/b/CD-7F3K?t=abc&x=1')
            ->note('Pay once.');

        self::assertSame('', $empty->toText(), 'builders return new instances');
        self::assertSame(
            "Hello <Asha>\n\nYour booking & payment.\n\nRef: CD-7F3K\nAmount: ₹1,499\n\nOpen status page: https://book.example.test/b/CD-7F3K?t=abc&x=1\n\nPay once.\n",
            $body->toText(),
        );

        $html = $body->toHtml();
        self::assertStringContainsString('Hello &lt;Asha&gt;', $html);
        self::assertStringContainsString('Your booking &amp; payment.', $html);
        self::assertStringContainsString('href="https://book.example.test/b/CD-7F3K?t=abc&amp;x=1"', $html);
        self::assertStringNotContainsString('<Asha>', $html);
    }

    public function testButtonsOnlyAcceptWebLinks(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new EmailBody())->button('Click', 'javascript:alert(1)');
    }
}
