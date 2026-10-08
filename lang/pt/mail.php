<?php

return [
    'greeting' => 'Olá, :name!',
    'salutation' => 'Atenciosamente, :app',
    'fallback_greeting' => 'Olá!',
    'error_greeting' => 'Ops!',
    'rights_reserved' => 'Todos os direitos reservados.',
    'action_fallback' => 'Se você estiver com problemas para clicar no botão ":action", copie e cole a URL abaixo no seu navegador:',

    'verify' => [
        'subject' => 'Confirme seu endereço de e-mail',
        'line' => 'Confirme seu endereço de e-mail para concluir a configuração da sua conta.',
        'action' => 'Confirmar endereço de e-mail',
        'ignore' => 'Se você não criou uma conta, nenhuma ação adicional é necessária.',
    ],

    'reset' => [
        'subject' => 'Redefina sua senha',
        'line' => 'Você está recebendo este e-mail porque recebemos uma solicitação de redefinição de senha para sua conta.',
        'action' => 'Redefinir senha',
        'expire' => 'Este link de redefinição de senha expirará em :count minutos.',
        'ignore' => 'Se você não solicitou a redefinição de senha, nenhuma ação adicional é necessária.',
    ],

    'contact' => [
        'subject' => 'Nova mensagem de contato: :subject',
        'heading' => 'Nova mensagem de contato',
        'name' => 'Nome',
        'email' => 'E-mail',
        'message_subject' => 'Assunto',
        'body' => 'Mensagem',
    ],

    'social' => [
        'account' => 'Conta do :provider: :email',
        'action' => 'Revisar contas conectadas',
        'connected' => [
            'subject' => 'O :provider foi conectado à sua conta',
            'line' => 'Uma conta do :provider foi conectada à sua conta do RateGuru. Agora ela pode ser usada para entrar.',
            'not_you' => 'Se não foi você, abra seu perfil e desconecte-a imediatamente.',
        ],
        'disconnected' => [
            'subject' => 'O :provider foi desconectado da sua conta',
            'line' => 'Uma conta do :provider foi desconectada da sua conta do RateGuru. Ela não pode mais ser usada para entrar.',
            'not_you' => 'Se não foi você, entre e verifique suas contas conectadas e sua senha.',
        ],
    ],
];
