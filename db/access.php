<?php
// SPDX-License-Identifier: GPL-3.0-or-later
 defined('MOODLE_INTERNAL') || die();
$capabilities = [
 'local/districtupdate:manage' => ['captype' => 'write', 'contextlevel' => CONTEXT_SYSTEM,
  'riskbitmask' => RISK_PERSONAL | RISK_DATALOSS, 'archetypes' => []],
];
