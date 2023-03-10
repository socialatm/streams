<?php

namespace Code\Module;

use Code\Web\Controller;
use Code\Lib\Account;
    
class Email_resend extends Controller
{

    public function post()
    {

        if ($_POST['token']) {
            if (!Account::approve(trim($_POST['token']))) {
                notice(t('Token verification failed.'));
            }
        }
    }


    public function get()
    {

        if (argc() > 1) {
            $result = false;
            $email = hex2bin(argv(1));

            if ($email) {
                $result = Account::verify_email_address(['resend' => true, 'email' => $email]);
            }

            if ($result) {
                notice(t('Email verification resent'));
            } else {
                notice(t('Unable to resend email verification message.'));
            }

            goaway(z_root() . '/email_validation/' . bin2hex($email));
        }

        // @todo - one can provide a form here to resend the mail
        // after directing to here if a successful login was attempted from an unverified address.
    }
}
