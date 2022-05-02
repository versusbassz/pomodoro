<?php

namespace Versusbassz\Pomodoro\Tests;

use Versusbassz\Pomodoro\Pomodoro;
use WP_UnitTestCase;

class BaseWpTest extends WP_UnitTestCase {
	public function testNothing() {
		self::factory()->post->create_many( 20 );

		$posts = get_posts( [
			'nopaging' => true,
		] );
		$this->assertCount( 20, $posts );
	}

	public function testIsPluginLoaded() {
		// the root class exists
		$this->assertTrue( class_exists( Pomodoro::class ) );
	}
}
