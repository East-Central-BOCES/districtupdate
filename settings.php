<?php
// SPDX-License-Identifier: GPL-3.0-or-later
 defined('MOODLE_INTERNAL') || die();
global $DB;
if ($hassiteconfig) {
 $settings = new admin_settingpage('local_districtupdate', get_string('pluginname', 'local_districtupdate'));
 $ADMIN->add('localplugins', $settings);
 $ADMIN->add('localplugins', new admin_externalpage('local_districtupdate_manage',
  'Manage district staff reviews', new moodle_url('/local/districtupdate/manage.php')));
 $settings->add(new admin_setting_heading('local_districtupdate/setup', 'Start here',
  'Configure districts and reviewers in Manage district staff reviews. LDAP writes default to off. '
  . 'Use a dedicated delegated AD account, trusted LDAPS, and full group DNs. See README.html in the plugin.'));
 $text = [
  'ldapurl' => ['LDAPS URL', 'Example: ldaps://dc.example.org:636. Certificate validation is required.', ''],
  'binddn' => ['AD service account bind DN', 'Delegate only the required group and user attribute permissions.', ''],
  'membersdn' => ['ECBOCES_Members group DN', 'Full distinguished name, not just the group name.', ''],
  'zoomdn' => ['Zoom group DN', 'Full distinguished name.', ''],
  'basicdn' => ['Basic group DN', 'Full distinguished name.', ''],
 ];
 foreach ($text as $key => [$label, $help, $default]) {
  $settings->add(new admin_setting_configtext('local_districtupdate/'.$key, $label, $help, $default, PARAM_RAW_TRIMMED));
 }
 $settings->add(new admin_setting_configtextarea('local_districtupdate/userbase', 'AD user search base DNs',
  'Enter one full OU or container DN per line, on the configured AD domain. Each search includes child OUs. '
  .'Keep the commas inside each DN; do not separate bases with commas or semicolons. Overlapping bases are supported. '
  .'All bases must be readable, and a username must identify exactly one AD account across them. '
  .'This setting locates AD accounts for preview/processing; roster creation still reads synced Moodle cohort members.', '', PARAM_RAW_TRIMMED, 70, 5));
 $settings->add(new admin_setting_configselect('local_districtupdate/identityattribute', 'AD username attribute',
  'Must match Moodle LDAP usernames exactly. Email is also checked.', 'samaccountname',
  ['samaccountname' => 'sAMAccountName', 'userprincipalname' => 'userPrincipalName']));
 $settings->add(new admin_setting_heading('local_districtupdate/secret', 'AD password',
  'Set bindpassword through $CFG->forced_plugin_settings in config.php; no password is collected by this page.'));
 $settings->add(new admin_setting_configcheckbox('local_districtupdate/livewrites', 'Enable approved AD writes',
  'Off: preview only. On: administrators can preview current AD state and explicitly confirm selected changes.', 0));
 $authoptions = [];
 foreach (core_component::get_plugin_list('auth') as $auth => $path) {
  $authoptions[$auth] = get_string('pluginname', 'auth_'.$auth).' ('.$auth.')';
 }
 foreach ($DB->get_records_sql('SELECT DISTINCT auth FROM {user} WHERE deleted = 0') as $account) {
  if (!isset($authoptions[$account->auth])) { $authoptions[$account->auth] = $account->auth; }
 }
 asort($authoptions);
 $settings->add(new admin_setting_configmultiselect('local_districtupdate/authmethods', 'Roster authentication methods',
  'Only members of the Moodle cohort ECBOCES_Members are included. Select the authentication methods whose cohort members should be reviewed. Include every applicable LDAP method, '
  .'even if its identifier is not ldap. This does not change how users sign in. All selected accounts use the one '
  .'AD connection configured above; username and email are verified before processing. Refresh draft rosters after changing this selection.',
  ['ldap'], $authoptions));
 $settings->add(new admin_setting_configtextarea('local_districtupdate/excluded', 'Excluded usernames',
  'One exact Moodle username per line. Exclude service and shared accounts. Site admins are always excluded.', '', PARAM_RAW));
 $settings->add(new admin_setting_configtext('local_districtupdate/districtprofilefield', 'District custom profile field',
  'Shortname of the additional user profile field containing the district name. Defaults to district (case-insensitive). '
  .'A unique display name is also accepted if no shortname matches. Values match district names or configured aliases. '
  .'Conflicts with email or Institution are left unmatched for administrator review. Missing fields or values use the other matching sources. '
  .'Leave blank to disable. Use an administrator-maintained field. Refresh draft rosters after changing this setting.', 'district', PARAM_RAW_TRIMMED));
 $settings->add(new admin_setting_configselect('local_districtupdate/auditretentiondays', 'Audit log retention',
  'Audit entries older than this period are deleted by the daily cleanup task. Rosters, responses, manual assignments '
  .'and processing status are retained. Moodle cron must be running.', 30,
  [7 => '7 days', 14 => '14 days', 30 => '30 days', 60 => '60 days', 90 => '90 days', 180 => '180 days', 365 => '365 days']));
 $settings->add(new admin_setting_configcheckbox('local_districtupdate/automatic', 'Automatically start annual reviews',
  'Creates this year’s roster on or after the start date and sends invitations. Enable only after pilot approval.', 0));
 $settings->add(new admin_setting_heading('local_districtupdate/emailtemplates', 'Email messages',
  'Edit the plain-text emails sent by this plugin. Placeholders: {district}, {year}, {duedate}, {reviewurl}. '
  .'{reviewurl} points to the district review in invitations and the protected approval page in admin notifications. '
  .'If omitted from a message, the link is appended automatically. Blank fields use the defaults. '
  .'Admin notifications never automatically include staff lists, responses or notes. Saving these settings sends no email.'));
 $emaildefaults = \local_districtupdate\email_templates::defaults();
 foreach (['invitation' => 'Reviewer invitation / reminder', 'admin' => 'Admin submission notification'] as $type => $label) {
  $settings->add(new admin_setting_configtext('local_districtupdate/'.$type.'subject', $label.' subject',
   '', $emaildefaults[$type.'subject'], PARAM_TEXT));
  $settings->add(new admin_setting_configtextarea('local_districtupdate/'.$type.'body', $label.' message',
   'Plain text. Use {reviewurl} where you want the link to appear.', $emaildefaults[$type.'body'], PARAM_RAW, 70, 8));
 }
 $settings->add(new admin_setting_configselect('local_districtupdate/startmonth', 'Annual start month', '', 8,
  array_combine(range(1,12), range(1,12))));
 $settings->add(new admin_setting_configselect('local_districtupdate/startday', 'Annual start day', '', 1,
  array_combine(range(1,28), range(1,28))));
 $settings->add(new admin_setting_configtext('local_districtupdate/duedays', 'Days to complete review', '', 30, PARAM_INT));
 $settings->add(new admin_setting_configtext('local_districtupdate/reminddays', 'Reminder interval in days',
  'Zero disables reminders. Reminders start only after an invitation has been sent.', 7, PARAM_INT));
}
