<?php

namespace App\Actions\Fortify;

use App\Models\User;
use App\Support\PhilippineFormats;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\UpdatesUserProfileInformation;

/**
 * Account Settings → name and login email (Fortify route user-profile-information.update).
 */
class UpdateUserProfileInformation implements UpdatesUserProfileInformation
{
    /**
     * @param  array<string, string>  $input
     *
     * @throws ValidationException
     */
    public function update(User $user, array $input): void
    {
        $input['name'] = PhilippineFormats::name((string) ($input['name'] ?? ''));

        Validator::make($input, [
            'name' => ['required', 'string', 'max:255', 'regex:'.PhilippineFormats::NAME_PATTERN],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users')->ignore($user->id)],
        ], ['name.regex' => PhilippineFormats::NAME_MESSAGE])->validateWithBag('updateProfileInformation');

        $email = Str::lower($input['email']);

        // Every User must verify its email, so a NEW address goes back to unverified
        // and the `verified` middleware holds them until they click the new link.
        // Compared in any case: a test account's saved email has capitals.
        if ($email !== Str::lower($user->email)) {
            $user->forceFill([
                'name' => $input['name'],
                'email' => $email,
                'email_verified_at' => null,
            ])->save();

            $user->sendEmailVerificationNotification();

            return;
        }

        $user->forceFill(['name' => $input['name']])->save();
    }
}
