<?php

namespace App\Actions\Fortify;

use App\Actions\Auth\ClaimEmployeeAccount;
use App\Models\User;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\CreatesNewUsers;

/**
 * Fortify's registration hook. It only handles HTTP concerns (input shape,
 * rate limiting by IP); the business rule lives in ClaimEmployeeAccount.
 */
class CreateNewUser implements CreatesNewUsers
{
    use PasswordValidationRules;

    public function __construct(private readonly ClaimEmployeeAccount $claimEmployeeAccount) {}

    /**
     * @param  array<string, string>  $input
     *
     * @throws ValidationException
     */
    public function create(array $input): User
    {
        $throttleKey = 'register|'.request()->ip();
        $maxAttempts = (int) config('security.registration.max_attempts');

        if (RateLimiter::tooManyAttempts($throttleKey, $maxAttempts)) {
            $minutes = (int) ceil(RateLimiter::availableIn($throttleKey) / 60);

            throw ValidationException::withMessages([
                'employee_number' => "Too many registration attempts. Please try again in {$minutes} minute(s).",
            ]);
        }

        Validator::make($input, [
            'employee_number' => ['required', 'string', 'max:50'],
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => $this->passwordRules(),
        ])->validate();

        RateLimiter::hit($throttleKey, (int) config('security.registration.decay_seconds'));

        return $this->claimEmployeeAccount->handle($input['employee_number'], $input['email'], $input['password']);
    }
}
