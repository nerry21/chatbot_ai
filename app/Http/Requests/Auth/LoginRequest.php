<?php

namespace App\Http\Requests\Auth;

use App\Models\User;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LoginRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'email'    => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'string', 'min:1', 'max:255'],
        ];
    }

    /**
     * Attempt to authenticate the request's credentials.
     *
     * @throws ValidationException
     */
    public function authenticate(): void
    {
        $this->ensureIsNotRateLimited();

        $configuredEmail = Str::lower(trim((string) config('chatbot.security.console_login.email')));
        $configuredPassword = (string) config('chatbot.security.console_login.password');
        $configuredName = trim((string) config('chatbot.security.console_login.name', 'Chatbot Admin'));
        $submittedEmail = Str::lower(trim((string) $this->input('email')));
        $submittedPassword = (string) $this->input('password');

        if ($configuredEmail === '' || $configuredPassword === '') {
            throw ValidationException::withMessages([
                'email' => 'Kredensial login admin belum dikonfigurasi.',
            ]);
        }

        if (
            $submittedEmail !== $configuredEmail
            || ! hash_equals($configuredPassword, $submittedPassword)
        ) {
            RateLimiter::hit($this->throttleKey());

            throw ValidationException::withMessages([
                'email' => 'Email atau password admin tidak sesuai.',
            ]);
        }

        $user = User::updateOrCreate(
            ['email' => $configuredEmail],
            [
                'name' => $configuredName !== '' ? $configuredName : 'Chatbot Admin',
                'email_verified_at' => now(),
                'password' => Hash::make($configuredPassword),
                'is_chatbot_admin' => true,
                'is_chatbot_operator' => false,
            ]
        );

        Auth::login($user, $this->boolean('remember'));

        RateLimiter::clear($this->throttleKey());
    }

    /**
     * Ensure the login request is not rate limited.
     *
     * @throws ValidationException
     */
    public function ensureIsNotRateLimited(): void
    {
        if (! RateLimiter::tooManyAttempts($this->throttleKey(), 5)) {
            return;
        }

        event(new Lockout($this));

        $seconds = RateLimiter::availableIn($this->throttleKey());

        throw ValidationException::withMessages([
            'email' => trans('auth.throttle', [
                'seconds' => $seconds,
                'minutes' => ceil($seconds / 60),
            ]),
        ]);
    }

    /**
     * Get the rate limiting throttle key for the request.
     */
    public function throttleKey(): string
    {
        return Str::transliterate(Str::lower($this->string('email')).'|'.$this->ip());
    }
}
