<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('personal_access_tokens', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            // uuidMorphs, not Sanctum's morphs(): users.id is a uuid, and the stub's
            // unsigned bigint tokenable_id could never reference it. Sanctum must also be
            // pointed at a PersonalAccessToken model using HasUuids - that binding belongs
            // to the authentication plan, and this column shape is what makes it possible.
            $table->uuidMorphs('tokenable');

            $table->text('name');
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->timestampTz('last_used_at')->nullable();
            $table->timestampTz('expires_at')->nullable()->index();
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('personal_access_tokens');
    }
};
