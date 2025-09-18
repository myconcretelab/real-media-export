<?php
/**
 * Plugin Name: Real Media Export
 * Description: Fournit une page d'export des fichiers classés avec Real Media Library.
 * Version: 1.0.0
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
         * Constructor.
         */
        public function __construct() {
            add_action( 'init', array( $this, 'load_textdomain' ) );
            add_action( 'admin_menu', array( $this, 'register_menu' ) );
            add_action( 'admin_init', array( $this, 'maybe_cleanup_old_archives' ) );
            add_action( 'admin_post_real_media_export', array( $this, 'handle_export_request' ) );
            add_action( 'admin_post_real_media_export_download', array( $this, 'handle_download_request' ) );
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
         * Try to detect the taxonomy used by Real Media Library to store folders.
         *
         * @return string|null
         */
        public function get_folder_taxonomy() {
            if ( null !== $this->folder_taxonomy ) {
                return $this->folder_taxonomy;
            }

            $default_taxonomies = array(
                'real_media_library',
                'rml_folder',
                'real_media_category',
            );

            $detected = null;
            foreach ( $default_taxonomies as $taxonomy ) {
                if ( taxonomy_exists( $taxonomy ) ) {
                    $detected = $taxonomy;
                    break;
                }
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

            $result = $this->maybe_get_result();

            echo '<div class="wrap real-media-export">';
            echo '<h1>' . esc_html__( 'Export des fichiers Real Media Library', 'real-media-export' ) . '</h1>';

            if ( empty( $taxonomy ) || ! taxonomy_exists( $taxonomy ) ) {
                printf(
                    '<div class="notice notice-error"><p>%s</p></div>',
                    esc_html__( 'Le plugin Real Media Library ne semble pas être actif. Aucune taxonomie de dossiers n’a été détectée.', 'real-media-export' )
                );
            }

            if ( isset( $result['message'] ) ) {
                printf( '<div class="notice notice-%1$s"><p>%2$s</p></div>', esc_attr( $result['status'] ), wp_kses_post( $result['message'] ) );
            }

            if ( ! empty( $result['archives'] ) ) {
                $this->render_results_table( $result['archives'], $result );
            }

            echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
            wp_nonce_field( self::NONCE_ACTION );
            echo '<input type="hidden" name="action" value="real_media_export" />';

            echo '<table class="form-table" role="presentation">';
            echo '<tbody>';

            echo '<tr>';
            echo '<th scope="row"><label for="real-media-export-folder">' . esc_html__( 'Dossier à exporter', 'real-media-export' ) . '</label></th>';
            echo '<td>';
            if ( empty( $taxonomy ) || ! taxonomy_exists( $taxonomy ) ) {
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
            echo '</div>';
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
            if ( empty( $taxonomy ) || ! taxonomy_exists( $taxonomy ) ) {
                return '';
            }

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

            $options = $this->render_folder_options_recursive( $by_parent, 0, $selected );

            return $options;
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

            $folder_id         = isset( $_POST['folder_id'] ) ? absint( $_POST['folder_id'] ) : 0;
            $include_children  = ! empty( $_POST['include_children'] );
            $max_size_mb       = isset( $_POST['max_size_mb'] ) ? floatval( $_POST['max_size_mb'] ) : 0;
            $archive_prefix    = isset( $_POST['archive_prefix'] ) ? sanitize_text_field( wp_unslash( $_POST['archive_prefix'] ) ) : '';
            $preserve_structure = ! empty( $_POST['preserve_structure'] );

            $result = array(
                'status'   => 'error',
                'message'  => '',
                'archives' => array(),
            );

            $taxonomy = $this->get_folder_taxonomy();
            if ( empty( $taxonomy ) || ! taxonomy_exists( $taxonomy ) ) {
                $result['message'] = esc_html__( 'Impossible de détecter la taxonomie utilisée par Real Media Library.', 'real-media-export' );
                $this->store_result( $result );
                $this->redirect_after_action();
            }

            if ( $folder_id <= 0 ) {
                $result['message'] = esc_html__( 'Veuillez sélectionner un dossier à exporter.', 'real-media-export' );
                $this->store_result( $result );
                $this->redirect_after_action();
            }

            $folder = get_term( $folder_id, $taxonomy );
            if ( ! $folder || is_wp_error( $folder ) ) {
                $result['message'] = esc_html__( 'Le dossier demandé est introuvable.', 'real-media-export' );
                $this->store_result( $result );
                $this->redirect_after_action();
            }

            $term_ids = array( $folder_id );
            if ( $include_children ) {
                $descendants = get_terms(
                    array(
                        'taxonomy'   => $taxonomy,
                        'hide_empty' => false,
                        'fields'     => 'ids',
                        'child_of'   => $folder_id,
                    )
                );
                if ( ! is_wp_error( $descendants ) && ! empty( $descendants ) ) {
                    $term_ids = array_unique( array_merge( $term_ids, array_map( 'intval', $descendants ) ) );
                }
            }

            $attachments = $this->query_attachments( $term_ids, $taxonomy, $include_children );

            if ( empty( $attachments ) ) {
                $result['status']  = 'warning';
                $result['message'] = esc_html__( 'Aucun fichier n’a été trouvé pour les paramètres choisis.', 'real-media-export' );
                $this->store_result( $result );
                $this->redirect_after_action();
            }

            $archives = $this->create_archives(
                $attachments,
                array(
                    'folder_id'          => $folder_id,
                    'term_ids'           => $term_ids,
                    'taxonomy'           => $taxonomy,
                    'max_size_mb'        => $max_size_mb,
                    'archive_prefix'     => $archive_prefix,
                    'preserve_structure' => $preserve_structure,
                )
            );

            if ( empty( $archives['archives'] ) ) {
                $this->store_result( $archives );
                $this->redirect_after_action();
            }

            update_user_meta( get_current_user_id(), 'real_media_export_last_folder', $folder_id );

            $this->store_result( $archives );
            $this->redirect_after_action();
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

            $query = new WP_Query(
                array(
                    'post_type'      => 'attachment',
                    'post_status'    => 'inherit',
                    'posts_per_page' => -1,
                    'fields'         => 'ids',
                    'tax_query'      => array(
                        array(
                            'taxonomy'         => $taxonomy,
                            'field'            => 'term_id',
                            'terms'            => $term_ids,
                            'include_children' => $include_children,
                        ),
                    ),
                )
            );

            if ( empty( $query->posts ) ) {
                return array();
            }

            return array_map( 'intval', $query->posts );
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

            $attachment_terms = wp_get_object_terms(
                $attachment_ids,
                $options['taxonomy'],
                array(
                    'fields' => 'all_with_object_id',
                )
            );

            $terms_by_object = array();
            if ( ! is_wp_error( $attachment_terms ) ) {
                foreach ( $attachment_terms as $term ) {
                    if ( ! isset( $term_map[ $term->term_id ] ) ) {
                        continue;
                    }
                    $object_id = (int) $term->object_id;
                    if ( ! isset( $terms_by_object[ $object_id ] ) ) {
                        $terms_by_object[ $object_id ] = array();
                    }
                    $terms_by_object[ $object_id ][] = $term;
                }
            }

            $archives       = array();
            $current_zip    = null;
            $current_size   = 0;
            $archive_index  = 0;
            $files_in_zip   = 0;
            $files_total    = 0;
            $files_skipped  = array();
            $timestamp      = gmdate( 'Ymd-His' );
            $prefix         = $options['archive_prefix'] ? sanitize_title( $options['archive_prefix'] ) : sanitize_title( get_bloginfo( 'name' ) );
            if ( empty( $prefix ) ) {
                $prefix = 'real-media-export';
            }
            $base_filename = $prefix . '-' . $timestamp;

            $close_archive = function() use ( &$current_zip, &$archives, &$archive_index, &$files_in_zip, &$current_size, $export_dir ) {
                if ( $current_zip instanceof ZipArchive ) {
                    $current_zip->close();
                    if ( ! empty( $current_zip->filename ) && file_exists( $current_zip->filename ) && $files_in_zip > 0 ) {
                        $archives[] = array(
                            'file' => basename( $current_zip->filename ),
                            'size' => filesize( $current_zip->filename ),
                            'path' => $current_zip->filename,
                        );
                    } elseif ( ! empty( $current_zip->filename ) && file_exists( $current_zip->filename ) ) {
                        // Remove empty archive.
                        unlink( $current_zip->filename );
                    }
                }

                $current_zip   = null;
                $files_in_zip  = 0;
                $current_size  = 0;
                $archive_index++;
            };

            $open_archive = function() use ( &$current_zip, &$archive_index, &$base_filename, $export_dir, &$files_in_zip ) {
                $filename   = sprintf( '%s-part-%02d.zip', $base_filename, $archive_index + 1 );
                $full_path  = trailingslashit( $export_dir ) . $filename;
                $zip        = new ZipArchive();
                $open_result = $zip->open( $full_path, ZipArchive::CREATE | ZipArchive::OVERWRITE );
                if ( true !== $open_result ) {
                    return new WP_Error( 'zip_open_failed', sprintf( esc_html__( 'Impossible de créer le fichier ZIP (%s).', 'real-media-export' ), $filename ) );
                }

                $current_zip  = $zip;
                $files_in_zip = 0;

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

            $terms = get_terms(
                array(
                    'taxonomy'   => $taxonomy,
                    'include'    => $term_ids,
                    'hide_empty' => false,
                )
            );

            $map = array();
            if ( ! is_wp_error( $terms ) ) {
                foreach ( $terms as $term ) {
                    $map[ $term->term_id ] = $term;
                }
            }

            // Ensure the selected folder is present even if not returned by include.
            foreach ( $term_ids as $term_id ) {
                if ( ! isset( $map[ $term_id ] ) ) {
                    $term = get_term( $term_id, $taxonomy );
                    if ( $term && ! is_wp_error( $term ) ) {
                        $map[ $term_id ] = $term;
                    }
                }
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
                    $parent = get_term( $current->parent, $taxonomy );
                    if ( ! $parent || is_wp_error( $parent ) ) {
                        break;
                    }
                    $term_map[ $parent->term_id ] = $parent;
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
                $term = isset( $term_map[ $current_id ] ) ? $term_map[ $current_id ] : get_term( $current_id, $taxonomy );
                if ( ! $term || is_wp_error( $term ) ) {
                    return -1;
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
         * Render the table with generated archives.
         *
         * @param array $archives Archives list.
         * @param array $result   Result data.
         */
        protected function render_results_table( $archives, $result ) {
            if ( empty( $archives ) ) {
                return;
            }

            echo '<h2>' . esc_html__( 'Archives générées', 'real-media-export' ) . '</h2>';
            echo '<table class="widefat striped">';
            echo '<thead><tr>';
            echo '<th>' . esc_html__( 'Fichier', 'real-media-export' ) . '</th>';
            echo '<th>' . esc_html__( 'Taille', 'real-media-export' ) . '</th>';
            echo '<th>' . esc_html__( 'Téléchargement', 'real-media-export' ) . '</th>';
            echo '</tr></thead>';
            echo '<tbody>';

            foreach ( $archives as $archive ) {
                $file_name = isset( $archive['file'] ) ? $archive['file'] : '';
                $size      = isset( $archive['size'] ) ? (int) $archive['size'] : 0;
                $download_url = $this->get_download_url( $file_name );
                echo '<tr>';
                echo '<td>' . esc_html( $file_name ) . '</td>';
                echo '<td>' . esc_html( size_format( $size ) ) . '</td>';
                echo '<td>';
                if ( $download_url ) {
                    echo '<a class="button button-secondary" href="' . esc_url( $download_url ) . '">' . esc_html__( 'Télécharger', 'real-media-export' ) . '</a>';
                } else {
                    echo '<em>' . esc_html__( 'Fichier introuvable', 'real-media-export' ) . '</em>';
                }
                echo '</td>';
                echo '</tr>';
            }

            echo '</tbody>';
            echo '</table>';
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
