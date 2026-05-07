<?php

declare(strict_types=1);

namespace RedeOAuth\Tests\Unit\Tokenization;

use PHPUnit\Framework\TestCase;
use RedeOAuth\Tokenization\TokenizationRequest;
use RedeOAuth\Tokenization\TokenizationResponse;
use RedeOAuth\Tokenization\TokenQueryResponse;
use RedeOAuth\Tokenization\CryptogramResponse;
use RedeOAuth\Tokenization\TokenManagementResponse;
use RedeOAuth\Transaction;

/**
 * Testes unitários dos modelos de dados de tokenização (sem HTTP, sem mocks).
 */
class TokenizationModelTest extends TestCase
{
    // -------------------------------------------------------------------------
    // TokenizationRequest::toArray
    // -------------------------------------------------------------------------

    public function testTokenizationRequestToArrayIncludesAllFields(): void
    {
        $request = new TokenizationRequest(
            cardNumber: '4111111111111111',
            expirationMonth: '12',
            expirationYear: '2028',
            email: 'portador@example.com',
            storageCard: 0,
            cardholderName: 'John Snow',
            securityCode: '123'
        );

        $data = $request->toArray();

        $this->assertSame('4111111111111111', $data['cardNumber']);
        $this->assertSame('12', $data['expirationMonth']);
        $this->assertSame('2028', $data['expirationYear']);
        $this->assertSame('portador@example.com', $data['email']);
        $this->assertSame(0, $data['storageCard']);
        $this->assertSame('John Snow', $data['cardholderName']);
        $this->assertSame('123', $data['securityCode']);
    }

    public function testTokenizationRequestToArrayWithoutOptionalFields(): void
    {
        $request = new TokenizationRequest(
            cardNumber: '4111111111111111',
            expirationMonth: '06',
            expirationYear: '2030',
            email: 'test@example.com',
            storageCard: 2
        );

        $data = $request->toArray();

        $this->assertArrayNotHasKey('cardholderName', $data);
        $this->assertArrayNotHasKey('securityCode', $data);
        $this->assertSame(2, $data['storageCard']);
    }

    public function testTokenizationRequestGetters(): void
    {
        $request = new TokenizationRequest(
            cardNumber: '5448280000000007',
            expirationMonth: '03',
            expirationYear: '2027',
            email: 'test@example.com',
            storageCard: 1,
            cardholderName: 'Jane Doe',
            securityCode: '999'
        );

        $this->assertSame('5448280000000007', $request->getCardNumber());
        $this->assertSame('03', $request->getExpirationMonth());
        $this->assertSame('2027', $request->getExpirationYear());
        $this->assertSame('test@example.com', $request->getEmail());
        $this->assertSame(1, $request->getStorageCard());
        $this->assertSame('Jane Doe', $request->getCardholderName());
        $this->assertSame('999', $request->getSecurityCode());
    }

    // -------------------------------------------------------------------------
    // TokenizationResponse
    // -------------------------------------------------------------------------

    public function testTokenizationResponseSuccess(): void
    {
        $response = new TokenizationResponse([
            'returnCode'     => '00',
            'returnMessage'  => 'Success',
            'tokenizationId' => 'uuid-abc-123',
        ]);

        $this->assertTrue($response->isSuccess());
        $this->assertSame('00', $response->getReturnCode());
        $this->assertSame('Success', $response->getReturnMessage());
        $this->assertSame('uuid-abc-123', $response->getTokenizationId());
    }

    public function testTokenizationResponseFailure(): void
    {
        $response = new TokenizationResponse([
            'returnCode'    => '22',
            'returnMessage' => 'Expired card',
        ]);

        $this->assertFalse($response->isSuccess());
        $this->assertNull($response->getTokenizationId());
    }

    // -------------------------------------------------------------------------
    // TokenQueryResponse
    // -------------------------------------------------------------------------

    public function testTokenQueryResponseActiveStatus(): void
    {
        $response = new TokenQueryResponse([
            'returnCode'         => '00',
            'returnMessage'      => 'Success',
            'tokenizationId'     => 'uuid-abc-123',
            'tokenizationStatus' => 'Active',
            'affiliation'        => '123456789',
            'lastModifiedDate'   => '2024-03-15T10:00:00-03:00',
            'last4'              => '1111',
            'brand'              => ['name' => 'Visa'],
            'token'              => [
                'code'           => '4000056655665556',
                'expirationDate' => '12/2028',
            ],
        ]);

        $this->assertTrue($response->isSuccess());
        $this->assertTrue($response->isActive());
        $this->assertSame('Active', $response->getTokenizationStatus());
        $this->assertSame('Visa', $response->getBrandName());
        $this->assertSame('1111', $response->getLast4());
        $this->assertSame('4000056655665556', $response->getTokenCode());
        $this->assertSame('12/2028', $response->getTokenExpirationDate());
        $this->assertSame('123456789', $response->getAffiliation());
        $this->assertSame('2024-03-15T10:00:00-03:00', $response->getLastModifiedDate());
    }

    public function testTokenQueryResponsePendingIsNotActive(): void
    {
        $response = new TokenQueryResponse([
            'returnCode'         => '00',
            'returnMessage'      => 'Success',
            'tokenizationId'     => 'uuid-pending',
            'tokenizationStatus' => 'Pending',
        ]);

        $this->assertFalse($response->isActive());
        $this->assertSame('Pending', $response->getTokenizationStatus());
        $this->assertNull($response->getBrandName());
        $this->assertNull($response->getLast4());
        $this->assertNull($response->getTokenCode());
    }

    public function testTokenQueryResponseFailedWithBrandMessage(): void
    {
        $response = new TokenQueryResponse([
            'returnCode'         => '33',
            'returnMessage'      => 'Failed',
            'tokenizationId'     => 'uuid-failed',
            'tokenizationStatus' => 'Failed',
            'brand'              => [
                'name'    => 'Visa',
                'message' => 'cardNotEligible: This card cannot be used for tokenization at this moment',
            ],
        ]);

        $this->assertFalse($response->isActive());
        $this->assertFalse($response->isSuccess());
        $this->assertSame('Failed', $response->getTokenizationStatus());
        $this->assertSame('Visa', $response->getBrandName());
        $this->assertNotNull($response->getBrandMessage());
    }

    // -------------------------------------------------------------------------
    // CryptogramResponse
    // -------------------------------------------------------------------------

    public function testCryptogramResponseWithAllFields(): void
    {
        $response = new CryptogramResponse([
            'returnCode'     => '00',
            'returnMessage'  => 'Success',
            'tokenizationId' => 'uuid-abc-123',
            'cryptogramInfo' => [
                'tokenCryptogram' => 'ANbuvvxnDbK2AAEShHMWGgADFA==',
                'eci'             => '07',
                'expirationDate'  => '2024-03-15T10:00:00.000Z',
            ],
        ]);

        $this->assertTrue($response->isSuccess());
        $this->assertSame('ANbuvvxnDbK2AAEShHMWGgADFA==', $response->getTokenCryptogram());
        $this->assertSame('07', $response->getEci());
        $this->assertSame('2024-03-15T10:00:00.000Z', $response->getExpirationDate());
        $this->assertSame('uuid-abc-123', $response->getTokenizationId());
    }

    public function testCryptogramResponseWithoutOptionalFields(): void
    {
        $response = new CryptogramResponse([
            'returnCode'     => '00',
            'returnMessage'  => 'Success',
            'tokenizationId' => 'uuid-no-extras',
            'cryptogramInfo' => [
                'tokenCryptogram' => 'NoBrandExpiry==',
            ],
        ]);

        $this->assertTrue($response->isSuccess());
        $this->assertNull($response->getExpirationDate());
        $this->assertNull($response->getEci());
        $this->assertSame('NoBrandExpiry==', $response->getTokenCryptogram());
    }

    // -------------------------------------------------------------------------
    // TokenManagementResponse
    // -------------------------------------------------------------------------

    public function testTokenManagementResponseSuccess(): void
    {
        $response = new TokenManagementResponse([
            'returnCode'     => '00',
            'returnMessage'  => 'Success',
            'tokenizationId' => 'uuid-managed',
        ]);

        $this->assertTrue($response->isSuccess());
        $this->assertSame('uuid-managed', $response->getTokenizationId());
        $this->assertNull($response->getBrandName());
        $this->assertNull($response->getBrandMessage());
    }

    public function testTokenManagementResponseWithBrandError(): void
    {
        $response = new TokenManagementResponse([
            'returnCode'     => '33',
            'returnMessage'  => 'Failed',
            'tokenizationId' => 'uuid-brand-error',
            'brand'          => [
                'name'    => 'Visa',
                'message' => 'Card not allowed',
            ],
        ]);

        $this->assertFalse($response->isSuccess());
        $this->assertSame('Visa', $response->getBrandName());
        $this->assertSame('Card not allowed', $response->getBrandMessage());
    }

    // -------------------------------------------------------------------------
    // Transaction::withBrandToken
    // -------------------------------------------------------------------------

    public function testTransactionWithBrandTokenIncludesAllFields(): void
    {
        $transaction = (new Transaction(20.99, 'pedido123'))
            ->creditCard('4000056655665556', '', '12', '2028', 'John Snow')
            ->withBrandToken(
                tokenCryptogram: 'ANbuvvxnDbK2AAEShHMWGgADFA==',
                storageCard: 2,
                sai: '07',
                credentialId: '01'
            );

        $data = $transaction->toArray();

        $this->assertSame('ANbuvvxnDbK2AAEShHMWGgADFA==', $data['tokenCryptogram']);
        $this->assertSame('2', $data['storageCard']);
        $this->assertSame(['sai' => '07'], $data['securityAuthentication']);
        $this->assertSame(['credentialId' => '01'], $data['transactionCredentials']);
    }

    public function testTransactionWithBrandTokenWithoutSaiAndCredentialId(): void
    {
        $transaction = (new Transaction(10.00, 'ref001'))
            ->creditCard('4111111111111111', '', '01', '2029', 'Portador')
            ->withBrandToken('CRYPTOtoken==', 2);

        $data = $transaction->toArray();

        $this->assertSame('CRYPTOtoken==', $data['tokenCryptogram']);
        $this->assertSame('2', $data['storageCard']);
        $this->assertArrayNotHasKey('securityAuthentication', $data);
        $this->assertArrayNotHasKey('transactionCredentials', $data);
    }

    public function testTransactionWithoutTokenizationDoesNotIncludeTokenFields(): void
    {
        $transaction = (new Transaction(5.00, 'semtoken'))
            ->creditCard('5448280000000007', '123', '06', '2027', 'Normal Card');

        $data = $transaction->toArray();

        $this->assertArrayNotHasKey('tokenCryptogram', $data);
        $this->assertArrayNotHasKey('storageCard', $data);
        $this->assertArrayNotHasKey('securityAuthentication', $data);
        $this->assertArrayNotHasKey('transactionCredentials', $data);
    }
}
