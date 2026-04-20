<?php

use Huwiya\Exceptions\HuwiyaConflictException;
use Huwiya\InteractsWithHuwiya;
use Huwiya\TokenClaims;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Str;

/**
 * Fixture with the default conflict policy — throws HuwiyaConflictException.
 */
class StrictConflictUser extends Authenticatable
{
    use InteractsWithHuwiya;

    protected $table = 'users';

    protected $guarded = [];

    public function getHuwiyaCreateAttributes(TokenClaims $claims): array
    {
        return ['name' => $claims->name, 'phone' => $claims->phone, 'email' => $claims->email];
    }

    public function getHuwiyaUpdateAttributes(TokenClaims $claims): array
    {
        return ['name' => $claims->name, 'phone' => $claims->phone, 'email' => $claims->email];
    }
}

/**
 * Fixture that deletes the colliding row and lets the retry proceed.
 */
class DeleteOnConflictUser extends Authenticatable
{
    use InteractsWithHuwiya;

    protected $table = 'users';

    protected $guarded = [];

    public function getHuwiyaCreateAttributes(TokenClaims $claims): array
    {
        return ['name' => $claims->name, 'phone' => $claims->phone, 'email' => $claims->email];
    }

    public function getHuwiyaUpdateAttributes(TokenClaims $claims): array
    {
        return ['name' => $claims->name, 'phone' => $claims->phone, 'email' => $claims->email];
    }

    public function resolveHuwiyaConflict(TokenClaims $claims, self $existingRow, string $column): void
    {
        $existingRow->delete();
    }
}

/**
 * Fixture that detaches the colliding value from the stale row by nulling
 * the conflicting column (e.g. an app treating phone as re-assignable but
 * preserving the old account shell for audit).
 */
class DetachColumnUser extends Authenticatable
{
    use InteractsWithHuwiya;

    protected $table = 'users';

    protected $guarded = [];

    public function getHuwiyaCreateAttributes(TokenClaims $claims): array
    {
        return ['name' => $claims->name, 'phone' => $claims->phone, 'email' => $claims->email];
    }

    public function getHuwiyaUpdateAttributes(TokenClaims $claims): array
    {
        return ['name' => $claims->name, 'phone' => $claims->phone, 'email' => $claims->email];
    }

    public function resolveHuwiyaConflict(TokenClaims $claims, self $existingRow, string $column): void
    {
        $existingRow->update([$column => null]);
    }
}

/**
 * Fixture whose policy throws a custom exception.
 */
class CustomRejectUser extends Authenticatable
{
    use InteractsWithHuwiya;

    protected $table = 'users';

    protected $guarded = [];

    public function getHuwiyaCreateAttributes(TokenClaims $claims): array
    {
        return ['name' => $claims->name, 'phone' => $claims->phone, 'email' => $claims->email];
    }

    public function resolveHuwiyaConflict(TokenClaims $claims, self $existingRow, string $column): void
    {
        throw new \RuntimeException('Custom rejection for phone reassignment.');
    }
}

it('throws HuwiyaConflictException by default on phone collision', function () {
    StrictConflictUser::create([
        'huwiya_id' => (string) Str::ulid(),
        'name' => 'Old User',
        'phone' => '+9647711111111',
        'email' => 'old@example.com',
    ]);

    $claims = makeTestClaims([
        'id' => (string) Str::ulid(),
        'phone' => '+9647711111111',
        'email' => 'new@example.com',
    ]);

    expect(fn () => StrictConflictUser::findOrCreateFromHuwiya($claims))
        ->toThrow(HuwiyaConflictException::class);
});

it('carries the claims, existing row, and column on the exception', function () {
    $existing = StrictConflictUser::create([
        'huwiya_id' => (string) Str::ulid(),
        'name' => 'Old',
        'phone' => '+9647722222222',
        'email' => 'stale@example.com',
    ]);

    $claims = makeTestClaims([
        'id' => (string) Str::ulid(),
        'phone' => '+9647722222222',
        'email' => 'new2@example.com',
    ]);

    try {
        StrictConflictUser::findOrCreateFromHuwiya($claims);
        $this->fail('Expected HuwiyaConflictException');
    } catch (HuwiyaConflictException $e) {
        expect($e->claims)->toBe($claims)
            ->and($e->existingRow?->getKey())->toBe($existing->getKey())
            ->and($e->conflictingColumn)->toBe('phone');
    }
});

it('resolves the conflict by deleting the old row and retrying', function () {
    $old = DeleteOnConflictUser::create([
        'huwiya_id' => (string) Str::ulid(),
        'name' => 'Old Owner',
        'phone' => '+9647733333333',
        'email' => 'olddelete@example.com',
    ]);

    $newId = (string) Str::ulid();
    $claims = makeTestClaims([
        'id' => $newId,
        'name' => 'New Owner',
        'phone' => '+9647733333333',
        'email' => 'new-owner@example.com',
    ]);

    $user = DeleteOnConflictUser::findOrCreateFromHuwiya($claims);

    expect($user->huwiya_id)->toBe($newId)
        ->and($user->phone)->toBe('+9647733333333');

    expect(DeleteOnConflictUser::find($old->getKey()))->toBeNull();
});

it('resolves the conflict by detaching the conflicting column from the stale row', function () {
    // Collide on email (nullable) so detach-to-null is schema-legal.
    $stale = DetachColumnUser::create([
        'huwiya_id' => (string) Str::ulid(),
        'name' => 'Stale Owner',
        'phone' => '+9647780000001',
        'email' => 'sharedemail@example.com',
    ]);

    $newId = (string) Str::ulid();
    $claims = makeTestClaims([
        'id' => $newId,
        'name' => 'New Owner',
        'phone' => '+9647780000002',
        'email' => 'sharedemail@example.com',
    ]);

    $user = DetachColumnUser::findOrCreateFromHuwiya($claims);

    expect($user->huwiya_id)->toBe($newId)
        ->and($user->email)->toBe('sharedemail@example.com');

    expect($stale->fresh()->email)->toBeNull();
});

it('propagates custom exceptions raised by the policy', function () {
    CustomRejectUser::create([
        'huwiya_id' => (string) Str::ulid(),
        'name' => 'Old',
        'phone' => '+9647755555555',
        'email' => 'oldcustom@example.com',
    ]);

    $claims = makeTestClaims([
        'id' => (string) Str::ulid(),
        'phone' => '+9647755555555',
        'email' => 'newcustom@example.com',
    ]);

    expect(fn () => CustomRejectUser::findOrCreateFromHuwiya($claims))
        ->toThrow(\RuntimeException::class, 'Custom rejection for phone reassignment.');
});

it('does not hit the conflict path for a normal first-time registration', function () {
    $newId = (string) Str::ulid();

    $claims = makeTestClaims([
        'id' => $newId,
        'name' => 'Fresh',
        'phone' => '+9647766666666',
        'email' => 'fresh@example.com',
    ]);

    $user = StrictConflictUser::findOrCreateFromHuwiya($claims);

    expect($user->huwiya_id)->toBe($newId);
});
