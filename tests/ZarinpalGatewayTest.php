<?php

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Rohama\Koin\Support\Currency;
use Rohama\Koin\Exception\InvalidRequestException;
use Rohama\Koin\Exception\PaymentException;
use Rohama\Koin\Exception\PaymentFailedException;
use Rohama\Koin\Zarinpal\ZarinpalGateway;
use Rohama\Translator\Translator;

final class ZarinpalGatewayTest extends TestCase
{
    public function testParseCallbackMapsASuccessfulCallback(): void
    {
        $gateway = new ZarinpalGateway('k');
        $result = $gateway->parseCallback(['Authority' => 'A1', 'Status' => 'OK'], []);

        $this->assertSame('success', $result->status);
        $this->assertSame('A1', $result->transactionId);
    }

    public function testParseCallbackMapsACanceledCallback(): void
    {
        $gateway = new ZarinpalGateway('k');
        $result = $gateway->parseCallback(['Authority' => 'A1', 'Status' => 'NOK'], []);

        $this->assertSame('canceled', $result->status);
    }

    public function testParseCallbackRejectsAMissingAuthority(): void
    {
        $this->expectException(InvalidRequestException::class);

        $gateway = new ZarinpalGateway('k');
        $gateway->parseCallback(['Status' => 'OK'], []);
    }

    public function testVerifyMapsAnInvalidAuthorityToAFailedResult(): void
    {
        $gateway = new ZarinpalGateway('k');
        $gateway->setClient($this->mockHttp([
            [200, ['data' => ['code' => -22, 'message' => 'invalid authority']]],
        ]));

        $result = $gateway->verify('A1', amount: 1000);

        $this->assertSame('failed', $result->status);
        $this->assertSame('A1', $result->transactionId);
        $this->assertStringContainsString('-22', $result->message);
    }

    public function testVerifyConvertsTomanAmountToRial(): void
    {
        $mock = new MockHandler([
            new Response(200, ['Content-Type' => 'application/json'], json_encode(['data' => ['code' => 100, 'ref_id' => 'ref1']])),
        ]);
        $client = new Client(['handler' => $mock]);

        $gateway = new ZarinpalGateway('k');
        $gateway->setClient($client);
        $result = $gateway->verify('A1', amount: 1000, currency: Currency::TOMAN);

        $body = json_decode($mock->getLastRequest()->getBody()->getContents(), true);
        $this->assertSame(10000, $body['amount']);
        $this->assertArrayNotHasKey('currency', $body);
        $this->assertSame(10000, $result->amount);
    }

    public function testGatewayDisplayNameResolvesALocale(): void
    {
        $gateway = new ZarinpalGateway('k');

        $this->assertSame('زرین پال', $gateway->displayName('fa'));
        $this->assertSame('Zarinpal', $gateway->displayName('en'));
    }

    public function testExceptionMessageResolvesTheDefaultLocale(): void
    {
        Translator::setDefaultLocale('fa');

        try {
            $gateway = new ZarinpalGateway('k');
            $gateway->request(1000, description: 'x');
            $this->fail('expected a PaymentException');
        } catch (PaymentException $e) {
            $this->assertSame('زرین پال نیازمند آدرس بازگشت است.', $e->message());
            $this->assertSame('Zarinpal requires a callback URL.', $e->message('en'));
        } finally {
            Translator::setDefaultLocale('en');
        }
    }

    public function testConfigFieldsListsTheZarinpalInputs(): void
    {
        $keys = array_map(fn ($f) => $f['key'], ZarinpalGateway::configFields());

        $this->assertSame(['apiKey', 'sandbox'], $keys);
    }

    public function testRequestTargetsTheVersionedSandboxEndpoint(): void
    {
        $uri = $this->captureRequestUri(true, static function (ZarinpalGateway $gateway): void {
            $gateway->request(1000, description: 'endpoint check');
        });

        $this->assertSame('https://sandbox.zarinpal.com/pg/v4/payment/request.json', $uri);
    }

    public function testRequestTargetsTheVersionedLiveEndpoint(): void
    {
        $uri = $this->captureRequestUri(false, static function (ZarinpalGateway $gateway): void {
            $gateway->request(1000, description: 'endpoint check');
        });

        $this->assertSame('https://payment.zarinpal.com/pg/v4/payment/request.json', $uri);
    }

    public function testVerifyTargetsTheVersionedEndpoint(): void
    {
        $uri = $this->captureRequestUri(true, static function (ZarinpalGateway $gateway): void {
            $gateway->verify('A1', amount: 1000);
        });

        $this->assertSame('https://sandbox.zarinpal.com/pg/v4/payment/verify.json', $uri);
    }

    public function testANonJsonResponseRaisesAGatewayException(): void
    {
        $gateway = new ZarinpalGateway('k', callbackUrl: 'https://example.com/cb');
        $gateway->setClient($this->mockHttp([
            [404, '<html><body>404 Not Found</body></html>'],
        ]));

        $this->expectException(PaymentFailedException::class);
        $this->expectExceptionMessage('non-JSON response (HTTP 404)');

        $gateway->request(1000, description: 'endpoint check');
    }

    /**
     * Runs a call against a mock handler that reuses the gateway's real base_uri,
     * so root-relative paths resolve exactly as they do in production.
     */
    private function captureRequestUri(bool $sandbox, callable $call): string
    {
        $container = [];
        $stack = new HandlerStack(new MockHandler([
            new Response(200, ['Content-Type' => 'application/json'], json_encode(['data' => ['code' => 100, 'authority' => 'A1', 'ref_id' => 'r1']])),
        ]));
        $stack->push(Middleware::history($container));

        $gateway = new ZarinpalGateway('k', callbackUrl: 'https://example.com/cb', sandbox: $sandbox);
        $baseUri = (string) $gateway->client()->getConfig('base_uri');

        $gateway->setClient(new Client([
            'base_uri' => $baseUri,
            'handler' => $stack,
        ]));

        $call($gateway);

        return (string) $container[0]['request']->getUri();
    }

    public function testLangFilesCoverTheZarinpalName(): void
    {
        $this->assertSame('Zarinpal', Translator::trans('Koin-zarinpal:driver.name'));
    }

    public function testValidationErrorsLocalizeViaGatewayNamespace(): void
    {
        try {
            $gateway = new ZarinpalGateway('k');
            $gateway->request(0);
            $this->fail('Expected an InvalidRequestException.');
        } catch (InvalidRequestException $e) {
            $this->assertSame('zarinpal', $e->gateway);
            $this->assertSame('مبلغ باید بزرگ‌تر از صفر باشد.', $e->message('fa'));
            $this->assertSame('Amount must be greater than zero.', $e->message());
        }
    }

    public function testRequestIssuesARealSandboxPayment(): void
    {
        $merchant = getenv('ZARINPAL_SANDBOX_MERCHANT');

        if ($merchant === false) {
            $this->markTestSkipped('Set ZARINPAL_SANDBOX_MERCHANT to run the live sandbox test.');
        }

        $gateway = new ZarinpalGateway(
            $merchant,
            callbackUrl: 'https://example.com/cb',
            sandbox: true,
        );

        $result = $gateway->request(
            1000,
            orderId: 'koin-' . time(),
            callbackUrl: 'https://example.com/cb',
            description: 'koin integration test',
        );

        $this->assertSame('pending', $result->status);
        $this->assertNotSame('', $result->transactionId);
        $this->assertStringContainsString('sandbox.zarinpal.com', $result->redirectUrl);
    }
    public function testRefundRequiresTheRefundableFlag(): void
    {
        $gateway = new ZarinpalGateway('k');

        $this->expectException(InvalidRequestException::class);
        $this->expectExceptionMessage('constructed with refundable: true');

        $gateway->refund('t1');
    }
}
