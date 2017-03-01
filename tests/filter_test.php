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
 * Tests for filter class
 * @author    Guy Thomas <gthomas@moodlerooms.com>
 * @copyright Guy Thomas 2017.
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

use filter_imageopt\test_util;

global $CFG;

require_once($CFG->dirroot . '/files/externallib.php');
require_once(__DIR__.'/../filter.php');

class filter_imageopt_filter_testcase extends advanced_testcase {

    /**
     * Test regex works with sample img tag + pluginfile.php src.
     */
    public function test_regex() {
        $regex = filter_imageopt::REGEXP_IMGSRC;
        $matches = [];
        $img = '<img src="http://www.example.com/moodle/pluginfile.php/236001/mod_label/intro/0/testpng_2880x1800.png"';
        $img .= ' alt="" role="presentation"';
        $imgclosed = $img.' />';
        $img .= '>';

        // Test self closing img tag.
        $match = preg_match($regex, $img, $matches);
        $this->assertEquals(1, $match);
        $this->assertEquals($img, $matches[0]);
        $match = preg_match($regex, '<div>'.$img.'</div>', $matches);
        $this->assertEquals(1, $match);
        $this->assertEquals($img, $matches[0]);
        $match = preg_match($regex, "\n\n\n".$img, $matches);
        $this->assertEquals(1, $match);
        $this->assertEquals($img, $matches[0]);

        // Test closed img tag.
        $match = preg_match($regex, $imgclosed, $matches);
        $this->assertEquals(1, $match);
        $this->assertEquals($imgclosed, $matches[0]);
        $match = preg_match($regex, '<div>'.$imgclosed.'</div>', $matches);
        $this->assertEquals(1, $match);
        $this->assertEquals($imgclosed, $matches[0]);
        $match = preg_match($regex, "\n\n\n".$imgclosed, $matches);
        $this->assertEquals(1, $match);
        $this->assertEquals($imgclosed, $matches[0]);
    }

    /**
     * Test empty svg image contains width and height params.
     * @throws dml_exception
     */
    public function test_empty_image() {
        $filter = new filter_imageopt(context_system::instance(), []);
        $sizes = [
            [400, 300],
            [640, 480],
            [1024, 768]
        ];
        foreach ($sizes as $size) {
            $emptyimage = test_util::call_restricted_method($filter, 'empty_image', $size);
            $this->assertContains('width="'.$size[0].'" height="'.$size[1].'"', $emptyimage);
        }
    }

    /**
     * Create moodle file.
     * @param context $context
     * @param string $fixturefile name of fixture file
     * @return stored_file
     * @throws file_exception
     * @throws stored_file_creation_exception
     */
    private function std_file_record(context $context, $fixturefile) {
        global $CFG;

        $component = 'mod_label';
        $filearea = 'intro';

        $filerecord = [
            'contextid' => $context->id,
            'component' => $component,
            'filearea' => $filearea,
            'itemid' => 0,
            'filepath' => '/',
            'filename' => $fixturefile
        ];

        $fs = \get_file_storage();
        $file = $fs->create_file_from_pathname($filerecord, $CFG->dirroot.'/filter/imageopt/tests/fixtures/'.$fixturefile);

        return $file;
    }

    /**
     * Test getting image from file path.
     * @throws coding_exception
     */
    public function test_get_img_file() {

        $this->resetAfterTest();
        $this->setAdminUser();
        $dg = $this->getDataGenerator();
        $course = $dg->create_course();
        $plugin = $dg->get_plugin_generator('mod_label');
        $fixturefile = 'testpng_2880x1800.png';
        $intro = '<p><img src="@@PLUGINFILE@@/'.$fixturefile.'" alt="" role="presentation"><br></p>';
        $label = $plugin->create_instance((object) ['course' => $course->id, 'intro' => $intro]);
        $context = context_module::instance($label->cmid);

        $this->std_file_record($context, $fixturefile);

        $filter = new filter_imageopt($context, []);

        $filepath = 'pluginfile.php/'.$context->id.'/mod_label/intro/'.$fixturefile;

        /** @var stored_file $imgfile */
        $imgfile = test_util::call_restricted_method($filter, 'get_img_file', [$filepath]);
        $this->assertNotEmpty($imgfile);
        $this->assertEquals($fixturefile, $imgfile->get_filename());
    }

    /**
     * Return image file in label and return label text + regex matches.
     * @return [string, array, stored_file]
     * @throws coding_exception
     * @throws file_exception
     * @throws stored_file_creation_exception
     */
    private function create_image_file_text($fixturefile) {

        $dg = $this->getDataGenerator();
        $course = $dg->create_course();
        $plugin = $dg->get_plugin_generator('mod_label');

        $intro = '<p><img src="@@PLUGINFILE@@/'.$fixturefile.'" alt="" role="presentation"><br></p>';
        $label = $plugin->create_instance((object) ['course' => $course->id, 'intro' => $intro]);
        $context = context_module::instance($label->cmid);

        $file = $this->std_file_record($context, $fixturefile);

        // Test filter plugin img, lazy load.
        $labeltxt = file_rewrite_pluginfile_urls($label->intro, 'pluginfile.php', $context->id,
                $file->get_component(), $file->get_filearea(), 0);
        $matches = [];
        $regex = filter_imageopt::REGEXP_IMGSRC;
        preg_match($regex, $labeltxt, $matches);
        return [$labeltxt, $matches, $file];
    }

    /**
     * Test apply load on visible.
     * @throws coding_exception
     */
    public function test_apply_loadonvisible() {
        global $CFG;

        $this->resetAfterTest();
        $this->setAdminUser();

        set_config('maxwidth','480','filter_imageopt');
        set_config('loadonvisible','1','filter_imageopt');

        $fixturefile = 'testpng_2880x1800.png';

        list ($labeltxt, $matches, $file) = $this->create_image_file_text($fixturefile);

        /** @var stored_file $file */
        $file = $file;

        $regex = filter_imageopt::REGEXP_IMGSRC;
        preg_match($regex, $labeltxt, $matches);

        $filter = new filter_imageopt(context_helper::instance_by_id($file->get_contextid()), []);
        $str = test_util::call_restricted_method($filter, 'apply_loadonvisible', [$matches]);

        $loadonvisibleurl = $CFG->wwwroot.'/pluginfile.php/'.$file->get_contextid().'/filter_imageopt/'.
            $file->get_filearea().'/0/'.$file->get_component().'/'.$fixturefile;

        // Test filter plugin img, lazy load.
        $this->assertContains('<img data-loadonvisible="'.$loadonvisibleurl.'"', $str);
        $this->assertContains('src="data:image/svg+xml;utf8,', $str);

    }

    /**
     * @param stored_file $file
     * @return string.
     */
    private function filter_imageopt_url_from_file(stored_file $file) {
        global $CFG;

        $url = $CFG->wwwroot.'/pluginfile.php/'.$file->get_contextid().'/filter_imageopt/'.$file->get_filearea().'/'.
                $file->get_itemid().'/'.$file->get_component().'/'.$file->get_filename();

        return $url;
    }

    /**
     * Test processing image src.
     * @throws coding_exception
     */
    public function test_process_image_src() {

        $this->resetAfterTest();
        $this->setAdminUser();

        set_config('maxwidth','480','filter_imageopt');

        $fixturefile = 'testpng_2880x1800.png';

        list ($labeltxt, $matches, $file) = $this->create_image_file_text($fixturefile);

        $context = context_helper::instance_by_id($file->get_contextid());

        $filter = new filter_imageopt($context, []);

        $processed = test_util::call_restricted_method($filter, 'process_image_src', [$matches]);
        $postfilterurl = $this->filter_imageopt_url_from_file($file);
        $this->assertContains('<img src="'.$postfilterurl, $processed);

    }

    /**
     * Test main filter function.
     * @throws coding_exception
     */
    public function test_filter() {
        global $CFG;

        $this->resetAfterTest();
        $this->setAdminUser();

        set_config('maxwidth','480','filter_imageopt');

        $fixturefile = 'testpng_2880x1800.png';

        list ($labeltxt, $matches, $file) = $this->create_image_file_text($fixturefile);

        $context = context_helper::instance_by_id($file->get_contextid());

        // Test filter plugin img, no lazy load.
        $filter = new filter_imageopt($context, []);
        $filtered = $filter->filter($labeltxt);
        $prefilterurl = $CFG->wwwroot.'/pluginfile.php/'.$context->id.'/mod_label/intro/0/testpng_2880x1800.png';
        $this->assertContains($prefilterurl, $labeltxt);
        $postfilterurl = $CFG->wwwroot.'/pluginfile.php/'.$context->id.'/filter_imageopt/intro/0/mod_label/testpng_2880x1800.png';
        $this->assertContains('<img src="'.$postfilterurl, $filtered);
        $this->assertNotContains('<img src="'.$prefilterurl, $filtered);
        $this->assertNotContains('<img data-loadonvisible="'.$postfilterurl, $filtered);
        $this->assertNotContains('<img data-loadonvisible="'.$prefilterurl, $filtered);

        // Test filter plugin img,  lazy load.
        set_config('loadonvisible','1','filter_imageopt');
        $filter = new filter_imageopt($context, []);
        $filtered = $filter->filter($labeltxt);
        $prefilterurl = $CFG->wwwroot.'/pluginfile.php/'.$context->id.'/mod_label/intro/0/testpng_2880x1800.png';
        $this->assertContains($prefilterurl, $labeltxt);
        $postfilterurl = $CFG->wwwroot.'/pluginfile.php/'.$context->id.'/filter_imageopt/intro/0/mod_label/testpng_2880x1800.png';
        $this->assertContains('<img data-loadonvisible="'.$postfilterurl, $filtered);
        $this->assertNotContains('<img data-loadonvisible="'.$prefilterurl, $filtered);
        $this->assertNotContains('<img src="'.$postfilterurl, $filtered);
        $this->assertNotContains('<img src="'.$prefilterurl, $filtered);
    }
}
