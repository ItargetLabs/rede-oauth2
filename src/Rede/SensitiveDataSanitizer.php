<?php

declare(strict_types=1);

namespace RedeOAuth;

/**
 * Remove ou mascara dados sensíveis de arrays de transação para logging e auditoria.
 */
class SensitiveDataSanitizer
{
    private const REDACTED = '[REDACTED]';

    private const SENSITIVE_KEYS = [
        'cardNumber',
        'securityCode',
        'cardholderName',
        'holderName',
        'tokenCryptogram',
        'credentialId',
        'access_token',
        'accessToken',
        'client_secret',
        'clientSecret',
        'password',
        'PaReq',
        'CReq',
    ];

    /**
     * Sanitiza recursivamente um array removendo ou mascarando campos sensíveis.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public static function sanitize(array $data): array
    {
        $result = [];

        foreach ($data as $key => $value) {
            if (is_array($value)) {
                if ($key === 'token' && isset($value['code'])) {
                    $result[$key] = self::sanitizeTokenNode($value);
                    continue;
                }

                if ($key === 'cryptogramInfo') {
                    $result[$key] = self::sanitizeCryptogramInfo($value);
                    continue;
                }

                $result[$key] = self::sanitize($value);
                continue;
            }

            if (in_array($key, self::SENSITIVE_KEYS, true)) {
                $result[$key] = self::maskField($key, (string) $value);
                continue;
            }

            if ($key === 'email' && is_string($value)) {
                $result[$key] = self::maskEmail($value);
                continue;
            }

            $result[$key] = $value;
        }

        return $result;
    }

    /**
     * Mascara um número de cartão mantendo BIN (6 dígitos) e últimos 4 dígitos.
     */
    public static function maskCardNumber(string $cardNumber): string
    {
        $digits = preg_replace('/\D/', '', $cardNumber) ?? '';

        if (strlen($digits) < 10) {
            return self::REDACTED;
        }

        $bin = substr($digits, 0, 6);
        $last4 = substr($digits, -4);

        return $bin . '******' . $last4;
    }

    /**
     * Detecta a bandeira do cartão a partir do BIN ou número do cartão.
     */
    public static function detectBrand(?string $cardNumberOrBin): ?string
    {
        if ($cardNumberOrBin === null || $cardNumberOrBin === '') {
            return null;
        }

        $digits = preg_replace('/\D/', '', $cardNumberOrBin) ?? '';

        if ($digits === '') {
            return null;
        }

        if (str_starts_with($digits, '4')) {
            return 'Visa';
        }

        $prefix2 = (int) substr($digits, 0, 2);
        $prefix4 = (int) substr($digits, 0, 4);

        if (($prefix2 >= 51 && $prefix2 <= 55) || ($prefix4 >= 2221 && $prefix4 <= 2720)) {
            return 'Mastercard';
        }

        if (str_starts_with($digits, '34') || str_starts_with($digits, '37')) {
            return 'Amex';
        }

        $eloPrefixes = ['401178', '401179', '431274', '438935', '451416', '457393', '457631', '457632', '504175', '506699', '5067', '509', '627780', '636297', '636368'];
        foreach ($eloPrefixes as $prefix) {
            if (str_starts_with($digits, $prefix)) {
                return 'Elo';
            }
        }

        if (str_starts_with($digits, '606282') || str_starts_with($digits, '637095') || str_starts_with($digits, '637568')) {
            return 'Hipercard';
        }

        return null;
    }

    private static function maskField(string $key, string $value): string
    {
        return match ($key) {
            'cardNumber' => self::maskCardNumber($value),
            'securityCode' => '***',
            'cardholderName', 'holderName' => self::maskHolderName($value),
            'tokenCryptogram', 'credentialId', 'access_token', 'accessToken', 'client_secret', 'clientSecret', 'password', 'PaReq', 'CReq' => self::REDACTED,
            default => self::REDACTED,
        };
    }

    private static function maskHolderName(string $name): string
    {
        $trimmed = trim($name);

        if ($trimmed === '') {
            return self::REDACTED;
        }

        $parts = preg_split('/\s+/', $trimmed) ?: [];
        $first = $parts[0] ?? '';

        if (count($parts) === 1) {
            return $first[0] . '***';
        }

        return $first . ' ***';
    }

    private static function maskEmail(string $email): string
    {
        $parts = explode('@', $email, 2);

        if (count($parts) !== 2 || $parts[0] === '') {
            return self::REDACTED;
        }

        $local = $parts[0];
        $domain = $parts[1];

        return $local[0] . '***@' . $domain;
    }

    /**
     * @param array<string, mixed> $token
     * @return array<string, mixed>
     */
    private static function sanitizeTokenNode(array $token): array
    {
        $sanitized = $token;

        if (isset($sanitized['code']) && is_string($sanitized['code'])) {
            $sanitized['code'] = self::maskCardNumber($sanitized['code']);
        }

        return $sanitized;
    }

    /**
     * @param array<string, mixed> $cryptogramInfo
     * @return array<string, mixed>
     */
    private static function sanitizeCryptogramInfo(array $cryptogramInfo): array
    {
        $sanitized = $cryptogramInfo;

        if (isset($sanitized['tokenCryptogram'])) {
            $sanitized['tokenCryptogram'] = self::REDACTED;
        }

        return $sanitized;
    }
}
