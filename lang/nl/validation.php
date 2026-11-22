<?php

/*
|--------------------------------------------------------------------------
| Validation messages (Dutch)
|--------------------------------------------------------------------------
|
| Covers the rules used in the app so far. A rule that is missing here falls
| back to the English message from the framework (APP_FALLBACK_LOCALE).
|
*/

return [

    'accepted' => ':Attribute moet geaccepteerd worden.',
    'array' => ':Attribute moet een lijst zijn.',
    'boolean' => ':Attribute moet waar of onwaar zijn.',
    'confirmed' => 'De bevestiging van :attribute komt niet overeen.',
    'date' => ':Attribute moet een geldige datum zijn.',
    'distinct' => ':Attribute komt meer dan één keer voor.',
    'email' => ':Attribute moet een geldig e-mailadres zijn.',
    'exists' => 'De gekozen :attribute bestaat niet.',
    'in' => 'De gekozen :attribute is ongeldig.',
    'integer' => ':Attribute moet een geheel getal zijn.',
    'lowercase' => ':Attribute mag alleen kleine letters bevatten.',
    'max' => [
        'array' => ':Attribute mag niet meer dan :max items bevatten.',
        'file' => ':Attribute mag niet groter zijn dan :max kilobytes.',
        'numeric' => ':Attribute mag niet groter zijn dan :max.',
        'string' => ':Attribute mag niet meer dan :max tekens bevatten.',
    ],
    'min' => [
        'array' => ':Attribute moet minimaal :min items bevatten.',
        'file' => ':Attribute moet minimaal :min kilobytes zijn.',
        'numeric' => ':Attribute moet minimaal :min zijn.',
        'string' => ':Attribute moet minimaal :min tekens bevatten.',
    ],
    'numeric' => ':Attribute moet een getal zijn.',
    'password' => [
        'letters' => ':Attribute moet minimaal één letter bevatten.',
        'mixed' => ':Attribute moet minimaal één hoofdletter en één kleine letter bevatten.',
        'numbers' => ':Attribute moet minimaal één cijfer bevatten.',
        'symbols' => ':Attribute moet minimaal één speciaal teken bevatten.',
        'uncompromised' => 'Dit :attribute komt voor in een datalek. Kies een ander :attribute.',
    ],
    'regex' => 'Het formaat van :attribute is ongeldig.',
    'required' => ':Attribute is verplicht.',
    'string' => ':Attribute moet tekst zijn.',
    'unique' => 'Dit :attribute is al in gebruik.',

    'custom' => [
        'email' => [
            'unique' => 'Er bestaat al een account met dit e-mailadres. Wil je inloggen of je wachtwoord resetten?',
        ],
    ],

    'attributes' => [
        'name' => 'naam',
        'email' => 'e-mailadres',
        'phone' => 'telefoonnummer',
        'password' => 'wachtwoord',
        'password_confirmation' => 'wachtwoordbevestiging',
        'token' => 'resetcode',
    ],

];
