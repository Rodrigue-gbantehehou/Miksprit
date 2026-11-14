<?php

declare(strict_types=1);

namespace App\Service\Payment;

final readonly class PaymentCustomer
{
    public function __construct(
        public string $email,
        public ?string $firstName = null,
        public ?string $lastName = null,
        public ?string $phoneNumber = null,
        public ?string $phoneCountry = null,
    ) {
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false || strlen($email) > 190) {
            throw new \InvalidArgumentException('Payment customer email is invalid.');
        }
        if (($phoneNumber === null) !== ($phoneCountry === null)
            || ($phoneNumber !== null && preg_match('/^[0-9]{5,15}$/D', $phoneNumber) !== 1)
            || ($phoneCountry !== null && preg_match('/^[A-Z]{2}$/D', $phoneCountry) !== 1)) {
            throw new \InvalidArgumentException('Payment customer phone details are invalid.');
        }
        foreach ([$firstName, $lastName, $phoneNumber, $phoneCountry] as $value) {
            if ($value !== null && (trim($value) === '' || strlen($value) > 120 || preg_match('/[\x00-\x1F\x7F]/', $value) === 1)) {
                throw new \InvalidArgumentException('Payment customer details are invalid.');
            }
        }
    }
}
