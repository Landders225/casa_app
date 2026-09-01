<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CASA — table `campagne_filiere` (docs/mld.md §2, ADR-09). Table de liaison
 * portant le quota, pour permettre des quotas différents par campagne.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campagne_filiere', function (Blueprint $table) {
            $table->uuid('campagne_id');
            $table->uuid('filiere_id');
            $table->integer('quota');

            $table->primary(['campagne_id', 'filiere_id']);
            $table->foreign('campagne_id')->references('id')->on('campagne');
            $table->foreign('filiere_id')->references('id')->on('filiere');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campagne_filiere');
    }
};
