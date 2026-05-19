<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection($this->connection())->table('leads', function (Blueprint $table) {
            $table->dropIndex(['lead_source_id']);
            $table->dropColumn(['lead_source_id', 'prospect_queue_id']);
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection())->table('leads', function (Blueprint $table) {
            $table->unsignedInteger('lead_source_id')->nullable()->index();
            $table->unsignedInteger('prospect_queue_id')->nullable();
        });
    }

    private function connection(): ?string
    {
        return config('leads.connection');
    }
};
