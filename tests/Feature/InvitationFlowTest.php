<?php

use Huwiya\Events\HuwiyaInvitationClaimed;
use Huwiya\Events\HuwiyaInvitationClaiming;
use Huwiya\Events\HuwiyaUserCreated;
use Huwiya\Events\HuwiyaUserUpdated;
use Huwiya\Exceptions\HuwiyaUserNotFoundException;
use Huwiya\Tests\Fixtures\InvitableAutoRegUser;
use Huwiya\Tests\Fixtures\InvitableUser;
use Huwiya\Tests\Fixtures\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;

it('claims an invitation row matching by phone', function () {
    $phone = '+9647712345678';

    $invitation = InvitableUser::create([
        'phone' => $phone,
        'name' => 'Seeded Name',
    ]);

    expect($invitation->huwiya_id)->toBeNull();

    Event::fake([HuwiyaInvitationClaiming::class, HuwiyaInvitationClaimed::class, HuwiyaUserCreated::class, HuwiyaUserUpdated::class]);

    $newId = (string) Str::ulid();
    $claims = makeTestClaims([
        'id' => $newId,
        'phone' => $phone,
        'email' => 'claimed@example.com',
        'name' => 'Claimed Name',
    ]);

    $user = InvitableUser::findOrCreateFromHuwiya($claims);

    expect($user->id)->toBe($invitation->id)
        ->and($user->huwiya_id)->toBe($newId)
        ->and($user->name)->toBe('Claimed Name')
        ->and($user->email)->toBe('claimed@example.com')
        ->and($user->phone)->toBe($phone);

    Event::assertDispatched(HuwiyaInvitationClaiming::class);
    Event::assertDispatched(HuwiyaInvitationClaimed::class);
    Event::assertDispatched(HuwiyaUserUpdated::class);
    Event::assertNotDispatched(HuwiyaUserCreated::class);
});

it('throws when invitations are disabled and no huwiya_id matches', function () {
    User::create([
        'phone' => '+9647700000001',
        'name' => 'Prospect',
    ]);

    // Default User fixture has invitations off and auto-reg on — so a
    // missing huwiya_id match would normally create. Force auto-reg off
    // via a subclass for this test.
    $model = new class extends User {
        protected $table = 'users';
        public function shouldAutoRegister(?\Huwiya\TokenClaims $claims = null): bool { return false; }
    };

    $claims = makeTestClaims([
        'id' => (string) Str::ulid(),
        'phone' => '+9647700000001',
    ]);

    expect(fn () => $model::findOrCreateFromHuwiya($claims))
        ->toThrow(HuwiyaUserNotFoundException::class);
});

it('throws when invitations are on but no phone matches and auto-reg is off', function () {
    InvitableUser::create([
        'phone' => '+9647711111111',
    ]);

    $claims = makeTestClaims([
        'id' => (string) Str::ulid(),
        'phone' => '+9647799999999',
    ]);

    expect(fn () => InvitableUser::findOrCreateFromHuwiya($claims))
        ->toThrow(HuwiyaUserNotFoundException::class);
});

it('creates a fresh user when invitations + auto-reg are on but no phone matches', function () {
    $newId = (string) Str::ulid();
    $claims = makeTestClaims([
        'id' => $newId,
        'phone' => '+9647722222222',
    ]);

    $user = InvitableAutoRegUser::findOrCreateFromHuwiya($claims);

    expect($user->exists)->toBeTrue()
        ->and($user->huwiya_id)->toBe($newId)
        ->and($user->phone)->toBe('+9647722222222');
});

it('matches by huwiya_id even when an unrelated invitation is in the table', function () {
    $realId = (string) Str::ulid();

    $real = InvitableUser::create([
        'huwiya_id' => $realId,
        'phone' => '+9647733333301',
        'name' => 'Real User',
    ]);

    $invitation = InvitableUser::create([
        'phone' => '+9647733333302',
        'name' => 'Unclaimed Invite',
    ]);

    $claims = makeTestClaims([
        'id' => $realId,
        'phone' => '+9647733333301',
        'name' => 'Real User Updated',
    ]);

    $resolved = InvitableUser::findOrCreateFromHuwiya($claims);

    expect($resolved->id)->toBe($real->id)
        ->and($resolved->huwiya_id)->toBe($realId)
        ->and($invitation->fresh()->huwiya_id)->toBeNull();
});

it('does not fire invitation events on a normal login for an existing huwiya_id', function () {
    $user = InvitableUser::create([
        'huwiya_id' => (string) Str::ulid(),
        'phone' => '+9647744444444',
        'name' => 'Existing',
    ]);

    Event::fake([HuwiyaInvitationClaiming::class, HuwiyaInvitationClaimed::class]);

    $claims = makeTestClaims([
        'id' => $user->huwiya_id,
        'phone' => $user->phone,
        'name' => 'Existing Updated',
    ]);

    InvitableUser::findOrCreateFromHuwiya($claims);

    Event::assertNotDispatched(HuwiyaInvitationClaiming::class);
    Event::assertNotDispatched(HuwiyaInvitationClaimed::class);
});

it('resolves with a single select query', function () {
    $user = InvitableUser::create([
        'huwiya_id' => (string) Str::ulid(),
        'phone' => '+9647755555555',
    ]);

    $claims = makeTestClaims([
        'id' => $user->huwiya_id,
        'phone' => $user->phone,
    ]);

    DB::enableQueryLog();
    DB::flushQueryLog();

    InvitableUser::resolveHuwiyaUser($claims);

    $selects = array_values(array_filter(
        DB::getQueryLog(),
        fn ($q) => str_starts_with(strtolower(ltrim($q['query'])), 'select') &&
            str_contains($q['query'], '"users"'),
    ));

    expect($selects)->toHaveCount(1);
});

it('emits a plain huwiya_id query when invitations are disabled', function () {
    // Force any lazy migrations to complete before we start logging.
    User::count();

    DB::enableQueryLog();
    DB::flushQueryLog();

    $claims = makeTestClaims(['id' => (string) Str::ulid()]);

    User::resolveHuwiyaUser($claims);

    $select = collect(DB::getQueryLog())
        ->first(fn ($q) => str_starts_with(strtolower(ltrim($q['query'])), 'select')
            && str_contains($q['query'], '"users"'));

    expect($select)->not->toBeNull();
    expect($select['query'])->toContain('"huwiya_id" = ?')
        ->and(strtolower($select['query']))->not->toContain('is null');
});

it('emits an OR clause and IS NULL ordering when invitations are enabled', function () {
    InvitableUser::count();

    DB::enableQueryLog();
    DB::flushQueryLog();

    $claims = makeTestClaims([
        'id' => (string) Str::ulid(),
        'phone' => '+9647766666666',
    ]);

    try {
        InvitableUser::findOrCreateFromHuwiya($claims);
    } catch (HuwiyaUserNotFoundException) {
        // expected — no match, no invitation
    }

    $select = collect(DB::getQueryLog())
        ->first(fn ($q) => str_starts_with(strtolower(ltrim($q['query'])), 'select')
            && str_contains($q['query'], '"users"'));

    expect($select)->not->toBeNull();
    expect(strtolower($select['query']))->toContain(' or ')
        ->and(strtolower($select['query']))->toContain('is null');
});

it('honors a renamed phone column across schema and runtime', function () {
    $model = new class extends InvitableUser {
        protected $table = 'users_renamed';
        protected array $huwiyaFieldsMap = [
            'phone' => 'phone_number',
            'name' => 'name',
        ];
    };

    \Illuminate\Support\Facades\Schema::create('users_renamed', function ($table) use ($model) {
        $table->id();
        $table->huwiyaFields($model::huwiyaFieldsSchema());
        $table->timestamps();
    });

    $phone = '+9647777777777';

    $model::create(['phone_number' => $phone, 'name' => 'Invited']);

    $newId = (string) Str::ulid();
    $claims = makeTestClaims([
        'id' => $newId,
        'phone' => $phone,
        'name' => 'Claimed',
    ]);

    $user = $model::findOrCreateFromHuwiya($claims);

    expect($user->huwiya_id)->toBe($newId)
        ->and($user->getAttribute('phone_number'))->toBe($phone)
        ->and($user->name)->toBe('Claimed');

    \Illuminate\Support\Facades\Schema::drop('users_renamed');
});

it('does not write omitted fields even when the column exists', function () {
    $model = new class extends User {
        protected $table = 'users';
        protected array $huwiyaFieldsMap = [
            'phone' => 'phone',
            'name' => 'name',
            // email, locale, zoneinfo, theme omitted
        ];
    };

    $newId = (string) Str::ulid();
    $claims = makeTestClaims([
        'id' => $newId,
        'phone' => '+9647788888888',
        'email' => 'should-not-persist@example.com',
        'locale' => 'ar',
    ]);

    $user = $model::findOrCreateFromHuwiya($claims);

    expect($user->email)->toBeNull()
        ->and($user->locale)->toBeNull()
        ->and($user->phone)->toBe('+9647788888888');
});
