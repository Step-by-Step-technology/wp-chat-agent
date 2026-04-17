<?php
/**
 * Runner de tests autonomes.
 * Usage : `php tests/run.php`
 * Exit code : 0 si tous les tests passent, 1 sinon.
 */

$suites = array(
    'sanitizers' => __DIR__ . '/test-sanitizers.php',
    'rag'        => __DIR__ . '/test-rag.php',
);

$total = 0;
$fail  = 0;

foreach ( $suites as $name => $file ) {
    echo "\n━━━ Suite : {$name} ━━━\n";
    $tests = require $file;
    foreach ( $tests as $t ) {
        $total++;
        $ok = ( $t['expected'] === $t['actual'] );
        if ( $ok ) {
            echo "  ✓ {$t['name']}\n";
        } else {
            $fail++;
            echo "  ✗ {$t['name']}\n";
            echo "      attendu  : " . var_export( $t['expected'], true ) . "\n";
            echo "      obtenu   : " . var_export( $t['actual'], true ) . "\n";
        }
    }
}

echo "\n━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
echo "Total : {$total} tests — ";
echo ( $fail === 0 ) ? "\033[32mTOUS OK\033[0m\n" : "\033[31m{$fail} ÉCHEC(S)\033[0m\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";

exit( $fail === 0 ? 0 : 1 );
