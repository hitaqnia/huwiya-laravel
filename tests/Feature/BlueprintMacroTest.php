<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

it('registers the huwiyaIdentifier and huwiyaFields macros', function () {
    expect(Blueprint::hasMacro('huwiyaIdentifier'))->toBeTrue()
        ->and(Blueprint::hasMacro('huwiyaFields'))->toBeTrue();
});

it('creates a nullable unique ulid column with the default name', function () {
    Schema::create('huwiya_macro_default', function (Blueprint $table) {
        $table->id();
        $table->huwiyaIdentifier();
    });

    expect(Schema::hasColumn('huwiya_macro_default', 'huwiya_id'))->toBeTrue();

    DB::table('huwiya_macro_default')->insert(['huwiya_id' => null]);
    DB::table('huwiya_macro_default')->insert(['huwiya_id' => null]);

    expect(DB::table('huwiya_macro_default')->count())->toBe(2);

    Schema::drop('huwiya_macro_default');
});

it('creates a nullable unique ulid column with a custom name', function () {
    Schema::create('huwiya_macro_custom', function (Blueprint $table) {
        $table->id();
        $table->huwiyaIdentifier('sso_id');
    });

    expect(Schema::hasColumn('huwiya_macro_custom', 'sso_id'))->toBeTrue()
        ->and(Schema::hasColumn('huwiya_macro_custom', 'huwiya_id'))->toBeFalse();

    Schema::drop('huwiya_macro_custom');
});

it('creates exactly the columns declared in the map', function () {
    Schema::create('huwiya_macro_fields', function (Blueprint $table) {
        $table->id();
        $table->huwiyaFields([
            'huwiya_id' => 'huwiya_id',
            'phone' => 'phone_number',
            'email' => 'email',
        ]);
    });

    expect(Schema::hasColumn('huwiya_macro_fields', 'huwiya_id'))->toBeTrue()
        ->and(Schema::hasColumn('huwiya_macro_fields', 'phone_number'))->toBeTrue()
        ->and(Schema::hasColumn('huwiya_macro_fields', 'email'))->toBeTrue()
        ->and(Schema::hasColumn('huwiya_macro_fields', 'phone'))->toBeFalse()
        ->and(Schema::hasColumn('huwiya_macro_fields', 'name'))->toBeFalse()
        ->and(Schema::hasColumn('huwiya_macro_fields', 'locale'))->toBeFalse();

    Schema::drop('huwiya_macro_fields');
});

it('skips disabled fields with false value in the map', function () {
    Schema::create('huwiya_macro_disabled', function (Blueprint $table) {
        $table->id();
        $table->huwiyaFields([
            'huwiya_id' => 'huwiya_id',
            'phone' => 'phone',
            'email' => false,
            'name' => null,
            'locale' => '',
        ]);
    });

    expect(Schema::hasColumn('huwiya_macro_disabled', 'huwiya_id'))->toBeTrue()
        ->and(Schema::hasColumn('huwiya_macro_disabled', 'phone'))->toBeTrue()
        ->and(Schema::hasColumn('huwiya_macro_disabled', 'email'))->toBeFalse()
        ->and(Schema::hasColumn('huwiya_macro_disabled', 'name'))->toBeFalse()
        ->and(Schema::hasColumn('huwiya_macro_disabled', 'locale'))->toBeFalse();

    Schema::drop('huwiya_macro_disabled');
});
