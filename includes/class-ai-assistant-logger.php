<?php
/**
 * Journalisation des conversations en base de données.
 *
 * @package AI_Assistant
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class AI_Assistant_Logger {

    const TABLE     = 'ai_assistant_chat_log';
    const DB_VERSION = '1.0';

    /**
     * Nom complet de la table (avec préfixe WP).
     */
    public static function table_name() {
        global $wpdb;
        return $wpdb->prefix . self::TABLE;
    }

    /**
     * Crée/met à jour la table. À appeler à l'activation du plugin
     * et à chaque chargement si la version DB a changé.
     */
    public static function maybe_install() {
        $installed = get_option( 'ai_assistant_log_db_version', '' );
        if ( $installed === self::DB_VERSION ) {
            return;
        }

        global $wpdb;
        $table   = self::table_name();
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            created_at DATETIME NOT NULL,
            ip VARCHAR(45) NOT NULL DEFAULT '',
            user_id BIGINT UNSIGNED NULL DEFAULT NULL,
            question TEXT NOT NULL,
            answer LONGTEXT NOT NULL,
            PRIMARY KEY (id),
            KEY created_at (created_at),
            KEY ip (ip)
        ) {$charset};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );

        update_option( 'ai_assistant_log_db_version', self::DB_VERSION );
    }

    /**
     * Insère un échange.
     */
    public static function log( $question, $answer, $ip ) {
        global $wpdb;
        self::maybe_install();

        $wpdb->insert(
            self::table_name(),
            array(
                'created_at' => current_time( 'mysql' ),
                'ip'         => substr( sanitize_text_field( $ip ), 0, 45 ),
                'user_id'    => get_current_user_id() ?: null,
                'question'   => $question,
                'answer'     => $answer,
            ),
            array( '%s', '%s', '%d', '%s', '%s' )
        );

        // Purge opportuniste (1 fois sur 50).
        if ( mt_rand( 1, 50 ) === 1 ) {
            self::purge_old();
        }
    }

    /**
     * Supprime les entrées plus vieilles que la durée de rétention.
     */
    public static function purge_old() {
        global $wpdb;
        $retention = (int) get_option( 'ai_assistant_log_retention_days', 30 );
        $retention = max( 1, min( 365, $retention ) );
        $cutoff    = gmdate( 'Y-m-d H:i:s', time() - ( $retention * DAY_IN_SECONDS ) );
        $wpdb->query( $wpdb->prepare( "DELETE FROM " . self::table_name() . " WHERE created_at < %s", $cutoff ) );
    }

    /**
     * Récupère une page de logs.
     */
    public static function get_page( $page = 1, $per_page = 25, $search = '', $ip = '' ) {
        global $wpdb;
        $page     = max( 1, (int) $page );
        $per_page = max( 1, min( 200, (int) $per_page ) );
        $offset   = ( $page - 1 ) * $per_page;
        $table    = self::table_name();

        $where  = '1=1';
        $params = array();
        if ( $search !== '' ) {
            $where   .= ' AND (question LIKE %s OR answer LIKE %s)';
            $like     = '%' . $wpdb->esc_like( $search ) . '%';
            $params[] = $like;
            $params[] = $like;
        }
        if ( $ip !== '' ) {
            $where   .= ' AND ip = %s';
            $params[] = $ip;
        }

        $count_sql = "SELECT COUNT(*) FROM {$table} WHERE {$where}";
        $total = $params ? (int) $wpdb->get_var( $wpdb->prepare( $count_sql, $params ) ) : (int) $wpdb->get_var( $count_sql );

        $rows_sql = "SELECT * FROM {$table} WHERE {$where} ORDER BY id DESC LIMIT %d OFFSET %d";
        $rows_params = array_merge( $params, array( $per_page, $offset ) );
        $rows = $wpdb->get_results( $wpdb->prepare( $rows_sql, $rows_params ), ARRAY_A );

        return array(
            'total'    => $total,
            'page'     => $page,
            'per_page' => $per_page,
            'rows'     => $rows ?: array(),
        );
    }

    /**
     * Vide intégralement la table.
     */
    public static function truncate() {
        global $wpdb;
        $wpdb->query( 'TRUNCATE TABLE ' . self::table_name() );
    }
}
