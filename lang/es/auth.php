<?php

return [
    'failed' => 'Estas credenciales no coinciden con nuestros registros.',
    'password' => 'La contraseña proporcionada es incorrecta.',
    'throttle' => 'Demasiados intentos de inicio de sesión. Vuelve a intentarlo en :seconds segundos.',
    'username_unavailable' => 'No se pudo crear un nombre de usuario único. Prueba con otro nombre.',
    'session_ended' => 'Tu sesión ha finalizado porque los datos de acceso de esta cuenta han cambiado. Vuelve a iniciar sesión.',

    'prompts' => [
        'no_account' => '¿No tienes una cuenta?',
        'have_account' => '¿Ya tienes una cuenta?',
    ],

    'fields' => [
        'name' => 'Nombre',
        'username' => 'Nombre de usuario',
        'email' => 'Correo electrónico',
        'password' => 'Contraseña',
        'password_confirmation' => 'Confirmar contraseña',
    ],

    'login' => [
        'title' => 'Iniciar sesión',
        'action' => 'Iniciar sesión',
        'remember' => 'Recordarme',
        'forgot_password' => '¿Olvidaste tu contraseña?',
    ],

    'register' => [
        'title' => 'Registrarse',
        'action' => 'Registrarse',
    ],

    'divider' => 'o',

    'forgot_password' => [
        'intro' => '¿Olvidaste tu contraseña? No hay problema. Indícanos tu dirección de correo electrónico y te enviaremos un enlace para restablecerla y elegir una nueva.',
        'action' => 'Enviar enlace para restablecer la contraseña',
    ],

    'reset_password' => [
        'action' => 'Restablecer contraseña',
    ],

    'confirm_password' => [
        'intro' => 'Esta es una zona segura de la aplicación. Confirma tu contraseña antes de continuar.',
        'action' => 'Confirmar',
    ],

    'verify_email' => [
        'intro' => '¡Gracias por registrarte! Antes de empezar, ¿podrías verificar tu dirección de correo electrónico haciendo clic en el enlace que te acabamos de enviar? Si no has recibido el correo, con gusto te enviaremos otro.',
        'link_sent' => 'Se ha enviado un nuevo enlace de verificación a la dirección de correo electrónico que indicaste al registrarte.',
        'resend' => 'Reenviar correo de verificación',
    ],

    'social' => [
        'log_in_with' => 'Iniciar sesión con :provider',
        'unavailable' => 'El inicio de sesión con :provider no está disponible en este momento.',
        'unavailable_notice' => 'El inicio de sesión con :provider está desactivado. Si antes iniciabas sesión con :provider, establece una contraseña para tu cuenta — te enviaremos un enlace por correo electrónico.',
        'unavailable_set_password' => 'Establecer una contraseña',
        'cancelled' => 'Se canceló el inicio de sesión con :provider. Vuelve a intentarlo.',
        'failed' => 'No pudimos iniciar tu sesión con :provider. Vuelve a intentarlo.',
        'expired' => 'Tu inicio de sesión con :provider caducó antes de completarse. Vuelve a empezar.',
        'email_missing' => 'Tu cuenta de :provider no compartió ninguna dirección de correo electrónico, así que no se puede usar para iniciar sesión.',
        'email_taken' => 'La dirección de correo electrónico de esta cuenta de :provider pertenece a otra cuenta de RateGuru. Inicia sesión en esa cuenta para conectar :provider allí.',
        'link_expired' => 'La sesión para conectar tu cuenta caducó o cambió. Vuelve a iniciar la conexión de la cuenta.',
        'already_signed_in' => 'Ya has iniciado sesión. Para iniciar sesión también con :provider, conéctalo en «Cuentas conectadas».',
        'already_linked' => 'Esta cuenta de :provider ya está conectada a otra cuenta.',
        'provider_already_linked' => 'Tu cuenta ya está conectada a otra cuenta de :provider.',
        'pending_link' => 'Ya existe una cuenta con esta dirección de correo electrónico. Inicia sesión como lo hacías antes —con tu contraseña o con otra cuenta social— y tu cuenta de :provider se conectará automáticamente.',
        'password_removed' => 'Tu correo electrónico está confirmado a través de :provider. Se eliminó la contraseña que se había establecido para esta dirección de correo electrónico — puedes establecer una nueva en tu perfil.',
    ],
];
