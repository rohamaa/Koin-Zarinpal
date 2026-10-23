<?php

namespace Rohama\Koin\Zarinpal;

use GuzzleHttp\Client;
use Psr\Http\Message\ResponseInterface;
use Rohama\Koin\Support\Currency;
use Rohama\Koin\Support\PaymentStatus;
use Rohama\Koin\Exception\InvalidRequestException;
use Rohama\Koin\Exception\PaymentFailedException;
use Rohama\Koin\Exception\VerificationFailedException;
use Rohama\Koin\FeeCalculatorGateway;
use Rohama\Koin\PaymentGateway;
use Rohama\Koin\RefundableGateway;
use Rohama\Koin\Requests\Callback;
use Rohama\Koin\Responses\FeeResponse;
use Rohama\Koin\Responses\PaymentResponse;
use Rohama\Koin\Responses\RefundResponse;
use Rohama\Koin\Responses\UnverifiedResponse;
use Rohama\Koin\Responses\VerificationResponse;
use Rohama\Koin\SandboxGateway;
use Rohama\Koin\Traits\IsPayment;
use Rohama\Koin\UnverifiedGateway;
use Rohama\Translator\Translator;

/**
 * Zarinpal gateway — classic REST v4 API, authenticated with the merchant code.
 */
class ZarinpalGateway implements PaymentGateway, RefundableGateway, UnverifiedGateway, FeeCalculatorGateway, SandboxGateway
{
    use IsPayment { __construct as protected paymentConstruct; }

    public const name = 'zarinpal';

    private const REQUEST_CODE_SUCCESS = 100;
    private const VERIFY_CODE_SUCCESS = 100;
    private const VERIFY_CODE_ALREADY_VERIFIED = 101;
    private const VERIFY_CODE_AUTHORITY_INVALID = -22;
    private const VERIFY_CODE_AUTHORITY_EXPIRED = -33;
    private const REVERSE_CODE_SUCCESS = 100;
    private const UNVERIFIED_CODE_SUCCESS = 100;
    private const FEE_CODE_SUCCESS = 100;

    public function __construct(string $apiKey, ?string $callbackUrl = null, bool $sandbox = false, array $extra = [], bool $refundable = false)
    {
        $this->paymentConstruct($apiKey, $callbackUrl, $sandbox, $extra, $refundable);

        Translator::register('Koin-' . self::name, dirname(__DIR__) . '/lang');
        $this->client = new Client([
            'base_uri' => $this->sandbox
                ? 'https://sandbox.zarinpal.com'
                : 'https://payment.zarinpal.com',
            'timeout' => 15,
            'connect_timeout' => 10,
            'http_errors' => false,
        ]);
    }

    public static function configFields(): array
    {
        $prefix = 'Koin-' . self::name;

        return [
            [
                'key' => 'apiKey',
                'name' => Translator::trans($prefix . ':fields.apiKey'),
                'desc' => Translator::trans($prefix . ':fields.apiKey_help'),
                'type' => 'text',
                'required' => true,
                'validation' => ['required', 'string', 'size:36'],
                'regex' => '/^.{36}$/',
            ],
            [
                'key' => 'sandbox',
                'name' => Translator::trans($prefix . ':fields.sandbox'),
                'desc' => Translator::trans($prefix . ':fields.sandbox_help'),
                'type' => 'checkbox',
                'required' => false,
                'validation' => ['boolean'],
                'regex' => null,
            ],
        ];
    }

    public function request(
        int $amount,
        string $orderId = '',
        ?string $callbackUrl = null,
        string $description = '',
        ?string $mobile = null,
        ?string $email = null,
        ?string $currency = null,
        array $metadata = [],
        array $extra = [],
    ): PaymentResponse {
        if ($amount <= 0) {
            throw new InvalidRequestException('Amount must be greater than zero.', gateway: self::name);
        }

        if ($callbackUrl === null && $this->callbackUrl === null) {
            throw new InvalidRequestException('Zarinpal requires a callback URL.', gateway: self::name);
        }

        if ($description === '') {
            throw new InvalidRequestException('Zarinpal requires a non-empty description.', gateway: self::name);
        }

        $rial = Currency::toRial($currency ?? Currency::RIAL, $amount);

        $response = $this->client()->post('/pg/v4/payment/request.json', [
            'json' => array_filter(array_merge([
                'merchant_id' => $this->apiKey,
                'amount' => $rial,
                'description' => $description,
                'callback_url' => $callbackUrl ?? $this->callbackUrl,
                'metadata' => array_filter([
                    'mobile' => $mobile,
                    'email' => $email,
                    'order_id' => $orderId !== '' ? $orderId : null,
                ]),
            ], $extra)),
        ]);
        $data = $this->decode($response);

        $code = (int) ($data['data']['code'] ?? -1);
        $authority = (string) ($data['data']['authority'] ?? '');

        if ($code !== self::REQUEST_CODE_SUCCESS || $authority === '') {
            throw new PaymentFailedException($this->requestError($data), $code, raw: $data, gateway: self::name);
        }

        $startPay = $this->sandbox
            ? 'https://sandbox.zarinpal.com/pg/StartPay/'
            : 'https://payment.zarinpal.com/pg/StartPay/';

        return new PaymentResponse(
            $data,
            $response,
            PaymentStatus::PENDING,
            $authority,
            $startPay . $authority,
            $orderId,
            isset($data['data']['message']) ? (string) $data['data']['message'] : null,
        );
    }

    public function parseCallback(array $query, array $body): Callback
    {
        $raw = array_merge($query, $body);
        $authority = isset($raw['Authority']) ? (string) $raw['Authority'] : '';
        $status = strtoupper(isset($raw['Status']) ? (string) $raw['Status'] : '');

        if ($authority === '') {
            throw new InvalidRequestException('Zarinpal invalid Callback payload.', gateway: self::name);
        }

        if ($status !== 'OK') {
            return new Callback(PaymentStatus::CANCELED, $authority, extra: $raw);
        }

        return new Callback(PaymentStatus::SUCCESS, $authority, extra: $raw);
    }

    public function verify(
        string $transactionId,
        string $orderId = '',
        ?int $amount = null,
        ?string $currency = null,
        array $extra = [],
    ): VerificationResponse {
        if ($amount === null) {
            throw new InvalidRequestException('Zarinpal requires the original amount for verification.', gateway: self::name);
        }

        $rial = Currency::toRial($currency ?? Currency::RIAL, $amount);

        $response = $this->client()->post('/pg/v4/payment/verify.json', [
            'json' => array_filter(array_merge([
                'merchant_id' => $this->apiKey,
                'amount' => $rial,
                'authority' => $transactionId,
            ], $extra)),
        ]);
        $data = $this->decode($response);

        $code = (int) ($data['data']['code'] ?? -1);

        if ($code === self::VERIFY_CODE_AUTHORITY_INVALID || $code === self::VERIFY_CODE_AUTHORITY_EXPIRED) {
            $message = isset($data['data']['message']) ? (string) $data['data']['message'] : '';

            return new VerificationResponse(
                $data,
                $response,
                PaymentStatus::FAILED,
                $transactionId,
                null,
                null,
                null,
                $message !== ''
                    ? sprintf('Zarinpal verification failed (code %d): %s', $code, $message)
                    : sprintf('Zarinpal verification failed (code %d).', $code),
            );
        }

        if ($code !== self::VERIFY_CODE_SUCCESS && $code !== self::VERIFY_CODE_ALREADY_VERIFIED) {
            throw new VerificationFailedException($this->requestError($data), $code, raw: $data, gateway: self::name);
        }

        return new VerificationResponse(
            $data,
            $response,
            PaymentStatus::SUCCESS,
            $transactionId,
            isset($data['data']['ref_id']) ? (string) $data['data']['ref_id'] : null,
            $rial,
            isset($data['data']['card_pan']) ? (string) $data['data']['card_pan'] : null,
            isset($data['data']['message']) ? (string) $data['data']['message'] : null,
        );
    }

    public function refund(
        string $transactionId,
        string $orderId = '',
        ?int $amount = null,
        ?string $currency = null,
        array $extra = [],
    ): RefundResponse {
        if (!$this->refundable) {
            throw new InvalidRequestException('Zarinpal refunds are only available for gateways constructed with refundable: true.', gateway: self::name);
        }

        $response = $this->client()->post('/pg/v4/payment/reverse.json', [
            'json' => array_filter(array_merge([
                'merchant_id' => $this->apiKey,
                'authority' => $transactionId,
            ], $extra)),
        ]);
        $data = $this->decode($response);

        $code = (int) ($data['data']['code'] ?? -1);

        if ($code !== self::REVERSE_CODE_SUCCESS) {
            throw new PaymentFailedException($this->requestError($data), $code, raw: $data, gateway: self::name);
        }

        return new RefundResponse(
            $data,
            $response,
            PaymentStatus::REFUNDED,
            $transactionId,
            null,
            isset($data['data']['message']) ? (string) $data['data']['message'] : null,
        );
    }

    public function unverified(): UnverifiedResponse
    {
        $response = $this->client()->post('/pg/v4/payment/unVerified.json', [
            'json' => [
                'merchant_id' => $this->apiKey,
            ],
        ]);
        $data = $this->decode($response);

        $code = (int) ($data['data']['code'] ?? -1);

        if ($code !== self::UNVERIFIED_CODE_SUCCESS) {
            throw new PaymentFailedException($this->requestError($data), $code, raw: $data, gateway: self::name);
        }

        return new UnverifiedResponse(
            $data,
            $response,
            isset($data['data']['authorities']) && is_array($data['data']['authorities'])
                ? array_values($data['data']['authorities'])
                : [],
        );
    }

    public function calculateFee(int $amount, ?string $currency = null): FeeResponse
    {
        if ($amount <= 0) {
            throw new InvalidRequestException('Amount must be greater than zero.', gateway: self::name);
        }

        $rial = Currency::toRial($currency ?? Currency::RIAL, $amount);

        $response = $this->client()->post('/pg/v4/payment/feeCalculation.json', [
            'json' => [
                'merchant_id' => $this->apiKey,
                'amount' => $rial,
                'currency' => 'IRR',
            ],
        ]);
        $data = $this->decode($response);

        $code = (int) ($data['data']['code'] ?? -1);

        if ($code !== self::FEE_CODE_SUCCESS) {
            throw new PaymentFailedException($this->requestError($data), $code, raw: $data, gateway: self::name);
        }

        return new FeeResponse(
            $data,
            $response,
            (int) ($data['data']['amount'] ?? $rial),
            (int) ($data['data']['fee'] ?? 0),
            (string) ($data['data']['fee_type'] ?? ''),
            (int) ($data['data']['suggested_amount'] ?? 0),
        );
    }

    /**
     * Decodes a JSON response body, failing loudly when the body is not a JSON object.
     */
    private function decode(ResponseInterface $response): array
    {
        $body = (string) $response->getBody();
        $data = json_decode($body, true);

        if (!is_array($data)) {
            throw new PaymentFailedException(
                sprintf('Zarinpal returned a non-JSON response (HTTP %d).', $response->getStatusCode()),
                raw: ['body' => $body],
                gateway: self::name,
            );
        }

        return $data;
    }

    /**
     * Builds an error message from a request failure payload.
     */
    private function requestError(array $data): string
    {
        $errors = $data['errors'] ?? [];
        if (is_array($errors)) {
            $code = isset($errors['code']) ? (string) $errors['code'] : null;
            $message = isset($errors['message']) ? (string) $errors['message'] : null;
            if ($code !== null || $message !== null) {
                return sprintf('Zarinpal error (code %s): %s', (string) $code, (string) $message);
            }
        }

        return 'Zarinpal returned an unknown error.';
    }
}
