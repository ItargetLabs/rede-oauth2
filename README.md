# SDK PHP eRede com OAuth 2.0

SDK de integração com a eRede utilizando autenticação OAuth 2.0 (client credentials), cobrindo transações de crédito/débito e o serviço de **Tokenização de Bandeira** (Card Brand Tokenization — MDES/VTS).

## Funcionalidades

**Gateway de Pagamento (`ERede`)**
- Autenticação OAuth 2.0 (client credentials)
- Autorização de transações de crédito e débito
- Captura de transações pré-autorizadas
- Cancelamento de transações
- Consulta por TID e por referência
- Parcelamento

**Tokenização de Bandeira (`CardBrandTokenizationClient`)**
- Criação de requisição de tokenização (`POST /token-service/oauth/v2/tokenization`)
- Consulta de status do token (`GET /token-service/oauth/v2/tokenization/{id}`)
- Geração de criptograma (`POST /token-service/oauth/v2/cryptogram/{id}`)
- Gerenciamento do ciclo de vida — suspender, reativar, deletar (`PUT /token-service/oauth/v2/tokenization/{id}`)
- Registro de webhook no sandbox (`POST /token-service/oauth/v2/tokenization/seturl`)
- Suporte a transações com token de bandeira via `Transaction::withBrandToken()`

## Requisitos

- PHP >= 8.1
- Docker (para desenvolvimento)

## Instalação

### Via Composer

```bash
composer require devsitarget/sdk-erede-php-oauth
```

### Via Docker (desenvolvimento)

```bash
make build    # Constrói a imagem
make up       # Inicia o container
make install  # Instala as dependências
make setup-env  # Cria o .env a partir do env.example
```

## Configuração

### Credenciais

```php
<?php
use RedeOAuth\Store;
use RedeOAuth\Environment;
use RedeOAuth\OAuth\OAuthClient;

// Sandbox
$oauthClient = new OAuthClient('https://rl7-sandbox-api.useredecloud.com.br/oauth2/token');
$store = new Store('MERCHANT_ID', 'MERCHANT_KEY', Environment::sandbox(), $oauthClient);

// Produção
$oauthClient = new OAuthClient('https://api.userede.com.br/erede/oauth2/token');
$store = new Store('MERCHANT_ID', 'MERCHANT_KEY', Environment::production(), $oauthClient);
```

---

## Gateway de Pagamento

### Autorizar uma transação (sem captura automática)

```php
<?php
use RedeOAuth\Transaction;
use RedeOAuth\ERede;

$transaction = (new Transaction(20.99, 'pedido' . time()))
    ->creditCard('5448280000000007', '235', '12', '2028', 'John Snow')
    ->capture(false);

try {
    $response = (new ERede($store))->create($transaction);

    if ($response->getReturnCode() === '00') {
        printf("Autorizada; tid=%s\n", $response->getTid());
    } else {
        printf("Recusada: %s\n", $response->getReturnMessage());
    }
} catch (\Exception $e) {
    printf("Erro: %s\n", $e->getMessage());
}
```

### Autorizar com captura automática

```php
<?php
$transaction = (new Transaction(20.99, 'pedido' . time()))
    ->creditCard('5448280000000007', '235', '12', '2028', 'John Snow')
    ->capture(true);

$response = (new ERede($store))->create($transaction);
```

### Parcelamento

```php
<?php
$transaction = (new Transaction(100.00, 'pedido' . time()))
    ->creditCard('5448280000000007', '235', '12', '2028', 'John Snow')
    ->setInstallments(3);

$response = (new ERede($store))->create($transaction);

if ($response->getReturnCode() === '00') {
    printf("Parcelado em %dx; tid=%s\n", $response->getInstallments(), $response->getTid());
}
```

### Capturar uma transação pré-autorizada

```php
<?php
// Pré-autoriza
$transaction = (new Transaction(20.99, 'pedido' . time()))
    ->creditCard('5448280000000007', '235', '12', '2028', 'John Snow')
    ->capture(false);

$response = (new ERede($store))->create($transaction);

// Captura
$captureTransaction = (new Transaction(20.99))->setTid($response->getTid());
$captureResponse = (new ERede($store))->capture($captureTransaction);

if ($captureResponse->getReturnCode() === '00') {
    printf("Capturada; tid=%s\n", $captureResponse->getTid());
}
```

### Cancelar uma transação

```php
<?php
$cancelTransaction = (new Transaction(20.99))->setTid('TID_AQUI');
$cancelResponse = (new ERede($store))->cancel($cancelTransaction);

// Código 359 = cancelamento realizado com sucesso
if (in_array($cancelResponse->getReturnCode(), ['00', '359'])) {
    printf("Cancelada; tid=%s\n", $cancelResponse->getTid());
}
```

### Consultar por TID

```php
<?php
$response = (new ERede($store))->get('TID_AQUI');

printf("Status: %s\n", $response->getAuthorization()->getStatus());
printf("NSU: %s\n", $response->getAuthorization()->getNsu());
printf("Autorização: %s\n", $response->getAuthorization()->getAuthorizationCode());
```

### Consultar por referência

```php
<?php
$response = (new ERede($store))->getByReference('pedido123');

printf("TID: %s\n", $response->getTid());
```

---

## Tokenização de Bandeira

> **Pré-requisito:** O serviço de tokenização precisa estar habilitado para o seu estabelecimento. Acesse [developer.userede.com.br](https://developer.userede.com.br) para habilitar.

O fluxo de tokenização é **assíncrono**: após criar uma requisição, o token é provisionado pela bandeira (MDES para Mastercard, VTS para Visa) e seu status é atualizado via webhook.

### 1. Configurar o cliente

```php
<?php
use RedeOAuth\Store;
use RedeOAuth\Environment;
use RedeOAuth\OAuth\OAuthClient;
use RedeOAuth\Tokenization\CardBrandTokenizationClient;

$oauthClient = new OAuthClient('https://rl7-sandbox-api.useredecloud.com.br/oauth2/token');
$store  = new Store('MERCHANT_ID', 'MERCHANT_KEY', Environment::sandbox(), $oauthClient);
$client = new CardBrandTokenizationClient($store);
```

### 2. Registrar URL de webhook (apenas sandbox)

Em produção, o registro é feito pelo Portal Logado da Rede.

```php
<?php
// Sem autenticação no webhook
$result = $client->setWebhookUrl('https://meusite.com/webhook/rede-token');

// Com autenticação Bearer
$result = $client->setWebhookUrl(
    'https://meusite.com/webhook/rede-token',
    'Bearer',
    'Bearer meu_token_secreto'
);
```

### 3. Criar tokenização

```php
<?php
use RedeOAuth\Tokenization\TokenizationRequest;

$request = new TokenizationRequest(
    cardNumber:      '5448280000000007',
    expirationMonth: '12',
    expirationYear:  '2028',
    email:           'portador@exemplo.com',
    storageCard:     0,            // 0 = não armazenado, 2 = já armazenado
    cardholderName:  'JOHN SNOW',
    securityCode:    '235'
);

$response = $client->createTokenization($request);

if ($response->getReturnCode() === '00') {
    $tokenizationId = $response->getTokenizationId(); // UUID a ser armazenado
    printf("Requisição criada; tokenizationId=%s\n", $tokenizationId);
}
```

### 4. Aguardar o webhook e consultar o status

Após receber o evento de webhook, consulte o status do token:

```php
<?php
$token = $client->getToken($tokenizationId);

printf("Status: %s\n", $token->getTokenizationStatus()); // Active | Pending | Failed | ...
printf("Bandeira: %s\n", $token->getBrandName());
printf("Últimos 4: %s\n", $token->getLast4());

if ($token->isActive()) {
    // Token pronto para uso em transações
}
```

Ciclo de vida do status:

| Status | Descrição |
|--------|-----------|
| `Pending` | Aguardando processamento da bandeira |
| `Active` | Pronto para uso em transações |
| `Inactive` | Inativo |
| `Suspended` | Suspenso temporariamente |
| `Deleted` | Removido permanentemente |
| `Failed` | Falha no provisionamento |

### 5. Gerar criptograma

> Gere um criptograma por transação. Não armazene nem reutilize.

```php
<?php
$cryptogram = $client->getCryptogram($tokenizationId);

if ($cryptogram->isSuccess()) {
    printf("Criptograma: %s\n", $cryptogram->getTokenCryptogram());
    printf("ECI: %s\n", $cryptogram->getEci()); // para Visa/ELO
}

// Para transações de recorrência
$cryptogram = $client->getCryptogram($tokenizationId, subscription: true);
```

### 6. Realizar transação com o token

```php
<?php
use RedeOAuth\Transaction;
use RedeOAuth\ERede;

$transaction = (new Transaction(99.90, 'pedido' . time()))
    ->creditCard(
        '5448280000000007', // número do cartão original (ou token PAN)
        '',                 // CVV vazio — a segurança vem do criptograma
        '12',
        '2028',
        'JOHN SNOW'
    )
    ->withBrandToken(
        tokenCryptogram: $cryptogram->getTokenCryptogram(),
        storageCard:     2,                      // 2 = credencial já armazenada
        sai:             $cryptogram->getEci(),  // obrigatório para Visa e ELO
        credentialId:    'CREDENTIAL_ID'         // obrigatório para Mastercard (storageCard 1 ou 2)
    );

$response = (new ERede($store))->create($transaction);
```

### 7. Gerenciar o ciclo de vida do token

```php
<?php
// Suspender (temporariamente)
$response = $client->manageToken(
    $tokenizationId,
    CardBrandTokenizationClient::STATUS_SUSPENDED,
    CardBrandTokenizationClient::REASON_CUSTOMER_REQUEST // ou REASON_SUSPECTED_FRAUD
);

// Reativar
$response = $client->manageToken(
    $tokenizationId,
    CardBrandTokenizationClient::STATUS_RESUMED,
    CardBrandTokenizationClient::REASON_CUSTOMER_REQUEST
);

// Deletar permanentemente
$response = $client->manageToken(
    $tokenizationId,
    CardBrandTokenizationClient::STATUS_DELETED,
    CardBrandTokenizationClient::REASON_CUSTOMER_REQUEST
);
```

---

## Testes

### Configuração

```bash
make setup-env  # cria .env a partir de env.example
```

Edite o `.env` com suas credenciais de sandbox:

```env
REDE_MERCHANT_ID=seu_merchant_id
REDE_MERCHANT_KEY=seu_merchant_key

# Apenas para testes de tokenização:
REDE_TOKENIZATION_WEBHOOK_URL=https://meusite.com/webhook/rede-token
```

### Executar

```bash
# Via Docker (recomendado)
make test

# Localmente
composer test

# Apenas testes unitários
composer test -- --testsuite Unit

# Apenas testes de integração
composer test -- --testsuite Integration

# Apenas tokenização
composer test -- --group tokenization
```

### Suites

| Suite | Descrição |
|-------|-----------|
| **Unit** | Testes dos modelos de dados e camadas HTTP/OAuth — sem chamadas de rede |
| **Integration** | Testes contra o sandbox real da eRede — requerem `.env` configurado |

### Comportamento dos testes de tokenização no sandbox

Os testes de **criptograma** e **gerenciamento de token** dependem do provisionamento completo pela bandeira, que ocorre após o evento de webhook. Sem webhook configurado, esses testes são marcados como `Incompleto` com a mensagem de como configurar. Os demais (criação e consulta de status) funcionam sem webhook.

---

## Estrutura do Projeto

```
rede-oauth2/
├── src/Rede/
│   ├── OAuth/                    # Autenticação OAuth 2.0
│   │   ├── OAuthClient.php       # Obtém/renova tokens via client credentials
│   │   ├── OAuthClientInterface.php
│   │   ├── Token.php
│   │   └── OAuthException.php
│   ├── Http/                     # Camada HTTP
│   │   ├── AuthenticatedHttpClient.php  # Injeta Bearer token + trata 401
│   │   ├── HttpClientInterface.php
│   │   └── HttpException.php
│   ├── Tokenization/             # Tokenização de Bandeira
│   │   ├── CardBrandTokenizationClient.php
│   │   ├── TokenizationRequest.php
│   │   ├── TokenizationResponse.php
│   │   ├── TokenQueryResponse.php
│   │   ├── CryptogramResponse.php
│   │   └── TokenManagementResponse.php
│   ├── ERede.php                 # Gateway de pagamento (fachada)
│   ├── Store.php                 # Configuração do merchant
│   ├── Environment.php           # URLs de sandbox e produção
│   ├── Transaction.php           # Modelo de transação
│   ├── TransactionResponse.php
│   ├── CreditCard.php
│   ├── DebitCard.php
│   └── ...
├── tests/
│   ├── Unit/
│   │   ├── Tokenization/
│   │   │   └── TokenizationModelTest.php   # Testes de DTO sem HTTP
│   │   ├── Http/
│   │   ├── OAuth/
│   │   ├── StoreTest.php
│   │   └── TransactionTest.php
│   ├── Integration/
│   │   ├── RedeGatewayTest.php             # Testes do gateway contra sandbox
│   │   └── TokenizationIntegrationTest.php # Testes de tokenização contra sandbox
│   └── bootstrap.php
├── env.example
├── phpunit.xml
├── composer.json
└── Makefile
```

## Comandos Disponíveis

| Comando | Descrição |
|---------|-----------|
| `make build` | Constrói a imagem Docker |
| `make up` | Inicia o container |
| `make down` | Para e remove o container |
| `make install` | Instala dependências via Composer |
| `make test` | Executa todos os testes |
| `make cs-check` | Verifica estilo de código (PSR-12) |
| `make cs-fix` | Corrige estilo de código automaticamente |
| `make phpstan` | Análise estática com PHPStan |
| `make shell` | Abre shell no container |
| `make setup-env` | Cria `.env` a partir de `env.example` |

## Licença

MIT
