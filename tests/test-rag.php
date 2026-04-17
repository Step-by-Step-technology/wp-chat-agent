<?php
/**
 * Tests des primitives RAG (chunking + similarité cosinus).
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../includes/class-ai-assistant-rag.php';

$tests = array();

// ───── chunk_text ─────
$tests[] = array(
    'name'     => 'chunk_text: texte vide → array vide',
    'expected' => array(),
    'actual'   => AI_Assistant_RAG::chunk_text( '' ),
);

$tests[] = array(
    'name'     => 'chunk_text: texte court → un seul chunk',
    'expected' => array( 'Bonjour le monde.' ),
    'actual'   => AI_Assistant_RAG::chunk_text( 'Bonjour le monde.', 100 ),
);

$long = str_repeat( 'Phrase un. Phrase deux. Phrase trois. ', 10 );
$chunks = AI_Assistant_RAG::chunk_text( $long, 100 );
$tests[] = array(
    'name'     => 'chunk_text: long texte → plusieurs chunks',
    'expected' => true,
    'actual'   => count( $chunks ) > 1,
);

foreach ( $chunks as $i => $c ) {
    $tests[] = array(
        'name'     => 'chunk_text: chunk #' . ( $i + 1 ) . ' respecte la taille max',
        'expected' => true,
        'actual'   => strlen( $c ) <= 120, // un peu de marge pour la dernière phrase
    );
}

$tests[] = array(
    'name'     => 'chunk_text: normalise les espaces multiples en un seul',
    'expected' => array( 'Un deux trois.' ),
    'actual'   => AI_Assistant_RAG::chunk_text( "Un    deux\n\ntrois.", 100 ),
);

// ───── cosine_similarity (via reflection) ─────
$ref = new ReflectionClass( 'AI_Assistant_RAG' );
$cos = $ref->getMethod( 'cosine_similarity' );
$cos->setAccessible( true );

$tests[] = array(
    'name'     => 'cosine: vecteurs identiques → 1.0',
    'expected' => 1.0,
    'actual'   => round( $cos->invoke( null, array( 1, 0, 0 ), array( 1, 0, 0 ) ), 6 ),
);

$tests[] = array(
    'name'     => 'cosine: vecteurs orthogonaux → 0',
    'expected' => 0.0,
    'actual'   => round( $cos->invoke( null, array( 1, 0, 0 ), array( 0, 1, 0 ) ), 6 ),
);

$tests[] = array(
    'name'     => 'cosine: vecteurs opposés → -1',
    'expected' => -1.0,
    'actual'   => round( $cos->invoke( null, array( 1, 0 ), array( -1, 0 ) ), 6 ),
);

$tests[] = array(
    'name'     => 'cosine: vecteur nul → 0 (pas de division par zéro)',
    'expected' => 0.0,
    'actual'   => $cos->invoke( null, array( 0, 0, 0 ), array( 1, 2, 3 ) ),
);

$tests[] = array(
    'name'     => 'cosine: tailles différentes → prend le min',
    'expected' => 1.0,
    'actual'   => round( $cos->invoke( null, array( 1, 1 ), array( 1, 1, 999 ) ), 6 ),
);

return $tests;
