# Koin — ZarinPal

<p align="center">
  <a href="https://rohamaa.github.io/Koin-Core/">Docs site</a> ·
  <a href="https://github.com/rohamaa/Koin-Core/blob/docs/index.md">Docs on GitHub</a>
</p>

The **ZarinPal** gateway driver for the Koin package — built on ZarinPal's classic REST v4 gateway (merchant code).

### Install

```bash
composer require rohama/koin-zarinpal
```

### Features

- Payment request — returns authority + StartPay redirect URL
- Verify
- Reverse (refund)
- unVerified
- Fee calculation
- Callback parsing
- Sandbox

> Documented exception: ZarinPal's Verify requires an amount its callback does not carry, so parseCallback() reports the status without calling verify() — the package's only exception to the verify rule.

### Configuration

| Field | Description |
| --- | --- |
| `apiKey` | 36-char merchant code |
| `sandbox` | Test mode (sandbox merchant) |

### Usage

```php
use Rohama\Koin\Zarinpal\ZarinpalGateway;
use Rohama\Koin\Support\PaymentStatus;

$gateway = new ZarinpalGateway('merchant-code-36-chars', 'https://example.com/callback');

$payment = $gateway->request(150000, 'ORDER-1', 'Subscription purchase');

if ($payment->isSuccessful()) {
    header('Location: ' . $payment->redirectUrl);
    exit;
}

// In the callback page — parseCallback runs verify() before reporting success
$callback = $gateway->parseCallback($_GET, $_POST);
if ($callback->status === PaymentStatus::SUCCESS) {
    // Verified
}
```

> `refund()` (in gateways that offer Refund) is available only when the developer-only 5th constructor parameter (`refundable`) is enabled.

Full documentation: <https://rohamaa.github.io/Koin-Core/>