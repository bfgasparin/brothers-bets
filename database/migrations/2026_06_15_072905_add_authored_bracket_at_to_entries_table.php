<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('entries', function (Blueprint $table) {
            // Set when an admin backfills this entry's knockout bracket "as authored" from a JSON blob
            // whose group classification didn't follow FIFA rules. Marks the bracket as NOT to be
            // re-derived (the prediction page is blocked for these entries) and records when it happened.
            $table->timestamp('authored_bracket_at')->nullable()->after('previous_rank');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('entries', function (Blueprint $table) {
            $table->dropColumn('authored_bracket_at');
        });
    }
};
