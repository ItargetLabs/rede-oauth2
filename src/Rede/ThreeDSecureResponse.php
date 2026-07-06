<?php

declare(strict_types=1);

namespace RedeOAuth;

/**
 * Representa a resposta do 3DS Secure
 */
class ThreeDSecureResponse
{
    private ?string $url = null;
    private ?string $method = null;
    private ?array $parameters = null;

    public function __construct(array $data)
    {
        $this->url = $data['url'] ?? null;
        $this->method = $data['method'] ?? null;
        $this->parameters = $data['parameters'] ?? null;
    }

    public function getUrl(): ?string
    {
        return $this->url;
    }

    public function getMethod(): ?string
    {
        return $this->method;
    }

    public function getParameters(): ?array
    {
        return $this->parameters;
    }

    /**
     * Retorna os dados de autenticação 3DS sem parâmetros sensíveis.
     *
     * @return array<string, mixed>
     */
    public function toSafeArray(): array
    {
        $data = [
            'url' => $this->url,
            'method' => $this->method,
        ];

        if ($this->parameters !== null) {
            $data['parameters'] = SensitiveDataSanitizer::sanitize($this->parameters);
        }

        return array_filter($data, static fn (mixed $value): bool => $value !== null);
    }
}
