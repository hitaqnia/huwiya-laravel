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

/**
 * Records every (column, existing-key) pair the policy was called with, and
 * clears each conflict so the write can proceed.
 */
class RecordingResolverUser extends Authenticatable
{
    use InteractsWithHuwiya;

    protected $table = 'users';

    protected $guarded = [];

    /** @var array<int, array{column: string, existing_id: int|string, claim_id: string}> */
    public static array $calls = [];

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
        self::$calls[] = [
            'column' => $column,
            'existing_id' => $existingRow->getKey(),
            'claim_id' => $claims->id,
        ];

        // `phone` is NOT NULL in the test schema, so fully delete the stale
        // row. The SDK refreshes the row between dispatches — a second call
        // against a now-deleted row won't happen.
        $existingRow->delete();
    }
}

it('dispatches once per column when one row collides on multiple columns and the policy clears only the named column', function () {
    // Policy that only nulls the *named* column (when nullable) or mutates it
    // to something unique; this proves the SDK re-evaluates per column.
    $fixture = new class extends Authenticatable {
        use InteractsWithHuwiya;

        protected $table = 'users';

        protected $guarded = [];

        public static array $calls = [];

        public function getHuwiyaCreateAttributes(TokenClaims $claims): array
        {
            return ['name' => $claims->name, 'phone' => $claims->phone, 'email' => $claims->email];
        }

        public function resolveHuwiyaConflict(TokenClaims $claims, self $existingRow, string $column): void
        {
            self::$calls[] = ['column' => $column, 'existing_id' => $existingRow->getKey()];

            if ($column === 'email') {
                $existingRow->update(['email' => null]);
            } else {
                // phone is NOT NULL → move it to a sentinel that won't collide.
                $existingRow->update(['phone' => 'recycled-'.$existingRow->getKey()]);
            }
        }
    };

    $class = $fixture::class;
    $class::$calls = [];

    $shared = $class::create([
        'huwiya_id' => (string) Str::ulid(),
        'name' => 'Shared',
        'phone' => '+9647790000010',
        'email' => 'shared2@example.com',
    ]);

    $claims = makeTestClaims([
        'id' => (string) Str::ulid(),
        'phone' => '+9647790000010',
        'email' => 'shared2@example.com',
    ]);

    $class::findOrCreateFromHuwiya($claims);

    $columns = array_column($class::$calls, 'column');

    expect($class::$calls)->toHaveCount(2)
        ->and($columns)->toBe(['phone', 'email'])
        ->and(array_unique(array_column($class::$calls, 'existing_id')))
        ->toBe([$shared->getKey()]);
});

it('skips later column dispatches when a policy deletes the row in an earlier dispatch', function () {
    RecordingResolverUser::$calls = [];

    $shared = RecordingResolverUser::create([
        'huwiya_id' => (string) Str::ulid(),
        'name' => 'Shared',
        'phone' => '+9647790000001',
        'email' => 'shared@example.com',
    ]);

    $newId = (string) Str::ulid();
    $claims = makeTestClaims([
        'id' => $newId,
        'name' => 'New',
        'phone' => '+9647790000001',
        'email' => 'shared@example.com',
    ]);

    RecordingResolverUser::findOrCreateFromHuwiya($claims);

    // Phone dispatches first (configured order), the policy deletes the row,
    // the SDK refreshes and sees the row is gone, so no email dispatch fires.
    $columns = array_column(RecordingResolverUser::$calls, 'column');

    expect(RecordingResolverUser::$calls)->toHaveCount(1)
        ->and($columns)->toBe(['phone'])
        ->and(RecordingResolverUser::$calls[0]['existing_id'])->toBe($shared->getKey());
});

it('dispatches once per (row, column) when two different rows each collide on a different column', function () {
    RecordingResolverUser::$calls = [];

    $phoneRow = RecordingResolverUser::create([
        'huwiya_id' => (string) Str::ulid(),
        'name' => 'Phone Owner',
        'phone' => '+9647790000002',
        'email' => 'phone-only@example.com',
    ]);

    $emailRow = RecordingResolverUser::create([
        'huwiya_id' => (string) Str::ulid(),
        'name' => 'Email Owner',
        'phone' => '+9647790000003',
        'email' => 'email-only@example.com',
    ]);

    $claims = makeTestClaims([
        'id' => (string) Str::ulid(),
        'phone' => '+9647790000002',
        'email' => 'email-only@example.com',
    ]);

    RecordingResolverUser::findOrCreateFromHuwiya($claims);

    expect(RecordingResolverUser::$calls)->toHaveCount(2);

    $byColumn = collect(RecordingResolverUser::$calls)->keyBy('column');

    expect($byColumn['phone']['existing_id'])->toBe($phoneRow->getKey())
        ->and($byColumn['email']['existing_id'])->toBe($emailRow->getKey());
});

it('does not dispatch for a recyclable column whose claim value is null', function () {
    RecordingResolverUser::$calls = [];

    // Existing row with a phone collision; email on the claim is null so the
    // email column must be skipped by the preflight entirely.
    RecordingResolverUser::create([
        'huwiya_id' => (string) Str::ulid(),
        'phone' => '+9647790000004',
        'email' => 'still-here@example.com',
    ]);

    $claims = makeTestClaims([
        'id' => (string) Str::ulid(),
        'phone' => '+9647790000004',
        'email' => null,
    ]);

    RecordingResolverUser::findOrCreateFromHuwiya($claims);

    $columns = array_column(RecordingResolverUser::$calls, 'column');

    expect($columns)->toBe(['phone']);
});

it('does not dispatch when an update leaves the row\'s own phone/email unchanged', function () {
    RecordingResolverUser::$calls = [];

    $existingId = (string) Str::ulid();
    RecordingResolverUser::create([
        'huwiya_id' => $existingId,
        'name' => 'Self',
        'phone' => '+9647790000005',
        'email' => 'self@example.com',
    ]);

    // Same ULID → resolution finds the existing user, update path runs.
    $claims = makeTestClaims([
        'id' => $existingId,
        'name' => 'Self Renamed',
        'phone' => '+9647790000005',
        'email' => 'self@example.com',
    ]);

    RecordingResolverUser::findOrCreateFromHuwiya($claims);

    expect(RecordingResolverUser::$calls)->toBeEmpty();
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
