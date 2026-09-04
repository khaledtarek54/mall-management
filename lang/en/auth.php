<?php

return [

    'failed' => 'These credentials do not match our records.',
    'password' => 'The provided password is incorrect.',
    'throttle' => 'Too many login attempts. Please try again in :seconds seconds.',

    // Atriom API-specific
    'login_success' => 'Login successful',
    'logout_success' => 'Signed out.',
    'account_inactive' => 'This account is not active. Contact your property manager.',
    'account_blocked' => 'Your account has been blocked. Please contact the mall management.',
    'read_only' => 'Your login can view this account but cannot act on it. Ask your administrator for permission.',

    // Password reset (mobile API). These mirror Laravel's passwords.* statuses
    // but live here so the API returns consistent, branded copy.
    'reset_link_sent' => 'If that email is registered, a reset link has been sent.',
    'reset_success' => 'Password has been reset. You can now sign in.',
    'reset_failed' => 'This password reset link is invalid or has expired.',
    'reset_throttled' => 'Please wait before requesting another reset link.',

];
