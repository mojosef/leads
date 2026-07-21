<?php

/*
|--------------------------------------------------------------------------
| Contact form — default visible wording
|--------------------------------------------------------------------------
|
| Keys are the canonical Question and answer enum values. Consuming sites
| override wording (fully or partially) in:
|
|   lang/vendor/contact-form/en/form.php
|
| Overrides change what visitors see only — the submitted values remain the
| canonical enum values regardless of wording.
|
*/

return [

    'age_bracket' => [
        'question' => 'How old are you?',
        'answers' => [
            'age_under_30' => 'Under 30',
            'age_30_39' => '30–39',
            'age_40_49' => '40–49',
            'age_50_59' => '50–59',
            'age_60_plus' => '60+',
        ],
    ],

    'town' => [
        'question' => 'Which town or city do you live in?',
    ],

    'occupation' => [
        'question' => 'What is your occupation?',
    ],

    'marital_status' => [
        'question' => 'What is your marital status?',
        'answers' => [
            'never_married' => 'Never married',
            'divorced' => 'Divorced',
            'separated' => 'Separated',
            'widowed' => 'Widowed',
        ],
    ],

    'search_goal' => [
        'question' => 'What are you hoping to find?',
        'answers' => [
            'marriage' => 'Marriage',
            'life_partner' => 'A life partner',
            'long_term_relationship' => 'A long-term relationship',
            'exploring' => 'I’m exploring my options',
        ],
    ],

    'dating_challenges' => [
        'question' => 'What have you found most challenging about dating?',
        'answers' => [
            'low_quality_app_matches' => 'Low-quality matches on dating apps',
            'limited_time' => 'Limited time to meet people',
            'poor_match_quality' => 'Poor match quality',
            'seeking_personalised_service' => 'I’m looking for a personalised service',
            'recently_divorced_or_separated' => 'I’m recently divorced or separated',
        ],
    ],

    'meet_timeline' => [
        'question' => 'How soon would you like to meet someone?',
        'answers' => [
            'as_soon_as_possible' => 'As soon as possible',
            'within_6_months' => 'Within 6 months',
            'within_12_months' => 'Within 12 months',
            'no_specific_timeline' => 'No specific timeline',
        ],
    ],

    'investment_range' => [
        'question' => 'How much are you prepared to invest in finding the right partner?',
        'answers' => [
            'gbp_under_4000' => 'Under £4,000',
            'gbp_4000_7999' => '£4,000–£7,999',
            'gbp_8000_11999' => '£8,000–£11,999',
            'gbp_12000_16000' => '£12,000–£16,000',
            'discuss_options_first' => 'I’d prefer to discuss the options first',
            'out_of_range' => 'This is outside my current budget',
        ],
    ],

    'support_level' => [
        'question' => 'What level of support are you looking for?',
        'answers' => [
            'personalised_guidance' => 'Personalised guidance',
            'dedicated_proactive_search' => 'A dedicated, proactive search',
            'highest_support' => 'The highest level of support',
            'unsure' => 'I’m not sure yet',
        ],
    ],

    'first_name' => [
        'question' => 'What is your first name?',
    ],

    'email' => [
        'question' => 'What is your email address?',
    ],

    'phone_number' => [
        'question' => 'What is your phone number?',
    ],

];
