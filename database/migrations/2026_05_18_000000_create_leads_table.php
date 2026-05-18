<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection($this->connection())->create('leads', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('site', 32)->index();
            $table->string('form_key', 64)->index();
            $table->string('status', 16)->index();
            $table->unsignedInteger('lead_source_id')->nullable()->index();
            $table->unsignedInteger('prospect_queue_id')->nullable();
            $table->unsignedInteger('office_id')->nullable();

            $table->string('fname')->nullable()->index();
            $table->string('lname')->nullable();
            $table->string('email')->nullable()->index();
            $table->string('contact')->nullable()->index();
            $table->string('town')->nullable();
            $table->string('country_code', 8)->nullable();

            $table->json('payload');
            $table->json('attribution')->nullable();
            $table->json('cookies')->nullable();

            $table->timestamp('sent_to_duo_at')->nullable();
            $table->string('duo_response_id')->nullable()->index();
            $table->json('duo_response_body')->nullable();
            $table->unsignedSmallInteger('duo_http_status')->nullable();

            $table->string('fb_event_id', 64)->nullable()->unique();
            $table->timestamp('fb_synced_at')->nullable();
            $table->json('fb_response')->nullable();
            $table->boolean('fb_eligible')->default(false);

            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('last_error_at')->nullable();
            $table->text('last_error')->nullable();

            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 512)->nullable();
            $table->string('previous_url', 1024)->nullable();
            $table->string('session_id', 64)->nullable()->index();

            $table->timestamps();
            $table->index(['site', 'status', 'created_at']);
            $table->index(['site', 'form_key', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection())->dropIfExists('leads');
    }

    private function connection(): ?string
    {
        return config('leads.connection');
    }
};
