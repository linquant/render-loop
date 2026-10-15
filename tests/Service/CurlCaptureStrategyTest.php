<?php

declare(strict_types=1);

namespace HtmlCompare\Tests\Service;

use HtmlCompare\Service\CurlCaptureStrategy;
use PHPUnit\Framework\TestCase;

class CurlCaptureStrategyTest extends TestCase
{
    public function testSansHoteInterneLUrlEstInchangee(): void
    {
        self::assertSame(
            'http://localhost:8084/tuto.php?x=1',
            CurlCaptureStrategy::rewriteTarget('http://localhost:8084/tuto.php?x=1', null),
        );
    }

    public function testRouteLeCheminVersLHoteInterne(): void
    {
        self::assertSame(
            'http://web/tuto.php?x=1',
            CurlCaptureStrategy::rewriteTarget('http://localhost:8084/tuto.php?x=1', 'http://web'),
        );
    }

    public function testConserveLeCheminSansQuery(): void
    {
        self::assertSame(
            'http://web/HtmlCompare',
            CurlCaptureStrategy::rewriteTarget('http://localhost:8084/HtmlCompare', 'http://web'),
        );
    }

    public function testBaseAvecSlashFinalNeDoublePas(): void
    {
        self::assertSame(
            'http://web/login.php',
            CurlCaptureStrategy::rewriteTarget('https://app.local/login.php', 'http://web/'),
        );
    }

    public function testUrlSansCheminDonneRacine(): void
    {
        self::assertSame(
            'http://web/',
            CurlCaptureStrategy::rewriteTarget('http://localhost:8084', 'http://web'),
        );
    }
}
