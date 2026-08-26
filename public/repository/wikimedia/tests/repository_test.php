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
 * Unit tests for repository_wikimedia class.
 *
 * @package    repository_wikimedia
 * @copyright  2026 Andi Permana
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace repository_wikimedia;

use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/repository/lib.php');
require_once($CFG->dirroot . '/repository/wikimedia/lib.php');

/**
 * Unit tests for Wikimedia repository
 *
 * @package    repository_wikimedia
 * @copyright  2026 Andi Permana
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \repository_wikimedia
 */
final class repository_test extends \advanced_testcase {
    /** @var \repository_wikimedia|null Repository instance */
    private $repo = null;

    /**
     * Setup test environment.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);

        $user = get_admin();
        $this->setUser($user);

        // Create repository instance.
        $record = $this->getDataGenerator()->create_repository('wikimedia');

        $this->repo = \repository::get_repository_by_id($record->id, \core\context\system::instance());
    }

    /**
     * Test that a HTTP 429 response from Wikimedia throws a rate limit exception.
     */
    public function test_get_file_rate_limited(): void {
        ['mock' => $mock] = $this->get_mocked_http_client();
        $mock->append(new Response(429));

        $this->expectException(\repository_exception::class);
        $this->expectExceptionMessage(get_string('ratelimited', 'repository_wikimedia'));

        $this->repo->get_file('https://upload.wikimedia.org/wikipedia/commons/test.jpg');
    }

    /**
     * Test that a successful HTTP 200 response returns the downloaded file path and URL.
     */
    public function test_get_file_success(): void {
        ['mock' => $mock] = $this->get_mocked_http_client();
        $mock->append(new Response(200, [], 'fake image content'));

        $result = $this->repo->get_file('https://upload.wikimedia.org/wikipedia/commons/test.jpg');

        $this->assertArrayHasKey('path', $result);
        $this->assertArrayHasKey('url', $result);
        $this->assertEquals('https://upload.wikimedia.org/wikipedia/commons/test.jpg', $result['url']);
        $this->assertFileExists($result['path']);
    }

    /**
     * Test that a non-200/non-429 HTTP error response throws a moodle_exception.
     */
    public function test_get_file_http_error(): void {
        ['mock' => $mock] = $this->get_mocked_http_client();
        $mock->append(new Response(503, [], 'Service Unavailable'));

        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessage(get_string('errorwhiledownload', 'repository', 'Service Unavailable'));

        $this->repo->get_file('https://upload.wikimedia.org/wikipedia/commons/test.jpg');
    }

    /**
     * Test that a network-level failure (e.g. connection refused) throws a moodle_exception.
     */
    public function test_get_file_network_error(): void {
        ['mock' => $mock] = $this->get_mocked_http_client();
        $mock->append(new RequestException('Connection refused', new Request('GET', 'https://upload.wikimedia.org/')));

        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessage(get_string('errorwhiledownload', 'repository', 'Connection refused'));

        $this->repo->get_file('https://upload.wikimedia.org/wikipedia/commons/test.jpg');
    }

    /**
     * Test that requested thumbnail widths are rounded up to standard Wikimedia widths.
     *
     * @dataProvider get_thumb_url_provider
     * @param string $imageurl the original image URL
     * @param int $origwidth original image width
     * @param int $origheight original image height
     * @param int $thumbwidth requested thumbnail width
     * @param bool $force whether to force thumb URL generation
     * @param string $expected expected URL
     */
    public function test_get_thumb_url(
        string $imageurl,
        int $origwidth,
        int $origheight,
        int $thumbwidth,
        bool $force,
        string $expected,
    ): void {
        $client = new \wikimedia();
        $this->assertEquals($expected, $client->get_thumb_url($imageurl, $origwidth, $origheight, $thumbwidth, $force));
    }

    /**
     * Data provider for test_get_thumb_url.
     *
     * @return array
     */
    public static function get_thumb_url_provider(): array {
        $base = 'https://upload.wikimedia.org/wikipedia/commons/';
        return [
            'Landscape image, standard width requested' => [
                $base . 'a/ab/test.jpg', 1000, 800, 120, false,
                $base . 'thumb/a/ab/test.jpg/120px-test.jpg',
            ],
            'Query parameters stripped from image URL' => [
                $base . 'a/ab/test.jpg?utm_source=commons.wikimedia.org&utm_campaign=imageinfo&utm_content=original',
                1000, 800, 120, false,
                $base . 'thumb/a/ab/test.jpg/120px-test.jpg',
            ],
            'Non-standard width rounded up to standard width' => [
                $base . 'a/ab/test.jpg', 1000, 800, 75, false,
                $base . 'thumb/a/ab/test.jpg/120px-test.jpg',
            ],
            'Icon width 24 rounded up to 40' => [
                $base . 'a/ab/test.jpg', 1000, 800, 24, false,
                $base . 'thumb/a/ab/test.jpg/40px-test.jpg',
            ],
            'Portrait image scaled width rounded up to standard width' => [
                // Width scaled to 120 * 500 / 1000 = 60, which is already standard.
                $base . 'a/ab/test.jpg', 500, 1000, 120, false,
                $base . 'thumb/a/ab/test.jpg/60px-test.jpg',
            ],
            'Portrait image with non-standard scaled width' => [
                // Width scaled to 120 * 700 / 1000 = 84, rounded up to 120.
                $base . 'a/ab/test.jpg', 700, 1000, 120, false,
                $base . 'thumb/a/ab/test.jpg/120px-test.jpg',
            ],
            'Original returned when image fits into requested size' => [
                $base . 'a/ab/test.jpg', 100, 80, 120, false,
                $base . 'a/ab/test.jpg',
            ],
            'Original returned when standard width would upscale bitmap' => [
                // Requested 90 is rounded up to 120, which exceeds the original width of 100.
                $base . 'a/ab/test.jpg', 100, 80, 90, false,
                $base . 'a/ab/test.jpg',
            ],
            'SVG can be rendered above its original width' => [
                $base . 'a/ab/test.svg', 512, 512, 512, true,
                $base . 'thumb/a/ab/test.svg/960px-test.svg.png',
            ],
            'Width above largest step capped at largest standard width' => [
                $base . 'a/ab/test.jpg', 8000, 6000, 4000, false,
                $base . 'thumb/a/ab/test.jpg/3840px-test.jpg',
            ],
        ];
    }
}
