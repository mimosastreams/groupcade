<?php
/**
 * Trivia, game one. Page: /trivia/ (and /trivia/CODE).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/class-gc-trivia-bank.php';
require_once __DIR__ . '/class-gc-trivia.php';

GC_Games::register( new GC_Trivia() );
