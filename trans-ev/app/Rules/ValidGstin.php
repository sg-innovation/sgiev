<?php

namespace App\Rules;

use App\Support\Gstin;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/** Format and check-character validation for a GSTIN (catches typos before an invoice is issued). */
class ValidGstin implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (blank($value)) {
            return;
        }
        $g = Gstin::normalise((string) $value);
        if (! preg_match(Gstin::PATTERN, $g)) {
            $fail('Enter a valid 15-character GSTIN.');
        } elseif (Gstin::checkCharacter($g) !== $g[14]) {
            $fail('This GSTIN’s last character doesn’t match. Please check it for typos.');
        }
    }
}
