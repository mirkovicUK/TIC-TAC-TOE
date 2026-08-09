<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * Laravel scaffold. Unused at runtime. This application has no authentication.
 *
 * Identity is a per-game, per-mark Player_Token in the server-side session, bound
 * to a `(Game_Id, Mark)` slot on the Game row (ADR-005). There is no login, no
 * guard, no `Auth::` call and no `auth` middleware in `app/` or `routes/`.
 *
 * The three references are all scaffold themselves:
 * - `config/auth.php` — provider entry; no guard is ever resolved to consult it
 * - `database/factories/UserFactory.php`
 * - `database/seeders/DatabaseSeeder.php` — not run by `composer setup` or by CI
 *
 * DO NOT DELETE `0001_01_01_000000_create_users_table.php`. It creates three
 * tables: `users`, `password_reset_tokens` AND `sessions`. `SESSION_DRIVER=database`
 * in `.env.example` and `compose.yaml`, so `sessions` is where every Player_Token
 * lives. Without it no Game can be created, joined or re-entered.
 *
 * Splitting that migration is worse than leaving it. It is already applied on every
 * database this project has, so a replacement re-runs against existing tables and
 * fails "already exists". SQLite migrations run in no transaction, so the row is
 * never written, every later deploy fails identically, and an image rollback does
 * not clear it. See `MULTI_TABLE_EXEMPT` in `scripts/check-migrations.php` and
 * `database/migrations/README.md`.
 *
 * Deleting this class alone breaks `config/auth.php` and gains nothing. The `users`
 * table exists either way; this is what would read it.
 */
#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

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
