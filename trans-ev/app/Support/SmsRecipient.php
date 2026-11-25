<?php

namespace App\Support;

use Illuminate\Notifications\Notifiable;

/** A phone number without an account, for messages such as login codes. */
final class SmsRecipient
{
    use Notifiable;

    public ?string $name = null;

    public function __construct(public string $phone) {}
}
