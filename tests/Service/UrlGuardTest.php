<?php

declare(strict_types=1);

namespace HtmlCompare\Tests\Service;

use HtmlCompare\Service\UrlGuard;
use PHPUnit\Framework\TestCase;

class UrlGuardTest extends TestCase
{
    public function testAutoriseUnHoteDeLaListeBlanche(): void
    {
        $guard = new UrlGuard(['127.0.0.1', 'app.local']);

        self::assertTrue($guard->isAllowed('http://127.0.0.1:8099/page.php'));
        self::assertTrue($guard->isAllowed('https://app.local/fiche.php?id=1'));
    }

    public function testRefuseUnHoteExterne(): void
    {
        $guard = new UrlGuard(['app.local']);

        self::assertFalse($guard->isAllowed('https://evil.example.com/steal'));
    }

    public function testRefuseUnSchemaNonHttp(): void
    {
        $guard = new UrlGuard(['app.local']);

        self::assertFalse($guard->isAllowed('file:///etc/passwd'));
        self::assertFalse($guard->isAllowed('gopher://app.local/'));
    }

    public function testAssertLeveUneExceptionSurUrlInterdite(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new UrlGuard(['app.local']))->assertAllowed('https://evil.example.com/');
    }
}
