<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * CASA — table `option_item` (docs/mld.md §6). Une ligne par réponse possible
 * d'un item de type `choix`, avec ses points (ex. SC.04=CEPE -> points=0,
 * eliminatoire=true).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('option_item', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->uuid('item_id');
            $table->string('valeur', 30);
            $table->string('label', 150);
            $table->decimal('points', 4, 2)->default(0);
            $table->boolean('eliminatoire')->default(false);

            $table->unique(['item_id', 'valeur']);
            $table->foreign('item_id')->references('id')->on('item');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('option_item');
    }
};
