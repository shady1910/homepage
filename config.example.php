<?php

// Documentation only. Keep the real config outside the webroot at ../private/config.php.
// Add this one key to the existing returned array; preserve all SMTP/host settings.
return [
    'admin_password_hash' => '', // Generate privately with password_hash(), never use a plaintext password here.
];
