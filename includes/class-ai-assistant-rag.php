<?php
/**
 * RAG (Retrieval-Augmented Generation) — indexation du contenu du site
 * et recherche vectorielle via les embeddings OpenAI.
 *
 * @package AI_Assistant
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class AI_Assistant_RAG {

    const TABLE      = 'ai_assistant_embeddings';
    const DB_VERSION = '1.0';

    public static function table_name() {
        global $wpdb;
        return $wpdb->prefix . self::TABLE;
    }

    /**
     * Crée la table si besoin.
     */
    public static function maybe_install() {
        $installed = get_option( 'ai_assistant_rag_db_version', '' );
        if ( $installed === self::DB_VERSION ) return;

        global $wpdb;
        $table   = self::table_name();
        $charset = $wpdb->get_charset_collate();

        // L'embedding est stocké en JSON (array de floats) pour portabilité
        // sur tout MySQL, sans dépendre de pgvector ou d'un type custom.
        $sql = "CREATE TABLE {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            post_id BIGINT UNSIGNED NOT NULL,
            chunk_index INT UNSIGNED NOT NULL,
            chunk_text TEXT NOT NULL,
            embedding LONGTEXT NOT NULL,
            post_type VARCHAR(40) NOT NULL DEFAULT '',
            indexed_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY post_id (post_id),
            KEY post_type (post_type)
        ) {$charset};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );
        update_option( 'ai_assistant_rag_db_version', self::DB_VERSION );
    }

    // ───────── API OpenAI Embeddings ─────────

    /**
     * Calcule l'embedding d'un texte via OpenAI. Retourne array de floats ou WP_Error.
     */
    public static function get_embedding( $text, $model = '' ) {
        $api_key = (string) get_option( 'ai_assistant_api_key', '' );
        if ( $api_key === '' ) {
            return new WP_Error( 'no_key', 'Clé API OpenAI absente.' );
        }
        if ( $model === '' ) {
            $model = (string) get_option( 'ai_assistant_rag_embedding_model', 'text-embedding-3-small' );
        }

        $response = wp_remote_post( 'https://api.openai.com/v1/embeddings', array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type'  => 'application/json',
            ),
            'body'    => wp_json_encode( array(
                'model' => $model,
                'input' => $text,
            ) ),
            'timeout' => 30,
        ) );

        if ( is_wp_error( $response ) ) {
            return new WP_Error( 'req_failed', 'Requête embedding échouée : ' . $response->get_error_message() );
        }
        $body = json_decode( (string) wp_remote_retrieve_body( $response ), true );
        if ( isset( $body['error']['message'] ) ) {
            return new WP_Error( 'openai_error', 'OpenAI : ' . $body['error']['message'] );
        }
        if ( ! isset( $body['data'][0]['embedding'] ) || ! is_array( $body['data'][0]['embedding'] ) ) {
            return new WP_Error( 'bad_response', 'Réponse embedding mal formée.' );
        }
        return $body['data'][0]['embedding'];
    }

    // ───────── Chunking ─────────

    /**
     * Découpe un texte en morceaux de taille ~approx $max_chars caractères,
     * en respectant les limites de phrases.
     */
    public static function chunk_text( $text, $max_chars = 2000 ) {
        $text = trim( preg_replace( '/\s+/u', ' ', (string) $text ) );
        if ( $text === '' ) return array();
        if ( strlen( $text ) <= $max_chars ) return array( $text );

        $chunks = array();
        $sentences = preg_split( '/(?<=[.!?])\s+/u', $text );
        $current = '';
        foreach ( $sentences as $s ) {
            if ( $current === '' ) {
                $current = $s;
            } elseif ( strlen( $current ) + strlen( $s ) + 1 <= $max_chars ) {
                $current .= ' ' . $s;
            } else {
                $chunks[] = $current;
                $current = $s;
            }
        }
        if ( $current !== '' ) $chunks[] = $current;
        return $chunks;
    }

    // ───────── Indexation ─────────

    /**
     * Récupère le texte brut d'un post (titre + contenu, sans shortcodes).
     */
    public static function get_post_text( $post ) {
        if ( ! $post ) return '';
        $title   = (string) $post->post_title;
        $content = (string) $post->post_content;
        $content = strip_shortcodes( $content );
        $content = wp_strip_all_tags( $content );
        return trim( $title . "\n\n" . $content );
    }

    /**
     * Indexe un post (supprime les anciens chunks puis re-embed).
     * Retourne le nombre de chunks créés, ou WP_Error.
     */
    public static function index_post( $post_id ) {
        self::maybe_install();
        $post = get_post( $post_id );
        if ( ! $post || $post->post_status !== 'publish' ) {
            self::delete_post_chunks( $post_id );
            return 0;
        }

        $post_types = self::allowed_post_types();
        if ( ! in_array( $post->post_type, $post_types, true ) ) {
            self::delete_post_chunks( $post_id );
            return 0;
        }

        $text = self::get_post_text( $post );
        if ( $text === '' ) {
            self::delete_post_chunks( $post_id );
            return 0;
        }

        $chunk_size = (int) get_option( 'ai_assistant_rag_chunk_size', 2000 );
        $chunk_size = max( 500, min( 8000, $chunk_size ) );
        $chunks = self::chunk_text( $text, $chunk_size );
        if ( empty( $chunks ) ) {
            self::delete_post_chunks( $post_id );
            return 0;
        }

        // Supprime les anciens chunks de ce post.
        self::delete_post_chunks( $post_id );

        global $wpdb;
        $now = current_time( 'mysql' );
        $inserted = 0;

        foreach ( $chunks as $idx => $chunk ) {
            $embedding = self::get_embedding( $chunk );
            if ( is_wp_error( $embedding ) ) {
                return $embedding; // on arrête dès une erreur (quota, clé invalide, etc.)
            }
            $wpdb->insert(
                self::table_name(),
                array(
                    'post_id'     => $post_id,
                    'chunk_index' => $idx,
                    'chunk_text'  => $chunk,
                    'embedding'   => wp_json_encode( $embedding ),
                    'post_type'   => $post->post_type,
                    'indexed_at'  => $now,
                ),
                array( '%d', '%d', '%s', '%s', '%s', '%s' )
            );
            $inserted++;
        }
        return $inserted;
    }

    public static function delete_post_chunks( $post_id ) {
        global $wpdb;
        $wpdb->delete( self::table_name(), array( 'post_id' => (int) $post_id ), array( '%d' ) );
    }

    public static function clear_all() {
        global $wpdb;
        $wpdb->query( 'TRUNCATE TABLE ' . self::table_name() );
    }

    /**
     * Liste les IDs des posts à indexer, paginée.
     */
    public static function list_post_ids( $offset = 0, $limit = 10 ) {
        $post_types = self::allowed_post_types();
        $query = new WP_Query( array(
            'post_type'      => $post_types,
            'post_status'    => 'publish',
            'posts_per_page' => $limit,
            'offset'         => $offset,
            'fields'         => 'ids',
            'orderby'        => 'ID',
            'order'          => 'ASC',
            'no_found_rows'  => false,
        ) );
        return array(
            'ids'   => $query->posts,
            'total' => (int) $query->found_posts,
        );
    }

    public static function allowed_post_types() {
        $raw = (string) get_option( 'ai_assistant_rag_post_types', 'post,page' );
        $types = array_filter( array_map( 'trim', explode( ',', $raw ) ) );
        return $types ?: array( 'post', 'page' );
    }

    // ───────── Stats ─────────

    public static function stats() {
        self::maybe_install();
        global $wpdb;
        $table = self::table_name();
        return array(
            'chunks'      => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ),
            'posts'       => (int) $wpdb->get_var( "SELECT COUNT(DISTINCT post_id) FROM {$table}" ),
            'last_index'  => (string) $wpdb->get_var( "SELECT MAX(indexed_at) FROM {$table}" ),
        );
    }

    /**
     * Liste paginée des posts indexés avec nb de chunks et date.
     */
    public static function list_indexed( $page = 1, $per_page = 50 ) {
        self::maybe_install();
        global $wpdb;
        $table    = self::table_name();
        $page     = max( 1, (int) $page );
        $per_page = max( 1, min( 200, (int) $per_page ) );
        $offset   = ( $page - 1 ) * $per_page;

        $total = (int) $wpdb->get_var( "SELECT COUNT(DISTINCT post_id) FROM {$table}" );

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT post_id, post_type, COUNT(*) AS chunks, MAX(indexed_at) AS indexed_at
             FROM {$table}
             GROUP BY post_id, post_type
             ORDER BY MAX(indexed_at) DESC
             LIMIT %d OFFSET %d",
            $per_page, $offset
        ), ARRAY_A );

        $items = array();
        foreach ( $rows ?: array() as $r ) {
            $post = get_post( (int) $r['post_id'] );
            $items[] = array(
                'post_id'    => (int) $r['post_id'],
                'post_type'  => (string) $r['post_type'],
                'chunks'     => (int) $r['chunks'],
                'indexed_at' => (string) $r['indexed_at'],
                'title'      => $post ? get_the_title( $post ) : '(supprimé)',
                'permalink'  => $post ? get_permalink( $post ) : '',
                'edit_link'  => $post ? get_edit_post_link( $post ) : '',
                'exists'     => (bool) $post,
            );
        }

        return array(
            'total'    => $total,
            'page'     => $page,
            'per_page' => $per_page,
            'items'    => $items,
        );
    }

    // ───────── Recherche ─────────

    /**
     * Recherche vectorielle : retourne les $top_k chunks les plus proches de la question.
     * Format retourné : [ [ post_id, chunk_text, similarity, post_title, permalink ], ... ]
     */
    public static function search( $query_text, $top_k = 5 ) {
        self::maybe_install();

        $q_emb = self::get_embedding( $query_text );
        if ( is_wp_error( $q_emb ) ) return array();

        global $wpdb;
        $rows = $wpdb->get_results( "SELECT post_id, chunk_text, embedding FROM " . self::table_name(), ARRAY_A );
        if ( empty( $rows ) ) return array();

        $scored = array();
        foreach ( $rows as $r ) {
            $emb = json_decode( $r['embedding'], true );
            if ( ! is_array( $emb ) ) continue;
            $sim = self::cosine_similarity( $q_emb, $emb );
            $scored[] = array(
                'post_id'    => (int) $r['post_id'],
                'chunk_text' => $r['chunk_text'],
                'similarity' => $sim,
            );
        }

        usort( $scored, function ( $a, $b ) {
            return $b['similarity'] <=> $a['similarity'];
        } );

        $top = array_slice( $scored, 0, max( 1, (int) $top_k ) );
        // Enrichissement : titre + lien du post.
        foreach ( $top as &$item ) {
            $post = get_post( $item['post_id'] );
            $item['post_title'] = $post ? get_the_title( $post ) : '';
            $item['permalink']  = $post ? get_permalink( $post ) : '';
        }
        return $top;
    }

    private static function cosine_similarity( $a, $b ) {
        $n = min( count( $a ), count( $b ) );
        if ( $n === 0 ) return 0.0;
        $dot = 0.0; $na = 0.0; $nb = 0.0;
        for ( $i = 0; $i < $n; $i++ ) {
            $x = (float) $a[ $i ];
            $y = (float) $b[ $i ];
            $dot += $x * $y;
            $na  += $x * $x;
            $nb  += $y * $y;
        }
        if ( $na == 0.0 || $nb == 0.0 ) return 0.0;
        return $dot / ( sqrt( $na ) * sqrt( $nb ) );
    }

    // ───────── Hooks WP ─────────

    /**
     * Appelé lors de l'enregistrement d'un post. Ne fait rien si RAG désactivé.
     */
    public static function on_save_post( $post_id, $post, $update ) {
        if ( get_option( 'ai_assistant_enable_rag', 'no' ) !== 'yes' ) return;
        if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) return;
        if ( $post->post_status !== 'publish' ) return;
        if ( ! in_array( $post->post_type, self::allowed_post_types(), true ) ) return;

        // Indexation silencieuse : on log mais on ne bloque pas la publication en cas d'erreur.
        $result = self::index_post( $post_id );
        if ( is_wp_error( $result ) ) {
            error_log( '[AI Assistant RAG] Échec indexation post ' . $post_id . ' : ' . $result->get_error_message() );
        }
    }

    public static function on_delete_post( $post_id ) {
        self::delete_post_chunks( $post_id );
    }
}

// Enregistrement des hooks WP (actif uniquement si l'option RAG est activée).
add_action( 'save_post',         array( 'AI_Assistant_RAG', 'on_save_post' ), 20, 3 );
add_action( 'before_delete_post', array( 'AI_Assistant_RAG', 'on_delete_post' ) );
