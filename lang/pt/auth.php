<?php

return [
    'failed' => 'Essas credenciais não correspondem aos nossos registros.',
    'password' => 'A senha informada está incorreta.',
    'throttle' => 'Muitas tentativas de login. Tente novamente em :seconds segundos.',
    'username_unavailable' => 'Não foi possível criar um nome de usuário exclusivo. Tente um nome diferente.',
    'session_ended' => 'Sua sessão foi encerrada porque os dados de acesso desta conta foram alterados. Entre novamente.',

    'prompts' => [
        'no_account' => 'Não tem uma conta?',
        'have_account' => 'Já tem uma conta?',
    ],

    'fields' => [
        'name' => 'Nome',
        'username' => 'Nome de usuário',
        'email' => 'E-mail',
        'password' => 'Senha',
        'password_confirmation' => 'Confirmar senha',
    ],

    'login' => [
        'title' => 'Entrar',
        'action' => 'Entrar',
        'remember' => 'Lembrar de mim',
        'forgot_password' => 'Esqueceu sua senha?',
    ],

    'register' => [
        'title' => 'Cadastrar-se',
        'action' => 'Cadastrar-se',
    ],

    'divider' => 'ou',

    'forgot_password' => [
        'intro' => 'Esqueceu sua senha? Sem problemas. Informe seu endereço de e-mail e enviaremos um link de redefinição de senha para você escolher uma nova.',
        'action' => 'Enviar link de redefinição de senha',
    ],

    'reset_password' => [
        'action' => 'Redefinir senha',
    ],

    'confirm_password' => [
        'intro' => 'Esta é uma área segura do aplicativo. Confirme sua senha antes de continuar.',
        'action' => 'Confirmar',
    ],

    'verify_email' => [
        'intro' => 'Obrigado por se cadastrar! Antes de começar, você poderia confirmar seu endereço de e-mail clicando no link que acabamos de enviar? Se você não recebeu o e-mail, teremos prazer em enviar outro.',
        'link_sent' => 'Um novo link de verificação foi enviado para o endereço de e-mail que você informou no cadastro.',
        'resend' => 'Reenviar e-mail de verificação',
    ],

    'social' => [
        'log_in_with' => 'Entrar com :provider',
        'unavailable' => 'O login com :provider está indisponível no momento.',
        'unavailable_notice' => 'O login com :provider está desativado. Se você costumava entrar com :provider, defina uma senha para sua conta — enviaremos um link por e-mail.',
        'unavailable_set_password' => 'Definir uma senha',
        'cancelled' => 'O login com :provider foi cancelado. Tente novamente.',
        'failed' => 'Não foi possível fazer seu login com :provider. Tente novamente.',
        'expired' => 'Seu login com :provider expirou antes de ser concluído. Comece novamente.',
        'email_missing' => 'Sua conta do :provider não compartilhou um endereço de e-mail, por isso não pode ser usada para entrar.',
        'email_taken' => 'O endereço de e-mail desta conta do :provider pertence a outra conta do RateGuru. Entre nessa conta para conectar o :provider a ela.',
        'link_expired' => 'Sua sessão de conexão de conta expirou ou foi alterada. Comece a conectar a conta novamente.',
        'already_signed_in' => 'Você já está conectado. Para também entrar com :provider, conecte-o em Contas conectadas.',
        'already_linked' => 'Esta conta do :provider já está conectada a outra conta.',
        'provider_already_linked' => 'Sua conta já está conectada a outra conta do :provider.',
        'pending_link' => 'Este endereço de e-mail já tem uma conta. Entre da mesma forma que antes — com sua senha ou com outra conta social — e sua conta do :provider será conectada automaticamente.',
        'password_removed' => 'Seu e-mail foi confirmado pelo :provider. Uma senha que havia sido definida para este endereço de e-mail foi removida — você pode definir uma nova no seu perfil.',
    ],
];
