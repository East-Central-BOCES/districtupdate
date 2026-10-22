<?php
// SPDX-License-Identifier: GPL-3.0-or-later
namespace local_districtupdate\form;
defined('MOODLE_INTERNAL') || die();
global $CFG;
require_once($CFG->libdir.'/formslib.php');
final class district extends \moodleform {
 public function definition() {
  global $DB;
  $mform = $this->_form;
  $mform->addElement('hidden', 'action', 'district'); $mform->setType('action', PARAM_ALPHA);
  $mform->addElement('hidden', 'districtid', 0); $mform->setType('districtid', PARAM_INT);
  $mform->addElement('text', 'name', 'District name (exact AD Company name)', ['maxlength' => 64, 'size' => 60]);
  $mform->setType('name', PARAM_TEXT);
  $mform->addRule('name', null, 'required', null, 'client');
  foreach (['domains' => 'Email domains, one per line (without @)',
   'aliases' => 'Other Institution or District profile names, one per line'] as $key => $label) {
   $mform->addElement('textarea', $key, $label, ['rows' => 4, 'cols' => 60]); $mform->setType($key, PARAM_RAW);
  }
  $ids = array_merge($this->_customdata['reviewerids'], optional_param_array('reviewerids', [], PARAM_INT));
  $options = [];
  foreach (array_slice(array_unique($ids), 0, 100) as $id) {
   $u = $DB->get_record('user', ['id' => $id]);
   $options[$id] = $u && !$u->deleted ? \local_districtupdate\reviewers::label($u) : 'Unavailable user ('.$id.')';
  }
  $mform->addElement('autocomplete', 'reviewerids', 'District reviewers', $options, [
   'multiple' => true, 'ajax' => 'local_districtupdate/reviewer_picker', 'tags' => false,
   'placeholder' => 'Search by name or email (at least 2 characters)', 'noselectionstring' => 'No reviewers selected',
  ]);
  $mform->setType('reviewerids', PARAM_INT);
  $mform->addElement('static', 'reviewerhelp', '', 'Type a name or email, then select a matching Moodle user. '
   .'You can choose multiple reviewers or remove a selection. Results include name, email and username. Up to 25 matches are shown; keep typing to narrow the search.');
  $this->add_action_buttons(true, $this->_customdata['editing'] ? 'Save district changes' : 'Save district and add another');
 }
 public function validation($data, $files) {
  global $DB;
  $errors = parent::validation($data, $files);
  if (trim($data['name']) === '' || \core_text::strlen($data['name']) > 64) {
   $errors['name'] = 'Use a district name of 1–64 characters, exactly as it should appear in AD Company.';
  }
  $existing = $DB->get_record('local_districtupdate_dist', ['name' => trim($data['name'])], 'id');
  if ($existing && (int)$existing->id !== (int)$data['districtid']) {
   $errors['name'] = 'A district with this name already exists. Edit that district or use a different name.';
  }
  try { \local_districtupdate\reviewers::validate_ids($data['reviewerids'] ?? []); }
  catch (\moodle_exception $e) { $errors['reviewerids'] = $e->getMessage(); }
  return $errors;
 }
}
