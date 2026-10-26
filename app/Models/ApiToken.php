<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * A key for the JSON API. Only its SHA-256 hash is stored; the key itself is shown once.
 */
#[Fillable(['name', 'token_hash', 'can_write', 'last_used_at'])]
class ApiToken extends Model
{
    public const PREFIX = 'rk_';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'can_write' => 'boolean',
            'last_used_at' => 'datetime',
        ];
    }

    /**
     * Creates a token and returns it with its plain key.
     *
     * @return array{0: ApiToken, 1: string}
     */
    public static function issue(string $name, bool $canWrite): array
    {
        $plain = self::PREFIX.Str::random(40);
        $token = self::query()->create(['name' => $name, 'token_hash' => hash('sha256', $plain), 'can_write' => $canWrite]);

        return [$token, $plain];
    }

    public static function findByKey(string $plain): ?self
    {
        return $plain === '' ? null : self::query()->where('token_hash', hash('sha256', $plain))->first();
    }
}
