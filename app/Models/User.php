<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'username', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    public const USERNAME_PATTERN = '/^[A-Za-z0-9._-]{3,50}$/';

    /**
     * Users need an email; a username-only admin gets one on this reserved domain that is never mailed.
     */
    public const PLACEHOLDER_EMAIL_DOMAIN = 'admin.ruang-kuesioner.invalid';

    public static function placeholderEmail(string $username): string
    {
        return $username.'@'.self::PLACEHOLDER_EMAIL_DOMAIN;
    }

    public function hasRealEmail(): bool
    {
        return ! str_ends_with((string) $this->email, '@'.self::PLACEHOLDER_EMAIL_DOMAIN);
    }

    /**
     * What the admin types to sign in.
     */
    public function loginName(): string
    {
        return $this->username ?? $this->email;
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }
}
