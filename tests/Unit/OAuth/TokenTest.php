<?php

declare(strict_types=1);

namespace RedeOAuth\Tests\Unit\OAuth;

use PHPUnit\Framework\TestCase;
use RedeOAuth\OAuth\Token;

class TokenTest extends TestCase
{
    public function testTokenCreation(): void
    {
        $token = new Token('access_token_123', 'Bearer', 3600);

        $this->assertEquals('access_token_123', $token->getAccessToken());
        $this->assertEquals('Bearer', $token->getTokenType());
        $this->assertEquals(3600, $token->getExpiresIn());
        $this->assertNotNull($token->getExpiresAt());
    }

    public function testTokenIsNotExpired(): void
    {
        $token = new Token('access_token_123', 'Bearer', 3600);

        $this->assertFalse($token->isExpired());
    }

    public function testTokenIsExpired(): void
    {
        $token = new Token('access_token_123', 'Bearer', -100);

        $this->assertTrue($token->isExpired());
    }

    public function testToAuthorizationHeader(): void
    {
        $token = new Token('access_token_123', 'Bearer', 3600);

        $this->assertEquals('Bearer access_token_123', $token->toAuthorizationHeader());
    }

    public function testToArrayAndFromArray(): void
    {
        $expiresAt = time() + 1800;
        $token = new Token('access_token_123', 'Bearer', 3600, $expiresAt);

        $data = $token->toArray();

        $this->assertSame([
            'access_token' => 'access_token_123',
            'token_type' => 'Bearer',
            'expires_in' => 3600,
            'expires_at' => $expiresAt,
        ], $data);

        $restored = Token::fromArray($data);

        $this->assertSame('access_token_123', $restored->getAccessToken());
        $this->assertSame('Bearer', $restored->getTokenType());
        $this->assertSame(3600, $restored->getExpiresIn());
        $this->assertSame($expiresAt, $restored->getExpiresAt());
        $this->assertFalse($restored->isExpired());
    }

    public function testFromArrayRequiresAccessToken(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Token::fromArray(['token_type' => 'Bearer']);
    }

    public function testFromArrayPreservesExpiresAtEvenWithShortExpiresIn(): void
    {
        $expiresAt = time() + 1200;
        $token = Token::fromArray([
            'access_token' => 'cached',
            'expires_in' => 3600,
            'expires_at' => $expiresAt,
        ]);

        $this->assertSame($expiresAt, $token->getExpiresAt());
        $this->assertFalse($token->isExpired());
    }
}
