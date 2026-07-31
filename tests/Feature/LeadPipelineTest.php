<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use mojosef\Leads\LeadPipeline;
use mojosef\Leads\Models\Lead;

beforeEach(function () {
    Schema::connection('leads_testing')->create('leads', function (Blueprint $table) {
        $table->string('id')->primary();
        $table->string('site');
        $table->string('form_key');
        $table->string('status');
        $table->unsignedInteger('office_id')->nullable();
        $table->string('fname')->nullable();
        $table->string('lname')->nullable();
        $table->string('email')->nullable();
        $table->string('contact')->nullable();
        $table->string('town')->nullable();
        $table->json('payload')->nullable();
        $table->json('attribution')->nullable();
        $table->json('cookies')->nullable();
        $table->string('event_id')->nullable();
        $table->boolean('fb_eligible')->default(false);
        $table->string('ip_address')->nullable();
        $table->text('user_agent')->nullable();
        $table->text('previous_url')->nullable();
        $table->string('country_code')->nullable();
        $table->string('session_id')->nullable();
        $table->timestamps();
    });
});

it('does not insert a row on start()', function () {
    $lead = app(LeadPipeline::class)->start('paid_search');

    expect($lead->exists)->toBeFalse()
        ->and(Lead::query()->count())->toBe(0)
        ->and($lead->id)->toHaveLength(26)
        ->and($lead->event_id)->toHaveLength(26)
        ->and($lead->status)->toBe(Lead::STATUS_PENDING)
        ->and($lead->site)->toBe('test-site')
        ->and($lead->form_key)->toBe('paid_search');
});

it('creates exactly one pending row on complete()', function () {
    $pipeline = app(LeadPipeline::class);

    $lead = $pipeline->complete($pipeline->start('paid_search'), [
        'name' => 'Alex',
        'lname' => 'Smith',
        'email' => 'alex@example.com',
        'phone' => '+44 7700 900123',
        'town' => 'Harrogate',
        'occupation' => 'Engineer',
    ]);

    expect(Lead::query()->count())->toBe(1);

    $saved = Lead::query()->findOrFail($lead->id);

    expect($saved->status)->toBe(Lead::STATUS_PENDING)
        ->and($saved->fname)->toBe('Alex')
        ->and($saved->lname)->toBe('Smith')
        ->and($saved->email)->toBe('alex@example.com')
        ->and($saved->contact)->toBe('+44 7700 900123')
        ->and($saved->town)->toBe('Harrogate')
        ->and($saved->payload['occupation'])->toBe('Engineer')
        ->and($saved->payload['name'])->toBe('Alex')
        ->and($saved->attribution)->toBeArray()
        ->and($saved->cookies)->toBeArray()
        ->and($saved->event_id)->toBe($lead->event_id);
});

it('persists the attribution snapshot from the session', function () {
    session(['attribution_data' => ['utm_source' => 'google', 'gclid' => 'x123']]);

    $pipeline = app(LeadPipeline::class);
    $lead = $pipeline->complete($pipeline->start('paid_search'), ['email' => 'alex@example.com']);

    $saved = Lead::query()->findOrFail($lead->id);

    expect($saved->attribution)->toBe(['utm_source' => 'google', 'gclid' => 'x123'])
        ->and($saved->cookies['gclid'])->toBe('x123');
});

it('marks leads with an fbclid as facebook eligible', function () {
    $this->app->instance('request', Request::create('/form?fbclid=abc123'));

    $pipeline = app(LeadPipeline::class);
    $lead = $pipeline->complete($pipeline->start('paid_search'), ['email' => 'alex@example.com']);

    $saved = Lead::query()->findOrFail($lead->id);

    expect($saved->fb_eligible)->toBeTrue()
        ->and($saved->cookies['fbclid'])->toBe('abc123')
        ->and($saved->cookies['_fbc'])->toStartWith('fb.1.');
});

it('marks leads without facebook attribution as not eligible', function () {
    $pipeline = app(LeadPipeline::class);
    $lead = $pipeline->complete($pipeline->start('paid_search'), ['email' => 'alex@example.com']);

    expect(Lead::query()->findOrFail($lead->id)->fb_eligible)->toBeFalse();
});

it('ignores a second complete() call on the same lead', function () {
    Log::spy();

    $pipeline = app(LeadPipeline::class);
    $lead = $pipeline->complete($pipeline->start('paid_search'), ['email' => 'alex@example.com']);

    $again = $pipeline->complete($lead, ['email' => 'other@example.com']);

    expect(Lead::query()->count())->toBe(1)
        ->and($again->email)->toBe('alex@example.com');

    Log::shouldHaveReceived('warning')->once();
});

it('writes nothing for an abandoned start()', function () {
    $pipeline = app(LeadPipeline::class);

    $pipeline->start('paid_search');
    $pipeline->start('paid_search');
    $pipeline->complete($pipeline->start('paid_search'), ['email' => 'alex@example.com']);

    expect(Lead::query()->count())->toBe(1);
});

it('resolves office_id from override, then form config, then defaults', function () {
    config(['leads.defaults.office_id' => 21, 'leads.forms.paid_search.office_id' => 17]);

    $pipeline = app(LeadPipeline::class);

    expect($pipeline->start('paid_search', ['office_id' => 99])->office_id)->toBe(99)
        ->and($pipeline->start('paid_search')->office_id)->toBe(17)
        ->and($pipeline->start('other_form')->office_id)->toBe(21);
});
