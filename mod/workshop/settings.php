<?php  //$Id$

require_once($CFG->dirroot.'/mod/workshop/lib.php');

$grades = workshop_get_maxgrades();

$settings->add(new admin_setting_configselect('workshop/grade', get_string('gradeforsubmission', 'workshop'),
                    get_string('configgrade', 'workshop'), 80, $grades));

$settings->add(new admin_setting_configselect('workshop/gradinggrade', get_string('gradeforassessment', 'workshop'),
                    get_string('configgradinggrade', 'workshop'), 20, $grades));

$options = get_max_upload_sizes($CFG->maxbytes);
$options[0] = get_string('courseuploadlimit');
$settings->add(new admin_setting_configselect('workshop/maxbytes', get_string('maxbytes', 'workshop'),
                    get_string('configmaxbytes', 'workshop'), 0, $options));

$settings->add(new admin_setting_configselect('workshop/strategy', get_string('strategy', 'workshop'),
                    get_string('configstrategy', 'workshop'), 'accumulative', workshop_get_strategies()));

$options = workshop_get_anonymity_modes();
$settings->add(new admin_setting_configselect('workshop/anonymity', get_string('anonymity', 'workshop'),
                    get_string('configanonymity', 'workshop'), WORKSHOP_ANONYMITY_NONE, $options));

$options = workshop_get_numbers_of_assessments();
$settings->add(new admin_setting_configselect('workshop/nsassessments', get_string('nsassessments', 'workshop'),
                    get_string('confignsassessments', 'workshop'), 3, $options));

$options = workshop_get_numbers_of_assessments();
$options[0] = get_string('assessallexamples', 'workshop');
$settings->add(new admin_setting_configselect('workshop/nexassessments', get_string('nexassessments', 'workshop'),
                    get_string('confignexassessments', 'workshop'), 0, $options));

$options = workshop_get_example_modes();
$settings->add(new admin_setting_configselect('workshop/examplesmode', get_string('examplesmode', 'workshop'),
                    get_string('configexamplesmode', 'workshop'), WORKSHOP_EXAMPLES_VOLUNTARY, $options));

$levels = array();
foreach (workshop_get_comparison_levels() as $code => $level) {
    $levels[$code] = $level->name;
}
$settings->add(new admin_setting_configselect('workshop/assessmentcomps', get_string('assessmentcomps', 'workshop'),
                    get_string('configassessmentcomps', 'workshop'), WORKSHOP_COMPARISON_NORMAL, $levels));

/*
$settings->add(new admin_setting_configcheckbox('assignment_showrecentsubmissions', get_string('showrecentsubmissions', 'assignment'),
                   get_string('configshowrecentsubmissions', 'assignment'), 1));
*/
?>