<?php
/**
 * Plugin Name: Real Media Export
 * Description: Fournit une page d'export des fichiers classés avec Real Media Library.
 * Version: 1.1.0
 * Author: OpenAI Assistant
 * Text Domain: real-media-export
 */

defined( 'ABSPATH' ) || exit;


if ( ! class_exists( 'Real_Media_Export_Plugin' ) ) {
    class Real_Media_Export_Plugin {
        const NONCE_ACTION = 'real_media_export_action';
        const DOWNLOAD_NONCE = 'real_media_export_download_';
        const TRANSIENT_PREFIX = 'real_media_export_result_';
        const EXPORT_FOLDER = 'real-media-export';
        const RESULT_TTL = 600; // 10 minutes.
        

        /**
         * Cached taxonomy name detected.
         *
         * @var string|null
         */
        protected $folder_taxonomy = null;

        /**
         * Cache of attachment => folders when using RML API.
         *
         * @var array<int,int[]>
         */
        protected $rml_terms_by_object = array();

        /**
         * Collected activity logs for UI journal.
         * Each entry: [ 'type' => 'info'|'warning'|'error', 'message' => string ].
         *
         * @var array<int,array{type:string,message:string}>
         */
        protected $activity_log = array();

        /**
         * Constructor.
         */
        public function __construct() {
            add_action( 'init', array( $this, 'load_textdomain' ) );
            // Debug: log taxonomies on init (after most plugins register them).
            add_action( 'init', array( $this, 'debug_log_taxonomies' ), 99 );
            add_action( 'admin_menu', array( $this, 'register_menu' ) );
            add_action( 'admin_init', array( $this, 'maybe_cleanup_old_archives' ) );
            add_action( 'admin_post_real_media_export', array( $this, 'handle_export_request' ) );
            add_action( 'admin_post_real_media_export_download', array( $this, 'handle_download_request' ) );
            add_action( 'wp_ajax_real_media_export_delete', array( $this, 'handle_ajax_delete_request' ) );
            add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
            add_action( 'wp_ajax_real_media_export_generate', array( $this, 'handle_ajax_export_request' ) );
        }

        /**
         * Load plugin translations.
         */
        public function load_textdomain() {
            load_plugin_textdomain( 'real-media-export', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
        }

        /**
         * Register the admin menu entry under Media.
         */
        public function register_menu() {
            add_media_page(
                __( 'Export Real Media Library', 'real-media-export' ),
                __( 'Export RML', 'real-media-export' ),
                'upload_files',
                'real-media-export',
                array( $this, 'render_admin_page' )
            );
        }

        /**
         * Return true if we can get a folder tree from either the RML API or the RML DB table.
         *
         * @return bool
         */
        protected function rml_has_tree() {
            return $this->rml_tree_available() || $this->rml_table_exists();
        }

        /**
         * Enqueue admin assets for the export screen.
         *
         * @param string $hook_suffix Current admin page hook suffix.
         */
        public function enqueue_assets( $hook_suffix ) {
            if ( 'media_page_real-media-export' !== $hook_suffix ) {
                return;
            }

            $asset_url = plugin_dir_url( __FILE__ );
            $asset_dir = plugin_dir_path( __FILE__ );
            $css_path  = $asset_dir . 'assets/css/admin.css';
            $js_path   = $asset_dir . 'assets/js/admin.js';
            $css_ver   = file_exists( $css_path ) ? (string) filemtime( $css_path ) : '1.2.0';
            $js_ver    = file_exists( $js_path ) ? (string) filemtime( $js_path ) : '1.2.0';

            wp_enqueue_style(
                'real-media-export-admin',
                $asset_url . 'assets/css/admin.css',
                array(),
                $css_ver
            );

            wp_enqueue_script(
                'real-media-export-admin',
                $asset_url . 'assets/js/admin.js',
                array(),
                $js_ver,
                true
            );
        }

        /**
         * Try to detect the taxonomy used by Real Media Library to store folders.
         *
         * @return string|null
         */
        public function get_folder_taxonomy() {
            if ( null !== $this->folder_taxonomy ) {
                return $this->folder_taxonomy;
            }

            $detected = null;

            // 1) Try known defaults.
            $default_taxonomies = array(
                'real_media_library',
                'rml_folder',
                'real_media_category',
            );

            if ( null === $detected ) {
                foreach ( $default_taxonomies as $taxonomy ) {
                    if ( taxonomy_exists( $taxonomy ) || $this->db_taxonomy_exists( $taxonomy ) ) {
                        $detected = $taxonomy;
                        break;
                    }
                }
            }

            if ( null === $detected ) {
                if ( defined( 'RML_TAXONOMY' ) && ( taxonomy_exists( RML_TAXONOMY ) || $this->db_taxonomy_exists( RML_TAXONOMY ) ) ) {
                    $detected = RML_TAXONOMY;
                } elseif ( defined( 'RML_FOLDER_TAXONOMY' ) && ( taxonomy_exists( RML_FOLDER_TAXONOMY ) || $this->db_taxonomy_exists( RML_FOLDER_TAXONOMY ) ) ) {
                    $detected = RML_FOLDER_TAXONOMY;
                }
            }

            if ( null === $detected ) {
                $detected = $this->detect_rml_taxonomy_from_registered();
            }

            if ( null === $detected ) {
                $detected = $this->detect_rml_taxonomy_from_db();
            }

            /**
             * Filter the taxonomy used to query Real Media Library folders.
             *
             * @param string|null $detected Taxonomy name or null if not found.
             */
            $detected = apply_filters( 'real_media_export/folder_taxonomy', $detected );

            $this->folder_taxonomy = $detected;

            return $this->folder_taxonomy;
        }

        

        /**
         * Try to infer the Real Media Library taxonomy from the registered ones.
         *
         * @return string|null
         */
        protected function detect_rml_taxonomy_from_registered() {
            $taxonomies = get_taxonomies( array(), 'objects' );
            if ( empty( $taxonomies ) || ! is_array( $taxonomies ) ) {
                return null;
            }

            $attachment_taxonomies = array();
            foreach ( $taxonomies as $taxonomy_name => $taxonomy_object ) {
                if ( empty( $taxonomy_object->object_type ) || ! in_array( 'attachment', (array) $taxonomy_object->object_type, true ) ) {
                    continue;
                }

                $attachment_taxonomies[ $taxonomy_name ] = $taxonomy_object;
            }

            if ( empty( $attachment_taxonomies ) ) {
                return null;
            }

            $scores = array();
            foreach ( $attachment_taxonomies as $taxonomy_name => $taxonomy_object ) {
                $score = 0;
                $slug  = strtolower( $taxonomy_name );

                if ( false !== strpos( $slug, 'rml' ) ) {
                    $score += 25;
                }
                if ( false !== strpos( $slug, 'real_media' ) || false !== strpos( $slug, 'realmedialibrary' ) ) {
                    $score += 25;
                }

                $label_candidates = array();
                if ( isset( $taxonomy_object->label ) && is_string( $taxonomy_object->label ) ) {
                    $label_candidates[] = strtolower( $taxonomy_object->label );
                }
                if ( isset( $taxonomy_object->labels ) ) {
                    foreach ( (array) $taxonomy_object->labels as $label_value ) {
                        if ( is_string( $label_value ) ) {
                            $label_candidates[] = strtolower( $label_value );
                        }
                    }
                }

                foreach ( $label_candidates as $label_candidate ) {
                    if ( false !== strpos( $label_candidate, 'real media' ) || false !== strpos( $label_candidate, 'rml' ) ) {
                        $score += 15;
                        break;
                    }
                }

                if ( ! empty( $taxonomy_object->hierarchical ) ) {
                    $score += 10;
                }

                // Look for RML-specific term meta to lift genuine matches to the top.
                $rml_meta_terms = get_terms(
                    array(
                        'taxonomy'   => $taxonomy_name,
                        'meta_key'   => 'rml_folder_type',
                        'fields'     => 'ids',
                        'hide_empty' => false,
                        'number'     => 1,
                    )
                );
                if ( ! is_wp_error( $rml_meta_terms ) && ! empty( $rml_meta_terms ) ) {
                    $score += 40;
                } else {
                    $rml_meta_terms = get_terms(
                        array(
                            'taxonomy'   => $taxonomy_name,
                            'meta_key'   => 'rml_type',
                            'fields'     => 'ids',
                            'hide_empty' => false,
                            'number'     => 1,
                        )
                    );
                    if ( ! is_wp_error( $rml_meta_terms ) && ! empty( $rml_meta_terms ) ) {
                        $score += 30;
                    }
                }

                if ( $score > 0 ) {
                    $scores[ $taxonomy_name ] = $score;
                }
            }

            if ( empty( $scores ) ) {
                foreach ( $attachment_taxonomies as $taxonomy_name => $taxonomy_object ) {
                    if ( ! empty( $taxonomy_object->hierarchical ) ) {
                        return $taxonomy_name;
                    }
                }

                $fallback_taxonomies = array_keys( $attachment_taxonomies );
                if ( empty( $fallback_taxonomies ) ) {
                    return null;
                }

                return reset( $fallback_taxonomies );
            }

            arsort( $scores );

            return key( $scores );
        }

        /**
         * Attempt to detect the RML taxonomy directly from the DB, based on attachments usage and meta hints.
         *
         * @return string|null
         */
        protected function detect_rml_taxonomy_from_db() {
            global $wpdb;
            $posts = $wpdb->posts;
            $tr    = $wpdb->term_relationships;
            $tt    = $wpdb->term_taxonomy;
            $tm    = $wpdb->termmeta;

            // Fetch taxonomy candidates used by attachments with usage counts.
            $sql = "SELECT tt.taxonomy AS taxonomy, COUNT(*) AS usage_count
                    FROM {$tt} tt
                    INNER JOIN {$tr} tr ON tr.term_taxonomy_id = tt.term_taxonomy_id
                    INNER JOIN {$posts} p ON p.ID = tr.object_id
                    WHERE p.post_type = 'attachment' AND p.post_status IN ('inherit','publish','private')
                    GROUP BY tt.taxonomy";
            $rows = $wpdb->get_results( $sql );
            if ( empty( $rows ) ) {
                return null;
            }

            $scores = array();
            foreach ( $rows as $row ) {
                $taxonomy = (string) $row->taxonomy;
                $score    = (int) $row->usage_count; // base on usage
                $slug     = strtolower( $taxonomy );

                if ( false !== strpos( $slug, 'rml' ) ) {
                    $score += 100;
                }
                if ( false !== strpos( $slug, 'real_media' ) || false !== strpos( $slug, 'realmedialibrary' ) || false !== strpos( $slug, 'real-media' ) ) {
                    $score += 80;
                }

                // Boost if termmeta hints are present for this taxonomy.
                $meta_count = (int) $wpdb->get_var( $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$tm} tm INNER JOIN {$tt} tt ON tt.term_id = tm.term_id WHERE tt.taxonomy = %s AND tm.meta_key IN ('rml_folder_type','rml_type')",
                    $taxonomy
                ) );
                if ( $meta_count > 0 ) {
                    $score += 120;
                }

                $scores[ $taxonomy ] = $score;
            }

            if ( empty( $scores ) ) {
                return null;
            }

            arsort( $scores );
            return key( $scores );
        }

        /**
         * Debug helper: log all registered taxonomies to PHP error log.
         * Only active when WP_DEBUG is true (can be overridden via filter).
         */
        public function debug_log_taxonomies() {
            $enabled = apply_filters( 'real_media_export/log_taxonomies', ( defined( 'WP_DEBUG' ) && WP_DEBUG ) );
            if ( ! $enabled ) {
                return;
            }

            $taxonomies = get_taxonomies( array(), 'objects' );
            if ( empty( $taxonomies ) || ! is_array( $taxonomies ) ) {
                error_log( '[Real Media Export] Aucune taxonomie trouvée.' );
                return;
            }

            $total = count( $taxonomies );
            error_log( sprintf( '[Real Media Export] Taxonomies enregistrées (%d):', $total ) );

            foreach ( $taxonomies as $name => $obj ) {
                $label        = isset( $obj->label ) ? $obj->label : '';
                $hierarchical = ! empty( $obj->hierarchical ) ? 'hierarchical' : 'flat';
                $public       = isset( $obj->public ) ? ( $obj->public ? 'public' : 'non-public' ) : 'public?';
                $object_types = implode( ',', array_map( 'strval', (array) ( $obj->object_type ?? array() ) ) );
                $object_types = $object_types !== '' ? $object_types : '-';

                error_log( sprintf( '[Real Media Export] - %s | label="%s" | %s | %s | objects=[%s]', $name, $label, $hierarchical, $public, $object_types ) );
            }
        }

        

        /**
         * Render the admin page form and results.
         */
        public function render_admin_page() {
            if ( ! current_user_can( 'upload_files' ) ) {
                wp_die( esc_html__( 'Vous n’avez pas les permissions nécessaires pour accéder à cette page.', 'real-media-export' ) );
            }

            $taxonomy = $this->get_folder_taxonomy();
            $selected_folder = isset( $_GET['folder'] ) ? absint( $_GET['folder'] ) : (int) get_user_meta( get_current_user_id(), 'real_media_export_last_folder', true );
            $include_children = isset( $_GET['include_children'] ) ? (bool) absint( $_GET['include_children'] ) : true;
            $max_size_mb = isset( $_GET['max_size_mb'] ) ? floatval( $_GET['max_size_mb'] ) : '';
            $archive_prefix = isset( $_GET['archive_prefix'] ) ? sanitize_text_field( wp_unslash( $_GET['archive_prefix'] ) ) : '';
            $preserve_structure = isset( $_GET['preserve_structure'] ) ? (bool) absint( $_GET['preserve_structure'] ) : true;
            $has_folders_source = ( ! empty( $taxonomy ) && $this->taxonomy_is_available( $taxonomy ) ) || $this->rml_has_tree();

            $result = $this->maybe_get_result();

            $script_settings = $this->prepare_script_settings( $result );
            wp_add_inline_script(
                'real-media-export-admin',
                'window.realMediaExportSettings = ' . wp_json_encode( $script_settings ) . ';',
                'before'
            );

            echo '<div class="wrap real-media-export">';
            echo '<h1>' . esc_html__( 'Export des fichiers Real Media Library', 'real-media-export' ) . '</h1>';

            if ( ! $has_folders_source ) {
                printf(
                    '<div class="notice notice-error"><p>%s</p></div>',
                    esc_html__( 'Le plugin Real Media Library ne semble pas être actif. Aucune taxonomie de dossiers n’a été détectée.', 'real-media-export' )
                );
            }

            echo '<div class="real-media-export-panels">';

            echo '<section class="real-media-export-panel real-media-export-panel--form">';
            echo '<form id="real-media-export-form" class="real-media-export-form" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
            wp_nonce_field( self::NONCE_ACTION );
            echo '<input type="hidden" name="action" value="real_media_export" />';

            echo '<table class="form-table" role="presentation">';
            echo '<tbody>';


            echo '<tr>';
            echo '<th scope="row"><label for="real-media-export-folder">' . esc_html__( 'Dossier à exporter', 'real-media-export' ) . '</label></th>';
            echo '<td>';
            if ( ! $has_folders_source ) {
                echo '<em>' . esc_html__( 'Aucun dossier disponible.', 'real-media-export' ) . '</em>';
            } else {
                echo '<select id="real-media-export-folder" name="folder_id" class="regular-text">';
                echo '<option value="">' . esc_html__( 'Sélectionnez un dossier…', 'real-media-export' ) . '</option>';
                echo $this->get_folder_options_html( $selected_folder );
                echo '</select>';
            }
            echo '<p class="description">' . esc_html__( 'Choisissez le dossier racine à exporter. Les fichiers seront rassemblés selon la structure de Real Media Library.', 'real-media-export' ) . '</p>';
            echo '</td>';
            echo '</tr>';

            echo '<tr>';
            echo '<th scope="row">' . esc_html__( 'Inclure les sous-dossiers', 'real-media-export' ) . '</th>';
            echo '<td>';
            printf(
                '<label><input type="checkbox" name="include_children" value="1" %s /> %s</label>',
                checked( $include_children, true, false ),
                esc_html__( 'Inclure les fichiers des sous-dossiers du dossier sélectionné.', 'real-media-export' )
            );
            echo '</td>';
            echo '</tr>';

            echo '<tr>';
            echo '<th scope="row"><label for="real-media-export-max-size">' . esc_html__( 'Taille maximale par archive (Mo)', 'real-media-export' ) . '</label></th>';
            echo '<td>';
            printf(
                '<input type="number" min="0" step="0.1" id="real-media-export-max-size" name="max_size_mb" value="%s" class="small-text" />',
                '' !== $max_size_mb ? esc_attr( $max_size_mb ) : ''
            );
            echo '<p class="description">' . esc_html__( 'Laisser vide ou 0 pour ne pas limiter la taille des fichiers ZIP générés.', 'real-media-export' ) . '</p>';
            echo '</td>';
            echo '</tr>';

            echo '<tr>';
            echo '<th scope="row"><label for="real-media-export-prefix">' . esc_html__( 'Préfixe du fichier ZIP', 'real-media-export' ) . '</label></th>';
            echo '<td>';
            printf(
                '<input type="text" id="real-media-export-prefix" name="archive_prefix" value="%s" class="regular-text" />',
                esc_attr( $archive_prefix )
            );
            echo '<p class="description">' . esc_html__( 'Utilisé comme préfixe du nom de fichier ZIP. La date et un index seront ajoutés automatiquement.', 'real-media-export' ) . '</p>';
            echo '</td>';
            echo '</tr>';

            echo '<tr>';
            echo '<th scope="row">' . esc_html__( 'Recréer la structure de dossiers', 'real-media-export' ) . '</th>';
            echo '<td>';
            printf(
                '<label><input type="checkbox" name="preserve_structure" value="1" %s /> %s</label>',
                checked( $preserve_structure, true, false ),
                esc_html__( 'Recréer la hiérarchie des sous-dossiers dans l’archive.', 'real-media-export' )
            );
            echo '</td>';
            echo '</tr>';

            echo '</tbody>';
            echo '</table>';

            submit_button( __( 'Générer l’archive', 'real-media-export' ) );

            echo '</form>';
            echo '</section>';

            echo '<section class="real-media-export-panel real-media-export-panel--activity">';
            $this->render_activity_panel( $result );
            echo '</section>';

            echo '</div>';
            echo '</div>';
        }

        /**
         * Prepare settings passed to the JavaScript controller.
         *
         * @param array $result Result data.
         *
         * @return array
         */
        protected function prepare_script_settings( $result ) {
            $settings = array(
                'ajaxUrl' => admin_url( 'admin-ajax.php' ),
                'i18n'    => array(
                    'preparing'           => esc_html__( 'Préparation de la génération…', 'real-media-export' ),
                    'processing'          => esc_html__( 'Analyse des fichiers et création des archives…', 'real-media-export' ),
                    'success'             => esc_html__( 'Les archives sont prêtes.', 'real-media-export' ),
                    'warning'             => esc_html__( 'Les archives sont prêtes avec des avertissements.', 'real-media-export' ),
                    'error'               => esc_html__( 'Une erreur est survenue pendant la génération.', 'real-media-export' ),
                    'ready'               => esc_html__( 'Liens de téléchargement disponibles.', 'real-media-export' ),
                    'activityPlaceholder' => esc_html__( 'Le suivi en direct de la génération apparaîtra ici.', 'real-media-export' ),
                    'resultsPlaceholder'  => esc_html__( 'Les liens de téléchargement apparaîtront ici une fois les archives prêtes.', 'real-media-export' ),
                    'downloadLabel'       => esc_html__( 'Télécharger', 'real-media-export' ),
                    'deleteLabel'         => esc_html__( 'Supprimer', 'real-media-export' ),
                    'confirmDelete'       => esc_html__( 'Supprimer ce fichier ZIP du disque ?', 'real-media-export' ),
                    'deleted'             => esc_html__( 'Archive supprimée.', 'real-media-export' ),
                    'deleteError'         => esc_html__( 'Impossible de supprimer l’archive.', 'real-media-export' ),
                    'generatedOn'         => esc_html__( 'Généré le %s', 'real-media-export' ),
                    'filesExportedSummary' => esc_html__( '%1$s fichier(s) exporté(s) sur %2$s', 'real-media-export' ),
                    'archivesCountSummary' => esc_html__( '%s archive(s)', 'real-media-export' ),
                    'filesSkippedSummary'  => esc_html__( '%s fichier(s) ignoré(s)', 'real-media-export' ),
                    'primaryFoldersLabel'  => esc_html__( 'Dossiers principaux', 'real-media-export' ),
                    'previewFilesLabel'    => esc_html__( 'Exemples de fichiers', 'real-media-export' ),
                    'maxSizeReachedNote'   => esc_html__( 'Cet archive a été clôturée automatiquement car la taille maximale définie a été atteinte.', 'real-media-export' ),
                    'downloadUnavailable'  => esc_html__( 'Lien de téléchargement indisponible.', 'real-media-export' ),
                    'archiveTitleFallback' => esc_html__( 'Archive ZIP', 'real-media-export' ),
                    'loading'              => esc_html__( 'Création des archives…', 'real-media-export' ),
                    'compressedSizeLabel'  => esc_html__( 'Taille compressée', 'real-media-export' ),
                    'originalsSizeLabel'   => esc_html__( 'Taille cumulée des originaux', 'real-media-export' ),
                    'filesCountLabel'      => esc_html__( 'Nombre de fichiers', 'real-media-export' ),
                ),
                'deleteNonce' => wp_create_nonce( self::NONCE_ACTION ),
            );

            $initial_result = $this->prepare_result_for_js( $result );
            // If there is no recent result, prefill with any ZIPs found on disk.
            if ( null === $initial_result || ( empty( $initial_result['archives'] ) && empty( $initial_result['summary']['archives_count'] ) ) ) {
                $existing = $this->get_existing_archives();
                if ( ! empty( $existing ) ) {
                    $initial_result = $this->prepare_result_for_js( array(
                        'status'   => '',
                        'message'  => '',
                        'archives' => $existing,
                        'summary'  => array( 'archives_count' => count( $existing ) ),
                        'generated_at' => $this->get_latest_archive_mtime( $existing ),
                    ) );
                }
            }
            if ( null !== $initial_result ) {
                $settings['initialResult'] = $initial_result;
            }

            return $settings;
        }

        /**
         * Render the activity panel containing the live log and generated archives.
         *
         * @param array $result Result data.
         */
        protected function render_activity_panel( $result ) {
            $status  = isset( $result['status'] ) ? $result['status'] : '';
            $message = isset( $result['message'] ) ? $result['message'] : '';

            $notice_class = 'notice notice-info';
            if ( 'success' === $status ) {
                $notice_class = 'notice notice-success';
            } elseif ( 'warning' === $status ) {
                $notice_class = 'notice notice-warning';
            } elseif ( 'error' === $status ) {
                $notice_class = 'notice notice-error';
            }

            echo '<div class="real-media-export-activity">';
            echo '<h2>' . esc_html__( 'Journal d’activité', 'real-media-export' ) . '</h2>';
            echo '<div id="real-media-export-log" class="real-media-export-log" role="log" aria-live="polite" aria-relevant="additions text">';
            echo '<p class="real-media-export-log__placeholder">' . esc_html__( 'Le suivi en direct de la génération apparaîtra ici.', 'real-media-export' ) . '</p>';
            echo '</div>';

            echo '<div id="real-media-export-message" class="real-media-export-message" data-status="' . esc_attr( $status ) . '">';
            if ( ! empty( $message ) ) {
                echo '<div class="' . esc_attr( $notice_class ) . '"><div class="real-media-export-message__content">' . wp_kses_post( $message ) . '</div></div>';
            }
            echo '</div>';

            $this->render_results_cards( $result );
            echo '</div>';
        }

        /**
         * Render the download cards for generated archives.
         *
         * @param array $result Result data.
         */
        protected function render_results_cards( $result ) {
            $archives = isset( $result['archives'] ) ? (array) $result['archives'] : array();

            // Merge with any ZIPs currently present on disk to keep cards visible.
            $existing = $this->get_existing_archives();
            if ( ! empty( $existing ) ) {
                $by_file = array();
                foreach ( $archives as $a ) {
                    $fname = isset( $a['file'] ) ? (string) $a['file'] : '';
                    if ( $fname !== '' ) { $by_file[ $fname ] = true; }
                }
                foreach ( $existing as $e ) {
                    $fname = isset( $e['file'] ) ? (string) $e['file'] : '';
                    if ( $fname !== '' && ! isset( $by_file[ $fname ] ) ) {
                        $archives[] = $e; // add missing ones from disk
                    }
                }
            }

            echo '<div id="real-media-export-results" class="real-media-export-results" aria-live="polite">';

            if ( ! empty( $result['generated_at_formatted'] ) || ! empty( $result['summary'] ) ) {
                echo '<div class="real-media-export-results__header">';
                if ( ! empty( $result['generated_at_formatted'] ) ) {
                    echo '<span class="real-media-export-results__timestamp">' . esc_html( sprintf( esc_html__( 'Généré le %s', 'real-media-export' ), $result['generated_at_formatted'] ) ) . '</span>';
                }

                if ( ! empty( $result['summary'] ) && is_array( $result['summary'] ) ) {
                    $summary_parts = array();
                    if ( isset( $result['summary']['files_exported'] ) && isset( $result['summary']['files_total'] ) ) {
                        $summary_parts[] = sprintf(
                            /* translators: 1: number of exported files, 2: total files. */
                            esc_html__( '%1$s fichier(s) exporté(s) sur %2$s', 'real-media-export' ),
                            number_format_i18n( (int) $result['summary']['files_exported'] ),
                            number_format_i18n( (int) $result['summary']['files_total'] )
                        );
                    }

                    if ( ! empty( $archives ) ) {
                        $summary_parts[] = sprintf(
                            /* translators: %s: number of archives. */
                            esc_html__( '%s archive(s)', 'real-media-export' ),
                            number_format_i18n( count( $archives ) )
                        );
                    }

                    if ( ! empty( $result['summary']['files_skipped'] ) ) {
                        $summary_parts[] = sprintf(
                            /* translators: %s: number of skipped files. */
                            esc_html__( '%s fichier(s) ignoré(s)', 'real-media-export' ),
                            number_format_i18n( (int) $result['summary']['files_skipped'] )
                        );
                    }

                    if ( ! empty( $summary_parts ) ) {
                        echo '<span class="real-media-export-results__summary">' . esc_html( implode( ' · ', $summary_parts ) ) . '</span>';
                    }
                }
                echo '</div>';
            }

            if ( empty( $archives ) ) {
                echo '<p class="real-media-export-results__placeholder">' . esc_html__( 'Les liens de téléchargement apparaîtront ici une fois les archives prêtes.', 'real-media-export' ) . '</p>';
                echo '</div>';
                return;
            }

            echo '<div class="real-media-export-results__grid">';
            foreach ( $archives as $index => $archive ) {
                $this->render_archive_card( $archive, (int) $index );
            }
            echo '</div>';
            echo '</div>';
        }

        /**
         * Render a single archive card.
         *
         * @param array $archive Archive data.
         * @param int   $index   Index within the list.
         */
        protected function render_archive_card( $archive, $index ) {
            $file_name      = isset( $archive['file'] ) ? $archive['file'] : '';
            $size_human     = isset( $archive['size_human'] ) ? $archive['size_human'] : ( isset( $archive['size'] ) ? size_format( (int) $archive['size'] ) : '' );
            $download_url   = isset( $archive['download_url'] ) ? $archive['download_url'] : $this->get_download_url( $file_name );
            $file_count     = isset( $archive['file_count'] ) ? (int) $archive['file_count'] : 0;
            $created_at     = isset( $archive['created_at_formatted'] ) ? $archive['created_at_formatted'] : '';
            $bytes_total    = isset( $archive['bytes_total_human'] ) ? $archive['bytes_total_human'] : '';
            $folders        = isset( $archive['folders'] ) ? array_values( array_filter( (array) $archive['folders'] ) ) : array();
            $files_preview  = isset( $archive['files_preview'] ) ? array_values( array_filter( (array) $archive['files_preview'] ) ) : array();
            $max_size_reach = ! empty( $archive['max_size_reached'] );
            $delete_nonce   = wp_create_nonce( self::NONCE_ACTION );

            echo '<article class="real-media-export-card" style="--real-media-export-card-index:' . esc_attr( $index ) . '">';
            echo '<header class="real-media-export-card__header">';
            echo '<h3 class="real-media-export-card__title">' . esc_html( $file_name ) . '</h3>';
            if ( ! empty( $created_at ) ) {
                echo '<p class="real-media-export-card__subtitle">' . esc_html( sprintf( esc_html__( 'Créé le %s', 'real-media-export' ), $created_at ) ) . '</p>';
            }
            echo '</header>';

            echo '<ul class="real-media-export-card__meta">';
            if ( '' !== $size_human ) {
                echo '<li><span class="real-media-export-card__meta-label">' . esc_html__( 'Taille compressée', 'real-media-export' ) . '</span><span class="real-media-export-card__meta-value">' . esc_html( $size_human ) . '</span></li>';
            }
            if ( '' !== $bytes_total ) {
                echo '<li><span class="real-media-export-card__meta-label">' . esc_html__( 'Taille cumulée des originaux', 'real-media-export' ) . '</span><span class="real-media-export-card__meta-value">' . esc_html( $bytes_total ) . '</span></li>';
            }
            if ( $file_count > 0 ) {
                echo '<li><span class="real-media-export-card__meta-label">' . esc_html__( 'Nombre de fichiers', 'real-media-export' ) . '</span><span class="real-media-export-card__meta-value">' . esc_html( number_format_i18n( $file_count ) ) . '</span></li>';
            }
            echo '</ul>';

            if ( ! empty( $folders ) ) {
                echo '<p class="real-media-export-card__detail"><span class="real-media-export-card__detail-label">' . esc_html__( 'Dossiers principaux', 'real-media-export' ) . '</span><span class="real-media-export-card__detail-value">' . esc_html( implode( ', ', $folders ) ) . '</span></p>';
            }

            if ( ! empty( $files_preview ) ) {
                echo '<p class="real-media-export-card__detail"><span class="real-media-export-card__detail-label">' . esc_html__( 'Exemples de fichiers', 'real-media-export' ) . '</span><span class="real-media-export-card__detail-value">' . esc_html( implode( ', ', $files_preview ) ) . '</span></p>';
            }

            if ( $max_size_reach ) {
                echo '<p class="real-media-export-card__note">' . esc_html__( 'Cet archive a été clôturée automatiquement car la taille maximale définie a été atteinte.', 'real-media-export' ) . '</p>';
            }

            echo '<div class="real-media-export-card__actions">';
            if ( $download_url ) {
                echo '<a class="button button-primary" href="' . esc_url( $download_url ) . '">' . esc_html__( 'Télécharger ce ZIP', 'real-media-export' ) . '</a> ';
            } else {
                echo '<span class="real-media-export-card__note">' . esc_html__( 'Lien de téléchargement indisponible.', 'real-media-export' ) . '</span> ';
            }
            echo '<button type="button" class="button-link-delete real-media-export-card__delete" data-file="' . esc_attr( $file_name ) . '" data-nonce="' . esc_attr( $delete_nonce ) . '">' . esc_html__( 'Supprimer', 'real-media-export' ) . '</button>';
            echo '</div>';

            echo '</article>';
        }

        /**
         * Enumerate existing ZIP archives on disk and return minimal info for cards.
         *
         * @return array<int,array>
         */
        protected function get_existing_archives() {
            $dir = $this->get_export_directory();
            if ( ! is_dir( $dir ) ) {
                return array();
            }
            $paths = glob( trailingslashit( $dir ) . '*.zip' );
            if ( empty( $paths ) ) {
                return array();
            }
            // Sort by mtime desc.
            usort( $paths, static function( $a, $b ) { return filemtime( $b ) <=> filemtime( $a ); } );
            $out = array();
            foreach ( $paths as $p ) {
                if ( ! is_file( $p ) ) { continue; }
                $out[] = array(
                    'file'       => basename( $p ),
                    'path'       => $p,
                    'size'       => (int) filesize( $p ),
                    'created_at' => (int) filemtime( $p ),
                );
            }
            return $out;
        }

        /**
         * Get the latest mtime from a prepared or minimal archives list.
         *
         * @param array $archives
         * @return int
         */
        protected function get_latest_archive_mtime( $archives ) {
            $latest = 0;
            foreach ( (array) $archives as $a ) {
                $t = 0;
                if ( isset( $a['created_at'] ) ) { $t = (int) $a['created_at']; }
                elseif ( isset( $a['path'] ) && file_exists( $a['path'] ) ) { $t = (int) filemtime( $a['path'] ); }
                if ( $t > $latest ) { $latest = $t; }
            }
            return $latest;
        }

        /**
         * AJAX: delete an archive file from disk.
         */
        public function handle_ajax_delete_request() {
            if ( ! current_user_can( 'upload_files' ) ) {
                wp_send_json_error( array( 'message' => esc_html__( 'Action non autorisée.', 'real-media-export' ) ), 403 );
            }

            check_ajax_referer( self::NONCE_ACTION );

            $file_name = isset( $_POST['file'] ) ? sanitize_file_name( wp_unslash( $_POST['file'] ) ) : '';
            if ( '' === $file_name ) {
                wp_send_json_error( array( 'message' => esc_html__( 'Paramètre manquant.', 'real-media-export' ) ) );
            }

            $dir = $this->get_export_directory();
            $full_path = trailingslashit( $dir ) . $file_name;
            $real_dir = realpath( $dir );
            $real_path = realpath( $full_path );
            if ( false === $real_path || false === $real_dir || strpos( $real_path, $real_dir ) !== 0 || ! file_exists( $real_path ) ) {
                wp_send_json_error( array( 'message' => esc_html__( 'Fichier introuvable.', 'real-media-export' ) ) );
            }

            $ok = @unlink( $real_path );
            if ( ! $ok ) {
                wp_send_json_error( array( 'message' => esc_html__( 'Échec de suppression du fichier.', 'real-media-export' ) ) );
            }

            wp_send_json_success( array( 'message' => esc_html__( 'Archive supprimée.', 'real-media-export' ), 'file' => $file_name ) );
        }

        /**
         * Build HTML options for the folder selector.
         *
         * @param int $selected Selected folder ID.
         *
         * @return string
         */
        protected function get_folder_options_html( $selected ) {
            $taxonomy = $this->get_folder_taxonomy();

            // 1) Prefer RML tree (API or DB table) for perfect parity with UI.
            if ( $this->rml_has_tree() ) {
                $tree = $this->rml_fetch_tree();
                if ( ! empty( $tree['by_parent'] ) ) {
                    $root_parent = isset( $tree['root_parent'] ) ? (int) $tree['root_parent'] : 0;
                    return $this->render_folder_options_recursive( $tree['by_parent'], $root_parent, $selected );
                }
            }

            // 2) If the taxonomy is registered, use WP's get_terms.
            if ( ! empty( $taxonomy ) && taxonomy_exists( $taxonomy ) ) {
                $terms = get_terms(
                    array(
                        'taxonomy'   => $taxonomy,
                        'hide_empty' => false,
                        'orderby'    => 'name',
                    )
                );

                if ( is_wp_error( $terms ) || empty( $terms ) ) {
                    return '';
                }

                $by_parent = array();
                foreach ( $terms as $term ) {
                    $parent = (int) $term->parent;
                    if ( ! isset( $by_parent[ $parent ] ) ) {
                        $by_parent[ $parent ] = array();
                    }
                    $by_parent[ $parent ][] = $term;
                }

                return $this->render_folder_options_recursive( $by_parent, 0, $selected );
            }

            // 3) Fallback to DB if taxonomy is only present in the database.
            if ( empty( $taxonomy ) || ! $this->db_taxonomy_exists( $taxonomy ) ) {
                return '';
            }
            global $wpdb;
            $t_table  = $wpdb->terms;
            $tt_table = $wpdb->term_taxonomy;

            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT t.term_id, t.name, tt.parent
                     FROM {$tt_table} tt
                     INNER JOIN {$t_table} t ON t.term_id = tt.term_id
                     WHERE tt.taxonomy = %s",
                    $taxonomy
                )
            );

            if ( empty( $rows ) ) {
                return '';
            }

            $by_parent = array();
            foreach ( $rows as $row ) {
                $obj = (object) array(
                    'term_id' => (int) $row->term_id,
                    'name'    => (string) $row->name,
                    'parent'  => (int) $row->parent,
                );
                $parent = $obj->parent;
                if ( ! isset( $by_parent[ $parent ] ) ) {
                    $by_parent[ $parent ] = array();
                }
                $by_parent[ $parent ][] = $obj;
            }

            return $this->render_folder_options_recursive( $by_parent, 0, $selected );
        }

        /**
         * Render folder options recursively.
         *
         * @param array $by_parent Map of parent => array of terms.
         * @param int   $parent    Current parent ID.
         * @param int   $selected  Selected term ID.
         * @param int   $depth     Depth level.
         *
         * @return string
         */
        protected function render_folder_options_recursive( $by_parent, $parent, $selected, $depth = 0 ) {
            if ( empty( $by_parent[ $parent ] ) ) {
                return '';
            }

            usort(
                $by_parent[ $parent ],
                static function( $a, $b ) {
                    return strcasecmp( $a->name, $b->name );
                }
            );

            $html = '';
            foreach ( $by_parent[ $parent ] as $term ) {
                $indent = str_repeat( '&#8212; ', $depth );
                $html  .= sprintf(
                    '<option value="%1$d" %2$s>%3$s%4$s</option>',
                    (int) $term->term_id,
                    selected( $selected, (int) $term->term_id, false ),
                    $indent,
                    esc_html( $term->name )
                );
                $html .= $this->render_folder_options_recursive( $by_parent, (int) $term->term_id, $selected, $depth + 1 );
            }

            return $html;
        }

        /**
         * Handle export form submission.
         */
        public function handle_export_request() {
            if ( ! current_user_can( 'upload_files' ) ) {
                wp_die( esc_html__( 'Action non autorisée.', 'real-media-export' ) );
            }

            check_admin_referer( self::NONCE_ACTION );

            

            $params = array(
                'folder_id'          => isset( $_POST['folder_id'] ) ? absint( $_POST['folder_id'] ) : 0,
                'include_children'   => ! empty( $_POST['include_children'] ),
                'max_size_mb'        => isset( $_POST['max_size_mb'] ) ? floatval( $_POST['max_size_mb'] ) : 0,
                'archive_prefix'     => isset( $_POST['archive_prefix'] ) ? sanitize_text_field( wp_unslash( $_POST['archive_prefix'] ) ) : '',
                'preserve_structure' => ! empty( $_POST['preserve_structure'] ),
            );

            $result = $this->process_export( $params );

            $this->store_result( $result );
            $this->redirect_after_action();
        }

        /**
         * Handle AJAX export requests.
         */
        public function handle_ajax_export_request() {
            if ( ! current_user_can( 'upload_files' ) ) {
                wp_send_json_error(
                    array(
                        'status'       => 'error',
                        'message_html' => esc_html__( 'Action non autorisée.', 'real-media-export' ),
                        'message_text' => esc_html__( 'Action non autorisée.', 'real-media-export' ),
                        'archives'     => array(),
                    ),
                    403
                );
            }

            check_ajax_referer( self::NONCE_ACTION );

            

            $params = array(
                'folder_id'          => isset( $_POST['folder_id'] ) ? absint( $_POST['folder_id'] ) : 0,
                'include_children'   => ! empty( $_POST['include_children'] ),
                'max_size_mb'        => isset( $_POST['max_size_mb'] ) ? floatval( $_POST['max_size_mb'] ) : 0,
                'archive_prefix'     => isset( $_POST['archive_prefix'] ) ? sanitize_text_field( wp_unslash( $_POST['archive_prefix'] ) ) : '',
                'preserve_structure' => ! empty( $_POST['preserve_structure'] ),
            );

            $result  = $this->process_export( $params );
            $payload = $this->prepare_result_for_js( $result );

            if ( null === $payload ) {
                $payload = array(
                    'status'       => 'error',
                    'message_html' => esc_html__( 'Une erreur inattendue est survenue.', 'real-media-export' ),
                    'message_text' => esc_html__( 'Une erreur inattendue est survenue.', 'real-media-export' ),
                    'archives'     => array(),
                );
            }

            if ( isset( $result['status'] ) && 'error' === $result['status'] ) {
                wp_send_json_error( $payload );
            }

            wp_send_json_success( $payload );
        }

        /**
         * Process an export request and return the result payload.
         *
         * @param array $params Export parameters.
         *
         * @return array
         */
        protected function process_export( $params ) {
            // Reset activity log at the beginning of a run.
            $this->reset_activity_log();

            $defaults = array(
                'folder_id'          => 0,
                'include_children'   => true,
                'max_size_mb'        => 0,
                'archive_prefix'     => '',
                'preserve_structure' => true,
            );

            $params = wp_parse_args( $params, $defaults );

            $result = array(
                'status'   => 'error',
                'message'  => '',
                'archives' => array(),
            );

            $taxonomy = $this->get_folder_taxonomy();
            $has_tree = $this->rml_has_tree();
            $taxonomy_ok = ( ! empty( $taxonomy ) && $this->taxonomy_is_available( $taxonomy ) );
            if ( ! $has_tree && ! $taxonomy_ok ) {
                $result['message'] = esc_html__( 'Impossible de détecter une source de dossiers (RML).', 'real-media-export' );
                return $result;
            }

            if ( $params['folder_id'] <= 0 ) {
                $result['message'] = esc_html__( 'Veuillez sélectionner un dossier à exporter.', 'real-media-export' );

                return $result;
            }

            // Validate selected folder — prefer RML tree first because the UI select is built from it.
            $folder = null;
            if ( $has_tree ) {
                $tree = $this->rml_fetch_tree();
                if ( isset( $tree['by_id'][ (int) $params['folder_id'] ] ) ) {
                    $node   = $tree['by_id'][ (int) $params['folder_id'] ];
                    $folder = (object) array(
                        'term_id' => (int) $node['id'],
                        'name'    => (string) $node['name'],
                        'parent'  => (int) $node['parent'],
                    );
                }
            }
            // Fallback to taxonomy lookup if needed.
            if ( ! $folder && $taxonomy_ok ) {
                $folder = taxonomy_exists( $taxonomy )
                    ? get_term( $params['folder_id'], $taxonomy )
                    : $this->db_get_term( (int) $params['folder_id'], $taxonomy );
            }
            if ( ! $folder || ( is_object( $folder ) && isset( $folder->errors ) ) ) {
                $result['message'] = esc_html__( 'Le dossier demandé est introuvable.', 'real-media-export' );

                return $result;
            }

            $term_ids = array( (int) $params['folder_id'] );
            if ( $params['include_children'] ) {
                $descendants = array();
                if ( $this->rml_has_tree() ) {
                    $descendants = $this->rml_get_descendant_ids( (int) $params['folder_id'] );
                } elseif ( $taxonomy_ok ) {
                    $descendants = $this->db_get_descendant_term_ids( (int) $params['folder_id'], $taxonomy );
                }
                if ( ! empty( $descendants ) ) {
                    $term_ids = array_unique( array_merge( $term_ids, array_map( 'intval', $descendants ) ) );
                }
            }

            $attachments = $this->query_attachments( $term_ids, $taxonomy_ok ? $taxonomy : '', (bool) $params['include_children'] );

            if ( empty( $attachments ) ) {
                $result['status']  = 'warning';
                $result['message'] = esc_html__( 'Aucun fichier n’a été trouvé pour les paramètres choisis.', 'real-media-export' );

                return $result;
            }

            $archives = $this->create_archives(
                $attachments,
                array(
                    'folder_id'          => (int) $params['folder_id'],
                    'term_ids'           => $term_ids,
                    'taxonomy'           => $taxonomy_ok ? $taxonomy : '',
                    'max_size_mb'        => $params['max_size_mb'],
                    'archive_prefix'     => $params['archive_prefix'],
                    'preserve_structure' => $params['preserve_structure'],
                )
            );

            if ( empty( $archives['archives'] ) ) {
                return $archives;
            }

            $archives['archives']            = $this->prepare_archives_for_client( $archives['archives'] );
            $archives['generated_at']         = current_time( 'timestamp' );
            $archives['generated_at_formatted'] = wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $archives['generated_at'] );
            $archives['summary']              = array(
                'files_total'    => isset( $archives['files_total'] ) ? (int) $archives['files_total'] : count( $attachments ),
                'files_exported' => isset( $archives['files_exported'] ) ? (int) $archives['files_exported'] : count( $attachments ),
                'files_skipped'  => isset( $archives['files_skipped'] ) ? count( (array) $archives['files_skipped'] ) : 0,
                'archives_count' => count( $archives['archives'] ),
            );

            if ( isset( $archives['files_skipped'] ) && is_array( $archives['files_skipped'] ) ) {
                $archives['files_skipped'] = array_map( 'wp_strip_all_tags', $archives['files_skipped'] );
            }

            update_user_meta( get_current_user_id(), 'real_media_export_last_folder', (int) $params['folder_id'] );

            return $archives;
        }

        /**
         * Prepare archive details for display or API usage.
         *
         * @param array $archives Archives list.
         *
         * @return array
         */
        protected function prepare_archives_for_client( $archives ) {
            $prepared = array();

            foreach ( (array) $archives as $archive ) {
                $file_name   = isset( $archive['file'] ) ? wp_strip_all_tags( $archive['file'] ) : '';
                $size        = isset( $archive['size'] ) ? (int) $archive['size'] : 0;
                $bytes_total = isset( $archive['bytes_total'] ) ? (int) $archive['bytes_total'] : 0;
                $created_at  = isset( $archive['created_at'] ) ? (int) $archive['created_at'] : 0;

                if ( ! $created_at && ! empty( $archive['path'] ) && file_exists( $archive['path'] ) ) {
                    $created_at = (int) filemtime( $archive['path'] );
                }

                $folders = array();
                if ( isset( $archive['folders'] ) ) {
                    foreach ( (array) $archive['folders'] as $folder ) {
                        $folder = wp_strip_all_tags( $folder );
                        if ( '' !== $folder ) {
                            $folders[] = $folder;
                        }
                    }
                }
                $folders = array_values( array_unique( $folders ) );

                $files_preview = array();
                if ( isset( $archive['files_preview'] ) ) {
                    foreach ( (array) $archive['files_preview'] as $preview ) {
                        $preview = wp_strip_all_tags( $preview );
                        if ( '' !== $preview ) {
                            $files_preview[] = $preview;
                        }
                    }
                }

                $download_url = $this->get_download_url( $file_name );

                $prepared[] = array(
                    'file'                 => $file_name,
                    'size'                 => $size,
                    'size_human'           => $size > 0 ? size_format( $size ) : '',
                    'bytes_total'          => $bytes_total,
                    'bytes_total_human'    => $bytes_total > 0 ? size_format( $bytes_total ) : '',
                    'created_at'           => $created_at,
                    'created_at_formatted' => $created_at ? wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $created_at ) : '',
                    'file_count'           => isset( $archive['file_count'] ) ? (int) $archive['file_count'] : 0,
                    'files_preview'        => $files_preview,
                    'folders'              => $folders,
                    'max_size_reached'     => ! empty( $archive['max_size_reached'] ),
                    'download_url'         => $download_url ? esc_url_raw( $download_url ) : '',
                );
            }

            return $prepared;
        }

        /**
         * Prepare a result payload to be consumed by JavaScript.
         *
         * @param array $result Result data.
         *
         * @return array|null
         */
        protected function prepare_result_for_js( $result ) {
            if ( empty( $result ) || ! is_array( $result ) ) {
                return null;
            }

            $status  = isset( $result['status'] ) ? $result['status'] : '';
            $message = isset( $result['message'] ) ? $result['message'] : '';

            $archives = isset( $result['archives'] ) ? (array) $result['archives'] : array();
            if ( ! empty( $archives ) && ( ! isset( $archives[0]['size_human'] ) || ! array_key_exists( 'download_url', $archives[0] ) ) ) {
                $archives = $this->prepare_archives_for_client( $archives );
            }

            $archives_data = array();
            foreach ( $archives as $archive ) {
                $archives_data[] = array(
                    'file'                 => isset( $archive['file'] ) ? $archive['file'] : '',
                    'size'                 => isset( $archive['size'] ) ? (int) $archive['size'] : 0,
                    'size_human'           => isset( $archive['size_human'] ) ? $archive['size_human'] : ( isset( $archive['size'] ) ? size_format( (int) $archive['size'] ) : '' ),
                    'bytes_total'          => isset( $archive['bytes_total'] ) ? (int) $archive['bytes_total'] : 0,
                    'bytes_total_human'    => isset( $archive['bytes_total_human'] ) ? $archive['bytes_total_human'] : '',
                    'created_at'           => isset( $archive['created_at'] ) ? (int) $archive['created_at'] : 0,
                    'created_at_formatted' => isset( $archive['created_at_formatted'] ) ? $archive['created_at_formatted'] : '',
                    'file_count'           => isset( $archive['file_count'] ) ? (int) $archive['file_count'] : 0,
                    'files_preview'        => isset( $archive['files_preview'] ) ? array_values( (array) $archive['files_preview'] ) : array(),
                    'folders'              => isset( $archive['folders'] ) ? array_values( (array) $archive['folders'] ) : array(),
                    'max_size_reached'     => ! empty( $archive['max_size_reached'] ),
                    'download_url'         => isset( $archive['download_url'] ) ? $archive['download_url'] : $this->get_download_url( isset( $archive['file'] ) ? $archive['file'] : '' ),
                );
            }

            $summary = array(
                'files_total'    => 0,
                'files_exported' => 0,
                'files_skipped'  => 0,
                'archives_count' => count( $archives_data ),
            );

            if ( isset( $result['summary'] ) && is_array( $result['summary'] ) ) {
                $summary['files_total']    = isset( $result['summary']['files_total'] ) ? (int) $result['summary']['files_total'] : $summary['files_total'];
                $summary['files_exported'] = isset( $result['summary']['files_exported'] ) ? (int) $result['summary']['files_exported'] : $summary['files_exported'];
                $summary['files_skipped']  = isset( $result['summary']['files_skipped'] ) ? (int) $result['summary']['files_skipped'] : $summary['files_skipped'];
                $summary['archives_count'] = isset( $result['summary']['archives_count'] ) ? (int) $result['summary']['archives_count'] : $summary['archives_count'];
            } else {
                $summary['files_total']    = isset( $result['files_total'] ) ? (int) $result['files_total'] : $summary['files_total'];
                $summary['files_exported'] = isset( $result['files_exported'] ) ? (int) $result['files_exported'] : $summary['files_exported'];
                $summary['files_skipped']  = isset( $result['files_skipped'] ) ? count( (array) $result['files_skipped'] ) : $summary['files_skipped'];
            }

            $generated_at         = isset( $result['generated_at'] ) ? (int) $result['generated_at'] : 0;
            $generated_at_display = isset( $result['generated_at_formatted'] ) ? $result['generated_at_formatted'] : ( $generated_at ? wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $generated_at ) : '' );

            return array(
                'status'                 => $status,
                'message_html'           => $message ? wp_kses_post( $message ) : '',
                'message_text'           => $message ? wp_strip_all_tags( $message ) : '',
                'archives'               => $archives_data,
                'generated_at'           => $generated_at,
                'generated_at_formatted' => $generated_at_display,
                'summary'                => $summary,
                'files_skipped'          => isset( $result['files_skipped'] ) ? array_values( array_map( 'wp_strip_all_tags', (array) $result['files_skipped'] ) ) : array(),
                'activity'               => $this->get_activity_log(),
            );
        }

        /**
         * Query attachments assigned to selected terms.
         *
         * @param array  $term_ids          Term IDs to include.
         * @param string $taxonomy          Taxonomy name.
         * @param bool   $include_children  Whether to include child terms.
         *
         * @return int[] List of attachment IDs.
         */
        protected function query_attachments( $term_ids, $taxonomy, $include_children ) {
            $term_ids = array_filter( array_map( 'intval', (array) $term_ids ) );
            if ( empty( $term_ids ) ) {
                return array();
            }

            // Prefer RML API if available.
            if ( $this->rml_attachment_available() ) {
                $this->rml_terms_by_object = array();
                $all = array();
                foreach ( $term_ids as $folder_id ) {
                    $ids = $this->rml_get_attachments_for_folder( (int) $folder_id );
                    foreach ( $ids as $aid ) {
                        $all[ $aid ] = true;
                        if ( ! isset( $this->rml_terms_by_object[ $aid ] ) ) {
                            $this->rml_terms_by_object[ $aid ] = array();
                        }
                        // Ensure we keep all folder assignments found in selected subtree.
                        $this->rml_terms_by_object[ $aid ][] = (int) $folder_id;
                    }
                }
                return array_map( 'intval', array_keys( $all ) );
            }

            global $wpdb;
            $posts_table = $wpdb->posts;
            $tr_table    = $wpdb->term_relationships;
            $tt_table    = $wpdb->term_taxonomy;

            // Prepare IN clause safely.
            $placeholders = implode( ',', array_fill( 0, count( $term_ids ), '%d' ) );
            $sql          = $wpdb->prepare(
                "SELECT DISTINCT p.ID
                 FROM {$posts_table} p
                 INNER JOIN {$tr_table} tr ON tr.object_id = p.ID
                 INNER JOIN {$tt_table} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
                 WHERE p.post_type = 'attachment'
                   AND p.post_status = 'inherit'
                   AND tt.taxonomy = %s
                   AND tt.term_id IN ($placeholders)",
                array_merge( array( $taxonomy ), $term_ids )
            );

            $ids = $wpdb->get_col( $sql );
            if ( empty( $ids ) ) {
                return array();
            }
            return array_map( 'intval', $ids );
        }

        /**
         * Create the ZIP archives for the provided attachments.
         *
         * @param array $attachment_ids List of attachment IDs.
         * @param array $options        Export options.
         *
         * @return array Result data.
         */
        protected function create_archives( $attachment_ids, $options ) {
            $defaults = array(
                'folder_id'          => 0,
                'term_ids'           => array(),
                'taxonomy'           => '',
                'max_size_mb'        => 0,
                'archive_prefix'     => '',
                'preserve_structure' => true,
            );
            $options  = wp_parse_args( $options, $defaults );

            $max_size_bytes = 0;
            if ( $options['max_size_mb'] > 0 ) {
                $max_size_bytes = (float) $options['max_size_mb'] * 1024 * 1024;
            }

            if ( ! class_exists( 'ZipArchive' ) ) {
                return array(
                    'status'  => 'error',
                    'message' => esc_html__( 'La classe ZipArchive n’est pas disponible sur ce serveur. Veuillez activer l’extension ZIP de PHP.', 'real-media-export' ),
                );
            }

            $export_dir = $this->get_export_directory();
            if ( ! wp_mkdir_p( $export_dir ) ) {
                return array(
                    'status'  => 'error',
                    'message' => esc_html__( 'Impossible de créer le dossier d’export.', 'real-media-export' ),
                );
            }

            $term_map = $this->build_term_map( $options['term_ids'], $options['taxonomy'] );
            if ( empty( $term_map ) || ! isset( $term_map[ $options['folder_id'] ] ) ) {
                return array(
                    'status'  => 'error',
                    'message' => esc_html__( 'Impossible de déterminer la hiérarchie des dossiers sélectionnés.', 'real-media-export' ),
                );
            }

            // Build a lightweight map of attachment_id => array of term objects (term_id only).
            if ( $this->rml_attachment_available() && ! empty( $this->rml_terms_by_object ) ) {
                $terms_by_object = array();
                foreach ( $this->rml_terms_by_object as $aid => $folder_ids ) {
                    foreach ( array_unique( array_map( 'intval', (array) $folder_ids ) ) as $fid ) {
                        $terms_by_object[ (int) $aid ][] = (object) array( 'term_id' => (int) $fid );
                    }
                }
            } else {
                $terms_by_object = $this->db_get_attachment_terms_map( $attachment_ids, $options['taxonomy'] );
            }

            $archives       = array();
            $current_zip    = null;
            $current_size   = 0;
            $archive_index  = 0;
            $files_in_zip   = 0;
            $files_total    = 0;
            $files_skipped  = array();
            $current_archive_meta = array();
            $timestamp      = gmdate( 'Ymd-His' );
            $prefix         = $options['archive_prefix'] ? sanitize_title( $options['archive_prefix'] ) : sanitize_title( get_bloginfo( 'name' ) );
            if ( empty( $prefix ) ) {
                $prefix = 'real-media-export';
            }
            $root_folder_slug = '';
            if ( isset( $term_map[ $options['folder_id'] ] ) ) {
                $root_name = (string) $term_map[ $options['folder_id'] ]->name;
                $root_folder_slug = sanitize_title( $root_name );
            }
            if ( '' === $root_folder_slug ) {
                $root_folder_slug = 'dossier';
            }
            $base_filename = $prefix . '-' . $root_folder_slug . '-' . $timestamp;

            $close_archive = function() use ( &$current_zip, &$archives, &$archive_index, &$files_in_zip, &$current_size, $export_dir, &$current_archive_meta ) {
                if ( $current_zip instanceof ZipArchive ) {
                    $current_zip->close();

                    if ( $files_in_zip > 0 ) {
                        $full_path = '';
                        if ( ! empty( $current_archive_meta['path'] ) ) {
                            $full_path = $current_archive_meta['path'];
                        } elseif ( ! empty( $current_zip->filename ) ) {
                            $full_path = $current_zip->filename;
                        }

                        if ( $full_path && file_exists( $full_path ) ) {
                            $current_archive_meta['path'] = $full_path;
                            $current_archive_meta['file'] = basename( $full_path );
                            $current_archive_meta['size'] = filesize( $full_path );
                            if ( empty( $current_archive_meta['created_at'] ) ) {
                                $current_archive_meta['created_at'] = filemtime( $full_path );
                            }
                        }

                        if ( empty( $current_archive_meta['folders'] ) ) {
                            $current_archive_meta['folders'] = array();
                        } else {
                            $current_archive_meta['folders'] = array_values( array_unique( array_filter( $current_archive_meta['folders'] ) ) );
                        }

                        if ( empty( $current_archive_meta['files_preview'] ) ) {
                            $current_archive_meta['files_preview'] = array();
                        } else {
                            $current_archive_meta['files_preview'] = array_values( array_filter( $current_archive_meta['files_preview'] ) );
                        }

                        if ( ! isset( $current_archive_meta['size'] ) ) {
                            $current_archive_meta['size'] = ( $full_path && file_exists( $full_path ) ) ? filesize( $full_path ) : 0;
                        }

                        if ( ! isset( $current_archive_meta['bytes_total'] ) ) {
                            $current_archive_meta['bytes_total'] = 0;
                        }

                        if ( ! isset( $current_archive_meta['file_count'] ) ) {
                            $current_archive_meta['file_count'] = 0;
                        }

                        $archives[] = $current_archive_meta;
                    } elseif ( ! empty( $current_zip->filename ) && file_exists( $current_zip->filename ) ) {
                        // Remove empty archive.
                        unlink( $current_zip->filename );
                    }
                }

                $current_zip          = null;
                $files_in_zip         = 0;
                $current_size         = 0;
                $current_archive_meta = array();
                $archive_index++;
            };

            $open_archive = function() use ( &$current_zip, &$archive_index, &$base_filename, $export_dir, &$files_in_zip, &$current_archive_meta ) {
                $filename   = sprintf( '%s-part-%02d.zip', $base_filename, $archive_index + 1 );
                $full_path  = trailingslashit( $export_dir ) . $filename;
                $zip        = new ZipArchive();
                $open_result = $zip->open( $full_path, ZipArchive::CREATE | ZipArchive::OVERWRITE );
                if ( true !== $open_result ) {
                    return new WP_Error( 'zip_open_failed', sprintf( esc_html__( 'Impossible de créer le fichier ZIP (%s).', 'real-media-export' ), $filename ) );
                }

                $current_zip  = $zip;
                $files_in_zip = 0;
                $current_archive_meta = array(
                    'file'             => $filename,
                    'path'             => $full_path,
                    'size'             => 0,
                    'created_at'       => current_time( 'timestamp' ),
                    'file_count'       => 0,
                    'files_preview'    => array(),
                    'folders'          => array(),
                    'bytes_total'      => 0,
                    'max_size_reached' => false,
                );

                return $zip;
            };

            foreach ( $attachment_ids as $attachment_id ) {
                $attachment_id = (int) $attachment_id;
                $file_path     = get_attached_file( $attachment_id );
                $files_total++;

                if ( empty( $file_path ) || ! file_exists( $file_path ) ) {
                    $files_skipped[] = sprintf( esc_html__( 'Le fichier original de la pièce jointe #%d est introuvable.', 'real-media-export' ), $attachment_id );
                    continue;
                }

                $file_size = filesize( $file_path );

                if ( $max_size_bytes > 0 && $file_size > $max_size_bytes ) {
                    $files_skipped[] = sprintf( esc_html__( 'Le fichier %1$s (%2$s) dépasse la taille maximale autorisée.', 'real-media-export' ), basename( $file_path ), size_format( $file_size ) );
                    continue;
                }

                if ( ! ( $current_zip instanceof ZipArchive ) ) {
                    $opened = $open_archive();
                    if ( is_wp_error( $opened ) ) {
                        return array(
                            'status'  => 'error',
                            'message' => $opened->get_error_message(),
                        );
                    }
                }

                if ( $max_size_bytes > 0 && ( $current_size + $file_size ) > $max_size_bytes && $files_in_zip > 0 ) {
                    if ( ! empty( $current_archive_meta ) ) {
                        $current_archive_meta['max_size_reached'] = true;
                    }
                    $close_archive();
                    $opened = $open_archive();
                    if ( is_wp_error( $opened ) ) {
                        return array(
                            'status'  => 'error',
                            'message' => $opened->get_error_message(),
                        );
                    }
                }

                $zip_path = basename( $file_path );
                if ( $options['preserve_structure'] ) {
                    $zip_path = $this->build_zip_path( $attachment_id, $terms_by_object, $term_map, $options['folder_id'], $zip_path, $options['taxonomy'] );
                }

                $add_result = $current_zip->addFile( $file_path, $zip_path );
                if ( ! $add_result ) {
                    $files_skipped[] = sprintf( esc_html__( 'Impossible d’ajouter %s à l’archive.', 'real-media-export' ), basename( $file_path ) );
                    continue;
                }

                if ( ! empty( $current_archive_meta ) ) {
                    $current_archive_meta['file_count'] = isset( $current_archive_meta['file_count'] ) ? (int) $current_archive_meta['file_count'] + 1 : 1;
                    $current_archive_meta['bytes_total'] = isset( $current_archive_meta['bytes_total'] ) ? (int) $current_archive_meta['bytes_total'] + (int) $file_size : (int) $file_size;
                    if ( count( $current_archive_meta['files_preview'] ) < 3 ) {
                        $current_archive_meta['files_preview'][] = basename( $file_path );
                    }
                    if ( false !== strpos( $zip_path, '/' ) ) {
                        $segments   = explode( '/', $zip_path );
                        $top_folder = reset( $segments );
                        if ( '' !== $top_folder ) {
                            $current_archive_meta['folders'][] = $top_folder;
                        }
                    }
                }

                $current_size += $file_size;
                $files_in_zip++;
            }

            // Close last archive.
            if ( $current_zip instanceof ZipArchive ) {
                $close_archive();
            }

            if ( empty( $archives ) ) {
                return array(
                    'status'  => 'warning',
                    'message' => esc_html__( 'Aucune archive n’a été générée.', 'real-media-export' ),
                );
            }

            $status  = 'success';
            $message = sprintf(
                /* translators: %1$d: number of archives, %2$s: number of files. */
                esc_html__( '%1$d archive(s) générée(s) contenant %2$s fichier(s).', 'real-media-export' ),
                count( $archives ),
                number_format_i18n( $files_total - count( $files_skipped ) )
            );

            if ( ! empty( $files_skipped ) ) {
                $message .= '<br />' . esc_html__( 'Certains fichiers ont été ignorés :', 'real-media-export' ) . '<ul><li>' . implode( '</li><li>', array_map( 'esc_html', $files_skipped ) ) . '</li></ul>';
                $status   = 'warning';
            }

            return array(
                'status'         => $status,
                'message'        => $message,
                'archives'       => $archives,
                'files_total'    => $files_total,
                'files_exported' => $files_total - count( $files_skipped ),
                'files_skipped'  => $files_skipped,
            );
        }

        /**
         * Build a mapping of term data for quick access.
         *
         * @param array  $term_ids Term IDs.
         * @param string $taxonomy Taxonomy name.
         *
         * @return array
         */
        protected function build_term_map( $term_ids, $taxonomy ) {
            $term_ids = array_filter( array_map( 'intval', (array) $term_ids ) );
            if ( empty( $term_ids ) ) {
                return array();
            }

            // Prefer RML tree (API or DB table) to match UI naming/order.
            $tree = $this->rml_fetch_tree();
            if ( ! empty( $tree ) && ! empty( $tree['by_id'] ) ) {
                $map = array();
                foreach ( $term_ids as $tid ) {
                    if ( isset( $tree['by_id'][ $tid ] ) ) {
                        $n = $tree['by_id'][ $tid ];
                        $map[ $tid ] = (object) array(
                            'term_id' => (int) $n['id'],
                            'name'    => (string) $n['name'],
                            'parent'  => (int) $n['parent'],
                        );
                    }
                }
                if ( ! empty( $map ) ) {
                    return $map;
                }
            }

            // Fallback to DB.
            global $wpdb;
            $t_table  = $wpdb->terms;
            $tt_table = $wpdb->term_taxonomy;

            $placeholders = implode( ',', array_fill( 0, count( $term_ids ), '%d' ) );
            $sql          = $wpdb->prepare(
                "SELECT t.term_id, t.name, tt.parent
                 FROM {$tt_table} tt
                 INNER JOIN {$t_table} t ON t.term_id = tt.term_id
                 WHERE tt.taxonomy = %s
                   AND t.term_id IN ($placeholders)",
                array_merge( array( $taxonomy ), $term_ids )
            );

            $rows = $wpdb->get_results( $sql );
            if ( empty( $rows ) ) {
                return array();
            }

            $map = array();
            foreach ( $rows as $row ) {
                $obj           = (object) array(
                    'term_id' => (int) $row->term_id,
                    'name'    => (string) $row->name,
                    'parent'  => (int) $row->parent,
                );
                $map[ $obj->term_id ] = $obj;
            }

            return $map;
        }

        /**
         * Build the path inside the ZIP archive for an attachment.
         *
         * @param int    $attachment_id   Attachment ID.
         * @param array  $terms_by_object Map of object ID => array of WP_Term.
         * @param array  $term_map        Map of term ID => WP_Term.
         * @param int    $root_folder_id  Selected root folder ID.
         * @param string $filename        Default filename.
         * @param string $taxonomy        Taxonomy name.
         *
         * @return string
         */
        protected function build_zip_path( $attachment_id, $terms_by_object, $term_map, $root_folder_id, $filename, $taxonomy ) {
            if ( empty( $terms_by_object[ $attachment_id ] ) ) {
                return $filename;
            }

            $chosen_term = null;
            $chosen_depth = -1;

            foreach ( $terms_by_object[ $attachment_id ] as $term ) {
                if ( ! isset( $term_map[ $term->term_id ] ) ) {
                    continue;
                }

                $depth = $this->calculate_term_depth( $term->term_id, $term_map, $root_folder_id, $taxonomy );
                if ( $depth < 0 ) {
                    continue;
                }

                if ( $depth >= $chosen_depth ) {
                    $chosen_depth = $depth;
                    $chosen_term  = $term_map[ $term->term_id ];
                }
            }

            if ( ! $chosen_term ) {
                return $filename;
            }

            $segments = array();
            $current  = $chosen_term;
            while ( $current && (int) $current->term_id !== (int) $root_folder_id ) {
                $segments[] = $this->sanitize_zip_segment( $current->name );
                if ( empty( $current->parent ) || ! isset( $term_map[ $current->parent ] ) ) {
                    // Try to fill missing parent from DB for robustness (only when taxonomy is known).
                    if ( ! empty( $taxonomy ) ) {
                        global $wpdb;
                        $t_table  = $wpdb->terms;
                        $tt_table = $wpdb->term_taxonomy;
                        $parent_id = (int) $current->parent;
                        $parent_row = $wpdb->get_row(
                            $wpdb->prepare(
                                "SELECT t.term_id, t.name, tt.parent
                                 FROM {$tt_table} tt
                                 INNER JOIN {$t_table} t ON t.term_id = tt.term_id
                                 WHERE tt.taxonomy = %s AND t.term_id = %d",
                                $taxonomy,
                                $parent_id
                            )
                        );
                        if ( empty( $parent_row ) ) {
                            break;
                        }
                        $term_map[ $parent_id ] = (object) array(
                            'term_id' => (int) $parent_row->term_id,
                            'name'    => (string) $parent_row->name,
                            'parent'  => (int) $parent_row->parent,
                        );
                    } else {
                        break;
                    }
                }

                if ( (int) $current->parent === (int) $root_folder_id ) {
                    break;
                }

                $current = $term_map[ $current->parent ];
            }

            $segments = array_reverse( $segments );
            if ( ! empty( $segments ) ) {
                $path = implode( '/', $segments ) . '/' . $filename;
            } else {
                $path = $filename;
            }

            return $path;
        }

        /**
         * Calculate the depth of a term relative to the selected folder.
         *
         * @param int    $term_id        Term ID.
         * @param array  $term_map       Term map.
         * @param int    $root_folder_id Root folder ID.
         * @param string $taxonomy       Taxonomy name.
         *
         * @return int Depth or -1 if the term is not a descendant.
         */
        protected function calculate_term_depth( $term_id, $term_map, $root_folder_id, $taxonomy ) {
            if ( ! isset( $term_map[ $term_id ] ) ) {
                return -1;
            }

            if ( (int) $term_id === (int) $root_folder_id ) {
                return 0;
            }

            $depth       = 0;
            $current_id  = $term_id;
            $safety      = 0;
            $max_loops   = 200;

            while ( $current_id && $safety < $max_loops ) {
                $safety++;
                $term = isset( $term_map[ $current_id ] ) ? $term_map[ $current_id ] : null;
                if ( ! $term ) {
                    global $wpdb;
                    $t_table  = $wpdb->terms;
                    $tt_table = $wpdb->term_taxonomy;
                    $row      = $wpdb->get_row(
                        $wpdb->prepare(
                            "SELECT t.term_id, t.name, tt.parent
                             FROM {$tt_table} tt
                             INNER JOIN {$t_table} t ON t.term_id = tt.term_id
                             WHERE tt.taxonomy = %s AND t.term_id = %d",
                            $taxonomy,
                            (int) $current_id
                        )
                    );
                    if ( empty( $row ) ) {
                        return -1;
                    }
                    $term = (object) array(
                        'term_id' => (int) $row->term_id,
                        'name'    => (string) $row->name,
                        'parent'  => (int) $row->parent,
                    );
                    $term_map[ $term->term_id ] = $term;
                }

                if ( empty( $term->parent ) ) {
                    return (int) $term->term_id === (int) $root_folder_id ? $depth : -1;
                }

                $current_id = (int) $term->parent;
                $depth++;

                if ( (int) $current_id === (int) $root_folder_id ) {
                    return $depth;
                }
            }

            return -1;
        }

        /**
         * Sanitize folder names inside the ZIP archive.
         *
         * @param string $name Folder name.
         *
         * @return string
         */
        protected function sanitize_zip_segment( $name ) {
            $name = wp_strip_all_tags( $name );
            $name = sanitize_text_field( $name );
            $name = str_replace( array( '/', '\\' ), '-', $name );
            $name = trim( $name );
            if ( '' === $name ) {
                $name = 'dossier';
            }

            return $name;
        }

        /**
         * Save the result for display after redirect.
         *
         * @param array $result Result data.
         */
        protected function store_result( $result ) {
            set_transient( $this->get_result_key(), $result, self::RESULT_TTL );
        }

        /**
         * Fetch result data if present.
         *
         * @return array
         */
        protected function maybe_get_result() {
            $result = get_transient( $this->get_result_key() );
            if ( ! $result ) {
                return array();
            }

            delete_transient( $this->get_result_key() );

            return $result;
        }

        /**
         * Build the transient key for the current user.
         *
         * @return string
         */
        protected function get_result_key() {
            return self::TRANSIENT_PREFIX . get_current_user_id();
        }

        /**
         * Redirect back to the plugin page after processing.
         */
        protected function redirect_after_action() {
            $url = add_query_arg(
                array(
                    'page' => 'real-media-export',
                ),
                admin_url( 'upload.php' )
            );
            wp_safe_redirect( $url );
            exit;
        }

        /**
         * Reset the in-memory activity log buffer.
         */
        protected function reset_activity_log() {
            $this->activity_log = array();
        }

        /**
         * Append a line to the activity log.
         *
         * @param string $message
         * @param string $type    info|warning|error
         */
        protected function log_activity( $message, $type = 'info' ) {
            $type = in_array( $type, array( 'info', 'warning', 'error' ), true ) ? $type : 'info';
            $msg  = is_string( $message ) ? wp_strip_all_tags( $message ) : '';
            if ( '' === $msg ) {
                return;
            }
            $this->activity_log[] = array(
                'type'    => $type,
                'message' => $msg,
            );
        }

        /**
         * Get the activity log buffer.
         *
         * @return array
         */
        protected function get_activity_log() {
            if ( empty( $this->activity_log ) ) {
                return array();
            }
            $out = array();
            foreach ( $this->activity_log as $row ) {
                $out[] = array(
                    'type'    => isset( $row['type'] ) ? $row['type'] : 'info',
                    'message' => isset( $row['message'] ) ? $row['message'] : '',
                );
            }
            return $out;
        }

        /**
         * Check if a taxonomy is available either via WP registration or directly present in DB.
         *
         * @param string $taxonomy Taxonomy slug.
         *
         * @return bool
         */
        protected function taxonomy_is_available( $taxonomy ) {
            return taxonomy_exists( $taxonomy ) || $this->db_taxonomy_exists( $taxonomy );
        }

        /**
         * Check DB for existence of a taxonomy (rows in term_taxonomy).
         *
         * @param string $taxonomy Taxonomy slug.
         *
         * @return bool
         */
        protected function db_taxonomy_exists( $taxonomy ) {
            if ( empty( $taxonomy ) ) {
                return false;
            }
            global $wpdb;
            $tt_table = $wpdb->term_taxonomy;
            $exists   = (int) $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM {$tt_table} WHERE taxonomy = %s LIMIT 1", $taxonomy ) );
            return $exists === 1;
        }

        /**
         * Fetch a term by ID and taxonomy directly from DB (minimal fields).
         *
         * @param int    $term_id  Term ID.
         * @param string $taxonomy Taxonomy slug.
         *
         * @return object|null Object with term_id, name, parent or null if not found.
         */
        protected function db_get_term( $term_id, $taxonomy ) {
            $term_id = (int) $term_id;
            if ( $term_id <= 0 || empty( $taxonomy ) ) {
                return null;
            }
            global $wpdb;
            $t_table  = $wpdb->terms;
            $tt_table = $wpdb->term_taxonomy;
            $row      = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT t.term_id, t.name, tt.parent
                     FROM {$tt_table} tt
                     INNER JOIN {$t_table} t ON t.term_id = tt.term_id
                     WHERE tt.taxonomy = %s AND t.term_id = %d",
                    $taxonomy,
                    $term_id
                )
            );
            if ( empty( $row ) ) {
                return null;
            }
            return (object) array(
                'term_id' => (int) $row->term_id,
                'name'    => (string) $row->name,
                'parent'  => (int) $row->parent,
            );
        }

        /**
         * Return descendant term IDs for a given root term using direct DB access (excludes the root).
         *
         * @param int    $root_term_id Root folder ID.
         * @param string $taxonomy     Taxonomy name.
         *
         * @return int[]
         */
        protected function db_get_descendant_term_ids( $root_term_id, $taxonomy ) {
            $root_term_id = (int) $root_term_id;
            if ( $root_term_id <= 0 ) {
                return array();
            }

            global $wpdb;
            $tt_table = $wpdb->term_taxonomy;
            $sql      = $wpdb->prepare( "SELECT term_id, parent FROM {$tt_table} WHERE taxonomy = %s", $taxonomy );
            $pairs    = $wpdb->get_results( $sql );
            if ( empty( $pairs ) ) {
                return array();
            }

            $children_by_parent = array();
            foreach ( $pairs as $p ) {
                $pid = (int) $p->parent;
                $tid = (int) $p->term_id;
                if ( ! isset( $children_by_parent[ $pid ] ) ) {
                    $children_by_parent[ $pid ] = array();
                }
                $children_by_parent[ $pid ][] = $tid;
            }

            if ( empty( $children_by_parent[ $root_term_id ] ) ) {
                return array();
            }

            $desc  = array();
            $stack = $children_by_parent[ $root_term_id ];
            while ( ! empty( $stack ) ) {
                $current = (int) array_pop( $stack );
                if ( in_array( $current, $desc, true ) ) {
                    continue;
                }
                $desc[] = $current;
                if ( ! empty( $children_by_parent[ $current ] ) ) {
                    foreach ( $children_by_parent[ $current ] as $child ) {
                        $stack[] = (int) $child;
                    }
                }
            }

            return $desc;
        }

        /**
         * Is the RML Tree API available?
         *
         * @return bool
         */
        protected function rml_tree_available() {
            return class_exists( 'MatthiasWeb\\RealMediaLibrary\\api\\Tree' );
        }

        /**
         * Is the RML Attachment API available?
         *
         * @return bool
         */
        protected function rml_attachment_available() {
            return class_exists( 'MatthiasWeb\\RealMediaLibrary\\api\\Attachment' );
        }

        /**
         * Check if the RML DB table exists (wp_realmedialibrary) to read folders.
         *
         * @return bool
         */
        protected function rml_table_exists() {
            global $wpdb;
            $table = $wpdb->prefix . 'realmedialibrary';
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
            return $exists === $table;
        }

        /**
         * Fetch the full RML tree and normalize it to arrays.
         * Returns an array with keys: by_id (id => node), by_parent (parent => [nodeObj...]), root_parent.
         * Node shape: [id:int, name:string, parent:int]
         *
         * @return array{by_id: array<int,array>, by_parent: array<int,array>, root_parent:int}
         */
        protected function rml_fetch_tree() {
            $result = array( 'by_id' => array(), 'by_parent' => array(), 'root_parent' => 0 );
            if ( ! $this->rml_tree_available() ) {
                // Fallback to DB table.
                if ( $this->rml_table_exists() ) {
                    return $this->rml_fetch_tree_from_table();
                }
                $this->log_activity( __( 'API RML Tree indisponible — aucune source de dossier alternative.', 'real-media-export' ), 'warning' );
                return $result;
            }

            try {
                $this->log_activity( __( 'RML: récupération de l’arborescence des dossiers…', 'real-media-export' ) );
                $cls = 'MatthiasWeb\\RealMediaLibrary\\api\\Tree';
                $instance = null;
                if ( method_exists( $cls, 'getInstance' ) ) {
                    $instance = $cls::getInstance();
                    $this->log_activity( __( 'RML Tree: initialisation via getInstance()', 'real-media-export' ) );
                } elseif ( method_exists( $cls, 'instance' ) ) {
                    $instance = $cls::instance();
                    $this->log_activity( __( 'RML Tree: initialisation via instance()', 'real-media-export' ) );
                } else {
                    // Try to instantiate without args if possible.
                    $instance = @new $cls();
                    $this->log_activity( __( 'RML Tree: initialisation par constructeur direct', 'real-media-export' ) );
                }

                if ( ! $instance ) {
                    $this->log_activity( __( 'RML Tree: échec d’initialisation.', 'real-media-export' ), 'warning' );
                    return $result;
                }

                $candidates = array( 'getHierarchy', 'get_hierarchy', 'getTree', 'get_tree', 'getAll', 'get' );
                $nodes = null;
                $used = '';
                foreach ( $candidates as $m ) {
                    if ( method_exists( $instance, $m ) ) {
                        $nodes = $instance->$m();
                        $used  = $m;
                        break;
                    }
                }

                if ( '' !== $used ) {
                    /* translators: %s: method name */
                    $this->log_activity( sprintf( __( 'RML Tree: méthode utilisée « %s »', 'real-media-export' ), $used ) );
                }

                if ( empty( $nodes ) ) {
                    $this->log_activity( __( 'RML Tree: aucune donnée de hiérarchie retournée.', 'real-media-export' ), 'warning' );
                    return $result;
                }

                // Normalize recursively.
                $this->rml_normalize_and_collect_nodes( $nodes, 0, $result['by_id'], $result['by_parent'] );
                $count = count( $result['by_id'] );
                /* translators: %d: number of folders */
                $this->log_activity( sprintf( __( 'RML Tree: %d dossier(s) collecté(s).', 'real-media-export' ), $count ) );
            } catch ( \Throwable $e ) {
                $this->log_activity( sprintf( __( 'RML Tree: erreur « %s »', 'real-media-export' ), wp_strip_all_tags( $e->getMessage() ) ), 'warning' );
            }

            // If API did not produce a tree, try DB table.
            if ( empty( $result['by_id'] ) && $this->rml_table_exists() ) {
                return $this->rml_fetch_tree_from_table();
            }

            return $result;
        }

        /**
         * Fetch and normalize the RML tree from the DB table {prefix}realmedialibrary.
         *
         * @return array{by_id: array<int,array>, by_parent: array<int,array>, root_parent:int}
         */
        protected function rml_fetch_tree_from_table() {
            $out = array( 'by_id' => array(), 'by_parent' => array(), 'root_parent' => 0 );
            global $wpdb;
            $table = $wpdb->prefix . 'realmedialibrary';
            // Expect columns: id, parent, name, cnt (cnt not required here).
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $rows = $wpdb->get_results( "SELECT id, parent, name FROM {$table} ORDER BY parent, name" );
            if ( empty( $rows ) ) {
                return $out;
            }

            $parents = array();
            foreach ( $rows as $row ) {
                $id = (int) $row->id;
                $parent = (int) $row->parent;
                $name = (string) $row->name;
                $out['by_id'][ $id ] = array( 'id' => $id, 'name' => $name, 'parent' => $parent );
                if ( ! isset( $out['by_parent'][ $parent ] ) ) {
                    $out['by_parent'][ $parent ] = array();
                }
                $out['by_parent'][ $parent ][] = (object) array(
                    'term_id' => $id,
                    'name'    => $name,
                    'parent'  => $parent,
                );
                $parents[ $parent ] = true;
            }

            // Determine likely root parent value (-1 or 0).
            $out['root_parent'] = isset( $parents[-1] ) ? -1 : 0;
            $this->log_activity( __( 'RML: arborescence lue depuis la table DB.', 'real-media-export' ) );
            return $out;
        }

        /**
         * Recursively normalize nodes from RML API and fill maps.
         *
         * @param mixed $nodes
         * @param int   $parent_id
         * @param array $by_id
         * @param array $by_parent
         */
        protected function rml_normalize_and_collect_nodes( $nodes, $parent_id, array &$by_id, array &$by_parent ) {
            if ( is_object( $nodes ) && method_exists( $nodes, 'toArray' ) ) {
                $nodes = $nodes->toArray();
            }
            if ( ! is_array( $nodes ) ) {
                return;
            }
            foreach ( $nodes as $node ) {
                $n = $this->rml_normalize_node( $node, $parent_id );
                if ( ! $n ) {
                    continue;
                }
                $id = (int) $n['id'];
                $pid = (int) $n['parent'];
                $by_id[ $id ] = $n;
                if ( ! isset( $by_parent[ $pid ] ) ) {
                    $by_parent[ $pid ] = array();
                }
                // Store minimal object similar to WP_Term for renderer.
                $by_parent[ $pid ][] = (object) array( 'term_id' => $id, 'name' => $n['name'], 'parent' => $pid );

                // Dive into children if present.
                $children = null;
                if ( is_object( $node ) ) {
                    if ( method_exists( $node, 'getChildren' ) ) {
                        $children = $node->getChildren();
                    } elseif ( isset( $node->children ) ) {
                        $children = $node->children;
                    }
                } elseif ( is_array( $node ) && isset( $node['children'] ) ) {
                    $children = $node['children'];
                }

                if ( $children ) {
                    $this->rml_normalize_and_collect_nodes( $children, $id, $by_id, $by_parent );
                }
            }
        }

        /**
         * Normalize a single RML node to [id,name,parent].
         *
         * @param mixed $node
         * @param int   $default_parent
         *
         * @return array|null
         */
        protected function rml_normalize_node( $node, $default_parent = 0 ) {
            $id = 0; $name = ''; $parent = (int) $default_parent;
            if ( is_object( $node ) ) {
                if ( method_exists( $node, 'getId' ) ) { $id = (int) $node->getId(); }
                elseif ( isset( $node->id ) ) { $id = (int) $node->id; }
                elseif ( isset( $node->ID ) ) { $id = (int) $node->ID; }

                if ( method_exists( $node, 'getName' ) ) { $name = (string) $node->getName(); }
                elseif ( isset( $node->name ) ) { $name = (string) $node->name; }
                elseif ( isset( $node->title ) ) { $name = (string) $node->title; }

                if ( method_exists( $node, 'getParentId' ) ) { $parent = (int) $node->getParentId(); }
                elseif ( isset( $node->parent ) ) { $parent = (int) $node->parent; }
                elseif ( method_exists( $node, 'getParent' ) ) {
                    try { $p = $node->getParent(); if ( is_object( $p ) ) { if ( method_exists( $p, 'getId' ) ) { $parent = (int) $p->getId(); } elseif ( isset( $p->id ) ) { $parent = (int) $p->id; } } } catch ( \Throwable $e ) {}
                }
            } elseif ( is_array( $node ) ) {
                $id     = isset( $node['id'] ) ? (int) $node['id'] : ( isset( $node['term_id'] ) ? (int) $node['term_id'] : 0 );
                $name   = isset( $node['name'] ) ? (string) $node['name'] : ( isset( $node['title'] ) ? (string) $node['title'] : '' );
                $parent = isset( $node['parent'] ) ? (int) $node['parent'] : $parent;
            }

            if ( $id <= 0 ) {
                return null;
            }

            return array( 'id' => $id, 'name' => $name, 'parent' => $parent );
        }

        /**
         * Get attachments for a single folder using RML API.
         *
         * @param int $folder_id
         * @return int[] Attachment IDs
         */
        protected function rml_get_attachments_for_folder( $folder_id ) {
            $folder_id = (int) $folder_id;
            if ( $folder_id <= 0 || ! $this->rml_attachment_available() ) {
                return array();
            }
            try {
                $cls = 'MatthiasWeb\\RealMediaLibrary\\api\\Attachment';
                if ( ! method_exists( $cls, 'getByFolder' ) ) {
                    return array();
                }
                $res = $cls::getByFolder( $folder_id );
                $ids = array();
                if ( $res instanceof \WP_Query ) {
                    foreach ( (array) $res->posts as $p ) { $ids[] = is_object( $p ) && isset( $p->ID ) ? (int) $p->ID : (int) $p; }
                } elseif ( is_array( $res ) || $res instanceof \Traversable ) {
                    foreach ( $res as $p ) { $ids[] = is_object( $p ) && isset( $p->ID ) ? (int) $p->ID : (int) $p; }
                } elseif ( is_numeric( $res ) ) {
                    $ids[] = (int) $res;
                }
                return array_values( array_unique( array_filter( array_map( 'intval', $ids ) ) ) );
            } catch ( \Throwable $e ) {
                return array();
            }
        }

        /**
         * Get descendant folder IDs from RML tree (excludes the root).
         *
         * @param int $root_id
         * @return int[]
         */
        protected function rml_get_descendant_ids( $root_id ) {
            $root_id = (int) $root_id;
            if ( $root_id <= 0 ) { return array(); }
            $tree = $this->rml_fetch_tree();
            if ( empty( $tree['by_parent'] ) ) { return array(); }
            $desc = array();
            $stack = isset( $tree['by_parent'][ $root_id ] ) ? $tree['by_parent'][ $root_id ] : array();
            while ( ! empty( $stack ) ) {
                $node = array_pop( $stack );
                $id   = (int) ( is_object( $node ) && isset( $node->term_id ) ? $node->term_id : ( is_array( $node ) && isset( $node['term_id'] ) ? $node['term_id'] : 0 ) );
                if ( $id <= 0 || in_array( $id, $desc, true ) ) { continue; }
                $desc[] = $id;
                if ( ! empty( $tree['by_parent'][ $id ] ) ) {
                    foreach ( $tree['by_parent'][ $id ] as $child ) { $stack[] = $child; }
                }
            }
            return $desc;
        }

        /**
         * Build attachment => terms map via DB for performance and independence from WP APIs.
         * Only term_id is required; name/parent are in $term_map.
         *
         * @param int[]  $attachment_ids Attachment IDs.
         * @param string $taxonomy       Taxonomy name.
         *
         * @return array Map of object_id => array of term-like objects (term_id only).
         */
        protected function db_get_attachment_terms_map( $attachment_ids, $taxonomy ) {
            $attachment_ids = array_filter( array_map( 'intval', (array) $attachment_ids ) );
            if ( empty( $attachment_ids ) ) {
                return array();
            }
            if ( empty( $taxonomy ) ) {
                return array();
            }

            global $wpdb;
            $tr_table = $wpdb->term_relationships;
            $tt_table = $wpdb->term_taxonomy;

            $placeholders = implode( ',', array_fill( 0, count( $attachment_ids ), '%d' ) );
            $sql          = $wpdb->prepare(
                "SELECT tr.object_id AS object_id, tt.term_id AS term_id
                 FROM {$tr_table} tr
                 INNER JOIN {$tt_table} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
                 WHERE tt.taxonomy = %s
                   AND tr.object_id IN ($placeholders)",
                array_merge( array( $taxonomy ), $attachment_ids )
            );

            $rows = $wpdb->get_results( $sql );
            if ( empty( $rows ) ) {
                return array();
            }

            $map = array();
            foreach ( $rows as $row ) {
                $oid = (int) $row->object_id;
                $tid = (int) $row->term_id;
                if ( ! isset( $map[ $oid ] ) ) {
                    $map[ $oid ] = array();
                }
                $map[ $oid ][] = (object) array( 'term_id' => $tid );
            }

            return $map;
        }

        /**
         * Generate a download URL secured by nonce.
         *
         * @param string $file_name File name.
         *
         * @return string|false
         */
        protected function get_download_url( $file_name ) {
            if ( empty( $file_name ) ) {
                return false;
            }

            $nonce = wp_create_nonce( self::DOWNLOAD_NONCE . $file_name );

            return add_query_arg(
                array(
                    'action' => 'real_media_export_download',
                    'file'   => rawurlencode( $file_name ),
                    '_wpnonce' => $nonce,
                ),
                admin_url( 'admin-post.php' )
            );
        }

        /**
         * Handle archive download.
         */
        public function handle_download_request() {
            if ( ! current_user_can( 'upload_files' ) ) {
                wp_die( esc_html__( 'Action non autorisée.', 'real-media-export' ) );
            }

            $file_name = isset( $_GET['file'] ) ? sanitize_file_name( wp_unslash( $_GET['file'] ) ) : '';
            $nonce     = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';

            if ( empty( $file_name ) || ! wp_verify_nonce( $nonce, self::DOWNLOAD_NONCE . $file_name ) ) {
                wp_die( esc_html__( 'Lien de téléchargement invalide.', 'real-media-export' ) );
            }

            $export_dir = $this->get_export_directory();
            $full_path  = $export_dir . '/' . $file_name;
            $real_path  = realpath( $full_path );
            $real_dir   = realpath( $export_dir );

            if ( ! $real_path || false === $real_dir || strpos( $real_path, $real_dir ) !== 0 || ! file_exists( $real_path ) ) {
                wp_die( esc_html__( 'Le fichier demandé est introuvable.', 'real-media-export' ) );
            }

            nocache_headers();
            header( 'Content-Type: application/zip' );
            header( 'Content-Disposition: attachment; filename="' . basename( $real_path ) . '"' );
            header( 'Content-Length: ' . filesize( $real_path ) );

            readfile( $real_path );
            exit;
        }

        /**
         * Get the export directory path.
         *
         * @return string
         */
        protected function get_export_directory() {
            $upload_dir = wp_upload_dir();
            $dir        = trailingslashit( $upload_dir['basedir'] ) . self::EXPORT_FOLDER;

            return $dir;
        }

        /**
         * Remove old archives from the export directory.
         */
        public function maybe_cleanup_old_archives() {
            $export_dir = $this->get_export_directory();
            if ( ! is_dir( $export_dir ) ) {
                return;
            }

            $files = glob( trailingslashit( $export_dir ) . '*.zip' );
            if ( empty( $files ) ) {
                return;
            }

            $lifetime = apply_filters( 'real_media_export/archive_lifetime', DAY_IN_SECONDS );
            $now      = time();

            foreach ( (array) $files as $file ) {
                if ( ! is_file( $file ) ) {
                    continue;
                }

                if ( ( $now - filemtime( $file ) ) > $lifetime ) {
                    unlink( $file );
                }
            }
        }
    }
}

// Bootstrap the plugin.
function real_media_export_plugin() {
    static $instance = null;

    if ( null === $instance ) {
        $instance = new Real_Media_Export_Plugin();
    }

    return $instance;
}

real_media_export_plugin();
