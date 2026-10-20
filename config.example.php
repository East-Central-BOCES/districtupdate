<?php
// Example only. Add these settings to your REAL Moodle config.php before it loads lib/setup.php.
// The plugin also has a settings screen for non-secret connection settings.
$CFG->forced_plugin_settings['local_districtupdate']['bindpassword'] = 'REPLACE_WITH_DELEGATED_AD_ACCOUNT_PASSWORD';
// You can also force connection settings here if your hosting policy requires it:
// $CFG->forced_plugin_settings['local_districtupdate']['ldapurl'] = 'ldaps://dc.example.org:636';
// $CFG->forced_plugin_settings['local_districtupdate']['binddn'] = 'CN=Moodle District Review,OU=Service Accounts,DC=example,DC=org';
// Do not put your actual credentials into this example file or into GitHub.
