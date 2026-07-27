<?php

use mojosef\Leads\Models\Lead;

it('builds a tracking payload with a normalised phone and event id', function () {
    $lead = new Lead;
    $lead->email = 'jane@example.com';
    $lead->contact = '07123 456789';
    $lead->fb_eligible = true;
    $lead->event_id = '01HZY0000000000000000000';

    expect($lead->trackingPayload())->toBe([
        'fb_lead' => true,
        'fb_event_id' => '01HZY0000000000000000000',
        'lead_email' => 'jane@example.com',
        'lead_phone' => '+447123456789',
    ]);
});

it('reflects a lead that is not facebook eligible and has no phone', function () {
    $lead = new Lead;
    $lead->email = 'jane@example.com';

    expect($lead->trackingPayload())->toBe([
        'fb_lead' => false,
        'fb_event_id' => null,
        'lead_email' => 'jane@example.com',
        'lead_phone' => null,
    ]);
});
