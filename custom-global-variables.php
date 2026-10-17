<?php
/**
 * Plugin Name: Custom Global Variables
 * Plugin URI: https://www.newtarget.com/solutions/wordpress-websites
 * Description: Easily create custom variables that can be accessed globally in Wordpress and PHP. Retrieval of information is extremely fast, with no database calls.
 * Version: 1.1.3
 * Author: new target, inc
 * Author URI: https://www.newtarget.com
 * License: GPL2
 */

class Custom_Global_Variables {

    private $file_path = '';

    private $dir_path = '';

    // 'invalid' or 'read_error' when the current file could not be loaded.
    private $load_error = '';

    private $loaded_from_backup = false;

    // Previous data files found when more than one could be recovered.
    private $recovery_candidates = array();

    // Constructor
    function __construct() {

        $this->dir_path  = WP_CONTENT_DIR . '/custom-global-variables';
        $this->file_path = $this->dir_path . '/' . md5( AUTH_KEY ) . '.json';

        /* Retrieve locally the current definitions. */

        $GLOBALS['cgv'] = $this->load_vars();

        // Add the menu item.
        add_action( 'admin_menu', array( &$this, 'add_menu' ) );

        // Show storage warnings.
        add_action( 'admin_notices', array( &$this, 'admin_notices' ) );

        // Setup the shortcode.
        add_shortcode( 'cgv', array( &$this, 'shortcode' ) );
    }

    // Loads the variables, recovering from a previous data file when it is unambiguous.
    private function load_vars() {

        $state = $this->read_file( $this->file_path, $vars );

        if ( 'valid' === $state ) {

            return $vars;
        }

        // Never treat a broken file as an empty dataset. Use the last-known-good backup in memory only.
        if ( 'invalid' === $state || 'read_error' === $state ) {

            $this->load_error = $state;

            if ( 'valid' === $this->read_file( $this->file_path . '.bak', $backup ) ) {

                $this->loaded_from_backup = true;

                return $backup;
            }

            return array();
        }

        // The current file is missing or 0 bytes (never saved), e.g. after AUTH_KEY changed.
        // A saved empty dataset is "[]" and is never recovered.
        $candidates = $this->find_recovery_candidates();

        if ( 1 === count( $candidates ) ) {

            $vars = reset( $candidates );

            // The previous file is kept as is.
            $this->write_file( $vars );

            return $vars;
        }

        if ( count( $candidates ) > 1 ) {

            $this->recovery_candidates = $candidates;
        }

        // Create the directory and file when it doesn't exist.
        if ( 'missing' === $state && wp_mkdir_p( $this->dir_path ) ) {

            $handle = @fopen( $this->file_path, 'x' );

            if ( false !== $handle ) {

                fclose( $handle );
            }
        }

        return array();
    }

    // Reads a data file. Returns 'valid', 'missing', 'empty', 'invalid' or 'read_error'.
    private function read_file( $path, &$vars ) {

        $vars = array();

        if ( ! file_exists( $path ) ) {

            return 'missing';
        }

        $contents = @file_get_contents( $path );

        if ( false === $contents ) {

            return 'read_error';
        }

        if ( '' === $contents ) {

            return 'empty';
        }

        $decoded = json_decode( $contents, true );

        if ( ! is_array( $decoded ) ) {

            return 'invalid';
        }

        $vars = $decoded;

        return 'valid';
    }

    // Returns the valid, non-empty data files other than the current one, keyed by file name.
    private function find_recovery_candidates() {

        $candidates = array();

        $files = is_dir( $this->dir_path ) ? @scandir( $this->dir_path ) : false;

        if ( false === $files ) {

            return $candidates;
        }

        foreach ( $files as $file ) {

            if ( '.json' !== substr( $file, -5 ) || basename( $this->file_path ) === $file ) {

                continue;
            }

            if ( 'valid' === $this->read_file( $this->dir_path . '/' . $file, $vars ) && ! empty( $vars ) ) {

                $candidates[ $file ] = $vars;
            }
        }

        return $candidates;
    }

    // Writes the variables through a temporary file so the current file is never left half written.
    // Returns true, or 'encode' / 'preserve' / 'write' on failure.
    private function write_file( $vars ) {

        $json = json_encode( $vars );

        if ( false === $json ) {

            return 'encode';
        }

        if ( ! wp_mkdir_p( $this->dir_path ) ) {

            return 'write';
        }

        $tmp_path = $this->file_path . '.tmp-' . uniqid( '', true );

        $written = @file_put_contents( $tmp_path, $json );

        if ( false === $written || strlen( $json ) !== $written ) {

            @unlink( $tmp_path );

            return 'write';
        }

        if ( file_exists( $this->file_path ) ) {

            $perms = @fileperms( $this->file_path );

            if ( false !== $perms ) {

                @chmod( $tmp_path, $perms & 0777 );
            }

            // Keep the last-known-good data before replacing it. A broken file never replaces the backup.
            if ( 'valid' === $this->read_file( $this->file_path, $current ) && ! @copy( $this->file_path, $this->file_path . '.bak' ) ) {

                @unlink( $tmp_path );

                return 'preserve';
            }
        }

        if ( ! @rename( $tmp_path, $this->file_path ) ) {

            @unlink( $tmp_path );

            return 'write';
        }

        return true;
    }

    // Saves the variables from the admin page. Returns true, or 'preserve' / 'encode' / 'write' on failure.
    private function save_vars( $vars ) {

        // Set aside a broken or unreadable file before it is replaced. Block saving if that fails.
        if ( '' !== $this->load_error && file_exists( $this->file_path ) ) {

            if ( ! @copy( $this->file_path, $this->file_path . '.corrupt-' . gmdate( 'Ymd-His' ) ) ) {

                return 'preserve';
            }
        }

        $result = $this->write_file( $vars );

        if ( true === $result ) {

            $this->load_error          = '';
            $this->loaded_from_backup  = false;
            $this->recovery_candidates = array();
        }

        return $result;
    }

    // Shows storage warnings on admin screens other than this plugin's page.
    function admin_notices() {

        if ( ! current_user_can( 'manage_options' ) ) {

            return;
        }

        if ( isset( $_GET['page'] ) && 'custom-global-variables' === $_GET['page'] ) {

            return;
        }

        $this->print_storage_notices();
    }

    private function print_storage_notices() {

        if ( '' !== $this->load_error ) {

            echo '<div class="error"><p>Custom Global Variables could not read its data file:</p><p><strong>' . esc_html( $this->file_path ) . '</strong></p>';

            if ( $this->loaded_from_backup ) {

                echo '<p>The last backup is being used instead.</p>';
            }

            echo '<p>Saving will keep a copy of the current file next to it with a ".corrupt-" suffix.</p></div>';
        }

        if ( ! empty( $this->recovery_candidates ) ) {

            echo '<div class="notice notice-warning"><p>Custom Global Variables found multiple previous data files. No automatic recovery was performed to avoid choosing the wrong data.</p><p>Folder: <strong>' . esc_html( $this->dir_path ) . '</strong></p><ul>';

            foreach ( $this->recovery_candidates as $file => $vars ) {

                $modified = @filemtime( $this->dir_path . '/' . $file );

                echo '<li>' . esc_html( $file ) . ' &mdash; ' . esc_html( false !== $modified ? gmdate( 'Y-m-d H:i:s', $modified ) . ' UTC' : 'unknown date' ) . ' &mdash; ' . esc_html( count( $vars ) ) . ' variable(s)</li>';
            }

            echo '</ul></div>';
        }
    }

    // Escapes a stored name or value for a form field. Existing entities are encoded again so they survive a save.
    private function esc_field( $value ) {

        return htmlspecialchars( $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
    }

    // Adds the menu item under Settings
    function add_menu() {

        add_submenu_page(
            'options-general.php',
            'Custom Global Variables',
            'Custom Global Variables',
            'manage_options',
            'custom-global-variables',
            array( &$this, 'admin_page' )
        );
    }

    // Admin page
    function admin_page() {

        // Terminate if the user isn't allowed to access the page.
        if ( ! current_user_can( 'manage_options' ) ) {

            wp_die( 'You do not have sufficient permissions.' );
        }

        wp_enqueue_style( 'custom-global-variables-style', plugins_url( 'style.css', __FILE__ ) );
        wp_enqueue_script( 'custom-global-variables-script', plugins_url( 'script.js', __FILE__ ), array( 'jquery' ) );

        $vars = $GLOBALS['cgv'];

        // Save definitions upon submission.
        if ( isset( $_POST['vars'] ) ) {
            check_admin_referer( 'cgv_nonce', 'cgv_nonce' );

            $vars_new = array();

            $vars_posted = wp_unslash( $_POST['vars'] );

            foreach ( is_array( $vars_posted ) ? $vars_posted : array() as $var ) {
                if ( ! isset( $var['name'], $var['val'] ) || ! is_string( $var['name'] ) || ! is_string( $var['val'] ) ) {
                    continue;
                }

                $var['name'] = sanitize_textarea_field($var['name']);
                $var['val'] = wp_kses_post($var['val']);

                // "0" is a valid value. Empty values are still skipped.
                if ( ! empty( $var['name'] ) && '' !== $var['val'] ) {

                    $name = trim( strtolower( str_replace( ' ', '_', $var['name'] ) ) );
                    $vars_new[ $name ] = $var['val'];
                }
            }

            $result = $this->save_vars( $vars_new );

            if ( true === $result ) {

                $vars = $vars_new;

                echo '<div id="message" class="updated"><p>Your variables have successfully been saved.</p></div>';
            }
            elseif ( 'encode' === $result ) {

                echo '<div id="message" class="error"><p>Your variables could not be saved because they could not be converted to JSON (for example, invalid UTF-8 characters). No changes were made.</p></div>';
            }
            elseif ( 'preserve' === $result ) {

                echo '<div id="message" class="error"><p>Your variables could not be saved because a copy of the current data file could not be kept. No changes were made.</p></div>';
            }
            else {

                echo '<div id="message" class="error"><p>Your variables could not be saved. Check to see if the following folder exists and has sufficient write permissions:</p><p><strong>' . esc_html( $this->dir_path ) . '</strong></p></div>';
            }
        }

        $this->print_storage_notices();
        ?>

        <div class="wrap">

            <h2>Custom Global Variables</h2>

            <div class="card">

                <h3>Usage</h3>

                <p>Display your variables using the shortcode syntax:</p>

                <p><code>[cgv <em>variable-name</em>]</code></p>

                <p>Or using the superglobal in PHP:</p>

                <p><code>&lt;?php echo $GLOBALS['cgv']['<em>variable-name</em>'] ?&gt;</code></p>

            </div>

            <div class="card">

                <h3>Define your variables</h3>

                <form method="POST" action="">
                    <?php
                        wp_nonce_field( 'cgv_nonce', 'cgv_nonce', false, true );                        
                    ?>
                    <table id="custom-global-variables-table-definitions">

                        <tbody>

                            <?php
                            $i = 0;

                            if ( !empty( $vars ) ):
                            ?>

                                <?php foreach ( $vars as $key => $val ):  ?>
                                    <?php
                                    $key = $this->esc_field( (string) $key );
                                    $val = wp_kses_post($val);
                                    ?>

                                    <tr>

                                        <td><input autocomplete="off" name="vars[<?php echo $i ?>][name]" placeholder="name" type="text" value="<?php echo $key ?>"></td>

                                        <td><span class="equals">=</span></td>

                                        <td class="value-input">

                                            <?php if ( $val == strip_tags( $val ) ): ?>

                                                <input autocomplete="off" name="vars[<?php echo $i ?>][val]" placeholder="value" type="text" value="<?php echo $this->esc_field( $val ) ?>">

                                            <?php else: ?>

                                                <textarea name="vars[<?php echo $i ?>][val]" placeholder="value"><?php echo $this->esc_field( $val ) ?></textarea>

                                            <?php endif ?>

                                        </td>

                                        <td class="options">

                                            <img alt="delete" class="delete" src="<?php echo esc_url( plugins_url( 'delete.png', __FILE__ ) ) ?>">

                                        </td>

                                    </tr>

                                <?php $i++; endforeach; ?>

                            <?php endif ?>

                            <tr>

                                <td><input autocomplete="off" name="vars[<?php echo $i ?>][name]" placeholder="name" type="text"></td>

                                <td><span class="equals">=</span></td>

                                <td><input autocomplete="off" name="vars[<?php echo $i ?>][val]" placeholder="value" type="text"></td>

                                <td></td>

                            </tr>

                        </tbody>

                    </table>

                    <p><input type="submit" value="Save" class="button-primary"></p>

                </form>

            </div>

        </div>

        <?php
    }

    // Shortcode for displaying values
    function shortcode( $params ) {
        if ( ! is_array( $params ) || ! isset( $params[0] ) ) {
            return false;
        }

        $param0 = sanitize_text_field($params[0]);
        // isset() keeps "0" while skipping missing keys.
        if ( isset( $GLOBALS['cgv'][ $param0 ] ) && is_scalar( $GLOBALS['cgv'][ $param0 ] ) ) {
            return wp_kses_post($GLOBALS['cgv'][ $param0 ]);
        }

        return false;
    }
}

$custom_global_variables = new Custom_Global_Variables;
