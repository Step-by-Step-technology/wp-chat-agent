<?php
/**
 * Gestion des réglages de l'Assistant IA (interface en français, config-driven).
 *
 * @package AI_Assistant
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class AI_Assistant_Settings {

    const OPTION_GROUP = 'ai_assistant_settings';
    const PAGE_SLUG    = 'ai-assistant-settings';

    private static $instance = null;

    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        // Ne registre les settings QUE sur de vraies pages admin, jamais pendant AJAX/CRON/REST.
        // Évite tout effet de bord pendant les requêtes AJAX qui n'ont pas besoin de ces hooks.
        if ( $this->is_real_admin_request() ) {
            add_action( 'admin_init', array( $this, 'register_settings' ) );
            add_action( 'admin_menu', array( $this, 'add_settings_page' ) );
            add_action( 'admin_enqueue_scripts', array( $this, 'admin_enqueue_scripts' ) );
        }
        // Bouton de test OpenAI (AJAX admin seulement, utilisateur authentifié admin).
        add_action( 'wp_ajax_ai_assistant_test_openai', array( $this, 'ajax_test_openai' ) );
        add_action( 'wp_ajax_ai_assistant_reset_rate_limit', array( $this, 'ajax_reset_rate_limit' ) );
        add_action( 'wp_ajax_ai_assistant_rag_index_batch', array( $this, 'ajax_rag_index_batch' ) );
        add_action( 'wp_ajax_ai_assistant_rag_clear', array( $this, 'ajax_rag_clear' ) );
        add_action( 'wp_ajax_ai_assistant_rag_delete_post', array( $this, 'ajax_rag_delete_post' ) );
        add_action( 'wp_ajax_ai_assistant_rag_reindex_post', array( $this, 'ajax_rag_reindex_post' ) );
        add_action( 'admin_post_ai_assistant_export_logs', array( $this, 'handle_export_logs' ) );
    }

    /**
     * Export CSV des logs (POST admin standard, pas AJAX, pour déclencher le download).
     */
    public function handle_export_logs() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Accès refusé.', 403 );
        }
        check_admin_referer( 'ai_assistant_export_logs' );
        if ( ! class_exists( 'AI_Assistant_Logger' ) ) {
            wp_die( 'Module journalisation indisponible.' );
        }
        $search = isset( $_POST['s'] ) ? sanitize_text_field( wp_unslash( $_POST['s'] ) ) : '';
        $ip     = isset( $_POST['ip'] ) ? sanitize_text_field( wp_unslash( $_POST['ip'] ) ) : '';
        AI_Assistant_Logger::stream_csv( $search, $ip );
    }

    private function is_real_admin_request() {
        if ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() ) return false;
        if ( function_exists( 'wp_doing_cron' ) && wp_doing_cron() ) return false;
        if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) return false;
        if ( defined( 'WP_CLI' ) && WP_CLI ) return false;
        return is_admin();
    }

    /**
     * Schéma de tous les réglages — source unique de vérité.
     * Utilisé pour : register_setting, add_settings_field, rendu, et lecture.
     */
    public static function schema() {
        return array(

            // ───────── API & modèles ─────────
            'api' => array(
                'title'  => 'Clés API et modèles',
                'fields' => array(
                    'ai_assistant_api_key' => array(
                        'label'       => 'Clé API OpenAI',
                        'type'        => 'password',
                        'default'     => '',
                        'sanitize'    => 'sanitize_text_field',
                        'description' => 'Obtenez une clé sur <a href="https://platform.openai.com/api-keys" target="_blank">platform.openai.com</a>.',
                    ),
                    'ai_assistant_main_model' => array(
                        'label'       => 'Modèle principal',
                        'type'        => 'select',
                        'default'     => 'gpt-4.1-nano',
                        'sanitize'    => 'sanitize_text_field',
                        'options'     => array(
                            'gpt-5.4-nano' => 'GPT-5.4 Nano',
                            'gpt-5-nano'   => 'GPT-5 Nano',
                            'gpt-4.1-nano' => 'GPT-4.1 Nano',
                        ),
                        'description' => 'Modèle OpenAI utilisé pour générer les réponses.',
                    ),
                    'ai_assistant_max_tokens' => array(
                        'label'       => 'Longueur max. des réponses (tokens)',
                        'type'        => 'number',
                        'default'     => 400,
                        'sanitize'    => 'absint',
                        'min'         => 50,
                        'max'         => 2000,
                        'description' => 'Plus élevé = réponses plus longues (et coût plus élevé).',
                    ),
                    'ai_assistant_brave_api_key' => array(
                        'label'       => 'Clé API Brave Search',
                        'type'        => 'password',
                        'default'     => '',
                        'sanitize'    => 'sanitize_text_field',
                        'description' => 'Optionnel. <a href="https://brave.com/search/api/" target="_blank">Obtenir une clé</a>.',
                    ),
                    'ai_assistant_enable_web_search' => array(
                        'label'       => 'Activer la recherche web',
                        'type'        => 'bool',
                        'default'     => 'no',
                        'sanitize'    => array( __CLASS__, 'sanitize_bool' ),
                        'description' => 'Utilise Brave Search pour les questions nécessitant des infos récentes.',
                    ),
                ),
            ),

            // ───────── Apparence ─────────
            'appearance' => array(
                'title'  => 'Apparence et identité',
                'fields' => array(
                    'ai_assistant_site_name' => array(
                        'label'       => 'Nom de l\'assistant',
                        'type'        => 'text',
                        'default'     => '',
                        'sanitize'    => 'sanitize_text_field',
                        'placeholder' => 'Mon site',
                        'description' => 'Affiché dans l\'en-tête et le message de bienvenue. Laisser vide utilise « Assistant » par défaut.',
                    ),
                    'ai_assistant_welcome_message' => array(
                        'label'       => 'Message de bienvenue',
                        'type'        => 'textarea',
                        'default'     => 'Bonjour ! Je suis votre assistant IA. Posez-moi vos questions.',
                        'sanitize'    => 'sanitize_textarea_field',
                        'rows'        => 3,
                        'description' => 'Premier message affiché à l\'ouverture du chat.',
                    ),
                    'ai_assistant_input_placeholder' => array(
                        'label'       => 'Placeholder du champ de saisie',
                        'type'        => 'text',
                        'default'     => 'Tapez votre question ici…',
                        'sanitize'    => 'sanitize_text_field',
                    ),
                    'ai_assistant_primary_color' => array(
                        'label'       => 'Couleur principale',
                        'type'        => 'color',
                        'default'     => '#10b981',
                        'sanitize'    => 'sanitize_hex_color',
                        'description' => 'Couleur de la bulle, de l\'en-tête et des boutons.',
                    ),
                    'ai_assistant_chat_width' => array(
                        'label'       => 'Largeur de la fenêtre de chat (px)',
                        'type'        => 'number',
                        'default'     => 460,
                        'sanitize'    => 'absint',
                        'min'         => 300,
                        'max'         => 800,
                        'description' => 'Largeur en pixels sur desktop (300–800 px). Par défaut : 460.',
                    ),
                    'ai_assistant_chat_height' => array(
                        'label'       => 'Hauteur de la fenêtre de chat (px)',
                        'type'        => 'number',
                        'default'     => 700,
                        'sanitize'    => 'absint',
                        'min'         => 400,
                        'max'         => 900,
                        'description' => 'Hauteur en pixels sur desktop (400–900 px). Par défaut : 700.',
                    ),
                    'ai_assistant_custom_icon' => array(
                        'label'       => 'Icône IA personnalisée',
                        'type'        => 'media',
                        'default'     => '',
                        'sanitize'    => array( __CLASS__, 'sanitize_icon_url' ),
                        'description' => 'Choisissez une icône dans la bibliothèque ou importez un PNG 256×256 px. Laissez vide pour l\'icône par défaut.',
                    ),
                ),
            ),

            // ───────── Contact ─────────
            'contact' => array(
                'title'  => 'Boutons de contact',
                'fields' => array(
                    'ai_assistant_phone_number' => array(
                        'label'       => 'Numéro de téléphone',
                        'type'        => 'text',
                        'default'     => '',
                        'sanitize'    => 'sanitize_text_field',
                        'placeholder' => '+33123456789',
                        'description' => 'Format international (ex: +33123456789). Laisser vide pour masquer le bouton téléphone.',
                    ),
                    'ai_assistant_contact_page_url' => array(
                        'label'       => 'URL de la page contact',
                        'type'        => 'url',
                        'default'     => '',
                        'sanitize'    => 'esc_url_raw',
                        'placeholder' => 'https://exemple.com/contact/',
                        'description' => 'Laisser vide pour masquer le bouton email/contact.',
                    ),
                    'ai_assistant_show_credit' => array(
                        'label'       => 'Afficher le crédit auteur',
                        'type'        => 'bool',
                        'default'     => 'yes',
                        'sanitize'    => array( __CLASS__, 'sanitize_bool' ),
                        'description' => 'Affiche « développement Step by Step » dans le footer du chat. Décochable.',
                    ),
                    'ai_assistant_contact_word_limit' => array(
                        'label'       => 'Seuil d\'affichage des boutons contact',
                        'type'        => 'number',
                        'default'     => 50,
                        'sanitize'    => 'absint',
                        'min'         => 0,
                        'max'         => 1000,
                        'description' => 'Nombre minimum de mots dans une réponse pour afficher les boutons contact. 0 = toujours afficher.',
                    ),
                ),
            ),

            // ───────── Persistance du chat ─────────
            'persistence' => array(
                'title'  => 'Persistance de la conversation',
                'fields' => array(
                    'ai_assistant_chat_persistence' => array(
                        'label'       => 'Mode de persistance',
                        'type'        => 'select',
                        'default'     => 'session',
                        'sanitize'    => 'sanitize_text_field',
                        'options'     => array(
                            'session' => 'Session (conservée tant que l\'onglet reste ouvert, survit aux changements de page)',
                            'local'   => 'Local (conservée plusieurs jours même après fermeture du navigateur)',
                            'none'    => 'Désactivée (le chat se vide à chaque rechargement)',
                        ),
                        'description' => 'Recommandé : « Session ». Évite la perte du chat lors de la navigation.',
                    ),
                    'ai_assistant_chat_persistence_ttl' => array(
                        'label'       => 'Durée de conservation (minutes, mode Local)',
                        'type'        => 'number',
                        'default'     => 1440,
                        'sanitize'    => 'absint',
                        'min'         => 5,
                        'max'         => 43200,
                        'description' => 'Uniquement pour le mode « Local ». 1440 = 24h, 10080 = 7 jours.',
                    ),
                    'ai_assistant_max_history' => array(
                        'label'       => 'Nombre max. de messages en contexte',
                        'type'        => 'number',
                        'default'     => 10,
                        'sanitize'    => 'absint',
                        'min'         => 2,
                        'max'         => 50,
                        'description' => 'Messages envoyés à OpenAI pour maintenir le contexte.',
                    ),
                ),
            ),

            // ───────── Filtrage et thèmes ─────────
            'scope' => array(
                'title'  => 'Filtrage par thèmes',
                'fields' => array(
                    'ai_assistant_enable_theme_filtering' => array(
                        'label'       => 'Activer le filtrage par thèmes',
                        'type'        => 'bool',
                        'default'     => 'yes',
                        'sanitize'    => array( __CLASS__, 'sanitize_bool' ),
                        'description' => 'Si activé, l\'IA ne répond qu\'aux questions liées aux thèmes ci-dessous.',
                    ),
                    'ai_assistant_themes' => array(
                        'label'       => 'Liste des thèmes',
                        'type'        => 'themes',
                        'default'     => '[]',
                        'sanitize'    => array( __CLASS__, 'sanitize_themes' ),
                        'description' => 'Un thème par ligne. Format : <code>Nom du thème</code> ou <code>Nom : Description</code>.',
                    ),
                    'ai_assistant_out_of_scope_message' => array(
                        'label'       => 'Message « hors périmètre »',
                        'type'        => 'textarea',
                        'default'     => 'Désolé, cette question dépasse le périmètre de cet assistant. Comment puis-je vous aider avec nos services ?',
                        'sanitize'    => 'sanitize_textarea_field',
                        'rows'        => 4,
                        'description' => 'Affiché lorsque la réponse ne correspond à aucun thème configuré.',
                    ),
                ),
            ),

            // ───────── RAG (connaissance du site) ─────────
            'rag' => array(
                'title'  => 'RAG — Connaissance du site',
                'fields' => array(
                    'ai_assistant_enable_rag' => array(
                        'label'       => 'Activer le RAG',
                        'type'        => 'bool',
                        'default'     => 'no',
                        'sanitize'    => array( __CLASS__, 'sanitize_bool' ),
                        'description' => 'Lorsqu\'activé, l\'assistant s\'appuie sur le contenu indexé de votre site pour répondre avec précision. Nécessite d\'indexer le contenu depuis <strong>Assistant IA → Indexation RAG</strong>.',
                    ),
                    'ai_assistant_rag_embedding_model' => array(
                        'label'       => 'Modèle d\'embedding',
                        'type'        => 'select',
                        'default'     => 'text-embedding-3-small',
                        'sanitize'    => 'sanitize_text_field',
                        'options'     => array(
                            'text-embedding-3-small' => 'text-embedding-3-small (rapide, économique — recommandé)',
                            'text-embedding-3-large' => 'text-embedding-3-large (plus précis, plus coûteux)',
                        ),
                        'description' => 'Modèle OpenAI utilisé pour vectoriser le contenu. Les réponses du chat utilisent le modèle défini dans « Modèle principal ».',
                    ),
                    'ai_assistant_rag_post_types' => array(
                        'label'       => 'Types de contenu à indexer',
                        'type'        => 'text',
                        'default'     => 'post,page',
                        'sanitize'    => 'sanitize_text_field',
                        'placeholder' => 'post,page',
                        'description' => 'Liste séparée par des virgules (ex : <code>post,page,product</code>).',
                    ),
                    'ai_assistant_rag_chunk_size' => array(
                        'label'       => 'Taille d\'un chunk (caractères)',
                        'type'        => 'number',
                        'default'     => 2000,
                        'sanitize'    => 'absint',
                        'min'         => 500,
                        'max'         => 8000,
                        'description' => 'Longueur max. d\'un morceau de texte indexé. 2000 = équilibre précision / coût.',
                    ),
                    'ai_assistant_rag_max_chunks' => array(
                        'label'       => 'Nombre de chunks injectés par question',
                        'type'        => 'number',
                        'default'     => 5,
                        'sanitize'    => 'absint',
                        'min'         => 1,
                        'max'         => 20,
                        'description' => 'Les N meilleurs morceaux les plus proches de la question sont passés à l\'IA.',
                    ),
                ),
            ),

            // ───────── Recherche web ─────────
            'websearch' => array(
                'title'  => 'Mots-clés de recherche web',
                'fields' => array(
                    'ai_assistant_web_search_keywords' => array(
                        'label'       => 'Mots-clés déclencheurs',
                        'type'        => 'textarea',
                        'default'     => "actualité\nnews\nnouveau\nrécent\naujourd'hui\nhier\ndemain\n2025\n2026\n2027\ndernier\ndernière\nmise à jour\ntendance\nprix\ntarif",
                        'sanitize'    => array( __CLASS__, 'sanitize_keywords' ),
                        'rows'        => 10,
                        'description' => 'Un mot-clé par ligne. Déclenche une recherche Brave si trouvé dans la question.',
                    ),
                ),
            ),

            // ───────── Sécurité & debug ─────────
            'advanced' => array(
                'title'  => 'Sécurité et débogage',
                'fields' => array(
                    'ai_assistant_max_requests_per_hour' => array(
                        'label'       => 'Requêtes max. par heure / utilisateur',
                        'type'        => 'number',
                        'default'     => 10,
                        'sanitize'    => 'absint',
                        'min'         => 0,
                        'max'         => 1000,
                        'description' => 'Protection anti-abus. 0 = illimité.',
                    ),
                    'ai_assistant_enable_debug' => array(
                        'label'       => 'Mode debug',
                        'type'        => 'bool',
                        'default'     => 'no',
                        'sanitize'    => array( __CLASS__, 'sanitize_bool' ),
                        'description' => 'Affiche les logs dans le chat et la console. À désactiver en production.',
                    ),
                    'ai_assistant_enable_chat_log' => array(
                        'label'       => 'Journaliser les conversations',
                        'type'        => 'bool',
                        'default'     => 'no',
                        'sanitize'    => array( __CLASS__, 'sanitize_bool' ),
                        'description' => 'Enregistre chaque échange (question/réponse + IP + horodatage) dans un fichier .log journalier. <strong>Attention RGPD</strong> : informez vos utilisateurs dans votre politique de confidentialité.',
                    ),
                    'ai_assistant_log_retention_days' => array(
                        'label'       => 'Rétention des logs (jours)',
                        'type'        => 'number',
                        'default'     => 30,
                        'sanitize'    => 'absint',
                        'min'         => 1,
                        'max'         => 365,
                        'description' => 'Durée de conservation des conversations en base. Au-delà, les entrées sont supprimées automatiquement (tâche planifiée quotidienne).',
                    ),
                ),
            ),
        );
    }

    /**
     * Récupère une option en utilisant le défaut du schéma.
     */
    public static function get( $key ) {
        foreach ( self::schema() as $section ) {
            if ( isset( $section['fields'][ $key ] ) ) {
                return get_option( $key, $section['fields'][ $key ]['default'] );
            }
        }
        return get_option( $key );
    }

    public function register_settings() {
        // CRITIQUE : on enregistre chaque onglet dans SON PROPRE option_group.
        // Sinon l'enregistrement d'un onglet écrase à vide les champs des autres onglets
        // (bug classique de la Settings API de WordPress).
        foreach ( self::schema() as $section_id => $section ) {
            $page  = self::PAGE_SLUG . '_tab_' . $section_id;
            $group = self::OPTION_GROUP . '_' . $section_id;

            add_settings_section(
                'ai_assistant_section_' . $section_id,
                '',
                '__return_false',
                $page
            );

            foreach ( $section['fields'] as $key => $field ) {
                register_setting(
                    $group,
                    $key,
                    array(
                        'sanitize_callback' => $field['sanitize'],
                        'default'           => $field['default'],
                    )
                );

                add_settings_field(
                    $key,
                    $field['label'],
                    array( $this, 'render_field' ),
                    $page,
                    'ai_assistant_section_' . $section_id,
                    array( 'key' => $key, 'field' => $field )
                );
            }
        }
    }

    /**
     * Retourne l'option_group utilisé pour un onglet donné.
     */
    private function group_for_tab( $tab ) {
        return self::OPTION_GROUP . '_' . $tab;
    }

    public function render_field( $args ) {
        $key   = $args['key'];
        $field = $args['field'];
        $value = get_option( $key, $field['default'] );
        $placeholder = isset( $field['placeholder'] ) ? $field['placeholder'] : '';

        switch ( $field['type'] ) {
            case 'password':
                printf(
                    '<input type="password" name="%s" value="%s" class="regular-text" autocomplete="off" />',
                    esc_attr( $key ),
                    esc_attr( $value )
                );
                break;

            case 'text':
            case 'url':
                printf(
                    '<input type="text" name="%s" value="%s" class="regular-text" placeholder="%s" />',
                    esc_attr( $key ),
                    esc_attr( $value ),
                    esc_attr( $placeholder )
                );
                break;

            case 'number':
                printf(
                    '<input type="number" name="%s" value="%s" class="small-text" min="%s" max="%s" />',
                    esc_attr( $key ),
                    esc_attr( $value ),
                    esc_attr( $field['min'] ?? '' ),
                    esc_attr( $field['max'] ?? '' )
                );
                break;

            case 'color':
                printf(
                    '<input type="text" name="%s" value="%s" class="ai-assistant-color-picker" data-default-color="%s" />',
                    esc_attr( $key ),
                    esc_attr( $value ),
                    esc_attr( $field['default'] )
                );
                break;

            case 'bool':
                $checked = ( $value === 'yes' ) ? 'checked' : '';
                // Hidden "no" d'abord : si la case est décochée, seul "no" est envoyé.
                // Si elle est cochée, les deux sont envoyés et PHP garde le dernier (yes).
                printf( '<input type="hidden" name="%s" value="no" />', esc_attr( $key ) );
                printf(
                    '<label><input type="checkbox" name="%s" value="yes" %s /> Activer</label>',
                    esc_attr( $key ),
                    $checked
                );
                break;

            case 'select':
                printf( '<select name="%s">', esc_attr( $key ) );
                foreach ( $field['options'] as $opt_val => $opt_label ) {
                    printf(
                        '<option value="%s" %s>%s</option>',
                        esc_attr( $opt_val ),
                        selected( $value, $opt_val, false ),
                        esc_html( $opt_label )
                    );
                }
                echo '</select>';
                break;

            case 'textarea':
                $rows = $field['rows'] ?? 5;
                printf(
                    '<textarea name="%s" rows="%d" class="large-text code">%s</textarea>',
                    esc_attr( $key ),
                    intval( $rows ),
                    esc_textarea( $value )
                );
                break;

            case 'media':
                $icons = self::get_icon_library();
                $lib_selected = '';
                foreach ( $icons as $icon_key => $icon ) {
                    if ( $value === 'data:image/svg+xml;base64,' . base64_encode( $icon['svg'] ) ) {
                        $lib_selected = $icon_key;
                        break;
                    }
                }
                $is_custom_upload = $value && ! $lib_selected;
                $default_icon_url = AI_ASSISTANT_URL . 'assets/generative.png';
                $default_sel      = ( ! $value ) ? ' ai-icon-selected' : '';
                ?>
                <div class="ai-icon-field">
                    <p class="ai-icon-section-title"><strong>Bibliothèque d'icônes</strong></p>
                    <div class="ai-icon-grid">
                        <button type="button"
                                class="ai-icon-btn<?php echo $default_sel; ?>"
                                data-uri=""
                                data-target="<?php echo esc_attr( $key ); ?>"
                                title="Icône par défaut">
                            <img src="<?php echo esc_url( $default_icon_url ); ?>" alt="Défaut" />
                            <span>Défaut</span>
                        </button>
                        <?php foreach ( $icons as $icon_key => $icon ) :
                            $uri = 'data:image/svg+xml;base64,' . base64_encode( $icon['svg'] );
                            $sel = ( $lib_selected === $icon_key ) ? ' ai-icon-selected' : '';
                        ?>
                        <button type="button"
                                class="ai-icon-btn<?php echo $sel; ?>"
                                data-uri="<?php echo esc_attr( $uri ); ?>"
                                data-target="<?php echo esc_attr( $key ); ?>"
                                title="<?php echo esc_attr( $icon['label'] ); ?>">
                            <img src="<?php echo esc_attr( $uri ); ?>" alt="<?php echo esc_attr( $icon['label'] ); ?>" />
                            <span><?php echo esc_html( $icon['label'] ); ?></span>
                        </button>
                        <?php endforeach; ?>
                    </div>
                    <div class="ai-icon-or"><span>ou importer une image personnalisée (PNG 256×256 px)</span></div>
                    <div class="ai-icon-upload-row">
                        <img class="ai-icon-upload-preview"
                             src="<?php echo esc_attr( $is_custom_upload ? $value : '' ); ?>"
                             <?php echo $is_custom_upload ? '' : 'style="display:none;"'; ?>
                             alt="" />
                        <button type="button" class="button ai-icon-upload-btn"
                                data-target="<?php echo esc_attr( $key ); ?>">
                            <?php echo $is_custom_upload ? 'Changer l\'image' : 'Choisir une image'; ?>
                        </button>
                        <button type="button" class="button ai-icon-clear-upload"
                                data-target="<?php echo esc_attr( $key ); ?>"
                                <?php echo $is_custom_upload ? '' : 'style="display:none;"'; ?>>
                            Supprimer l'image
                        </button>
                    </div>
                    <input type="hidden"
                           name="<?php echo esc_attr( $key ); ?>"
                           id="<?php echo esc_attr( $key ); ?>"
                           value="<?php echo esc_attr( $value ); ?>" />
                </div>
                <?php
                break;

            case 'themes':
                $this->render_themes_field( $key, $value );
                break;
        }

        if ( ! empty( $field['description'] ) ) {
            printf( '<p class="description">%s</p>', wp_kses_post( $field['description'] ) );
        }
    }

    private function render_themes_field( $key, $value ) {
        $themes = json_decode( $value, true );
        if ( ! is_array( $themes ) ) {
            $themes = array();
        }

        $text_value = '';
        foreach ( $themes as $theme ) {
            if ( ! isset( $theme['name'] ) ) continue;
            if ( ! empty( $theme['description'] ) ) {
                $text_value .= $theme['name'] . ' : ' . $theme['description'] . "\n";
            } else {
                $text_value .= $theme['name'] . "\n";
            }
        }

        printf(
            '<textarea name="%s" rows="8" class="large-text code" placeholder="%s">%s</textarea>',
            esc_attr( $key ),
            esc_attr( "Création d'entreprise : Aide aux formalités\nRessources humaines" ),
            esc_textarea( $text_value )
        );
    }

    public function add_settings_page() {
        // Menu principal
        add_menu_page(
            'Assistant IA',
            'Assistant IA',
            'manage_options',
            self::PAGE_SLUG,
            array( $this, 'render_settings_page' ),
            'dashicons-format-chat',
            85
        );
        add_submenu_page(
            self::PAGE_SLUG,
            'Réglages',
            'Réglages',
            'manage_options',
            self::PAGE_SLUG,
            array( $this, 'render_settings_page' )
        );
        add_submenu_page(
            self::PAGE_SLUG,
            'Journal des conversations',
            'Journal',
            'manage_options',
            self::PAGE_SLUG . '-logs',
            array( $this, 'render_logs_page' )
        );
        add_submenu_page(
            self::PAGE_SLUG,
            'Indexation RAG',
            'Indexation RAG',
            'manage_options',
            self::PAGE_SLUG . '-rag',
            array( $this, 'render_rag_page' )
        );
    }

    public function render_rag_page() {
        if ( ! current_user_can( 'manage_options' ) ) return;
        if ( ! class_exists( 'AI_Assistant_RAG' ) ) {
            echo '<div class="wrap"><h1>Indexation RAG</h1><p>Module RAG non chargé.</p></div>';
            return;
        }
        AI_Assistant_RAG::maybe_install();

        $stats      = AI_Assistant_RAG::stats();
        $enabled    = ( get_option( 'ai_assistant_enable_rag', 'no' ) === 'yes' );
        $api_key    = (string) get_option( 'ai_assistant_api_key', '' );
        $post_types = AI_Assistant_RAG::allowed_post_types();
        $nonce      = wp_create_nonce( 'ai_assistant_rag' );
        ?>
        <div class="wrap">
            <h1>Indexation RAG</h1>

            <?php if ( $api_key === '' ) : ?>
                <div class="notice notice-error"><p>
                    Aucune clé API OpenAI configurée. Allez dans
                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE_SLUG ) ); ?>">Réglages</a>
                    avant d'indexer.
                </p></div>
            <?php endif; ?>

            <?php if ( ! $enabled ) : ?>
                <div class="notice notice-warning"><p>
                    Le RAG est <strong>désactivé</strong>. Vous pouvez indexer sans problème, mais les réponses du chat n'utiliseront pas l'index tant que vous n'aurez pas activé le RAG dans
                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE_SLUG ) ); ?>">Réglages → RAG</a>.
                </p></div>
            <?php endif; ?>

            <div style="background:#fff; border:1px solid #c3c4c7; padding:15px 20px; margin:15px 0; border-radius:4px;">
                <h2 style="margin-top:0;">État de l'index</h2>
                <ul style="margin:0;">
                    <li><strong>Articles/pages indexés :</strong> <?php echo (int) $stats['posts']; ?></li>
                    <li><strong>Morceaux (chunks) stockés :</strong> <?php echo (int) $stats['chunks']; ?></li>
                    <li><strong>Dernière indexation :</strong> <?php echo $stats['last_index'] ? esc_html( $stats['last_index'] ) : '<em>jamais</em>'; ?></li>
                    <li><strong>Types de contenu configurés :</strong> <code><?php echo esc_html( implode( ', ', $post_types ) ); ?></code></li>
                </ul>
            </div>

            <?php
            // Liste des posts indexés (paginée).
            $list_page = isset( $_GET['ipage'] ) ? max( 1, (int) $_GET['ipage'] ) : 1;
            $list = AI_Assistant_RAG::list_indexed( $list_page, 50 );
            $list_total_pages = max( 1, (int) ceil( $list['total'] / 50 ) );
            ?>
            <div style="background:#fff; border:1px solid #c3c4c7; padding:15px 20px; margin:15px 0; border-radius:4px;">
                <h2 style="margin-top:0;">Contenu indexé (<?php echo (int) $list['total']; ?>)</h2>
                <?php if ( empty( $list['items'] ) ) : ?>
                    <p><em>Aucun contenu indexé pour le moment.</em></p>
                <?php else : ?>
                <table class="widefat striped">
                    <thead>
                        <tr>
                            <th>Titre</th>
                            <th style="width:70px;">Type</th>
                            <th style="width:60px;">Chunks</th>
                            <th style="width:140px;">Indexé le</th>
                            <th style="width:200px;">Actions</th>
                        </tr>
                    </thead>
                    <tbody id="ai-rag-list-body">
                        <?php foreach ( $list['items'] as $it ) : ?>
                            <tr data-post-id="<?php echo (int) $it['post_id']; ?>">
                                <td>
                                    <?php if ( $it['exists'] && $it['permalink'] ) : ?>
                                        <a href="<?php echo esc_url( $it['permalink'] ); ?>" target="_blank"><?php echo esc_html( $it['title'] ); ?></a>
                                    <?php else : ?>
                                        <em><?php echo esc_html( $it['title'] ); ?></em>
                                    <?php endif; ?>
                                    <?php if ( $it['edit_link'] ) : ?>
                                        <a href="<?php echo esc_url( $it['edit_link'] ); ?>" class="row-actions" style="margin-left:8px;">éditer</a>
                                    <?php endif; ?>
                                </td>
                                <td><code><?php echo esc_html( $it['post_type'] ); ?></code></td>
                                <td><?php echo (int) $it['chunks']; ?></td>
                                <td><?php echo esc_html( $it['indexed_at'] ); ?></td>
                                <td>
                                    <button type="button" class="button button-small ai-rag-reindex" data-id="<?php echo (int) $it['post_id']; ?>">Ré-indexer</button>
                                    <button type="button" class="button button-small button-link-delete ai-rag-delete" data-id="<?php echo (int) $it['post_id']; ?>">Retirer</button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <?php if ( $list_total_pages > 1 ) :
                    $base = add_query_arg( array( 'page' => self::PAGE_SLUG . '-rag' ), admin_url( 'admin.php' ) );
                ?>
                    <div class="tablenav" style="margin-top:10px;"><div class="tablenav-pages">
                        <?php echo paginate_links( array(
                            'base'      => add_query_arg( 'ipage', '%#%', $base ),
                            'format'    => '',
                            'current'   => $list_page,
                            'total'     => $list_total_pages,
                            'prev_text' => '‹',
                            'next_text' => '›',
                        ) ); ?>
                    </div></div>
                <?php endif; ?>
                <?php endif; ?>
            </div>

            <div style="background:#fff; border:1px solid #c3c4c7; padding:15px 20px; margin:15px 0; border-radius:4px;">
                <h2 style="margin-top:0;">Actions</h2>
                <p>
                    <button type="button" id="ai-rag-index-btn" class="button button-primary" <?php echo $api_key === '' ? 'disabled' : ''; ?>>Indexer tout le site maintenant</button>
                    <button type="button" id="ai-rag-clear-btn" class="button">Vider l'index</button>
                </p>
                <div id="ai-rag-progress" style="margin-top:15px;"></div>
                <p class="description" style="margin-top:15px;">
                    L'indexation consomme votre quota OpenAI (API embeddings). Coût approximatif :
                    <strong>~0,02 $ pour 100 pages de contenu moyen</strong> avec <code>text-embedding-3-small</code>.
                    Une fois activé, chaque modification d'article est re-indexée automatiquement.
                </p>
            </div>
        </div>

        <script>
        jQuery(function($){
            var nonce = '<?php echo esc_js( $nonce ); ?>';
            var $progress = $('#ai-rag-progress');

            $('#ai-rag-index-btn').on('click', function(){
                if (!confirm('Lancer l\'indexation complète du site ? Cela peut prendre quelques minutes.')) return;
                var $btn = $(this);
                $btn.prop('disabled', true);
                $('#ai-rag-clear-btn').prop('disabled', true);
                $progress.html('<p>Indexation en cours…</p>');
                runBatch(0, 0, 0);
            });

            function runBatch(offset, indexedTotal, chunksTotal) {
                $.ajax({
                    url: ajaxurl,
                    method: 'POST',
                    dataType: 'json',
                    data: {
                        action: 'ai_assistant_rag_index_batch',
                        _wpnonce: nonce,
                        offset: offset,
                        batch: 3
                    }
                }).done(function(resp){
                    if (!resp || !resp.success) {
                        var m = (resp && resp.data && resp.data.message) || 'Erreur inconnue';
                        $progress.html('<p class="notice notice-error" style="padding:10px;">Échec : ' + $('<div>').text(m).html() + '</p>');
                        $('#ai-rag-index-btn, #ai-rag-clear-btn').prop('disabled', false);
                        return;
                    }
                    indexedTotal += resp.data.indexed;
                    chunksTotal  += resp.data.chunks;
                    var total    = resp.data.total;
                    var nextOff  = resp.data.next_offset;
                    var pct      = total > 0 ? Math.round((nextOff / total) * 100) : 100;

                    $progress.html(
                        '<p>Progression : <strong>' + pct + '%</strong> — ' +
                        indexedTotal + ' / ' + total + ' articles, ' +
                        chunksTotal + ' chunks créés</p>' +
                        '<div style="background:#eee; height:10px; border-radius:5px; overflow:hidden;">' +
                        '<div style="background:#2271b1; height:100%; width:' + pct + '%;"></div></div>'
                    );

                    if (nextOff >= total) {
                        $progress.append('<p style="color:#008a20;"><strong>✓ Indexation terminée.</strong></p>');
                        $('#ai-rag-index-btn, #ai-rag-clear-btn').prop('disabled', false);
                        setTimeout(function(){ location.reload(); }, 1500);
                    } else {
                        runBatch(nextOff, indexedTotal, chunksTotal);
                    }
                }).fail(function(xhr){
                    var raw = (xhr && xhr.responseText) || '';
                    $progress.html('<p class="notice notice-error" style="padding:10px;">Échec réseau : ' + $('<div>').text(raw.substring(0,500)).html() + '</p>');
                    $('#ai-rag-index-btn, #ai-rag-clear-btn').prop('disabled', false);
                });
            }

            // Suppression d'un post de l'index.
            $(document).on('click', '.ai-rag-delete', function(){
                var $btn = $(this);
                var pid = $btn.data('id');
                if (!confirm('Retirer ce contenu de l\'index ? (le post lui-même n\'est PAS supprimé)')) return;
                $btn.prop('disabled', true);
                $.post(ajaxurl, {
                    action: 'ai_assistant_rag_delete_post',
                    _wpnonce: nonce,
                    post_id: pid
                }, null, 'json').done(function(resp){
                    if (resp && resp.success) {
                        $btn.closest('tr').fadeOut(200, function(){ $(this).remove(); });
                    } else {
                        alert('Erreur : ' + ((resp && resp.data && resp.data.message) || 'inconnue'));
                        $btn.prop('disabled', false);
                    }
                }).fail(function(){
                    alert('Erreur réseau');
                    $btn.prop('disabled', false);
                });
            });

            // Ré-indexation d'un post.
            $(document).on('click', '.ai-rag-reindex', function(){
                var $btn = $(this);
                var pid = $btn.data('id');
                var origText = $btn.text();
                $btn.prop('disabled', true).text('…');
                $.post(ajaxurl, {
                    action: 'ai_assistant_rag_reindex_post',
                    _wpnonce: nonce,
                    post_id: pid
                }, null, 'json').done(function(resp){
                    if (resp && resp.success) {
                        $btn.text('✓ ' + resp.data.chunks);
                        setTimeout(function(){ location.reload(); }, 800);
                    } else {
                        alert('Erreur : ' + ((resp && resp.data && resp.data.message) || 'inconnue'));
                        $btn.prop('disabled', false).text(origText);
                    }
                }).fail(function(){
                    alert('Erreur réseau');
                    $btn.prop('disabled', false).text(origText);
                });
            });

            $('#ai-rag-clear-btn').on('click', function(){
                if (!confirm('Vider complètement l\'index RAG ? Il faudra le recréer.')) return;
                var $btn = $(this);
                $btn.prop('disabled', true);
                $.post(ajaxurl, {
                    action: 'ai_assistant_rag_clear',
                    _wpnonce: nonce
                }, null, 'json').done(function(resp){
                    if (resp && resp.success) {
                        $progress.html('<p style="color:#008a20;">Index vidé.</p>');
                        setTimeout(function(){ location.reload(); }, 800);
                    } else {
                        $progress.html('<p class="notice notice-error" style="padding:10px;">Erreur lors du vidage.</p>');
                        $btn.prop('disabled', false);
                    }
                });
            });
        });
        </script>
        <?php
    }

    public function ajax_rag_index_batch() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => 'Accès refusé.' ), 403 );
        }
        if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( $_POST['_wpnonce'], 'ai_assistant_rag' ) ) {
            wp_send_json_error( array( 'message' => 'Nonce invalide.' ), 403 );
        }
        if ( ! class_exists( 'AI_Assistant_RAG' ) ) {
            wp_send_json_error( array( 'message' => 'Module RAG indisponible.' ) );
        }

        $offset = isset( $_POST['offset'] ) ? max( 0, (int) $_POST['offset'] ) : 0;
        $batch  = isset( $_POST['batch'] ) ? max( 1, min( 20, (int) $_POST['batch'] ) ) : 3;

        $list = AI_Assistant_RAG::list_post_ids( $offset, $batch );
        $indexed = 0;
        $chunks  = 0;

        foreach ( $list['ids'] as $pid ) {
            $result = AI_Assistant_RAG::index_post( $pid );
            if ( is_wp_error( $result ) ) {
                wp_send_json_error( array( 'message' => 'Post ' . $pid . ' : ' . $result->get_error_message() ) );
            }
            $indexed++;
            $chunks += (int) $result;
        }

        wp_send_json_success( array(
            'indexed'     => $indexed,
            'chunks'      => $chunks,
            'next_offset' => $offset + count( $list['ids'] ),
            'total'       => $list['total'],
        ) );
    }

    public function ajax_rag_clear() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => 'Accès refusé.' ), 403 );
        }
        if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( $_POST['_wpnonce'], 'ai_assistant_rag' ) ) {
            wp_send_json_error( array( 'message' => 'Nonce invalide.' ), 403 );
        }
        if ( class_exists( 'AI_Assistant_RAG' ) ) {
            AI_Assistant_RAG::clear_all();
        }
        wp_send_json_success();
    }

    public function ajax_rag_delete_post() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => 'Accès refusé.' ), 403 );
        }
        if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( $_POST['_wpnonce'], 'ai_assistant_rag' ) ) {
            wp_send_json_error( array( 'message' => 'Nonce invalide.' ), 403 );
        }
        $post_id = isset( $_POST['post_id'] ) ? (int) $_POST['post_id'] : 0;
        if ( $post_id <= 0 ) {
            wp_send_json_error( array( 'message' => 'ID post invalide.' ) );
        }
        if ( class_exists( 'AI_Assistant_RAG' ) ) {
            AI_Assistant_RAG::delete_post_chunks( $post_id );
        }
        wp_send_json_success();
    }

    public function ajax_rag_reindex_post() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => 'Accès refusé.' ), 403 );
        }
        if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( $_POST['_wpnonce'], 'ai_assistant_rag' ) ) {
            wp_send_json_error( array( 'message' => 'Nonce invalide.' ), 403 );
        }
        $post_id = isset( $_POST['post_id'] ) ? (int) $_POST['post_id'] : 0;
        if ( $post_id <= 0 || ! class_exists( 'AI_Assistant_RAG' ) ) {
            wp_send_json_error( array( 'message' => 'Module RAG indisponible ou ID invalide.' ) );
        }
        $result = AI_Assistant_RAG::index_post( $post_id );
        if ( is_wp_error( $result ) ) {
            wp_send_json_error( array( 'message' => $result->get_error_message() ) );
        }
        wp_send_json_success( array( 'chunks' => (int) $result ) );
    }

    public function render_logs_page() {
        if ( ! current_user_can( 'manage_options' ) ) return;
        if ( ! class_exists( 'AI_Assistant_Logger' ) ) {
            echo '<div class="wrap"><h1>Journal</h1><p>Module de journalisation non chargé.</p></div>';
            return;
        }
        AI_Assistant_Logger::maybe_install();

        // Action : vider le journal
        if ( isset( $_POST['ai_assistant_truncate_log'] ) && check_admin_referer( 'ai_assistant_truncate_log' ) ) {
            AI_Assistant_Logger::truncate();
            echo '<div class="notice notice-success is-dismissible"><p>Journal vidé.</p></div>';
        }

        $page     = isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1;
        $search   = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
        $ip       = isset( $_GET['ip'] ) ? sanitize_text_field( wp_unslash( $_GET['ip'] ) ) : '';
        $per_page = 25;

        $result = AI_Assistant_Logger::get_page( $page, $per_page, $search, $ip );
        $total_pages = max( 1, (int) ceil( $result['total'] / $per_page ) );
        $enabled = ( get_option( 'ai_assistant_enable_chat_log', 'no' ) === 'yes' );
        ?>
        <div class="wrap">
            <h1>Journal des conversations</h1>

            <?php if ( ! $enabled ) : ?>
                <div class="notice notice-warning">
                    <p>La journalisation est <strong>désactivée</strong>. Activez-la dans <a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE_SLUG ) ); ?>">Réglages → Assistant IA</a> (section « Sécurité et débogage »).</p>
                </div>
            <?php endif; ?>

            <p><strong><?php echo (int) $result['total']; ?></strong> échange(s) enregistré(s).</p>

            <form method="get" style="margin:15px 0;">
                <input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE_SLUG . '-logs' ); ?>" />
                <input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="Rechercher dans les messages…" class="regular-text" />
                <input type="text" name="ip" value="<?php echo esc_attr( $ip ); ?>" placeholder="Filtrer par IP" />
                <button type="submit" class="button">Filtrer</button>
                <?php if ( $search || $ip ) : ?>
                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE_SLUG . '-logs' ) ); ?>" class="button">Effacer les filtres</a>
                <?php endif; ?>
            </form>

            <table class="widefat striped">
                <thead>
                    <tr>
                        <th style="width:140px;">Date</th>
                        <th style="width:110px;">IP</th>
                        <th>Question</th>
                        <th>Réponse</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ( empty( $result['rows'] ) ) : ?>
                        <tr><td colspan="4"><em>Aucun échange enregistré.</em></td></tr>
                    <?php else : foreach ( $result['rows'] as $row ) : ?>
                        <tr>
                            <td><?php echo esc_html( $row['created_at'] ); ?></td>
                            <td><code><?php echo esc_html( $row['ip'] ); ?></code></td>
                            <td><?php echo esc_html( wp_trim_words( $row['question'], 40 ) ); ?></td>
                            <td><?php echo esc_html( wp_trim_words( $row['answer'], 60 ) ); ?></td>
                        </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>

            <?php if ( $total_pages > 1 ) :
                $base = add_query_arg( array(
                    'page' => self::PAGE_SLUG . '-logs',
                    's'    => $search ?: null,
                    'ip'   => $ip ?: null,
                ), admin_url( 'admin.php' ) );
            ?>
                <div class="tablenav"><div class="tablenav-pages">
                    <?php echo paginate_links( array(
                        'base'      => add_query_arg( 'paged', '%#%', $base ),
                        'format'    => '',
                        'current'   => $page,
                        'total'     => $total_pages,
                        'prev_text' => '‹',
                        'next_text' => '›',
                    ) ); ?>
                </div></div>
            <?php endif; ?>

            <hr style="margin:30px 0;">
            <div style="display:flex; gap:15px; align-items:center;">
                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                    <?php wp_nonce_field( 'ai_assistant_export_logs' ); ?>
                    <input type="hidden" name="action" value="ai_assistant_export_logs" />
                    <input type="hidden" name="s" value="<?php echo esc_attr( $search ); ?>" />
                    <input type="hidden" name="ip" value="<?php echo esc_attr( $ip ); ?>" />
                    <button type="submit" class="button">⬇ Exporter en CSV<?php echo ( $search || $ip ) ? ' (filtré)' : ''; ?></button>
                </form>
                <form method="post" onsubmit="return confirm('Vider tout le journal ? Action irréversible.');">
                    <?php wp_nonce_field( 'ai_assistant_truncate_log' ); ?>
                    <button type="submit" name="ai_assistant_truncate_log" value="1" class="button button-secondary">Vider le journal</button>
                </form>
            </div>
        </div>
        <?php
    }

    public function render_settings_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        $schema       = self::schema();
        $tab_keys     = array_keys( $schema );
        $current_tab  = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : $tab_keys[0];
        if ( ! in_array( $current_tab, $tab_keys, true ) ) {
            $current_tab = $tab_keys[0];
        }
        $test_nonce = wp_create_nonce( 'ai_assistant_test_openai' );
        $page_for_tab = self::PAGE_SLUG . '_tab_' . $current_tab;
        ?>
        <div class="wrap">
            <h1>Réglages de l'Assistant IA</h1>

            <h2 class="nav-tab-wrapper">
                <?php foreach ( $schema as $tab_id => $tab ) :
                    $url = add_query_arg( array( 'page' => self::PAGE_SLUG, 'tab' => $tab_id ), admin_url( 'admin.php' ) );
                    $class = ( $tab_id === $current_tab ) ? 'nav-tab nav-tab-active' : 'nav-tab';
                ?>
                    <a href="<?php echo esc_url( $url ); ?>" class="<?php echo esc_attr( $class ); ?>">
                        <?php echo esc_html( $tab['title'] ); ?>
                    </a>
                <?php endforeach; ?>
            </h2>

            <?php if ( $current_tab === 'api' ) : ?>
                <div style="background:#fff; border:1px solid #c3c4c7; padding:15px 20px; margin:15px 0; border-radius:4px;">
                    <h2 style="margin-top:0;">Test de la connexion OpenAI</h2>
                    <p>Vérifie que la clé API et le modèle configurés fonctionnent réellement.</p>
                    <button type="button" id="ai-assistant-test-btn" class="button button-primary">Tester maintenant</button>
                    <button type="button" id="ai-assistant-reset-rl-btn" class="button" style="margin-left:10px;">Réinitialiser le compteur anti-abus</button>
                    <span id="ai-assistant-test-spinner" class="spinner" style="float:none; margin-left:10px;"></span>
                    <div id="ai-assistant-test-result" style="margin-top:15px;"></div>
                </div>
            <?php endif; ?>

            <form method="post" action="options.php">
                <?php
                // IMPORTANT : settings_fields utilise le group SPÉCIFIQUE à l'onglet.
                // Cela garantit que seules les options de cet onglet sont traitées,
                // les autres onglets restent intacts.
                settings_fields( $this->group_for_tab( $current_tab ) );
                do_settings_sections( $page_for_tab );
                echo '<input type="hidden" name="_wp_http_referer" value="' . esc_attr( add_query_arg( array( 'page' => self::PAGE_SLUG, 'tab' => $current_tab ), admin_url( 'admin.php' ) ) ) . '" />';
                submit_button( 'Enregistrer les modifications' );
                ?>
            </form>
        </div>

        <style>
            .form-table th { width: 260px; }
            #ai-assistant-test-result .ok   { color: #008a20; font-weight: bold; }
            #ai-assistant-test-result .fail { color: #d63638; font-weight: bold; }
            #ai-assistant-test-result pre   { background: #f6f7f7; padding: 10px; overflow: auto; }
        </style>

        <script>
        jQuery(function($){
            $('#ai-assistant-test-btn').on('click', function(){
                var $btn = $(this);
                var $result = $('#ai-assistant-test-result');
                var $spinner = $('#ai-assistant-test-spinner');

                $btn.prop('disabled', true);
                $spinner.css('visibility', 'visible').addClass('is-active');
                $result.html('<em>Test en cours…</em>');

                $.ajax({
                    url: ajaxurl,
                    method: 'POST',
                    dataType: 'json',
                    data: {
                        action: 'ai_assistant_test_openai',
                        _wpnonce: '<?php echo esc_js( $test_nonce ); ?>'
                    }
                }).done(function(resp){
                    if (resp && resp.success) {
                        var d = resp.data;
                        var html = '<p class="ok">✓ Connexion OpenAI OK</p>';
                        html += '<ul>';
                        html += '<li><strong>Modèle :</strong> ' + d.model + '</li>';
                        html += '<li><strong>Réponse de test :</strong> ' + d.reply + '</li>';
                        html += '<li><strong>Temps :</strong> ' + d.duration + ' ms</li>';
                        html += '</ul>';
                        $result.html(html);
                    } else {
                        var msg = (resp && resp.data && resp.data.message) || 'Erreur inconnue.';
                        var details = (resp && resp.data && resp.data.details) ? '<pre>' + $('<div>').text(resp.data.details).html() + '</pre>' : '';
                        $result.html('<p class="fail">✗ Échec : ' + $('<div>').text(msg).html() + '</p>' + details);
                    }
                }).fail(function(xhr){
                    var raw = (xhr && xhr.responseText) || '';
                    $result.html('<p class="fail">✗ Requête AJAX échouée (HTTP ' + (xhr && xhr.status) + ')</p><pre>' + $('<div>').text(raw.substring(0, 2000)).html() + '</pre>');
                }).always(function(){
                    $btn.prop('disabled', false);
                    $spinner.css('visibility', 'hidden').removeClass('is-active');
                });
            });

            $('#ai-assistant-reset-rl-btn').on('click', function(){
                var $btn = $(this);
                var $result = $('#ai-assistant-test-result');
                $btn.prop('disabled', true);
                $.post(ajaxurl, {
                    action: 'ai_assistant_reset_rate_limit',
                    _wpnonce: '<?php echo esc_js( wp_create_nonce( 'ai_assistant_reset_rl' ) ); ?>'
                }, null, 'json').done(function(resp){
                    if (resp && resp.success) {
                        $result.html('<p class="ok">✓ Compteur anti-abus remis à zéro (' + resp.data.cleared + ' entrée(s) effacée(s)).</p>');
                    } else {
                        $result.html('<p class="fail">✗ ' + ((resp && resp.data && resp.data.message) || 'Erreur') + '</p>');
                    }
                }).always(function(){ $btn.prop('disabled', false); });
            });
        });
        </script>
        <?php
    }

    /**
     * AJAX : teste la connexion OpenAI avec la config actuelle.
     */
    public function ajax_test_openai() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => 'Accès refusé.' ), 403 );
        }
        if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( $_POST['_wpnonce'], 'ai_assistant_test_openai' ) ) {
            wp_send_json_error( array( 'message' => 'Nonce invalide.' ), 403 );
        }

        $api_key = (string) get_option( 'ai_assistant_api_key', '' );
        $model   = (string) get_option( 'ai_assistant_main_model', 'gpt-4.1-nano' );

        if ( $api_key === '' ) {
            wp_send_json_error( array( 'message' => 'Aucune clé API OpenAI configurée.' ) );
        }

        $start = microtime( true );

        $body_payload = array(
            'model'    => $model,
            'messages' => array(
                array( 'role' => 'user', 'content' => 'Dis simplement "OK" pour tester la connexion.' ),
            ),
        );
        if ( preg_match( '/^(gpt-5|gpt-4\.1|o1|o3|o4)/i', $model ) ) {
            $body_payload['max_completion_tokens'] = 20;
        } else {
            $body_payload['max_tokens'] = 20;
        }

        $response = wp_remote_post( 'https://api.openai.com/v1/chat/completions', array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type'  => 'application/json',
            ),
            'body'    => wp_json_encode( $body_payload ),
            'timeout' => 30,
        ) );

        $duration = round( ( microtime( true ) - $start ) * 1000 );

        if ( is_wp_error( $response ) ) {
            wp_send_json_error( array(
                'message' => 'Échec réseau : ' . $response->get_error_message(),
            ) );
        }

        $code = wp_remote_retrieve_response_code( $response );
        $body_raw = wp_remote_retrieve_body( $response );
        $body = json_decode( (string) $body_raw, true );

        if ( $code !== 200 ) {
            $err_msg = is_array( $body ) && isset( $body['error']['message'] )
                ? $body['error']['message']
                : 'HTTP ' . $code;
            wp_send_json_error( array(
                'message' => 'OpenAI a refusé la requête : ' . $err_msg,
                'details' => substr( (string) $body_raw, 0, 1000 ),
            ) );
        }

        if ( ! is_array( $body ) || empty( $body['choices'][0]['message']['content'] ) ) {
            wp_send_json_error( array(
                'message' => 'Réponse OpenAI vide ou mal formée.',
                'details' => substr( (string) $body_raw, 0, 1000 ),
            ) );
        }

        wp_send_json_success( array(
            'model'    => $model,
            'reply'    => trim( $body['choices'][0]['message']['content'] ),
            'duration' => $duration,
        ) );
    }

    /**
     * AJAX : remet à zéro les transients de rate limit.
     */
    public function ajax_reset_rate_limit() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => 'Accès refusé.' ), 403 );
        }
        if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( $_POST['_wpnonce'], 'ai_assistant_reset_rl' ) ) {
            wp_send_json_error( array( 'message' => 'Nonce invalide.' ), 403 );
        }

        global $wpdb;
        $cleared = 0;
        // Nettoie les deux types de clés : IP (historique) et user_id (v2.9.3+).
        $patterns = array(
            $wpdb->esc_like( '_transient_ai_assistant_rl_' ) . '%',
            $wpdb->esc_like( '_transient_timeout_ai_assistant_rl_' ) . '%',
        );
        foreach ( $patterns as $like ) {
            $rows = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $like ) );
            foreach ( $rows as $name ) {
                $key = preg_replace( '/^_transient_(timeout_)?/', '', $name );
                if ( delete_transient( $key ) ) {
                    $cleared++;
                }
            }
        }
        wp_send_json_success( array( 'cleared' => $cleared ) );
    }

    public function admin_enqueue_scripts( $hook ) {
        // Ne charge que sur nos pages admin (principale ou sous-menus).
        if ( strpos( (string) $hook, self::PAGE_SLUG ) === false ) {
            return;
        }
        wp_enqueue_style( 'wp-color-picker' );
        wp_enqueue_script( 'wp-color-picker' );
        wp_add_inline_script(
            'wp-color-picker',
            'jQuery(function($){ $(".ai-assistant-color-picker").wpColorPicker(); });'
        );

        wp_enqueue_media();
        wp_add_inline_style( 'wp-color-picker', '
            .ai-icon-field { max-width: 620px; }
            .ai-icon-section-title { margin: 0 0 8px; }
            .ai-icon-grid { display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 4px; }
            .ai-icon-btn {
                width: 72px; height: 76px; padding: 6px 4px 4px;
                border: 2px solid #ddd !important; border-radius: 6px;
                background: #fff !important; cursor: pointer;
                display: inline-flex; flex-direction: column;
                align-items: center; justify-content: center; gap: 4px;
                transition: border-color .15s, box-shadow .15s;
                box-shadow: none !important; line-height: 1;
            }
            .ai-icon-btn:hover { border-color: #10b981 !important; }
            .ai-icon-btn.ai-icon-selected {
                border-color: #10b981 !important;
                box-shadow: 0 0 0 3px rgba(16,185,129,.25) !important;
                background: #f0fdf9 !important;
            }
            .ai-icon-btn img { width: 32px; height: 32px; object-fit: contain; pointer-events: none; }
            .ai-icon-btn span { font-size: 10px; color: #555; text-align: center; line-height: 1.2; pointer-events: none; }
            .ai-icon-or { display: flex; align-items: center; gap: 8px; margin: 14px 0 10px; color: #888; font-size: 12px; }
            .ai-icon-or::before, .ai-icon-or::after { content: ""; flex: 1; height: 1px; background: #ddd; }
            .ai-icon-upload-row { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
            .ai-icon-upload-preview { width: 64px; height: 64px; object-fit: contain; border: 1px solid #ddd; border-radius: 4px; background: #f9f9f9; }
        ' );
        wp_add_inline_script( 'wp-color-picker', "
jQuery(function($){
    $(document).on('click', '.ai-icon-btn', function(e){
        e.preventDefault();
        var \$btn     = $(this);
        var targetId = \$btn.data('target');
        var uri      = \$btn.data('uri');
        $('#' + targetId).val(uri);
        \$btn.closest('.ai-icon-grid').find('.ai-icon-btn').removeClass('ai-icon-selected');
        \$btn.addClass('ai-icon-selected');
        var \$field = \$btn.closest('.ai-icon-field');
        \$field.find('.ai-icon-upload-preview').attr('src','').hide();
        \$field.find('.ai-icon-clear-upload').hide();
        \$field.find('.ai-icon-upload-btn').text('Choisir une image');
    });

    $(document).on('click', '.ai-icon-upload-btn', function(e){
        e.preventDefault();
        var \$btn     = $(this);
        var targetId = \$btn.data('target');
        var frame    = wp.media({ title: 'Choisir une image', button: { text: 'Utiliser cette image' }, multiple: false, library: { type: 'image' } });
        frame.on('select', function(){
            var att = frame.state().get('selection').first().toJSON();
            $('#' + targetId).val(att.url);
            var \$field = \$btn.closest('.ai-icon-field');
            \$field.find('.ai-icon-upload-preview').attr('src', att.url).show();
            \$field.find('.ai-icon-clear-upload').show();
            \$btn.text('Changer l\\'image');
            \$field.find('.ai-icon-btn').removeClass('ai-icon-selected');
        });
        frame.open();
    });

    $(document).on('click', '.ai-icon-clear-upload', function(e){
        e.preventDefault();
        var \$btn     = $(this);
        var targetId = \$btn.data('target');
        $('#' + targetId).val('');
        var \$field = \$btn.closest('.ai-icon-field');
        \$field.find('.ai-icon-upload-preview').attr('src','').hide();
        \$btn.hide();
        \$field.find('.ai-icon-upload-btn').text('Choisir une image');
    });
});
        " );
    }

    // ───────── Bibliothèque d'icônes ─────────

    private static function get_icon_library() {
        $base = ' xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"'
              . ' stroke="#1e293b" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"';
        $svg  = function ( $inner ) use ( $base ) {
            return '<svg' . $base . '>' . $inner . '</svg>';
        };
        return array(
            'robot'    => array( 'label' => 'Robot',      'svg' => $svg( '<rect x="3" y="8" width="18" height="12" rx="2"/><path d="M12 2v6"/><circle cx="12" cy="2" r="1" fill="#1e293b" stroke="none"/><circle cx="9" cy="14" r="1.5"/><circle cx="15" cy="14" r="1.5"/><path d="M9 18h6"/><path d="M3 13h2m14 0h2"/>' ) ),
            'sparkle'  => array( 'label' => 'Étincelle',  'svg' => $svg( '<path d="M12 3 9.5 9.5 3 12l6.5 2.5L12 21l2.5-6.5L21 12l-6.5-2.5z"/><path d="M5 5l.8.8M18.2 5l.8-.8M5 19l.8-.8M18.2 19l.8.8"/>' ) ),
            'brain'    => array( 'label' => 'Cerveau',    'svg' => $svg( '<path d="M9.5 2A2.5 2.5 0 0 1 12 4.5v15a2.5 2.5 0 0 1-4.96-.44 2.5 2.5 0 0 1-2.96-3.08 3 3 0 0 1-.34-5.58 2.5 2.5 0 0 1 1.32-4.24A2.5 2.5 0 0 1 9.5 2z"/><path d="M14.5 2A2.5 2.5 0 0 0 12 4.5v15a2.5 2.5 0 0 0 4.96-.44 2.5 2.5 0 0 0 2.96-3.08 3 3 0 0 0 .34-5.58 2.5 2.5 0 0 0-1.32-4.24A2.5 2.5 0 0 0 14.5 2z"/>' ) ),
            'chip'     => array( 'label' => 'Puce IA',    'svg' => $svg( '<rect x="7" y="7" width="10" height="10" rx="1"/><path d="M7 9H5m0 3H3m2 3H5M17 9h2m0 3h2m-2 3h2M9 7V5m3 0V3m3 2V5M9 17v2m3 0v2m3-2v2"/>' ) ),
            'lightning'=> array( 'label' => 'Éclair',     'svg' => $svg( '<path d="M13 2L3 14h9l-1 8 10-12h-9z"/>' ) ),
            'atom'     => array( 'label' => 'Atome',      'svg' => $svg( '<circle cx="12" cy="12" r="1.5" fill="#1e293b" stroke="none"/><ellipse cx="12" cy="12" rx="10" ry="3.5"/><ellipse cx="12" cy="12" rx="10" ry="3.5" transform="rotate(60 12 12)"/><ellipse cx="12" cy="12" rx="10" ry="3.5" transform="rotate(120 12 12)"/>' ) ),
            'wand'     => array( 'label' => 'Baguette',   'svg' => $svg( '<path d="M3 21 12.5 11.5"/><path d="M9.5 11.5 15 6l3 3-5.5 5.5"/><path d="M16 2l.6 1.4L18 4l-1.4.6L16 6l-.6-1.4L14 4l1.4-.6z"/>' ) ),
            'message'  => array( 'label' => 'Message IA', 'svg' => $svg( '<path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/><path d="M8 10l1.5 2.5 1-3 1.5 4 1-2.5L14.5 12"/>' ) ),
            'rocket'   => array( 'label' => 'Fusée',      'svg' => $svg( '<path d="M4.5 16.5c-1.5 1.26-2 5-2 5s3.74-.5 5-2c.71-.84.7-2.13-.09-2.91a2.18 2.18 0 0 0-2.91-.09z"/><path d="M12 15l-3-3a22 22 0 0 1 2-3.95A12.88 12.88 0 0 1 22 2c0 2.72-.78 7.5-6 11a22.35 22.35 0 0 1-4 2z"/><path d="M9 12H4s.55-3.03 2-4c1.62-1.08 5 0 5 0"/><path d="M12 15v5s3.03-.55 4-2c1.08-1.62 0-5 0-5"/>' ) ),
            'star'     => array( 'label' => 'Étoile',     'svg' => $svg( '<polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/>' ) ),
            'infinity' => array( 'label' => 'Infini',     'svg' => $svg( '<path d="M12 12c-2-2.5-4-4-6-4a4 4 0 0 0 0 8c2 0 4-1.5 6-4z"/><path d="M12 12c2 2.5 4 4 6 4a4 4 0 0 0 0-8c-2 0-4 1.5-6 4z"/>' ) ),
            'eye'      => array( 'label' => 'Vision IA',  'svg' => $svg( '<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>' ) ),
            'globe'    => array( 'label' => 'Monde',      'svg' => $svg( '<circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/>' ) ),
            'diamond'  => array( 'label' => 'Diamant',    'svg' => $svg( '<path d="M6 3h12l4 6-10 13L2 9z"/><line x1="2" y1="9" x2="22" y2="9"/><path d="M12 3l4 6-4 13-4-13z"/>' ) ),
            'shield'   => array( 'label' => 'Sécurité',   'svg' => $svg( '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><path d="M9 12l2 2 4-4"/>' ) ),
            'flame'    => array( 'label' => 'Flamme',     'svg' => $svg( '<path d="M8.5 14.5A2.5 2.5 0 0 0 11 12c0-1.38-.5-2-1-3-1.072-2.143-.224-4.054 2-6 .5 2.5 2 4.9 4 6.5 2 1.6 3 3.5 3 5.5a7 7 0 1 1-14 0c0-1.153.433-2.294 1-3a2.5 2.5 0 0 0 2.5 3z"/>' ) ),
        );
    }

    // ───────── Sanitizers ─────────

    public static function sanitize_bool( $value ) {
        return ( $value === 'yes' ) ? 'yes' : 'no';
    }

    public static function sanitize_icon_url( $value ) {
        if ( empty( $value ) ) {
            return '';
        }
        if ( strpos( $value, 'data:image/svg+xml;base64,' ) === 0 ) {
            $b64 = substr( $value, strlen( 'data:image/svg+xml;base64,' ) );
            if ( base64_decode( $b64, true ) !== false ) {
                return $value;
            }
            return '';
        }
        return esc_url_raw( $value );
    }

    public static function sanitize_keywords( $input ) {
        if ( empty( $input ) ) {
            return '';
        }
        $lines = array_filter( array_map( 'trim', explode( "\n", $input ) ) );
        $lines = array_unique( array_map( 'sanitize_text_field', $lines ) );
        return implode( "\n", $lines );
    }

    public static function sanitize_themes( $input ) {
        if ( empty( $input ) ) {
            return json_encode( array() );
        }

        // Si c'est déjà du JSON, on le valide
        $decoded = json_decode( $input, true );
        if ( json_last_error() === JSON_ERROR_NONE && is_array( $decoded ) ) {
            $valid = array();
            foreach ( $decoded as $theme ) {
                if ( is_array( $theme ) && isset( $theme['name'] ) ) {
                    $valid[] = array(
                        'name'        => sanitize_text_field( $theme['name'] ),
                        'description' => sanitize_text_field( $theme['description'] ?? '' ),
                    );
                }
            }
            return json_encode( $valid );
        }

        // Sinon on parse le format texte "Nom : Description"
        $themes = array();
        foreach ( explode( "\n", $input ) as $line ) {
            $line = trim( $line );
            if ( empty( $line ) ) continue;

            if ( strpos( $line, ':' ) !== false ) {
                $parts = explode( ':', $line, 2 );
                $themes[] = array(
                    'name'        => sanitize_text_field( trim( $parts[0] ) ),
                    'description' => sanitize_text_field( trim( $parts[1] ) ),
                );
            } else {
                $themes[] = array(
                    'name'        => sanitize_text_field( $line ),
                    'description' => '',
                );
            }
        }
        return json_encode( $themes );
    }
}
