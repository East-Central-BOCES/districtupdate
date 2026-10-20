<?php
// SPDX-License-Identifier: GPL-3.0-or-later
 defined('MOODLE_INTERNAL') || die();
function local_districtupdate_page(string $title, moodle_url $url): void {
 global $PAGE;
 $PAGE->set_context(context_system::instance()); $PAGE->set_url($url);
 $PAGE->set_pagelayout('standard'); $PAGE->set_title($title); $PAGE->set_heading($title);
}
function local_districtupdate_hidden(string $name, $value): string {
 return html_writer::empty_tag('input', ['type' => 'hidden', 'name' => $name, 'value' => $value]);
}
function local_districtupdate_input(string $name, string $value = '', string $type = 'text', array $extra = []): string {
 return html_writer::empty_tag('input', array_merge(['type' => $type, 'name' => $name, 'value' => $value, 'class' => 'form-control'], $extra));
}
function local_districtupdate_formstart(moodle_url $url): string {
 return html_writer::start_tag('form', ['method' => 'post', 'action' => $url->out(false)]) . local_districtupdate_hidden('sesskey', sesskey());
}
function local_districtupdate_button(string $label, string $name = 'action', string $value = 'save'): string {
 return html_writer::tag('button', s($label), ['type' => 'submit', 'name' => $name, 'value' => $value, 'class' => 'btn btn-primary m-1']);
}
function local_districtupdate_table(array $head, array $rows): string {
 $t = new html_table(); $t->head = $head; $t->data = $rows; $t->attributes['class'] = 'generaltable table table-striped';
 return html_writer::tag('div', html_writer::table($t), ['class' => 'table-responsive']);
}
