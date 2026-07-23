<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection($this->connection())->table('leads', function (Blueprint $table) {
            $table->renameColumn('fb_event_id', 'event_id');
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection())->table('leads', function (Blueprint $table) {
            $table->renameColumn('event_id', 'fb_event_id');
        });
    }

    private function connection(): ?string
    {
        return config('leads.connection');
    }
};
