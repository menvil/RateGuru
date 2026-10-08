<?php

return [
    'failed' => 'Þessar innskráningarupplýsingar passa ekki við skrár okkar.',
    'password' => 'Lykilorðið sem var slegið inn er rangt.',
    'throttle' => 'Of margar innskráningartilraunir. Reyndu aftur eftir :seconds sekúndur.',
    'username_unavailable' => 'Ekki tókst að búa til einkvæmt notandanafn. Prófaðu annað nafn.',
    'session_ended' => 'Lotunni þinni lauk vegna þess að innskráningarupplýsingum þessa aðgangs var breytt. Skráðu þig inn aftur.',

    'prompts' => [
        'no_account' => 'Ertu ekki með aðgang?',
        'have_account' => 'Ertu nú þegar með aðgang?',
    ],

    'fields' => [
        'name' => 'Nafn',
        'username' => 'Notandanafn',
        'email' => 'Netfang',
        'password' => 'Lykilorð',
        'password_confirmation' => 'Staðfestu lykilorð',
    ],

    'login' => [
        'title' => 'Innskráning',
        'action' => 'Skrá inn',
        'remember' => 'Muna eftir mér',
        'forgot_password' => 'Gleymdirðu lykilorðinu?',
    ],

    'register' => [
        'title' => 'Nýskráning',
        'action' => 'Nýskrá',
    ],

    'divider' => 'eða',

    'forgot_password' => [
        'intro' => 'Gleymdirðu lykilorðinu? Ekkert mál. Gefðu okkur upp netfangið þitt og við sendum þér tölvupóst með hlekk til að endurstilla lykilorðið svo þú getir valið nýtt.',
        'action' => 'Senda hlekk til að endurstilla lykilorð',
    ],

    'reset_password' => [
        'action' => 'Endurstilla lykilorð',
    ],

    'confirm_password' => [
        'intro' => 'Þetta er öruggt svæði forritsins. Staðfestu lykilorðið þitt áður en þú heldur áfram.',
        'action' => 'Staðfesta',
    ],

    'verify_email' => [
        'intro' => 'Takk fyrir að skrá þig! Áður en þú byrjar, gætirðu staðfest netfangið þitt með því að smella á hlekkinn sem við vorum að senda þér í tölvupósti? Ef þú fékkst ekki tölvupóstinn sendum við þér gjarnan annan.',
        'link_sent' => 'Nýr staðfestingarhlekkur hefur verið sendur á netfangið sem þú gafst upp við skráningu.',
        'resend' => 'Senda staðfestingarpóst aftur',
    ],

    'social' => [
        'log_in_with' => 'Skrá inn með :provider',
        'unavailable' => 'Innskráning með :provider er ekki í boði eins og er.',
        'unavailable_notice' => 'Slökkt er á innskráningu með :provider. Ef þú skráðir þig áður inn með :provider skaltu setja lykilorð fyrir aðganginn þinn — við sendum þér hlekk í tölvupósti.',
        'unavailable_set_password' => 'Setja lykilorð',
        'cancelled' => 'Hætt var við innskráningu með :provider. Reyndu aftur.',
        'failed' => 'Ekki tókst að skrá þig inn með :provider. Reyndu aftur.',
        'expired' => 'Innskráningin með :provider rann út áður en henni lauk. Byrjaðu aftur.',
        'email_missing' => 'Aðgangurinn þinn hjá :provider deildi ekki netfangi og því er ekki hægt að nota hann til innskráningar.',
        'email_taken' => 'Netfang þessa aðgangs hjá :provider tilheyrir öðrum aðgangi á RateGuru. Skráðu þig inn á þann aðgang til að tengja :provider þar.',
        'link_expired' => 'Lotan til að tengja aðganginn rann út eða breyttist. Byrjaðu aftur að tengja aðganginn.',
        'already_signed_in' => 'Þú ert þegar með virka innskráningu. Til að geta líka skráð þig inn með :provider skaltu tengja það undir „Tengdir aðgangar“.',
        'already_linked' => 'Þessi aðgangur hjá :provider er nú þegar tengdur öðrum aðgangi.',
        'provider_already_linked' => 'Aðgangurinn þinn er nú þegar tengdur öðrum aðgangi hjá :provider.',
        'pending_link' => 'Það er nú þegar til aðgangur með þessu netfangi. Skráðu þig inn eins og áður — með lykilorðinu þínu eða öðrum samfélagsmiðlaaðgangi — og aðgangurinn þinn hjá :provider verður tengdur sjálfkrafa.',
        'password_removed' => 'Netfangið þitt er staðfest í gegnum :provider. Lykilorð sem hafði verið sett fyrir þetta netfang var fjarlægt — þú getur sett nýtt á prófílnum þínum.',
    ],
];
