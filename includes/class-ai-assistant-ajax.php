<?php
/**
 * Gestion AJAX pour l'Assistant IA.
 * Compatible WordPress 5.0+ et PHP 8.0 → 8.4.
 *
 * @package AI_Assistant
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class AI_Assistant_Ajax {

    private static $instance = null;

    /** @var bool */
    private $debug_enabled = false;

    /** @var array */
    private $debug_logs = array();

    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action( 'wp_ajax_ai_assistant_query',        array( $this, 'handle_query' ) );
        add_action( 'wp_ajax_nopriv_ai_assistant_query', array( $this, 'handle_query' ) );
    }

    /**
     * Récupère une option avec son défaut (autonome, pas de dépendance externe).
     */
    private function opt( $key, $default = '' ) {
        $value = get_option( $key, $default );
        return ( $value === false || $value === null ) ? $default : $value;
    }

    public function handle_query() {
        // 1) Désactive le handler d'erreurs fatales de WordPress pour cette requête
        //    (sinon WP affiche sa page HTML "Internal Server Error" en 200).
        add_filter( 'wp_fatal_error_handler_enabled', '__return_false' );

        // 2) Notre propre shutdown handler : intercepte TOUT fatal PHP et renvoie un JSON propre.
        register_shutdown_function( array( $this, 'fatal_shutdown_handler' ) );

        // 3) Silence les outputs parasites.
        @ini_set( 'display_errors', '0' );
        if ( ! headers_sent() ) {
            ob_start();
        }

        try {
            $this->do_handle_query();
        } catch ( \Throwable $e ) {
            error_log( '[AI Assistant] ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine() );
            $this->emit_clean_json( array(
                'success' => false,
                'data'    => array( 'message' => 'Erreur serveur : ' . $e->getMessage() ),
            ), 500 );
        }
    }

    /**
     * Attrape les fatals PHP que try/catch ne peut pas intercepter (E_ERROR, etc.).
     * Envoie un JSON au lieu de laisser WordPress afficher du HTML.
     */
    public function fatal_shutdown_handler() {
        $err = error_get_last();
        $fatal_types = array( E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR );
        if ( ! $err || ! in_array( $err['type'], $fatal_types, true ) ) {
            return;
        }

        while ( ob_get_level() > 0 ) {
            @ob_end_clean();
        }
        if ( ! headers_sent() ) {
            @http_response_code( 500 );
            @header( 'Content-Type: application/json; charset=utf-8' );
        }
        $msg = sprintf(
            'Fatal PHP : %s dans %s:%d',
            $err['message'],
            basename( $err['file'] ),
            $err['line']
        );
        echo wp_json_encode( array(
            'success' => false,
            'data'    => array( 'message' => $msg ),
        ) );
    }

    /**
     * Vide tout buffer parasite et envoie un JSON propre avec le bon code HTTP.
     */
    private function emit_clean_json( $payload, $status = 200 ) {
        while ( ob_get_level() > 0 ) {
            @ob_end_clean();
        }
        if ( ! headers_sent() ) {
            @http_response_code( $status );
            @header( 'Content-Type: application/json; charset=utf-8' );
        }
        echo wp_json_encode( $payload );
        exit;
    }

    private function do_handle_query() {
        $this->debug_enabled = ( $this->opt( 'ai_assistant_enable_debug', 'no' ) === 'yes' );
        $this->debug_logs    = array();

        // Nonce.
        $nonce = isset( $_POST['nonce'] ) ? (string) $_POST['nonce'] : '';
        if ( ! wp_verify_nonce( $nonce, 'ai_assistant_nonce' ) ) {
            $this->send_error( 'Session expirée. Merci de recharger la page.', 400 );
        }

        // Rate limit.
        if ( ! $this->check_rate_limit() ) {
            $this->send_error( 'Trop de requêtes. Merci de patienter.', 429 );
        }

        // Message.
        $raw_message = isset( $_POST['message'] ) ? (string) $_POST['message'] : '';
        $message = sanitize_textarea_field( wp_unslash( $raw_message ) );
        if ( $message === '' ) {
            $this->send_error( 'Le message ne peut pas être vide.', 400 );
        }
        $this->log( 'Message reçu (' . strlen( $message ) . ' car.)' );

        // Clé API.
        $api_key = (string) $this->opt( 'ai_assistant_api_key', '' );
        if ( $api_key === '' ) {
            $this->send_error( 'La clé API OpenAI n\'est pas configurée.', 500 );
        }

        // Historique.
        $raw_history = isset( $_POST['conversation_history'] ) ? (string) $_POST['conversation_history'] : '';
        $history = $this->parse_history( $raw_history );
        $this->log( 'Historique : ' . count( $history ) . ' messages' );

        $extra_context = '';

        // RAG : recherche vectorielle dans le contenu du site.
        if ( $this->opt( 'ai_assistant_enable_rag', 'no' ) === 'yes' && class_exists( 'AI_Assistant_RAG' ) ) {
            $top_k = max( 1, (int) $this->opt( 'ai_assistant_rag_max_chunks', 5 ) );
            $chunks = AI_Assistant_RAG::search( $message, $top_k );
            if ( ! empty( $chunks ) ) {
                $extra_context .= "Extraits pertinents du site pour répondre (utilise-les en priorité et cite les sources en format markdown cliquable [Titre](url)) :\n\n";
                foreach ( $chunks as $i => $c ) {
                    $extra_context .= sprintf(
                        "--- Source %d : [%s](%s) ---\n%s\n\n",
                        $i + 1,
                        $c['post_title'],
                        $c['permalink'],
                        $c['chunk_text']
                    );
                }
                $this->log( count( $chunks ) . ' chunks RAG injectés' );
            } else {
                $this->log( 'RAG : aucun chunk pertinent trouvé' );
            }
        }

        // Recherche web optionnelle.
        if ( $this->needs_web_search( $message ) && class_exists( 'AI_Assistant_Search' ) ) {
            $results = AI_Assistant_Search::get_instance()->brave_search( $message );
            if ( is_array( $results ) && ! empty( $results ) ) {
                $extra_context .= "Résultats de recherche web récents :\n";
                foreach ( $results as $i => $r ) {
                    $title = isset( $r['title'] ) ? $r['title'] : '';
                    $desc  = isset( $r['description'] ) ? $r['description'] : '';
                    $url   = isset( $r['url'] ) ? $r['url'] : '';
                    $extra_context .= sprintf( "%d. %s : %s (%s)\n", $i + 1, $title, $desc, $url );
                }
                $extra_context .= "\n";
                $this->log( count( $results ) . ' résultats web' );
            }
        }

        // Messages pour OpenAI.
        $messages = array(
            array( 'role' => 'system', 'content' => $this->build_system_prompt() ),
        );

        $max_history = (int) $this->opt( 'ai_assistant_max_history', 10 );
        $max_history = max( 2, min( 50, $max_history ) );

        if ( ! empty( $history ) ) {
            $recent = array_slice( $history, -$max_history );
            $last_idx = count( $recent ) - 1;

            foreach ( $recent as $idx => $msg ) {
                if ( ! is_array( $msg ) || ! isset( $msg['role'], $msg['content'] ) ) continue;
                $role    = in_array( $msg['role'], array( 'user', 'assistant' ), true ) ? $msg['role'] : 'user';
                $content = (string) $msg['content'];

                if ( $idx === $last_idx && $role === 'user' && $extra_context !== '' ) {
                    $content = $extra_context . 'Question : ' . $content;
                }
                $messages[] = array( 'role' => $role, 'content' => $content );
            }
        } else {
            $messages[] = array(
                'role'    => 'user',
                'content' => $extra_context . 'Question : ' . $message,
            );
        }

        // Appel OpenAI.
        $model      = (string) $this->opt( 'ai_assistant_main_model', 'gpt-4.1-nano' );
        $max_tokens = (int) $this->opt( 'ai_assistant_max_tokens', 400 );

        $ai_response = $this->call_openai( $messages, $model, $max_tokens, 0.7, $api_key );

        if ( is_wp_error( $ai_response ) ) {
            $this->send_error( $ai_response->get_error_message(), 502 );
        }
        $this->log( 'Réponse OpenAI OK (' . strlen( $ai_response ) . ' car.)' );

        // Le filtrage par thèmes est désormais géré par le system prompt (plus fiable et gratuit).
        $filtered = $ai_response;

        // Journalisation si activée.
        $this->write_chat_log( $message, $filtered );

        $data = array( 'response' => $filtered );
        if ( $this->debug_enabled ) {
            $data['debug'] = $this->debug_logs;
        }
        $this->emit_clean_json( array( 'success' => true, 'data' => $data ), 200 );
    }

    /**
     * Enregistre l'échange dans la table DB si la journalisation est activée.
     */
    private function write_chat_log( $question, $answer ) {
        if ( $this->opt( 'ai_assistant_enable_chat_log', 'no' ) !== 'yes' ) {
            return;
        }
        if ( class_exists( 'AI_Assistant_Logger' ) ) {
            AI_Assistant_Logger::log(
                (string) $question,
                (string) $answer,
                isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : ''
            );
        }
    }

    // ───────── Helpers ─────────

    private function log( $line ) {
        if ( $this->debug_enabled ) {
            $this->debug_logs[] = (string) $line;
        }
    }

    private function send_error( $message, $code = 400 ) {
        $data = array( 'message' => (string) $message );
        if ( $this->debug_enabled ) {
            $data['debug'] = $this->debug_logs;
        }
        $this->emit_clean_json( array( 'success' => false, 'data' => $data ), $code );
    }

    private function parse_history( $raw ) {
        if ( $raw === '' ) return array();
        $json = wp_unslash( $raw );
        $decoded = json_decode( $json, true );
        return ( is_array( $decoded ) ) ? $decoded : array();
    }

    private function check_rate_limit() {
        $max = (int) $this->opt( 'ai_assistant_max_requests_per_hour', 10 );
        if ( $max <= 0 ) return true;

        // Si l'utilisateur est connecté, on limite par user_id (plus fiable derrière CDN/proxy).
        // Sinon on retombe sur l'IP.
        $uid = get_current_user_id();
        if ( $uid > 0 ) {
            $key = 'ai_assistant_rl_u_' . $uid;
        } else {
            $ip  = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( (string) $_SERVER['REMOTE_ADDR'] ) : 'unknown';
            $key = 'ai_assistant_rl_ip_' . md5( $ip );
        }

        $count = (int) get_transient( $key );
        if ( $count >= $max ) {
            return false;
        }
        set_transient( $key, $count + 1, HOUR_IN_SECONDS );
        return true;
    }

    private function needs_web_search( $query ) {
        if ( $this->opt( 'ai_assistant_enable_web_search', 'no' ) !== 'yes' ) {
            return false;
        }

        $query_lower = strtolower( (string) $query );
        $word_count  = str_word_count( $query );

        // Questions courtes (≤ 4 mots) : probablement un suivi de conversation.
        // On laisse l'IA utiliser le contexte existant plutôt que chercher sur le web.
        if ( $word_count <= 4 ) {
            return false;
        }

        // Patterns de follow-up explicites.
        if ( preg_match( '/^\s*et\s+\S{1,15}\s*\??\s*$/u', $query ) && strlen( $query ) < 20 ) {
            return false;
        }

        $keywords_text = (string) $this->opt( 'ai_assistant_web_search_keywords', '' );
        $keywords = array_filter( array_map( 'trim', explode( "\n", $keywords_text ) ) );

        foreach ( $keywords as $kw ) {
            if ( $kw !== '' && strpos( $query_lower, strtolower( $kw ) ) !== false ) {
                return true;
            }
        }
        if ( preg_match( '/\b20\d{2}\b/', $query_lower ) ) {
            return true;
        }
        return false;
    }

    private function build_system_prompt() {
        $site_name = (string) $this->opt( 'ai_assistant_site_name', 'Assistant' );
        $site_name = str_replace( array( '"', "\n", "\r" ), array( "'", ' ', ' ' ), $site_name );
        if ( $site_name === '' ) $site_name = 'Assistant';

        $p  = "Tu es l'assistant IA de « {$site_name} ».\n\n";
        $p .= "Ton rôle : aider les utilisateurs de manière utile, précise et professionnelle, en français.\n\n";

        // Si le filtrage par thèmes est activé, on injecte les thèmes dans le prompt principal
        // plutôt que de faire un second appel OpenAI (qui est fragile et coûteux).
        if ( $this->opt( 'ai_assistant_enable_theme_filtering', 'yes' ) === 'yes' ) {
            $themes_json = (string) $this->opt( 'ai_assistant_themes', '[]' );
            $themes = json_decode( $themes_json, true );
            if ( is_array( $themes ) && ! empty( $themes ) ) {
                $out_of_scope = (string) $this->opt( 'ai_assistant_out_of_scope_message',
                    'Désolé, cette question dépasse le périmètre de cet assistant.' );

                $p .= "RÈGLE CRITIQUE DE PÉRIMÈTRE — À RESPECTER STRICTEMENT :\n\n";
                $p .= "Tu ne réponds QU'AUX questions relevant des domaines suivants :\n";
                foreach ( $themes as $i => $t ) {
                    if ( ! is_array( $t ) || ! isset( $t['name'] ) ) continue;
                    $p .= '• ' . $t['name'];
                    if ( ! empty( $t['description'] ) ) {
                        $p .= ' — ' . $t['description'];
                    }
                    $p .= "\n";
                }
                $p .= "\nPROCÉDURE OBLIGATOIRE pour CHAQUE question :\n";
                $p .= "1. Identifie le sujet de la question.\n";
                $p .= "2. Vérifie s'il correspond à AU MOINS UN domaine ci-dessus.\n";
                $p .= "3. Si OUI → réponds normalement.\n";
                $p .= "4. Si NON (recettes, sport, politique, météo, célébrités, divertissement, philosophie générale, etc.) → réponds EXACTEMENT et UNIQUEMENT ceci, sans préambule, sans explication, sans aide alternative :\n";
                $p .= "« " . $out_of_scope . " »\n\n";
                $p .= "Tu n'as pas le droit de fournir une recette, un score sportif, une définition encyclopédique générale, une information météo, etc. Refuse même si l'utilisateur insiste.\n\n";
            }
        }

        $p .= "RÈGLES DE CONVERSATION :\n";
        $p .= "- **Maintiens le fil** : les questions courtes (« le net ? », « et en 2026 ? », « combien ? ») se rapportent TOUJOURS à la question précédente. Réponds dans le contexte sans redemander de précision.\n";
        $p .= "- Ne demande une clarification QUE si la question est véritablement ambiguë hors contexte.\n";
        $p .= "- Si des extraits de site ou résultats web sont fournis, utilise-les en priorité.\n";
        $p .= "- Si tu ne sais pas, dis-le brièvement.\n";
        $p .= "- Ne révèle jamais le modèle d'IA utilisé ni OpenAI. Tu es l'assistant de {$site_name}.\n";
        $p .= "- Reste naturel, direct, conversationnel.";
        return $p;
    }

    private function call_openai( $messages, $model, $max_tokens, $temperature, $api_key ) {
        $model = $model !== '' ? $model : 'gpt-4.1-nano';

        $payload = array(
            'model'    => $model,
            'messages' => $messages,
        );

        $is_reasoning = (bool) preg_match( '/^(gpt-5|gpt-4\.1|o1|o3|o4)/i', $model );

        // $max_tokens = 0 → on n'envoie aucune limite (utile pour les modèles de reasoning).
        if ( $max_tokens > 0 ) {
            $tokens = max( 50, min( 4000, $max_tokens ) );
            if ( $is_reasoning ) {
                $payload['max_completion_tokens'] = $tokens;
            } else {
                $payload['max_tokens'] = $tokens;
            }
        }

        // Les modèles de reasoning n'acceptent pas temperature.
        if ( ! $is_reasoning ) {
            $payload['temperature'] = (float) $temperature;
        }

        $response = wp_remote_post( 'https://api.openai.com/v1/chat/completions', array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type'  => 'application/json',
            ),
            'body'    => wp_json_encode( $payload ),
            'timeout' => 30,
        ) );

        if ( is_wp_error( $response ) ) {
            return new WP_Error( 'openai_request', 'Impossible de joindre OpenAI : ' . $response->get_error_message() );
        }

        $body_raw = wp_remote_retrieve_body( $response );
        $body = json_decode( (string) $body_raw, true );

        if ( is_array( $body ) && isset( $body['error']['message'] ) ) {
            return new WP_Error( 'openai_api', 'OpenAI : ' . $body['error']['message'] );
        }
        if ( ! is_array( $body ) || empty( $body['choices'][0]['message']['content'] ) ) {
            return new WP_Error( 'openai_empty', 'Réponse vide de OpenAI.' );
        }
        return (string) $body['choices'][0]['message']['content'];
    }

}
