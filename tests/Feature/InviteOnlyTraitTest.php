<?php

use Huwiya\Exceptions\HuwiyaUserNotFoundException;
use Huwiya\InteractsWithHuwiyaAsInviteOnly;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Str;

it('invite-only trait disables auto-registration and enables invitations', function () {
    $model = new class extends Authenticatable {
        use InteractsWithHuwiyaAsInviteOnly;

        protected $table = 'users';

        protected $guarded = [];
    };

    expect($model->shouldAutoRegister())->toBeFalse()
        ->and($model->invitationsEnabled())->toBeTrue();
});

it('invite-only model rejects unknown phones with HuwiyaUserNotFoundException', function () {
    $modelClass = new class extends Authenticatable {
        use InteractsWithHuwiyaAsInviteOnly;

        protected $table = 'users';

        protected $guarded = [];
    };

    $fqcn = $modelClass::class;

    $claims = makeTestClaims([
        'id' => (string) Str::ulid(),
        'phone' => '+9647799999999',
    ]);

    expect(fn () => $fqcn::findOrCreateFromHuwiya($claims))
        ->toThrow(HuwiyaUserNotFoundException::class);
});

it('invite-only model claims an invitation row matching by phone', function () {
    $modelClass = new class extends Authenticatable {
        use InteractsWithHuwiyaAsInviteOnly;

        protected $table = 'users';

        protected $guarded = [];
    };

    $fqcn = $modelClass::class;

    $phone = '+9647788112233';
    $fqcn::create(['phone' => $phone, 'name' => 'Seeded']);

    $newId = (string) Str::ulid();
    $claims = makeTestClaims([
        'id' => $newId,
        'phone' => $phone,
        'name' => 'Claimed',
    ]);

    $user = $fqcn::findOrCreateFromHuwiya($claims);

    expect($user->huwiya_id)->toBe($newId)
        ->and($user->name)->toBe('Claimed')
        ->and($user->phone)->toBe($phone);
});
