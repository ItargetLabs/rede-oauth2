<?php

declare(strict_types=1);

namespace RedeOAuth\OAuth;

/**
 * Representa um token OAuth
 */
class Token
{
    private string $accessToken;
    private string $tokenType;
    private int $expiresIn;
    private ?int $expiresAt = null;

    public function __construct(
        string $accessToken,
        string $tokenType = 'Bearer',
        int $expiresIn = 3600,
        ?int $expiresAt = null
    ) {
        $this->accessToken = $accessToken;
        $this->tokenType = $tokenType;
        $this->expiresIn = $expiresIn;
        $this->expiresAt = $expiresAt ?? (time() + $expiresIn);
    }

    /**
     * Recria um token a partir de dados persistidos (cache, sessão, etc.).
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $accessToken = $data['access_token'] ?? null;
        if (!is_string($accessToken) || $accessToken === '') {
            throw new \InvalidArgumentException('access_token é obrigatório para recriar o Token');
        }

        return new self(
            $accessToken,
            isset($data['token_type']) && is_string($data['token_type']) ? $data['token_type'] : 'Bearer',
            isset($data['expires_in']) ? (int) $data['expires_in'] : 3600,
            isset($data['expires_at']) ? (int) $data['expires_at'] : null
        );
    }

    /**
     * Serializa o token para persistência externa.
     *
     * @return array{access_token: string, token_type: string, expires_in: int, expires_at: int|null}
     */
    public function toArray(): array
    {
        return [
            'access_token' => $this->accessToken,
            'token_type' => $this->tokenType,
            'expires_in' => $this->expiresIn,
            'expires_at' => $this->expiresAt,
        ];
    }

    public function getAccessToken(): string
    {
        return $this->accessToken;
    }

    public function getTokenType(): string
    {
        return $this->tokenType;
    }

    public function getExpiresIn(): int
    {
        return $this->expiresIn;
    }

    public function getExpiresAt(): ?int
    {
        return $this->expiresAt;
    }

    public function isExpired(): bool
    {
        if ($this->expiresAt === null) {
            return false;
        }

        // Considera expirado se faltar menos de 60 segundos
        return ($this->expiresAt - 60) < time();
    }

    public function toAuthorizationHeader(): string
    {
        return sprintf('%s %s', $this->tokenType, $this->accessToken);
    }
}
