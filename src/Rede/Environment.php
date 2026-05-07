<?php

declare(strict_types=1);

namespace RedeOAuth;

/**
 * Representa os ambientes disponíveis da API eRede
 */
class Environment
{
    private string $apiUrl;
    private string $tokenServiceBaseUrl;

    private function __construct(string $apiUrl, string $tokenServiceBaseUrl)
    {
        $this->apiUrl = $apiUrl;
        $this->tokenServiceBaseUrl = $tokenServiceBaseUrl;
    }

    public static function production(): self
    {
        return new self(
            'https://api.userede.com.br/erede',
            'https://api.userede.com.br/redelabs'
        );
    }

    public static function sandbox(): self
    {
        return new self(
            'https://sandbox-erede.useredecloud.com.br',
            'https://rl7-sandbox-api.useredecloud.com.br'
        );
    }

    public function getApiUrl(): string
    {
        return $this->apiUrl;
    }

    public function getTokenServiceBaseUrl(): string
    {
        return $this->tokenServiceBaseUrl;
    }
}
