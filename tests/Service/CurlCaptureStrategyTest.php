<?php

declare(strict_types=1);

namespace RenderLoop\Tests\Service;

use RenderLoop\Service\CurlCaptureStrategy;
use RenderLoop\Service\UrlGuard;
use PHPUnit\Framework\TestCase;

class CurlCaptureStrategyTest extends TestCase
{
    /**
     * @param list<string> $headers
     */
    private function strategie(
        ?string $method = null,
        array $headers = [],
        ?string $data = null,
        ?string $internalBase = null
    ): CurlCaptureStrategy {
        return new CurlCaptureStrategy(
            new UrlGuard(['localhost']),
            null,
            'PHPSESSID',
            $internalBase,
            15,
            $method,
            $headers,
            $data,
        );
    }

    public function testSansOptionLeComportementResteUnGetSansCorps(): void
    {
        $options = $this->strategie()->buildRequestOptions(null);

        self::assertArrayNotHasKey(CURLOPT_CUSTOMREQUEST, $options);
        self::assertArrayNotHasKey(CURLOPT_POSTFIELDS, $options);
        self::assertArrayNotHasKey(CURLOPT_HTTPHEADER, $options);
        self::assertFalse($options[CURLOPT_FOLLOWLOCATION]);
    }

    public function testLaMethodePersonnaliseeEstAppliquee(): void
    {
        $options = $this->strategie('post')->buildRequestOptions(null);

        self::assertSame('POST', $options[CURLOPT_CUSTOMREQUEST]);
    }

    public function testDataForceLaMethodePostParDefaut(): void
    {
        $options = $this->strategie(null, [], 'q=sac&page=2')->buildRequestOptions(null);

        self::assertSame('POST', $options[CURLOPT_CUSTOMREQUEST]);
        self::assertSame('q=sac&page=2', $options[CURLOPT_POSTFIELDS]);
    }

    public function testLaMethodeExpliciteLemporteSurLeDefautDeData(): void
    {
        $options = $this->strategie('PUT', [], '{"x":1}')->buildRequestOptions(null);

        self::assertSame('PUT', $options[CURLOPT_CUSTOMREQUEST]);
        self::assertSame('{"x":1}', $options[CURLOPT_POSTFIELDS]);
    }

    public function testLesEntetesCustomSontTransmis(): void
    {
        $options = $this->strategie(null, ['X-Requested-With: XMLHttpRequest'])->buildRequestOptions(null);

        self::assertSame(['X-Requested-With: XMLHttpRequest'], $options[CURLOPT_HTTPHEADER]);
    }

    public function testLesEntetesCustomCohabitentAvecLenteteHostInterne(): void
    {
        $options = $this->strategie(null, ['X-Requested-With: XMLHttpRequest'], null, 'http://web')->buildRequestOptions('app.local:8084');

        self::assertSame(
            ['Host: app.local:8084', 'X-Requested-With: XMLHttpRequest'],
            $options[CURLOPT_HTTPHEADER],
        );
    }

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
            'http://web/RenderLoop',
            CurlCaptureStrategy::rewriteTarget('http://localhost:8084/RenderLoop', 'http://web'),
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
