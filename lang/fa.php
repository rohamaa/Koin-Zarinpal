<?php

return [
    'driver.name' => 'زرین پال',
    'fields.apiKey' => 'کد مرچنت',
    'fields.apiKey_help' => 'کد مرچنت زرین پال (merchant_id)، یک UUID سی و شش کاراکتری.',
    'fields.sandbox' => 'حالت آزمایشی',
    'fields.sandbox_help' => 'استفاده از محیط آزمایشی زرین پال به جای محیط اصلی.',
    'messages.Amount must be greater than zero.' => 'مبلغ باید بزرگ‌تر از صفر باشد.',
    'messages.Zarinpal requires a callback URL.' => 'زرین پال نیازمند آدرس بازگشت است.',
    'messages.Zarinpal requires a non-empty description.' => 'زرین پال نیازمند توضیحات غیر خالی است.',
    'messages.Zarinpal requires the original amount for verification.' => 'زرین پال برای تایید نیازمند مبلغ اصلی است.',
    'messages.Zarinpal invalid Callback payload.' => 'پاسخ بازگشتی زرین پال نامعتبر است.',
];
