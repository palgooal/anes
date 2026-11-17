<?php

namespace App;

enum CodePurpose: string
{
    /** Beneficiary phone login. */
    case Login = 'login';

    /** Second step of the staff login, after the password. */
    case TwoFactor = 'two_factor';
}
