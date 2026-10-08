<?php

return [
    'greeting' => 'Halló, :name!',
    'salutation' => 'Kveðja, :app',
    'fallback_greeting' => 'Halló!',
    'error_greeting' => 'Úbbs!',
    'rights_reserved' => 'Allur réttur áskilinn.',
    'action_fallback' => 'Ef þú átt í vandræðum með að smella á hnappinn „:action“ skaltu afrita vefslóðina hér fyrir neðan og líma hana í vafrann þinn:',

    'verify' => [
        'subject' => 'Staðfestu netfangið þitt',
        'line' => 'Staðfestu netfangið þitt til að ljúka uppsetningu aðgangsins.',
        'action' => 'Staðfesta netfang',
        'ignore' => 'Ef þú stofnaðir ekki aðgang þarftu ekki að gera neitt.',
    ],

    'reset' => [
        'subject' => 'Endurstilltu lykilorðið þitt',
        'line' => 'Þú færð þennan tölvupóst vegna þess að okkur barst beiðni um að endurstilla lykilorðið fyrir aðganginn þinn.',
        'action' => 'Endurstilla lykilorð',
        'expire' => 'Þessi hlekkur til að endurstilla lykilorð rennur út eftir :count mínútur.',
        'ignore' => 'Ef þú baðst ekki um að endurstilla lykilorðið þarftu ekki að gera neitt.',
    ],

    'contact' => [
        'subject' => 'Ný skilaboð af samskiptaeyðublaði: :subject',
        'heading' => 'Ný skilaboð af samskiptaeyðublaði',
        'name' => 'Nafn',
        'email' => 'Netfang',
        'message_subject' => 'Efni',
        'body' => 'Skilaboð',
    ],

    'social' => [
        'account' => 'Aðgangur hjá :provider: :email',
        'action' => 'Skoða tengda aðganga',
        'connected' => [
            'subject' => ':provider var tengt við aðganginn þinn',
            'line' => 'Aðgangur hjá :provider var tengdur við aðganginn þinn á RateGuru. Nú er hægt að nota hann til innskráningar.',
            'not_you' => 'Ef þetta varst ekki þú skaltu opna prófílinn þinn og aftengja hann strax.',
        ],
        'disconnected' => [
            'subject' => ':provider var aftengt frá aðganginum þínum',
            'line' => 'Aðgangur hjá :provider var aftengdur frá aðganginum þínum á RateGuru. Ekki er lengur hægt að nota hann til innskráningar.',
            'not_you' => 'Ef þetta varst ekki þú skaltu skrá þig inn og athuga tengda aðganga og lykilorðið þitt.',
        ],
    ],
];
