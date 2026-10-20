<?php

declare(strict_types=1);

namespace RenderLoop\Service;

/**
 * HTML déjà capturé par un client externe (typiquement un navigateur headless
 * pour une page Vue/DataTables) et poussé tel quel.
 */
final class PushedHtmlStrategy implements CaptureStrategy
{
    private string $html;

    public function __construct(string $html)
    {
        $this->html = $html;
    }

    public function capture(string $url): string
    {
        return $this->html;
    }
}
