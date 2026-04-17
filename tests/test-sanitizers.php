<?php
/**
 * Tests des sanitizers (classe AI_Assistant_Settings).
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../includes/class-ai-assistant-settings.php';

$tests = array();

// ───── sanitize_bool ─────
$tests[] = array(
    'name'     => 'sanitize_bool: "yes" → "yes"',
    'expected' => 'yes',
    'actual'   => AI_Assistant_Settings::sanitize_bool( 'yes' ),
);
$tests[] = array(
    'name'     => 'sanitize_bool: "no" → "no"',
    'expected' => 'no',
    'actual'   => AI_Assistant_Settings::sanitize_bool( 'no' ),
);
$tests[] = array(
    'name'     => 'sanitize_bool: "" → "no"',
    'expected' => 'no',
    'actual'   => AI_Assistant_Settings::sanitize_bool( '' ),
);
$tests[] = array(
    'name'     => 'sanitize_bool: null → "no"',
    'expected' => 'no',
    'actual'   => AI_Assistant_Settings::sanitize_bool( null ),
);
$tests[] = array(
    'name'     => 'sanitize_bool: random string → "no"',
    'expected' => 'no',
    'actual'   => AI_Assistant_Settings::sanitize_bool( 'bla' ),
);

// ───── sanitize_keywords ─────
$out = AI_Assistant_Settings::sanitize_keywords( "foo\nbar\n\nfoo\n  baz  " );
$tests[] = array(
    'name'     => 'sanitize_keywords: dédoublonne + trim',
    'expected' => "foo\nbar\nbaz",
    'actual'   => $out,
);
$tests[] = array(
    'name'     => 'sanitize_keywords: vide → ""',
    'expected' => '',
    'actual'   => AI_Assistant_Settings::sanitize_keywords( '' ),
);

// ───── sanitize_themes ─────
$json = AI_Assistant_Settings::sanitize_themes( "Paie : salaire\nRH\n" );
$decoded = json_decode( $json, true );
$tests[] = array(
    'name'     => 'sanitize_themes: 2 thèmes dont un avec description',
    'expected' => array(
        array( 'name' => 'Paie', 'description' => 'salaire' ),
        array( 'name' => 'RH',   'description' => '' ),
    ),
    'actual'   => $decoded,
);

$json = AI_Assistant_Settings::sanitize_themes( '' );
$tests[] = array(
    'name'     => 'sanitize_themes: vide → "[]"',
    'expected' => '[]',
    'actual'   => $json,
);

// Accepte du JSON déjà bien formé.
$input = json_encode( array( array( 'name' => 'Test', 'description' => 'abc' ) ) );
$json = AI_Assistant_Settings::sanitize_themes( $input );
$tests[] = array(
    'name'     => 'sanitize_themes: accepte du JSON en entrée',
    'expected' => array( array( 'name' => 'Test', 'description' => 'abc' ) ),
    'actual'   => json_decode( $json, true ),
);

return $tests;
