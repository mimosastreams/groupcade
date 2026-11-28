<?php
/**
 * Phony Baloney, game two: write a fake answer, find the real one. Page: /baloney/ (and /baloney/CODE).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/class-gc-baloney-prompts.php';
require_once __DIR__ . '/class-gc-baloney.php';

GC_Games::register( new GC_Baloney() );
GC_Baloney_Prompts::init();
