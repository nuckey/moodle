<?php

// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Contains logic class and interface for the grading evaluation plugin "Comparison
 * with the best assessment".
 *
 * @package   mod-workshop
 * @copyright 2009 David Mudrak <david.mudrak@gmail.com>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once(dirname(dirname(__FILE__)) . '/lib.php');  // interface definition
require_once($CFG->libdir . '/gradelib.php');

/**
 * Defines the computation login of the grading evaluation subplugin
 */
class workshop_best_evaluation implements workshop_evaluation {

    /** @var workshop the parent workshop instance */
    protected $workshop;

    /**
     * Constructor
     *
     * @param workshop $workshop The workshop api instance
     * @return void
     */
    public function __construct(workshop $workshop) {
        $this->workshop         = $workshop;
    }

    /**
     * Calculates the grades for assessment and updates 'gradinggrade' fields in 'workshop_assessments' table
     *
     * This function relies on the grading strategy subplugin providing get_assessments_recordset() method.
     * {@see self::process_assessments()} for the required structure of the recordset.
     *
     * @param null|int|array $restrict If null, update all reviewers, otherwise update just grades for the given reviewers(s)
     *
     * @return void
     */
    public function update_grading_grades($restrict=null) {
        global $DB;

        $grader = $this->workshop->grading_strategy_instance();

        // get the information about the assessment dimensions
        $diminfo = $grader->get_dimensions_info();

        // fetch a recordset with all assessments to process
        $rs         = $grader->get_assessments_recordset($restrict);
        $batch      = array();    // will contain a set of all assessments of a single submission
        $previous   = null;       // a previous record in the recordset
        foreach ($rs as $current) {
            if (is_null($previous)) {
                // we are processing the very first record in the recordset
                $previous = $current;
            }
            if ($current->submissionid == $previous->submissionid) {
                $batch[] = $current;
            } else {
                // process all the assessments of a sigle submission
                $this->process_assessments($batch, $diminfo);
                // start with a new batch to be processed
                $batch = array($current);
                $previous = $current;
            }
        }
        // do not forget to process the last batch!
        $this->process_assessments($batch, $diminfo);
        $rs->close();
    }

    ////////////////////////////////////////////////////////////////////////////////
    // Internal methods                                                           //
    ////////////////////////////////////////////////////////////////////////////////

    /**
     * Given a list of all assessments of a single submission, updates the grading grades in database
     *
     * @param array $assessments of stdClass (->assessmentid ->assessmentweight ->reviewerid ->gradinggrade ->submissionid ->dimensionid ->grade)
     * @param array $diminfo of stdClass (->id ->weight ->max ->min)
     * @return void
     */
    protected function process_assessments(array $assessments, array $diminfo) {
        global $DB;

        // reindex the passed flat structure to be indexed by assessmentid
        $assessments = $this->prepare_data_from_recordset($assessments);

        // normalize the dimension grades to the interval 0 - 100
        $assessments = $this->normalize_grades($assessments, $diminfo);

        // get a hypothetical average assessment
        $average = $this->average_assessment($assessments);

        // calculate variance of dimension grades
        $variances = $this->weighted_variance($assessments);
        foreach ($variances as $dimid => $variance) {
            $diminfo[$dimid]->variance = $variance;
        }

        // for every assessment, calculate its distance from the average one
        $distances = array();
        foreach ($assessments as $asid => $assessment) {
            $distances[$asid] = $this->assessments_distance($assessment, $average, $diminfo);
        }

        // identify the best assessments - it est those with the shortest distance from the best assessment
        $bestids = array_keys($distances, min($distances));

        // for every assessment, calculate its distance from the nearest best assessment
        $distances = array();
        foreach ($bestids as $bestid) {
            $best = $assessments[$bestid];
            foreach ($assessments as $asid => $assessment) {
                $d = $this->assessments_distance($assessment, $best, $diminfo);
                if (!isset($distances[$asid]) or $d < $distances[$asid]) {
                    $distances[$asid] = $d;
                }
            }
        }

        // calculate the grading grade
        foreach ($distances as $asid => $distance) {
            $gradinggrade = (100 - $distance);
            /**
            if ($gradinggrade < 0) {
                $gradinggrade = 0;
            }
            if ($gradinggrade > 100) {
                $gradinggrade = 100;
            }
             */
            $grades[$asid] = grade_floatval($gradinggrade);
        }

        // if the new grading grade differs from the one stored in database, update it
        // we do not use set_field() here because we want to pass $bulk param
        foreach ($grades as $assessmentid => $grade) {
            if (grade_floats_different($grade, $assessments[$assessmentid]->gradinggrade)) {
                // the value has changed
                $record = new stdClass();
                $record->id = $assessmentid;
                $record->gradinggrade = grade_floatval($grade);
                $DB->update_record('workshop_assessments', $record, true);  // bulk operations expected
            }
        }

        // done. easy, heh? ;-)
    }

    /**
     * Prepares a structure of assessments and given grades
     *
     * @param array $assessments batch of recordset items as returned by the grading strategy
     * @return array
     */
    protected function prepare_data_from_recordset($assessments) {
        $data = array();    // to be returned
        foreach ($assessments as $a) {
            $id = $a->assessmentid; // just an abbrevation
            if (!isset($data[$id])) {
                $data[$id] = new stdClass();
                $data[$id]->assessmentid = $a->assessmentid;
                $data[$id]->weight       = $a->assessmentweight;
                $data[$id]->reviewerid   = $a->reviewerid;
                $data[$id]->gradinggrade = $a->gradinggrade;
                $data[$id]->submissionid = $a->submissionid;
                $data[$id]->dimgrades    = array();
            }
            $data[$id]->dimgrades[$a->dimensionid] = $a->grade;
        }
        return $data;
    }

    /**
     * Normalizes the dimension grades to the interval 0.00000 - 100.00000
     *
     * Note: this heavily relies on PHP5 way of handling references in array of stdClasses. Hopefuly
     * it will not change again soon.
     *
     * @param array $assessments of stdClass as returned by {@see self::prepare_data_from_recordset()}
     * @param array $diminfo of stdClass
     * @return array of stdClass with the same structure as $assessments
     */
    protected function normalize_grades(array $assessments, array $diminfo) {
        foreach ($assessments as $asid => $assessment) {
            foreach ($assessment->dimgrades as $dimid => $dimgrade) {
                $dimmin = $diminfo[$dimid]->min;
                $dimmax = $diminfo[$dimid]->max;
                $assessment->dimgrades[$dimid] = grade_floatval(($dimgrade - $dimmin) / ($dimmax - $dimmin) * 100);
            }
        }
        return $assessments;
    }

    /**
     * Given a set of a submission's assessments, returns a hypothetical average assessment
     *
     * The passed structure must be array of assessments objects with ->weight and ->dimgrades properties.
     *
     * @param array $assessments as prepared by {@link self::prepare_data_from_recordset()}
     * @return null|stdClass
     */
    protected function average_assessment(array $assessments) {
        $sumdimgrades = array();
        foreach ($assessments as $a) {
            foreach ($a->dimgrades as $dimid => $dimgrade) {
                if (!isset($sumdimgrades[$dimid])) {
                    $sumdimgrades[$dimid] = 0;
                }
                $sumdimgrades[$dimid] += $dimgrade * $a->weight;
            }
        }

        $sumweights = 0;
        foreach ($assessments as $a) {
            $sumweights += $a->weight;
        }
        if ($sumweights == 0) {
            // unable to calculate average assessment
            return null;
        }

        $average = new stdClass();
        $average->dimgrades = array();
        foreach ($sumdimgrades as $dimid => $sumdimgrade) {
            $average->dimgrades[$dimid] = grade_floatval($sumdimgrade / $sumweights);
        }
        return $average;
    }

    /**
     * Given a set of a submission's assessments, returns standard deviations of all their dimensions
     *
     * The passed structure must be array of assessments objects with at least ->weight
     * and ->dimgrades properties. This implementation uses weighted incremental algorithm as
     * suggested in "D. H. D. West (1979). Communications of the ACM, 22, 9, 532-535:
     * Updating Mean and Variance Estimates: An Improved Method"
     * {@link http://en.wikipedia.org/wiki/Algorithms_for_calculating_variance#Weighted_incremental_algorithm}
     *
     * @param array $assessments as prepared by {@link self::prepare_data_from_recordset()}
     * @return null|array indexed by dimension id
     */
    protected function weighted_variance(array $assessments) {
        $first = reset($assessments);
        if (empty($first)) {
            return null;
        }
        $dimids = array_keys($first->dimgrades);
        $asids  = array_keys($assessments);
        $vars   = array();  // to be returned
        foreach ($dimids as $dimid) {
            $n = 0;
            $s = 0;
            $sumweight = 0;
            foreach ($asids as $asid) {
                $x = $assessments[$asid]->dimgrades[$dimid];    // value (data point)
                $weight = $assessments[$asid]->weight;          // the values's weight
                if ($weight == 0) {
                    continue;
                }
                if ($n == 0) {
                    $n = 1;
                    $mean = $x;
                    $s = 0;
                    $sumweight = $weight;
                } else {
                    $n++;
                    $temp = $weight + $sumweight;
                    $q = $x - $mean;
                    $r = $q * $weight / $temp;
                    $s = $s + $sumweight * $q * $r;
                    $mean = $mean + $r;
                    $sumweight = $temp;
                }
            }
            if ($sumweight > 0 and $n > 1) {
                // for the sample: $vars[$dimid] = ($s * $n) / (($n - 1) * $sumweight);
                // for the population:
                $vars[$dimid] = $s / $sumweight;
            } else {
                $vars[$dimid] = null;
            }
        }
        return $vars;
    }

    /**
     * Measures the distance of the assessment from a referential one
     *
     * The passed data structures must contain ->dimgrades property. The referential
     * assessment is supposed to be close to the average assessment. All dimension grades are supposed to be
     * normalized to the interval 0 - 100.
     *
     * @param stdClass $assessment the assessment being measured
     * @param stdClass $referential assessment
     * @param array $diminfo of stdClass(->weight ->min ->max ->variance) indexed by dimension id
     * @return float|null rounded to 5 valid decimals
     */
    protected function assessments_distance(stdClass $assessment, stdClass $referential, array $diminfo) {
        $distance = 0;
        $n = 0;
        foreach (array_keys($assessment->dimgrades) as $dimid) {
            $agrade = $assessment->dimgrades[$dimid];
            $rgrade = $referential->dimgrades[$dimid];
            $var    = $diminfo[$dimid]->variance;
            $weight = $diminfo[$dimid]->weight;

            // variations very close to zero are too sensitive to a small change of data values
            if ($var > 0.01 and $agrade != $rgrade) {
                $absdelta   = abs($agrade - $rgrade);
                // todo the following constant is the param. For 1 it is very strict, for 5 it is quite lax
                $reldelta   = pow($agrade - $rgrade, 2) / (5 * $var);
                $distance  += $absdelta * $reldelta * $weight;
                $n         += $weight;
            }
        }
        if ($n > 0) {
            // average distance across all dimensions
            return grade_floatval($distance / $n);
        } else {
            return null;
        }
    }
}