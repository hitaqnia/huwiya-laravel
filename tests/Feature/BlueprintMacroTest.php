<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

it('registers the huwiyaIdentifier blueprint macro', function () {
    expect(Blueprint::hasMacro('huwiyaIdentifier'))->toBeTrue();
});

it('creates a unique ulid column with the default name', function () {
    Schema::create('huwiya_macro_default', function (Blueprint $table) {
        $table->id();
        $table->huwiyaIdentifier();
    });

    expect(Schema::hasColumn('huwiya_macro_default', 'huwiya_id'))->toBeTrue();

    Schema::drop('huwiya_macro_default');
});

it('creates a unique ulid column with a custom name', function () {
    Schema::create('huwiya_macro_custom', function (Blueprint $table) {
        $table->id();
        $table->huwiyaIdentifier('sso_id');
    });

    expect(Schema::hasColumn('huwiya_macro_custom', 'sso_id'))->toBeTrue()
        ->and(Schema::hasColumn('huwiya_macro_custom', 'huwiya_id'))->toBeFalse();

    Schema::drop('huwiya_macro_custom');
});
