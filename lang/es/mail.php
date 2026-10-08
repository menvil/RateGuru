<?php

return [
    'greeting' => '¡Hola, :name!',
    'salutation' => 'Saludos, :app',
    'fallback_greeting' => '¡Hola!',
    'error_greeting' => '¡Vaya!',
    'rights_reserved' => 'Todos los derechos reservados.',
    'action_fallback' => 'Si tienes problemas para hacer clic en el botón «:action», copia y pega la siguiente URL en tu navegador web:',

    'verify' => [
        'subject' => 'Confirma tu dirección de correo electrónico',
        'line' => 'Confirma tu dirección de correo electrónico para terminar de configurar tu cuenta.',
        'action' => 'Confirmar dirección de correo electrónico',
        'ignore' => 'Si no creaste una cuenta, no tienes que hacer nada más.',
    ],

    'reset' => [
        'subject' => 'Restablece tu contraseña',
        'line' => 'Recibes este correo porque hemos recibido una solicitud para restablecer la contraseña de tu cuenta.',
        'action' => 'Restablecer contraseña',
        'expire' => 'Este enlace para restablecer la contraseña caducará en :count minutos.',
        'ignore' => 'Si no solicitaste restablecer la contraseña, no tienes que hacer nada más.',
    ],

    'contact' => [
        'subject' => 'Nuevo mensaje de contacto: :subject',
        'heading' => 'Nuevo mensaje de contacto',
        'name' => 'Nombre',
        'email' => 'Correo electrónico',
        'message_subject' => 'Asunto',
        'body' => 'Mensaje',
    ],

    'social' => [
        'account' => 'Cuenta de :provider: :email',
        'action' => 'Revisar las cuentas conectadas',
        'connected' => [
            'subject' => ':provider se conectó a tu cuenta',
            'line' => 'Se conectó una cuenta de :provider a tu cuenta de RateGuru. Ahora se puede usar para iniciar sesión.',
            'not_you' => 'Si no fuiste tú, abre tu perfil y desconéctala de inmediato.',
        ],
        'disconnected' => [
            'subject' => ':provider se desconectó de tu cuenta',
            'line' => 'Se desconectó una cuenta de :provider de tu cuenta de RateGuru. Ya no se puede usar para iniciar sesión.',
            'not_you' => 'Si no fuiste tú, inicia sesión y revisa tus cuentas conectadas y tu contraseña.',
        ],
    ],
];
