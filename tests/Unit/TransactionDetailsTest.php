<?php

declare(strict_types=1);

namespace RedeOAuth\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RedeOAuth\Authorization;
use RedeOAuth\Device;
use RedeOAuth\Http\HttpException;
use RedeOAuth\SensitiveDataSanitizer;
use RedeOAuth\ThreeDSecureResponse;
use RedeOAuth\Transaction;
use RedeOAuth\TransactionDetails;
use RedeOAuth\TransactionResponse;
use RedeOAuth\Url;

class TransactionDetailsTest extends TestCase
{
    public function testTransactionToSafeArrayMasksSensitiveCardData(): void
    {
        $transaction = (new Transaction(20.99, 'pedido123'))
            ->creditCard('5448280000000007', '235', '12', '2028', 'John Snow')
            ->capture(false)
            ->setInstallments(3);

        $safe = $transaction->toSafeArray();

        $this->assertSame('544828******0007', $safe['cardNumber']);
        $this->assertSame('***', $safe['securityCode']);
        $this->assertSame('John ***', $safe['cardholderName']);
        $this->assertSame('544828', $safe['cardBin']);
        $this->assertSame('0007', $safe['last4']);
        $this->assertSame('Mastercard', $safe['brand']['name']);
        $this->assertFalse($safe['capture']);
        $this->assertSame(3, $safe['installments']);
    }

    public function testTransactionToSafeArrayWithBrandToken(): void
    {
        $transaction = (new Transaction(10.00, 'ref001'))
            ->creditCard('4111111111111111', '123', '01', '2029', 'Portador')
            ->withBrandToken('CRYPTOtoken==', 2, '07', 'cred-01');

        $safe = $transaction->toSafeArray();

        $this->assertSame('[REDACTED]', $safe['tokenCryptogram']);
        $this->assertSame('[REDACTED]', $safe['transactionCredentials']['credentialId']);
        $this->assertSame('Visa', $safe['brand']['name']);
    }

    public function testTransactionResponseToSafeArray(): void
    {
        $response = new TransactionResponse([
            'tid' => '123456789',
            'reference' => 'pedido123',
            'amount' => 2099,
            'returnCode' => '00',
            'returnMessage' => 'Success.',
            'dateTime' => '2024-01-01T10:00:00',
            'installments' => 3,
            'cardBin' => '544828',
            'last4' => '0007',
            'brand' => ['name' => 'Mastercard', 'message' => null],
            'nsu' => '12345',
            'authorizationCode' => 'ABC123',
        ]);

        $safe = $response->toSafeArray();

        $this->assertSame('123456789', $safe['tid']);
        $this->assertSame('00', $safe['returnCode']);
        $this->assertSame('Success.', $safe['returnMessage']);
        $this->assertSame('Mastercard', $safe['brand']['name']);
        $this->assertSame('544828', $safe['cardBin']);
        $this->assertSame('0007', $safe['last4']);
        $this->assertSame('Approved', $safe['authorization']['status']);
        $this->assertSame('ABC123', $safe['authorization']['authorizationCode']);
    }

    public function testTransactionResponseDetectsBrandFromBin(): void
    {
        $response = new TransactionResponse([
            'returnCode' => '00',
            'returnMessage' => 'Success.',
            'cardBin' => '411111',
            'last4' => '1111',
        ]);

        $this->assertSame('Visa', $response->getBrandName());
    }

    public function testTransactionDetailsFromTransactionAndResponse(): void
    {
        $transaction = (new Transaction(50.00, 'ref999'))
            ->creditCard('5448280000000007', '235', '12', '2028', 'John Snow');

        $response = new TransactionResponse([
            'tid' => 'TID999',
            'returnCode' => '00',
            'returnMessage' => 'Success.',
            'cardBin' => '544828',
            'last4' => '0007',
            'brand' => ['name' => 'Mastercard'],
        ]);

        $details = TransactionDetails::fromTransaction($transaction, $response);
        $data = $details->toArray();

        $this->assertArrayHasKey('request', $data);
        $this->assertArrayHasKey('response', $data);
        $this->assertSame('00', $data['returnCode']);
        $this->assertSame('Success.', $data['returnMessage']);
        $this->assertSame('Mastercard', $data['brand']['name']);
        $this->assertSame('544828******0007', $data['request']['cardNumber']);
    }

    public function testTransactionDetailsWithThreeDSecureAuthentication(): void
    {
        $device = new Device(1, 'BROWSER', false, 'BR', 500, 500, 3);
        $transaction = (new Transaction(30.00, 'ref3ds'))
            ->debitCard('4111111111111111', '123', '06', '2029', 'Jane Doe')
            ->threeDSecure($device)
            ->addUrl('https://example.com/success', Url::THREE_D_SECURE_SUCCESS)
            ->addUrl('https://example.com/failure', Url::THREE_D_SECURE_FAILURE);

        $response = new TransactionResponse([
            'returnCode' => '220',
            'returnMessage' => 'Redirect to 3DS',
            'threeDSecure' => [
                'url' => 'https://acs.example.com',
                'method' => 'POST',
                'parameters' => [
                    'PaReq' => 'sensitive-pareq-data',
                    'MD' => 'merchant-data',
                ],
            ],
        ]);

        $details = TransactionDetails::fromTransaction($transaction, $response);
        $auth = $details->getAuthentication();

        $this->assertNotNull($auth);
        $this->assertArrayHasKey('request', $auth);
        $this->assertArrayHasKey('response', $auth);
        $this->assertSame('https://acs.example.com', $auth['response']['url']);
        $this->assertSame('[REDACTED]', $auth['response']['parameters']['PaReq']);
        $this->assertSame('merchant-data', $auth['response']['parameters']['MD']);
        $this->assertSame('220', $details->getReturnCode());
    }

    public function testTransactionDetailsFromException(): void
    {
        $exception = new HttpException(
            '51 - Insufficient funds',
            400,
            null,
            '51',
            'Insufficient funds',
            [
                'returnCode' => '51',
                'returnMessage' => 'Insufficient funds',
                'brand' => ['name' => 'Visa', 'message' => 'Not enough balance'],
            ]
        );

        $requestData = ['reference' => 'pedido123', 'amount' => 1000];
        $details = TransactionDetails::fromException($exception, $requestData);
        $data = $details->toArray();

        $this->assertSame('51', $data['returnCode']);
        $this->assertSame('Insufficient funds', $data['returnMessage']);
        $this->assertSame('Visa', $data['brand']['name']);
        $this->assertSame('pedido123', $data['request']['reference']);
    }

    public function testSensitiveDataSanitizerMasksEmail(): void
    {
        $sanitized = SensitiveDataSanitizer::sanitize([
            'email' => 'portador@example.com',
            'cardNumber' => '4111111111111111',
        ]);

        $this->assertSame('p***@example.com', $sanitized['email']);
        $this->assertSame('411111******1111', $sanitized['cardNumber']);
    }

    public function testAuthorizationToSafeArray(): void
    {
        $auth = new Authorization([
            'status' => 'Approved',
            'returnCode' => '00',
            'returnMessage' => 'Success.',
            'tid' => 'TID001',
            'nsu' => '99999',
            'authorizationCode' => 'AUTH01',
        ]);

        $safe = $auth->toSafeArray();

        $this->assertSame('Approved', $safe['status']);
        $this->assertSame('00', $safe['returnCode']);
        $this->assertSame('AUTH01', $safe['authorizationCode']);
    }

    public function testThreeDSecureResponseToSafeArray(): void
    {
        $threeDS = new ThreeDSecureResponse([
            'url' => 'https://acs.example.com',
            'method' => 'POST',
            'parameters' => ['PaReq' => 'secret', 'CReq' => 'also-secret'],
        ]);

        $safe = $threeDS->toSafeArray();

        $this->assertSame('[REDACTED]', $safe['parameters']['PaReq']);
        $this->assertSame('[REDACTED]', $safe['parameters']['CReq']);
    }
}
