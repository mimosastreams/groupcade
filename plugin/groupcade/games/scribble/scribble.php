<?php
/**
 * Scribble Squad, game three: draw your secret prompt, guess everyone else's. Page: /scribble/ (and /scribble/CODE).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/class-gc-scribble-prompts.php';
require_once __DIR__ . '/class-gc-scribble.php';

GC_Games::register( new GC_Scribble() );
GC_Scribble_Prompts::init();
