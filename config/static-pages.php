<?php

// Seeded into a new project once, before it has a name of its own, so the text
// names no site: the page shows the project's name above it, and the project
// edits the text from then on.
return [
    'defaults' => [
        'about' => [
            'en' => [
                'title' => 'About us',
                'content' => 'This is a community for publishing posts, comparing opinions, and discovering how other people rate the things that interest them. This placeholder can be replaced by an administrator.',
            ],
        ],
        'privacy' => [
            'en' => [
                'title' => 'Privacy Policy',
                'content' => 'The privacy policy is being prepared. An administrator can replace this temporary text with the approved policy for every supported language.',
            ],
        ],
        'terms' => [
            'en' => [
                'title' => 'Terms of Use',
                'content' => 'The terms of use are being prepared. An administrator can replace this temporary text with the approved terms for every supported language.',
            ],
        ],
        'contact' => [
            'en' => [
                'title' => 'Contact',
                'content' => 'Use the form below to contact the site administration.',
            ],
        ],
    ],
];
