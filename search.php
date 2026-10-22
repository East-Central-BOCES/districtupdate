<?php
// SPDX-License-Identifier: GPL-3.0-or-later
define('AJAX_SCRIPT', true);
require_once(__DIR__.'/../../config.php');
$PAGE->set_context(context_system::instance());
require_login();
require_capability('local/districtupdate:manage', context_system::instance());
require_sesskey();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { throw new invalid_parameter_exception('POST required.'); }
$results = \local_districtupdate\reviewers::search(required_param('query', PARAM_RAW_TRIMMED));
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
echo json_encode($results, JSON_THROW_ON_ERROR);
