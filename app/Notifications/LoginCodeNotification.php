<?php

namespace App\Notifications;

/**
 * A one-time login (or two-factor) code delivered on WhatsApp, with the
 * settings in effect for OTP codes (the active mode).
 */
class LoginCodeNotification extends WhatsAppCodeNotification {}
