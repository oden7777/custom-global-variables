<?php
/**
 * Regression tests for storage, values and the shortcode.
 */

class Test_Custom_Global_Variables extends WP_UnitTestCase {

    private $dir;

    private $current;

    private $legacy;

    // Uses the real storage location: WP_CONTENT_DIR of the test install and md5( AUTH_KEY ).
    public function set_up() {
        parent::set_up();

        $this->dir     = WP_CONTENT_DIR . '/custom-global-variables';
        $this->current = md5( AUTH_KEY ) . '.json';
        $this->legacy  = md5( 'old-auth-key' ) . '.json';

        $this->remove_dir();
        mkdir( $this->dir );

        wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
    }

    public function tear_down() {
        unset( $_POST['vars'], $_POST['cgv_nonce'], $_REQUEST['cgv_nonce'], $_GET['page'] );

        $this->remove_dir();

        parent::tear_down();
    }

    private function remove_dir() {
        if ( ! is_dir( $this->dir ) ) {
            return;
        }

        chmod( $this->dir, 0755 );
        foreach ( array_diff( scandir( $this->dir ), array( '.', '..' ) ) as $file ) {
            $path = $this->dir . '/' . $file;
            is_dir( $path ) ? rmdir( $path ) : unlink( $path );
        }
        rmdir( $this->dir );
    }

    private function path( $file ) {
        return $this->dir . '/' . $file;
    }

    private function put( $file, $contents ) {
        file_put_contents( $this->path( $file ), $contents );
    }

    private function plugin() {
        return new Custom_Global_Variables();
    }

    private function files() {
        return array_values( array_diff( scandir( $this->dir ), array( '.', '..' ) ) );
    }

    // Submits the admin form with the given rows and returns the rendered page.
    private function save( $plugin, $rows, $nonce = true ) {
        $_POST['vars'] = wp_slash( $rows );

        if ( $nonce ) {
            $_POST['cgv_nonce']    = wp_create_nonce( 'cgv_nonce' );
            $_REQUEST['cgv_nonce'] = $_POST['cgv_nonce'];
        }

        ob_start();
        try {
            $plugin->admin_page();
        } finally {
            $html = ob_get_clean();
            unset( $_POST['vars'], $_POST['cgv_nonce'], $_REQUEST['cgv_nonce'] );
        }

        return $html;
    }

    private function render( $plugin ) {
        ob_start();
        try {
            $plugin->admin_page();
        } finally {
            $html = ob_get_clean();
        }

        return $html;
    }

    // Reads the rows back from the rendered form, as a browser would submit them.
    private function form_rows( $html ) {
        $doc = new DOMDocument();
        libxml_use_internal_errors( true );
        $doc->loadHTML( '<?xml encoding="utf-8"?>' . $html );
        libxml_clear_errors();

        $rows  = array();
        $xpath = new DOMXPath( $doc );

        foreach ( $xpath->query( '//input[starts-with(@name,"vars[")] | //textarea[starts-with(@name,"vars[")]' ) as $field ) {
            preg_match( '/^vars\[(\d+)\]\[(name|val)\]$/', $field->getAttribute( 'name' ), $m );
            $rows[ $m[1] ][ $m[2] ] = 'textarea' === $field->nodeName ? $field->textContent : $field->getAttribute( 'value' );
        }

        return $rows;
    }

    private function stored() {
        return json_decode( file_get_contents( $this->path( $this->current ) ), true );
    }

    /*
     * Public API
     */

    public function test_globals_are_set_when_the_plugin_file_is_loaded() {
        $this->assertTrue( class_exists( 'Custom_Global_Variables' ) );
        $this->assertInstanceOf( 'Custom_Global_Variables', $GLOBALS['custom_global_variables'] );
        $this->assertIsArray( $GLOBALS['cgv'] );
        $this->assertTrue( shortcode_exists( 'cgv' ) );
    }

    /*
     * Storage: reading
     */

    public function test_reads_current_valid_json() {
        $this->put( $this->current, '{"foo":"bar"}' );
        $this->put( $this->legacy, '{"old":"data"}' );

        $this->plugin();

        $this->assertSame( array( 'foo' => 'bar' ), $GLOBALS['cgv'] );
        $this->assertSame( '{"foo":"bar"}', file_get_contents( $this->path( $this->current ) ) );
    }

    public function test_missing_current_file_is_created_empty() {
        $this->plugin();

        $this->assertSame( array(), $GLOBALS['cgv'] );
        $this->assertSame( '', file_get_contents( $this->path( $this->current ) ) );
    }

    public function test_missing_directory_is_created() {
        rmdir( $this->dir );

        $this->plugin();

        $this->assertSame( array(), $GLOBALS['cgv'] );
        $this->assertFileExists( $this->path( $this->current ) );
    }

    public function test_saved_empty_dataset_is_not_recovered() {
        $this->put( $this->current, '[]' );
        $this->put( $this->legacy, '{"deleted":"by user"}' );

        $this->plugin();

        $this->assertSame( array(), $GLOBALS['cgv'] );
        $this->assertSame( '[]', file_get_contents( $this->path( $this->current ) ) );
    }

    /*
     * Storage: AUTH_KEY change recovery
     */

    public function test_recovers_single_legacy_file_when_current_is_missing() {
        $this->put( $this->legacy, '{"phone":"555-0100","zero":"0"}' );

        $this->plugin();

        $this->assertSame( array( 'phone' => '555-0100', 'zero' => '0' ), $GLOBALS['cgv'] );
        $this->assertSame( array( 'phone' => '555-0100', 'zero' => '0' ), $this->stored() );
        $this->assertSame( '{"phone":"555-0100","zero":"0"}', file_get_contents( $this->path( $this->legacy ) ) );
        $this->assertEqualsCanonicalizing( array( $this->legacy, $this->current ), $this->files() );
    }

    public function test_recovers_single_legacy_file_when_current_is_zero_bytes() {
        $this->put( $this->current, '' );
        $this->put( $this->legacy, '{"phone":"555-0100"}' );

        $this->plugin();

        $this->assertSame( array( 'phone' => '555-0100' ), $GLOBALS['cgv'] );
        $this->assertSame( array( 'phone' => '555-0100' ), $this->stored() );
        $this->assertFileExists( $this->path( $this->legacy ) );
    }

    public function test_recovered_data_is_used_on_the_next_load() {
        $this->put( $this->legacy, '{"phone":"555-0100"}' );
        $this->plugin();

        unlink( $this->path( $this->legacy ) );
        $this->plugin();

        $this->assertSame( array( 'phone' => '555-0100' ), $GLOBALS['cgv'] );
    }

    public function test_invalid_and_empty_legacy_files_are_not_candidates() {
        $this->put( $this->legacy, '{"phone":"555-0100"}' );
        $this->put( md5( 'a' ) . '.json', '{broken' );
        $this->put( md5( 'b' ) . '.json', '[]' );
        $this->put( md5( 'c' ) . '.json', '' );
        $this->put( md5( 'd' ) . '.json.bak', '{"backup":"x"}' );

        $this->plugin();

        $this->assertSame( array( 'phone' => '555-0100' ), $GLOBALS['cgv'] );
    }

    public function test_multiple_legacy_files_are_not_recovered_and_show_a_warning() {
        $this->put( $this->legacy, '{"one":"secret-value-1"}' );
        $this->put( md5( 'older-auth-key' ) . '.json', '{"two":"secret-value-2","three":"x"}' );

        $plugin = $this->plugin();

        $this->assertSame( array(), $GLOBALS['cgv'] );
        $this->assertSame( '', file_get_contents( $this->path( $this->current ) ) );
        $this->assertSame( '{"one":"secret-value-1"}', file_get_contents( $this->path( $this->legacy ) ) );

        ob_start();
        $plugin->admin_notices();
        $notice = ob_get_clean();

        $this->assertStringContainsString( 'found multiple previous data files', $notice );
        $this->assertStringContainsString( $this->legacy, $notice );
        $this->assertStringContainsString( md5( 'older-auth-key' ) . '.json', $notice );
        $this->assertStringContainsString( '1 variable(s)', $notice );
        $this->assertStringContainsString( '2 variable(s)', $notice );
        $this->assertStringNotContainsString( 'secret-value', $notice );
    }

    public function test_storage_notices_are_hidden_from_users_without_manage_options() {
        $this->put( $this->legacy, '{"one":"1"}' );
        $this->put( md5( 'older-auth-key' ) . '.json', '{"two":"2"}' );
        $plugin = $this->plugin();

        wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

        ob_start();
        $plugin->admin_notices();
        $this->assertSame( '', ob_get_clean() );
    }

    /*
     * Storage: broken files
     */

    public function test_invalid_current_file_is_reported_and_not_recovered_from_legacy() {
        $this->put( $this->current, '{broken' );
        $this->put( $this->legacy, '{"old":"data"}' );

        $plugin = $this->plugin();

        $this->assertSame( array(), $GLOBALS['cgv'] );
        $this->assertSame( '{broken', file_get_contents( $this->path( $this->current ) ) );

        ob_start();
        $plugin->admin_notices();
        $this->assertStringContainsString( 'could not read its data file', ob_get_clean() );
    }

    public function test_invalid_current_file_uses_backup_in_memory() {
        $this->put( $this->current, '{broken' );
        $this->put( $this->current . '.bak', '{"foo":"from backup"}' );

        $plugin = $this->plugin();

        $this->assertSame( array( 'foo' => 'from backup' ), $GLOBALS['cgv'] );
        $this->assertSame( '{broken', file_get_contents( $this->path( $this->current ) ) );

        ob_start();
        $plugin->admin_notices();
        $this->assertStringContainsString( 'The last backup is being used instead.', ob_get_clean() );
    }

    public function test_json_scalar_is_treated_as_invalid() {
        $this->put( $this->current, 'null' );

        $this->plugin();

        $this->assertSame( array(), $GLOBALS['cgv'] );
        $this->assertSame( 'null', file_get_contents( $this->path( $this->current ) ) );
    }

    public function test_saving_over_invalid_file_keeps_a_corrupt_copy_and_the_backup() {
        $this->put( $this->current, '{broken' );
        $this->put( $this->current . '.bak', '{"foo":"last good"}' );

        $html = $this->save( $this->plugin(), array( array( 'name' => 'foo', 'val' => 'new' ) ) );

        $this->assertStringContainsString( 'successfully been saved', $html );
        $this->assertStringNotContainsString( 'could not read its data file', $html );
        $this->assertSame( array( 'foo' => 'new' ), $this->stored() );
        $this->assertSame( '{"foo":"last good"}', file_get_contents( $this->path( $this->current . '.bak' ) ) );

        $corrupt = preg_grep( '/^' . preg_quote( $this->current, '/' ) . '\.corrupt-\d{8}-\d{6}$/', $this->files() );
        $this->assertCount( 1, $corrupt );
        $this->assertSame( '{broken', file_get_contents( $this->path( reset( $corrupt ) ) ) );
    }

    /*
     * Storage: writing
     */

    public function test_save_keeps_previous_valid_file_as_backup_and_leaves_no_temp_files() {
        $this->put( $this->current, '{"foo":"old"}' );

        $this->save( $this->plugin(), array( array( 'name' => 'foo', 'val' => 'new' ) ) );

        $this->assertSame( array( 'foo' => 'new' ), $this->stored() );
        $this->assertSame( '{"foo":"old"}', file_get_contents( $this->path( $this->current . '.bak' ) ) );
        $this->assertEqualsCanonicalizing( array( $this->current, $this->current . '.bak' ), $this->files() );
    }

    public function test_backup_failure_aborts_save_and_leaves_file_unchanged() {
        $this->put( $this->current, '{"foo":"old"}' );
        // A directory in place of the backup file makes copy() fail.
        mkdir( $this->path( $this->current . '.bak' ) );

        $html = $this->save( $this->plugin(), array( array( 'name' => 'foo', 'val' => 'new' ) ) );

        $this->assertStringContainsString( 'a copy of the current data file could not be kept', $html );
        $this->assertStringNotContainsString( 'successfully been saved', $html );
        $this->assertSame( '{"foo":"old"}', file_get_contents( $this->path( $this->current ) ) );
        $this->assertEqualsCanonicalizing( array( $this->current, $this->current . '.bak' ), $this->files() );
    }

    public function test_save_keeps_file_permissions() {
        $this->put( $this->current, '{"foo":"old"}' );
        chmod( $this->path( $this->current ), 0640 );

        $this->save( $this->plugin(), array( array( 'name' => 'foo', 'val' => 'new' ) ) );

        clearstatcache();
        $this->assertSame( 0640, fileperms( $this->path( $this->current ) ) & 0777 );
    }

    public function test_saving_all_variables_removed_writes_empty_dataset() {
        $this->put( $this->current, '{"foo":"old"}' );

        $this->save( $this->plugin(), array( array( 'name' => '', 'val' => '' ) ) );

        $this->assertSame( '[]', file_get_contents( $this->path( $this->current ) ) );
    }

    public function test_json_encode_failure_leaves_file_unchanged() {
        $this->put( $this->current, '{"foo":"old"}' );

        $html = $this->save( $this->plugin(), array( array( 'name' => 'foo', 'val' => "bad \xB1\x31 utf-8" ) ) );

        $this->assertStringContainsString( 'could not be converted to JSON', $html );
        $this->assertSame( '{"foo":"old"}', file_get_contents( $this->path( $this->current ) ) );
        $this->assertEqualsCanonicalizing( array( $this->current ), $this->files() );
    }

    public function test_write_failure_leaves_file_unchanged() {
        $this->put( $this->current, '{"foo":"old"}' );
        $plugin = $this->plugin();

        chmod( $this->dir, 0555 );
        if ( is_writable( $this->dir ) ) {
            $this->markTestSkipped( 'Directory permissions are not enforced for this user (root).' );
        }

        $html = $this->save( $plugin, array( array( 'name' => 'foo', 'val' => 'new' ) ) );

        chmod( $this->dir, 0755 );

        $this->assertStringContainsString( 'could not be saved', $html );
        $this->assertSame( '{"foo":"old"}', file_get_contents( $this->path( $this->current ) ) );
        $this->assertEqualsCanonicalizing( array( $this->current ), $this->files() );
    }

    public function test_save_requires_a_valid_nonce() {
        $this->put( $this->current, '{"foo":"old"}' );
        $plugin = $this->plugin();

        try {
            $this->save( $plugin, array( array( 'name' => 'foo', 'val' => 'new' ) ), false );
            $this->fail( 'Expected wp_die().' );
        } catch ( WPDieException $e ) {
            $this->assertSame( '{"foo":"old"}', file_get_contents( $this->path( $this->current ) ) );
        }
    }

    public function test_admin_page_requires_manage_options() {
        wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

        $this->expectException( 'WPDieException' );
        $this->render( $this->plugin() );
    }

    public function test_malformed_post_input_is_ignored() {
        $_POST['vars']         = array( 'not-a-row', array( 'name' => 'only-name' ), array( 'name' => array( 'x' ), 'val' => 'y' ), array( 'name' => 'ok', 'val' => 'yes' ) );
        $_POST['cgv_nonce']    = wp_create_nonce( 'cgv_nonce' );
        $_REQUEST['cgv_nonce'] = $_POST['cgv_nonce'];

        $this->put( $this->current, '' );
        ob_start();
        $this->plugin()->admin_page();
        ob_end_clean();

        $this->assertSame( array( 'ok' => 'yes' ), $this->stored() );
    }

    /*
     * Values
     */

    public function test_values_are_saved() {
        $this->save(
            $this->plugin(),
            array(
                array( 'name' => 'Normal Name', 'val' => 'normal string' ),
                array( 'name' => 'zero', 'val' => '0' ),
                array( 'name' => 'empty', 'val' => '' ),
                array( 'name' => '0', 'val' => 'zero name' ),
                array( 'name' => 'html', 'val' => '<strong class="x">Hi</strong><script>alert(1)</script>' ),
            )
        );

        $this->assertSame(
            array(
                'normal_name' => 'normal string',
                'zero'        => '0',
                'html'        => '<strong class="x">Hi</strong>alert(1)',
            ),
            $this->stored()
        );
    }

    public function test_quotes_and_backslashes_are_unslashed_once() {
        $this->save( $this->plugin(), array( array( 'name' => 'path', 'val' => 'C:\\dir "quoted" it\'s' ) ) );

        $this->assertSame( array( 'path' => 'C:\\dir "quoted" it\'s' ), $this->stored() );
    }

    public function test_entities_survive_display_and_save_round_trip() {
        $original = array(
            'literal_tag'  => '&lt;b&gt;bold&lt;/b&gt;',
            'ampersand'    => 'Tom &amp; Jerry',
            'quotes'       => 'say "hi" it\'s \\ ok',
            'zero'         => '0',
            'html'         => '<a href="https://example.com/?a=1&amp;b=2">link &amp; more</a>',
            'html_literal' => '<p>&lt;script&gt; is shown as text</p>',
            'multibyte'    => 'こんにちは',
            'a&amp;b'      => 'entity in name',
        );
        $this->put( $this->current, json_encode( $original ) );
        $plugin = $this->plugin();

        $html = $this->render( $plugin );
        $rows = $this->form_rows( $html );
        $this->save( $plugin, $rows );

        $this->assertSame( $original, $this->stored() );

        // A second round trip changes nothing either.
        $this->save( $this->plugin(), $this->form_rows( $this->render( $this->plugin() ) ) );
        $this->assertSame( $original, $this->stored() );
    }

    public function test_existing_name_with_backslash_is_kept_on_resave() {
        $original = array( 'it\\\'s' => 'value' );
        $this->put( $this->current, json_encode( $original ) );
        $plugin = $this->plugin();

        $this->save( $plugin, $this->form_rows( $this->render( $plugin ) ) );

        $this->assertSame( $original, $this->stored() );
    }

    public function test_admin_page_escapes_names_and_values() {
        $this->put( $this->current, json_encode( array( 'x"><script>alert(1)</script>' => '"><img src=x onerror=alert(1)>' ) ) );

        $html = $this->render( $this->plugin() );

        $this->assertStringNotContainsString( '<script>alert(1)</script>', $html );
        $this->assertStringNotContainsString( 'onerror=alert(1)>', $html );
    }

    /*
     * Shortcode
     */

    public function test_shortcode() {
        $this->put( $this->current, json_encode( array( 'foo' => 'bar', 'zero' => '0', 'int_zero' => 0, 'empty' => '', 'html' => '<em>hi</em><script>x</script>' ) ) );
        $this->plugin();

        $this->assertSame( 'bar', do_shortcode( '[cgv foo]' ) );
        $this->assertSame( '0', do_shortcode( '[cgv zero]' ) );
        $this->assertSame( '0', do_shortcode( '[cgv int_zero]' ) );
        $this->assertSame( '', do_shortcode( '[cgv empty]' ) );
        $this->assertSame( '<em>hi</em>x', do_shortcode( '[cgv html]' ) );
        $this->assertSame( '', do_shortcode( '[cgv missing]' ) );
        $this->assertSame( '', do_shortcode( '[cgv]' ) );
        $this->assertSame( '', do_shortcode( '[cgv foo="bar"]' ) );
        $this->assertSame( 'a bar b', do_shortcode( 'a [cgv foo] b' ) );
    }

    public function test_php_api() {
        $this->put( $this->current, json_encode( array( 'foo' => 'bar', 'zero' => '0' ) ) );
        $this->plugin();

        $this->assertSame( 'bar', $GLOBALS['cgv']['foo'] );
        $this->assertSame( '0', $GLOBALS['cgv']['zero'] );
    }

    /*
     * Feedback panel
     */

    private function feedback_panel( $survey_url ) {
        $method = new ReflectionMethod( 'Custom_Global_Variables', 'print_feedback_panel' );
        if ( PHP_VERSION_ID < 80100 ) {
            $method->setAccessible( true );
        }

        ob_start();
        try {
            $method->invoke( $this->plugin(), $survey_url );
        } finally {
            $html = ob_get_clean();
        }

        return $html;
    }

    // Returns the "Share feedback" links in the given HTML.
    private function feedback_links( $html ) {
        $doc = new DOMDocument();
        libxml_use_internal_errors( true );
        $doc->loadHTML( '<?xml encoding="utf-8"?>' . $html );
        libxml_clear_errors();

        $links = array();
        $xpath = new DOMXPath( $doc );

        foreach ( $xpath->query( '//a[starts-with(normalize-space(.),"Share feedback")]' ) as $link ) {
            $links[] = $link;
        }

        return $links;
    }

    public function test_feedback_panel_is_shown_on_the_settings_page() {
        $html  = $this->render( $this->plugin() );
        $links = $this->feedback_links( $html );

        $this->assertStringContainsString( 'Agency features are being considered', $html );
        $this->assertCount( 1, $links );
        $this->assertSame( Custom_Global_Variables::SURVEY_URL, $links[0]->getAttribute( 'href' ) );
        $this->assertSame( '_blank', $links[0]->getAttribute( 'target' ) );
        $this->assertEqualsCanonicalizing( array( 'noopener', 'noreferrer' ), explode( ' ', $links[0]->getAttribute( 'rel' ) ) );
    }

    public function test_survey_url_is_https_without_tracking_parameters() {
        $this->assertStringStartsWith( 'https://', Custom_Global_Variables::SURVEY_URL );
        $this->assertFalse( strpos( Custom_Global_Variables::SURVEY_URL, '?' ) );
    }

    public function test_feedback_panel_is_not_shown_on_other_admin_screens() {
        // A broken file makes admin_notices print something, so the hook is known to run.
        $this->put( $this->current, '{broken' );
        $this->plugin();

        foreach ( array( 'dashboard', 'plugins', 'options-general' ) as $screen ) {
            set_current_screen( $screen );

            ob_start();
            do_action( 'admin_notices' );
            do_action( 'all_admin_notices' );
            do_action( 'wp_dashboard_setup' );
            $html = ob_get_clean();

            $this->assertStringContainsString( 'could not read its data file', $html );
            $this->assertStringNotContainsString( 'Agency features', $html );
            $this->assertStringNotContainsString( 'Share feedback', $html );
        }
    }

    public function test_feedback_panel_is_hidden_until_a_real_url_is_set() {
        $this->assertSame( '', $this->feedback_panel( 'SURVEY_URL_HERE' ) );
        $this->assertSame( '', $this->feedback_panel( '' ) );
        $this->assertSame( '', $this->feedback_panel( 'http://example.com/' ) );
        $this->assertSame( '', $this->feedback_panel( 'javascript:alert(1)' ) );
    }

    public function test_survey_url_is_escaped() {
        $html  = $this->feedback_panel( 'https://example.com/?a=1&b="><script>alert(1)</script>' );
        $links = $this->feedback_links( $html );

        $this->assertStringNotContainsString( '<script>', $html );
        $this->assertStringNotContainsString( '"><', $html );
        $this->assertCount( 1, $links );
        $this->assertStringStartsWith( 'https://example.com/?a=1&b=', $links[0]->getAttribute( 'href' ) );
    }

    public function test_feedback_panel_does_not_affect_saving() {
        $plugin = $this->plugin();
        $html   = $this->save( $plugin, array( array( 'name' => 'foo', 'val' => 'bar' ) ) );

        $this->assertStringContainsString( 'successfully been saved', $html );
        $this->assertSame( array( 'foo' => 'bar' ), $this->stored() );
        $this->assertCount( 1, $this->feedback_links( $html ) );

        // The panel adds no form fields, so a re-save keeps the same data.
        $this->assertSame( array( array( 'name' => 'foo', 'val' => 'bar' ), array( 'name' => '', 'val' => '' ) ), $this->form_rows( $html ) );
    }

    /*
     * No external requests
     */

    public function test_loading_the_plugin_and_opening_the_settings_page_make_no_http_requests() {
        $requests = array();
        add_filter(
            'pre_http_request',
            function ( $pre, $args, $url ) use ( &$requests ) {
                $requests[] = $url;
                return $pre;
            },
            10,
            3
        );

        $plugin = $this->plugin();
        do_action( 'admin_menu' );
        do_action( 'admin_notices' );
        $this->render( $plugin );
        $this->save( $plugin, array( array( 'name' => 'foo', 'val' => 'bar' ) ) );
        do_shortcode( '[cgv foo]' );

        $this->assertSame( array(), $requests );
    }

    // The WordPress HTTP API filter cannot see direct PHP or browser requests, so the sources are checked too.
    public function test_sources_contain_no_external_request_code() {
        $root    = dirname( __DIR__ );
        $sources = array(
            'custom-global-variables.php' => file_get_contents( $root . '/custom-global-variables.php' ),
            'script.js'                   => file_get_contents( $root . '/script.js' ),
        );

        $patterns = array(
            '/\bwp_(safe_)?remote_/',
            '/\bdownload_url\s*\(/',
            '/\bcurl_/',
            '/\bfsockopen\s*\(/',
            '/\bstream_socket_client\s*\(/',
            '/\bget_headers\s*\(/',
            '/\bfetch\s*\(/',
            '/XMLHttpRequest/',
            '/sendBeacon/',
            '/\.(ajax|get|post|getJSON|load)\s*\(/',
            '/new\s+Image\s*\(/',
            '/<img[^>]+src=["\']https?:/i',
            '/<script[^>]+src=/i',
        );

        foreach ( $sources as $file => $source ) {
            foreach ( $patterns as $pattern ) {
                $this->assertDoesNotMatchRegularExpression( $pattern, $source, $file . ' matches ' . $pattern );
            }
        }

        // The only external URLs are the plugin header links and the survey link.
        preg_match_all( '#https?://[^\s\'"<>]+#', $sources['custom-global-variables.php'], $m );
        $this->assertEqualsCanonicalizing(
            array(
                'https://www.newtarget.com/solutions/wordpress-websites',
                'https://www.newtarget.com',
                Custom_Global_Variables::SURVEY_URL,
            ),
            array_values( array_unique( $m[0] ) )
        );
        $this->assertDoesNotMatchRegularExpression( '#https?://#', $sources['script.js'] );
    }
}
