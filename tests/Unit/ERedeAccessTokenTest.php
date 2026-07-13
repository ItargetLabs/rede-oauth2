<?php

declare(strict_types=1);

namespace RedeOAuth\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RedeOAuth\Environment;
use RedeOAuth\ERede;
use RedeOAuth\OAuth\OAuthClient;
use RedeOAuth\OAuth\Token;
use RedeOAuth\Store;
use RedeOAuth\Tokenization\CardBrandTokenizationClient;

class ERedeAccessTokenTest extends TestCase
{
    public function testSetAndGetAccessTokenOnERede(): void
    {
        $token = new Token('cached_token', 'Bearer', 3600);
        $mockOAuthClient = $this->createMock(OAuthClient::class);
        $mockOAuthClient->expects($this->never())->method('getAccessToken');

        $store = new Store('PV123', 'TOKEN123', Environment::sandbox(), $mockOAuthClient);
        $erede = new ERede($store, accessToken: $token);

        $this->assertSame('cached_token', $erede->getAccessToken()->getAccessToken());
    }

    public function testSetAccessTokenAfterConstruction(): void
    {
        $token = new Token('injected_token', 'Bearer', 3600);
        $mockOAuthClient = $this->createMock(OAuthClient::class);
        $mockOAuthClient->expects($this->never())->method('getAccessToken');

        $store = new Store('PV123', 'TOKEN123', Environment::sandbox(), $mockOAuthClient);
        $erede = new ERede($store);
        $erede->setAccessToken($token);

        $this->assertSame('injected_token', $erede->getAccessToken()->getAccessToken());
    }

    public function testGetAccessTokenGeneratesWhenMissing(): void
    {
        $generated = new Token('generated_token', 'Bearer', 3600);
        $mockOAuthClient = $this->createMock(OAuthClient::class);
        $mockOAuthClient->expects($this->once())
            ->method('getAccessToken')
            ->with('PV123', 'TOKEN123')
            ->willReturn($generated);

        $store = new Store('PV123', 'TOKEN123', Environment::sandbox(), $mockOAuthClient);
        $erede = new ERede($store);

        $this->assertSame('generated_token', $erede->getAccessToken()->getAccessToken());
    }

    public function testExpiredTokenIsRefreshed(): void
    {
        $expired = new Token('expired_token', 'Bearer', -100);
        $fresh = new Token('fresh_token', 'Bearer', 3600);

        $mockOAuthClient = $this->createMock(OAuthClient::class);
        $mockOAuthClient->expects($this->once())
            ->method('getAccessToken')
            ->willReturn($fresh);

        $store = new Store('PV123', 'TOKEN123', Environment::sandbox(), $mockOAuthClient);
        $erede = new ERede($store, accessToken: $expired);

        $this->assertSame('fresh_token', $erede->getAccessToken()->getAccessToken());
    }

    public function testCardBrandTokenizationClientAcceptsAccessToken(): void
    {
        $token = new Token('tokenization_token', 'Bearer', 3600);
        $mockOAuthClient = $this->createMock(OAuthClient::class);
        $mockOAuthClient->expects($this->never())->method('getAccessToken');

        $store = new Store('PV123', 'TOKEN123', Environment::sandbox(), $mockOAuthClient);
        $client = new CardBrandTokenizationClient($store, accessToken: $token);

        $this->assertSame('tokenization_token', $client->getAccessToken()->getAccessToken());
    }

    public function testPersistedTokenRoundTrip(): void
    {
        $original = new Token('persist_me', 'Bearer', 3600, time() + 1800);
        $restored = Token::fromArray($original->toArray());

        $mockOAuthClient = $this->createMock(OAuthClient::class);
        $mockOAuthClient->expects($this->never())->method('getAccessToken');

        $store = new Store('PV123', 'TOKEN123', Environment::sandbox(), $mockOAuthClient);
        $erede = new ERede($store);
        $erede->setAccessToken($restored);

        $this->assertSame('persist_me', $erede->getAccessToken()->getAccessToken());
        $this->assertSame($original->getExpiresAt(), $erede->getAccessToken()->getExpiresAt());
    }

    public function testGetAccessTokenRequiresAuthenticatedHttpClient(): void
    {
        $mockHttpClient = $this->createMock(\RedeOAuth\Http\HttpClientInterface::class);
        $store = new Store('PV123', 'TOKEN123', Environment::sandbox());
        $erede = new ERede($store, $mockHttpClient);

        $this->expectException(\LogicException::class);
        $erede->getAccessToken();
    }
}
