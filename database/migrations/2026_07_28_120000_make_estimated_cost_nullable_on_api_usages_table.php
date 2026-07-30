<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A failed OpenAI call (connection error, timeout, non-2xx) never returns a usage
     * payload, so there is no real token count to base a cost estimate on. Previously the
     * column was NOT NULL DEFAULT 0, which forced failed rows to lie with a 0 cost
     * indistinguishable from "we checked and it really cost nothing" — this makes null mean
     * what it says: no usage was billed/returned, not "no cost".
     */
    public function up(): void
    {
        if (!Schema::hasTable('api_usages')) {
            return;
        }

        DB::statement('ALTER TABLE api_usages MODIFY estimated_cost DECIMAL(10,6) NULL DEFAULT NULL');
    }

    public function down(): void
    {
        if (!Schema::hasTable('api_usages')) {
            return;
        }

        DB::statement('UPDATE api_usages SET estimated_cost = 0 WHERE estimated_cost IS NULL');
        DB::statement('ALTER TABLE api_usages MODIFY estimated_cost DECIMAL(10,6) NOT NULL DEFAULT 0');
    }
};
