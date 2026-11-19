<?php

// Documentation only. Keep the real config outside the webroot at ../private/config.php.
// Add the relevant keys to the existing returned array and preserve the other settings.
return [
    'admin_password_hash' => '', // Generate privately with password_hash(), never use a plaintext password here.
    'weather_met_enabled' => false, // Set to true after accepting the MET Norway API terms.
];
