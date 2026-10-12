<?php
/**
 * Plugin Name: Comdeva Bridge
 * Description: Låter Comdevas verktyg läsa och ändra Yoast-fält och Elementor-/Divi-innehåll via REST, med samma behörighet som i WordPress (edit_post). Installeras som vanligt tillägg (zip) och uppdateras sedan under Tillägg som andra tillägg.
 * Version: 1.11.2
 * Author: Comdeva
 * Requires PHP: 7.4
 * Update URI: https://github.com/troykankillah/comdeva-bridge
 *
 * Endpoints (kräver inloggning, t.ex. Application Password):
 *   GET  /wp-json/comdeva/v1/status
 *   GET  /wp-json/comdeva/v1/seo/<id>
 *   POST /wp-json/comdeva/v1/seo/<id>        {title, description, focus_keyword, canonical, noindex}
 *   GET  /wp-json/comdeva/v1/elementor/<id>
 *   POST /wp-json/comdeva/v1/elementor/<id>  {elements: [...]}
 *   GET  /wp-json/comdeva/v1/divi/<id>       sida eller Divi-layout (header/footer)
 *   POST /wp-json/comdeva/v1/divi/<id>       {content: "[et_pb_section …]"}
 *   GET  /wp-json/comdeva/v1/divi-theme       Theme Builder, layouter, temainställningar
 *   POST /wp-json/comdeva/v1/divi-options     {phone_number, header_email}
 *   GET/POST /wp-json/comdeva/v1/snippets     egna HTML/CSS-snuttar på hela sajten
 *   GET  /wp-json/comdeva/v1/muffin/<id>     Muffin Builder/BeBuilder (Betheme)
 *   POST /wp-json/comdeva/v1/muffin/<id>     {items, object, local_style, seo, css} – alla delar på en gång
 *   GET/POST /wp-json/comdeva/v1/page-options/<id>  Betheme sidinställningar (mfn-post-*, t.ex. egen meny)
 *   GET  /wp-json/comdeva/v1/mail-log        FluentSMTP:s e-postlogg, bara läsning (search, after, before, limit, page)
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const COMDEVA_BRIDGE_VERSION = '1.11.2';

// Uppdateringar som ett vanligt tillägg: WordPress läser update.json i det publika repot
// troykankillah/comdeva-bridge (byggs av bridge_release.py) och visar
// "Det finns en ny version" under Tillägg. Gäller inte som mu-plugin.
// Varje zip är signerad (Ed25519) med Comdevas privata nyckel, som bara finns lokalt (.env), aldrig
// på GitHub. WordPress installerar bara en uppdatering vars signatur stämmer mot nyckeln nedan –
// ett kapat GitHub-konto räcker alltså inte för att skicka ut kod. Nyckeln sätts av bridge_release.py keygen.
const COMDEVA_BRIDGE_REPO_RAW   = 'https://raw.githubusercontent.com/troykankillah/comdeva-bridge/';
const COMDEVA_BRIDGE_UPDATE_URL = COMDEVA_BRIDGE_REPO_RAW . 'main/update.json';
const COMDEVA_BRIDGE_PUBLIC_KEY = '0pOjM2x8hy7slSkzOlmO1XG0p32jj6yV7z2r8L02Snc=';

function comdeva_bridge_manifest( $force = false ) {
	$cached = get_site_transient( 'comdeva_bridge_manifest' );
	if ( ! $force && is_array( $cached ) ) {
		return $cached ? $cached : null;
	}
	$res  = wp_remote_get( COMDEVA_BRIDGE_UPDATE_URL, array( 'timeout' => 10 ) );
	$data = is_wp_error( $res ) || 200 !== wp_remote_retrieve_response_code( $res )
		? null : json_decode( wp_remote_retrieve_body( $res ), true );
	$ok   = is_array( $data ) && ! empty( $data['version'] ) && ! empty( $data['signature'] )
		&& ! empty( $data['download_url'] ) && 0 === strpos( $data['download_url'], COMDEVA_BRIDGE_REPO_RAW );
	// Kort cache: WordPress begränsar redan hur ofta den söker (12 h, 1 h på Tillägg, 1 min på Uppdateringar),
	// så en ny version syns vid nästa sökning, inte först när en lång cache gått ut.
	set_site_transient( 'comdeva_bridge_manifest', $ok ? $data : array(), 5 * MINUTE_IN_SECONDS );
	return $ok ? $data : null;
}

function comdeva_bridge_update_item( $m ) {
	$file = plugin_basename( __FILE__ );
	return (object) array(
		'id'            => 'comdeva-bridge',
		'slug'          => dirname( $file ),
		'plugin'        => $file,
		'new_version'   => $m['version'],
		'url'           => isset( $m['homepage'] ) ? $m['homepage'] : '',
		'package'       => $m['download_url'],
		'requires'      => isset( $m['requires'] ) ? $m['requires'] : '',
		'requires_php'  => isset( $m['requires_php'] ) ? $m['requires_php'] : '',
		'tested'        => isset( $m['tested'] ) ? $m['tested'] : '',
		'icons'         => array(),
		'banners'       => array(),
		'compatibility' => new stdClass(),
	);
}

add_filter( 'pre_set_site_transient_update_plugins', function ( $transient ) {
	if ( ! is_object( $transient ) || 0 !== strpos( wp_normalize_path( __FILE__ ), wp_normalize_path( WP_PLUGIN_DIR ) ) ) {
		return $transient;
	}
	// "Sök efter uppdateringar igen" i Kontrollpanelen → Uppdateringar hämtar direkt, förbi cachen.
	$m = comdeva_bridge_manifest( isset( $_GET['force-check'] ) );
	if ( ! $m ) {
		return $transient;
	}
	$item = comdeva_bridge_update_item( $m );
	if ( version_compare( $m['version'], COMDEVA_BRIDGE_VERSION, '>' ) ) {
		$transient->response[ $item->plugin ] = $item;
	} else {
		// Med i no_update så att länken "Aktivera automatiska uppdateringar" visas.
		$transient->no_update[ $item->plugin ] = $item;
	}
	return $transient;
} );

add_filter( 'plugins_api', function ( $result, $action, $args ) {
	if ( 'plugin_information' !== $action || empty( $args->slug ) || dirname( plugin_basename( __FILE__ ) ) !== $args->slug ) {
		return $result;
	}
	$m = comdeva_bridge_manifest();
	if ( ! $m ) {
		return $result;
	}
	return (object) array(
		'name'          => 'Comdeva Bridge',
		'slug'          => $args->slug,
		'version'       => $m['version'],
		'author'        => 'Comdeva',
		'homepage'      => isset( $m['homepage'] ) ? $m['homepage'] : '',
		'requires'      => isset( $m['requires'] ) ? $m['requires'] : '',
		'requires_php'  => isset( $m['requires_php'] ) ? $m['requires_php'] : '',
		'tested'        => isset( $m['tested'] ) ? $m['tested'] : '',
		'last_updated'  => isset( $m['released'] ) ? $m['released'] : '',
		'download_link' => $m['download_url'],
		'sections'      => array(
			'description' => 'Låter Comdevas verktyg läsa och ändra SEO-fält och sidinnehåll via REST.',
			'changelog'   => isset( $m['changelog'] ) ? wp_kses_post( $m['changelog'] ) : '',
		),
	);
}, 10, 3 );

function comdeva_bridge_signature_ok( $file, $signature ) {
	$sig = base64_decode( (string) $signature, true );
	$key = base64_decode( COMDEVA_BRIDGE_PUBLIC_KEY, true );
	if ( ! $sig || ! $key || 64 !== strlen( $sig ) || 32 !== strlen( $key ) || ! function_exists( 'sodium_crypto_sign_verify_detached' ) ) {
		return false;
	}
	try {
		return sodium_crypto_sign_verify_detached( $sig, (string) file_get_contents( $file ), $key );
	} catch ( Throwable $e ) {
		return false;
	}
}

// Laddar ner Bridge-uppdateringen själv och släpper bara igenom den om signaturen stämmer.
add_filter( 'upgrader_pre_download', function ( $reply, $package, $upgrader = null, $hook_extra = array() ) {
	$ours = ( is_array( $hook_extra ) && isset( $hook_extra['plugin'] ) && plugin_basename( __FILE__ ) === $hook_extra['plugin'] )
		|| 0 === strpos( (string) $package, COMDEVA_BRIDGE_REPO_RAW );
	if ( false !== $reply || ! $ours ) {
		return $reply;
	}
	$m = comdeva_bridge_manifest();
	if ( ! $m || $package !== $m['download_url'] ) {
		$m = comdeva_bridge_manifest( true ); // ny version sedan senaste kollen
	}
	if ( ! $m || $package !== $m['download_url'] ) {
		return new WP_Error( 'comdeva_bridge_package', 'Comdeva Bridge: okänd uppdateringsfil – sök efter uppdateringar igen.' );
	}
	$file = download_url( $package );
	if ( is_wp_error( $file ) ) {
		return $file;
	}
	if ( ! comdeva_bridge_signature_ok( $file, $m['signature'] ) ) {
		wp_delete_file( $file );
		return new WP_Error( 'comdeva_bridge_signature', 'Comdeva Bridge: signaturen stämmer inte – uppdateringen installerades inte.' );
	}
	return $file;
}, 10, 4 );

add_action( 'upgrader_process_complete', function () {
	delete_site_transient( 'comdeva_bridge_manifest' );
} );

const COMDEVA_SEO_FIELDS = array(
	'title'         => '_yoast_wpseo_title',
	'description'   => '_yoast_wpseo_metadesc',
	'focus_keyword' => '_yoast_wpseo_focuskw',
	'canonical'     => '_yoast_wpseo_canonical',
	'noindex'       => '_yoast_wpseo_meta-robots-noindex',
);

add_action( 'rest_api_init', function () {
	$can_edit = function ( WP_REST_Request $req ) {
		$id = (int) $req['id'];
		return $id > 0 && get_post( $id ) && current_user_can( 'edit_post', $id );
	};

	register_rest_route( 'comdeva/v1', '/status', array(
		'methods'             => 'GET',
		'permission_callback' => function () {
			return current_user_can( 'edit_posts' );
		},
		'callback'            => function () {
			$user = wp_get_current_user();
			return array(
				'bridge'    => COMDEVA_BRIDGE_VERSION,
				'wordpress' => get_bloginfo( 'version' ),
				'yoast'     => defined( 'WPSEO_VERSION' ) ? WPSEO_VERSION : null,
				'elementor' => defined( 'ELEMENTOR_VERSION' ) ? ELEMENTOR_VERSION : null,
				'divi'      => comdeva_divi_version(),
				'betheme'   => comdeva_betheme_version(),
				'user'      => $user->user_login,
				'roles'     => $user->roles,
			);
		},
	) );

	register_rest_route( 'comdeva/v1', '/seo/(?P<id>\d+)', array(
		array(
			'methods'             => 'GET',
			'permission_callback' => $can_edit,
			'callback'            => function ( WP_REST_Request $req ) {
				return comdeva_seo_get( (int) $req['id'] );
			},
		),
		array(
			'methods'             => 'POST',
			'permission_callback' => $can_edit,
			'callback'            => function ( WP_REST_Request $req ) {
				$id     = (int) $req['id'];
				$params = $req->get_json_params();
				foreach ( COMDEVA_SEO_FIELDS as $field => $meta_key ) {
					if ( ! is_array( $params ) || ! array_key_exists( $field, $params ) ) {
						continue;
					}
					$value = $params[ $field ];
					if ( 'noindex' === $field ) {
						$value = $value ? '1' : '';
					} elseif ( 'canonical' === $field ) {
						$value = esc_url_raw( (string) $value );
					} else {
						$value = sanitize_text_field( (string) $value );
					}
					if ( '' === $value ) {
						delete_post_meta( $id, $meta_key );
					} else {
						update_post_meta( $id, $meta_key, wp_slash( $value ) );
					}
				}
				// Låt Yoast bygga om sin indexable från de nya värdena.
				wp_update_post( array( 'ID' => $id ) );
				return comdeva_seo_get( $id );
			},
		),
	) );

	register_rest_route( 'comdeva/v1', '/elementor/(?P<id>\d+)', array(
		array(
			'methods'             => 'GET',
			'permission_callback' => $can_edit,
			'callback'            => function ( WP_REST_Request $req ) {
				$id   = (int) $req['id'];
				$raw  = get_post_meta( $id, '_elementor_data', true );
				$data = is_string( $raw ) && '' !== $raw ? json_decode( $raw, true ) : null;
				return array(
					'id'        => $id,
					'edit_mode' => get_post_meta( $id, '_elementor_edit_mode', true ),
					'elements'  => $data,
				);
			},
		),
		array(
			'methods'             => 'POST',
			'permission_callback' => $can_edit,
			'callback'            => function ( WP_REST_Request $req ) {
				$id       = (int) $req['id'];
				$params   = $req->get_json_params();
				$elements = is_array( $params ) ? ( $params['elements'] ?? null ) : null;
				if ( ! is_array( $elements ) ) {
					return new WP_Error( 'comdeva_bad_elements', 'elements måste vara en lista', array( 'status' => 400 ) );
				}
				if ( 'builder' !== get_post_meta( $id, '_elementor_edit_mode', true ) ) {
					return new WP_Error( 'comdeva_not_elementor', 'Sidan är inte byggd med Elementor', array( 'status' => 400 ) );
				}
				if ( class_exists( '\Elementor\Plugin' ) ) {
					// Elementors egen sparning: hanterar slashing, revision, CSS och post_content.
					$document = \Elementor\Plugin::$instance->documents->get( $id, false );
					if ( ! $document ) {
						return new WP_Error( 'comdeva_no_document', 'Elementor hittar inte sidan', array( 'status' => 404 ) );
					}
					$document->save( array( 'elements' => $elements ) );
					\Elementor\Plugin::$instance->files_manager->clear_cache();
				} else {
					update_post_meta( $id, '_elementor_data', wp_slash( wp_json_encode( $elements ) ) );
					delete_post_meta( $id, '_elementor_css' );
				}
				$raw = get_post_meta( $id, '_elementor_data', true );
				return array( 'id' => $id, 'elements' => json_decode( $raw, true ) );
			},
		),
	) );

	register_rest_route( 'comdeva/v1', '/divi/(?P<id>\d+)', array(
		'methods'             => 'POST',
		'permission_callback' => $can_edit,
		'callback'            => function ( WP_REST_Request $req ) {
			$id      = (int) $req['id'];
			$params  = $req->get_json_params();
			$content = is_array( $params ) ? ( $params['content'] ?? null ) : null;
			if ( ! is_string( $content ) || false === strpos( $content, '[et_pb_section' ) ) {
				return new WP_Error( 'comdeva_bad_content', 'content måste vara Divi-innehåll', array( 'status' => 400 ) );
			}
			if ( false === strpos( get_post_field( 'post_content', $id, 'raw' ), '[et_pb_section' ) ) {
				return new WP_Error( 'comdeva_not_divi', 'Sidan är inte byggd med Divi', array( 'status' => 400 ) );
			}
			// Vanlig sparning: revision skapas, samma filter som när Divi sparar.
			$result = wp_update_post( wp_slash( array( 'ID' => $id, 'post_content' => $content ) ), true );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			comdeva_divi_clear_cache( $id );
			return array( 'id' => $id, 'content' => get_post_field( 'post_content', $id, 'raw' ) );
		},
	) );
} );

function comdeva_divi_version() {
	$theme = wp_get_theme( get_template() );
	if ( 'Divi' === $theme->get( 'Name' ) || 'Extra' === $theme->get( 'Name' ) ) {
		return $theme->get( 'Version' );
	}
	return defined( 'ET_BUILDER_PLUGIN_VERSION' ) ? ET_BUILDER_PLUGIN_VERSION : null;
}

/** Divi bygger statisk CSS och "dynamic assets" per sida – töm dem så att ändringen syns direkt. */
function comdeva_divi_clear_cache( $id ) {
	if ( class_exists( 'ET_Core_PageResource' ) && method_exists( 'ET_Core_PageResource', 'remove_static_resources' ) ) {
		ET_Core_PageResource::remove_static_resources( $id, 'all' );
	}
	delete_post_meta( $id, '_et_dynamic_cached_shortcodes' );
	delete_post_meta( $id, '_et_dynamic_cached_attributes' );
	clean_post_cache( $id );
}

/*
 * Filer i webbroten (kräver manage_options):
 *   GET  /comdeva/v1/files                      lista .txt/.xml/.html i roten (+ avstängda)
 *   GET  /comdeva/v1/file?name=robots.txt       innehåll
 *   POST /comdeva/v1/file      {name, content}  skriv – robots.txt och IndexNow-nyckelfil
 *   POST /comdeva/v1/file/disable {name}        döp om till <namn>.comdeva-disabled (raderar aldrig)
 *   POST /comdeva/v1/file/enable  {name}        döp tillbaka
 */
const COMDEVA_FILE_PATTERN = '/^[A-Za-z0-9_.-]+\.(txt|xml|html)$/';

function comdeva_root_file( $name, $disabled = false ) {
	$name = (string) $name;
	if ( ! preg_match( COMDEVA_FILE_PATTERN, $name ) || false !== strpos( $name, '..' ) ) {
		return new WP_Error( 'comdeva_bad_name', 'Ogiltigt filnamn', array( 'status' => 400 ) );
	}
	return ABSPATH . $name . ( $disabled ? '.comdeva-disabled' : '' );
}

add_action( 'rest_api_init', function () {
	$is_admin = function () {
		return current_user_can( 'manage_options' );
	};

	register_rest_route( 'comdeva/v1', '/files', array(
		'methods'             => 'GET',
		'permission_callback' => $is_admin,
		'callback'            => function () {
			$out = array();
			foreach ( (array) scandir( ABSPATH ) as $f ) {
				$active   = preg_match( COMDEVA_FILE_PATTERN, $f );
				$disabled = preg_match( '/\.comdeva-disabled$/', $f );
				if ( ( $active || $disabled ) && is_file( ABSPATH . $f ) ) {
					$out[] = array(
						'name'     => $disabled ? substr( $f, 0, -strlen( '.comdeva-disabled' ) ) : $f,
						'disabled' => (bool) $disabled,
						'size'     => filesize( ABSPATH . $f ),
						'modified' => gmdate( 'c', filemtime( ABSPATH . $f ) ),
					);
				}
			}
			return $out;
		},
	) );

	register_rest_route( 'comdeva/v1', '/file', array(
		array(
			'methods'             => 'GET',
			'permission_callback' => $is_admin,
			'callback'            => function ( WP_REST_Request $req ) {
				$path = comdeva_root_file( $req['name'] );
				if ( is_wp_error( $path ) ) {
					return $path;
				}
				return array(
					'name'    => $req['name'],
					'exists'  => is_file( $path ),
					'content' => is_file( $path ) ? file_get_contents( $path ) : null,
				);
			},
		),
		array(
			'methods'             => 'POST',
			'permission_callback' => $is_admin,
			'callback'            => function ( WP_REST_Request $req ) {
				$p    = $req->get_json_params();
				$name = (string) ( $p['name'] ?? '' );
				// Tillåtet: robots.txt och IndexNow-nyckelfil (<nyckel>.txt med nyckeln som enda innehåll).
				$is_key = preg_match( '/^([a-f0-9]{32})\.txt$/', $name, $m ) && trim( (string) ( $p['content'] ?? '' ) ) === $m[1];
				if ( ( 'robots.txt' !== $name && ! $is_key ) || ! is_string( $p['content'] ?? null ) ) {
					return new WP_Error( 'comdeva_not_allowed', 'Bara robots.txt och IndexNow-nyckelfiler kan skrivas', array( 'status' => 400 ) );
				}
				$path = ABSPATH . $name;
				if ( false === file_put_contents( $path, $p['content'] ) ) {
					return new WP_Error( 'comdeva_write_failed', 'Kunde inte skriva ' . $name, array( 'status' => 500 ) );
				}
				return array( 'name' => $name, 'exists' => true, 'content' => file_get_contents( $path ) );
			},
		),
	) );

	foreach ( array( 'disable' => true, 'enable' => false ) as $action => $disable ) {
		register_rest_route( 'comdeva/v1', '/file/' . $action, array(
			'methods'             => 'POST',
			'permission_callback' => $is_admin,
			'callback'            => function ( WP_REST_Request $req ) use ( $disable ) {
				$name = ( $req->get_json_params() )['name'] ?? '';
				$from = comdeva_root_file( $name, ! $disable );
				$to   = comdeva_root_file( $name, $disable );
				if ( is_wp_error( $from ) ) {
					return $from;
				}
				if ( 'robots.txt' === $name ) {
					return new WP_Error( 'comdeva_not_allowed', 'robots.txt skrivs om, den stängs inte av', array( 'status' => 400 ) );
				}
				if ( ! is_file( $from ) ) {
					return new WP_Error( 'comdeva_missing', 'Filen finns inte: ' . basename( $from ), array( 'status' => 404 ) );
				}
				if ( is_file( $to ) || ! rename( $from, $to ) ) {
					return new WP_Error( 'comdeva_rename_failed', 'Kunde inte döpa om ' . basename( $from ), array( 'status' => 500 ) );
				}
				return array( 'name' => $name, 'disabled' => $disable );
			},
		) );
	}
} );

/*
 * POST /comdeva/v1/clone/<id>  {title, slug}
 * Kopierar en sida/ett inlägg (Elementor-design, mall, sidinställningar) till ett nytt UTKAST.
 * Yoast-fält, lås och genererad CSS följer inte med. Kräver edit_post på mallen och edit_posts.
 */
add_action( 'rest_api_init', function () {
	register_rest_route( 'comdeva/v1', '/clone/(?P<id>\d+)', array(
		'methods'             => 'POST',
		'permission_callback' => function ( WP_REST_Request $req ) {
			$id = (int) $req['id'];
			return get_post( $id ) && current_user_can( 'edit_post', $id ) && current_user_can( 'edit_posts' );
		},
		'callback'            => function ( WP_REST_Request $req ) {
			$src = get_post( (int) $req['id'] );
			$p   = $req->get_json_params();
			$title = sanitize_text_field( $p['title'] ?? '' );
			if ( '' === $title ) {
				return new WP_Error( 'comdeva_no_title', 'title krävs', array( 'status' => 400 ) );
			}
			$new_id = wp_insert_post( wp_slash( array(
				'post_type'    => $src->post_type,
				'post_status'  => 'draft',
				'post_title'   => $title,
				'post_name'    => sanitize_title( $p['slug'] ?? $title ),
				'post_content' => $src->post_content,
				'post_excerpt' => $src->post_excerpt,
				'post_parent'  => $src->post_parent,
				'menu_order'   => $src->menu_order,
				'post_author'  => get_current_user_id(),
			) ), true );
			if ( is_wp_error( $new_id ) ) {
				return $new_id;
			}
			$skip = array( '_edit_lock', '_edit_last', '_wp_old_slug', '_wp_old_date', '_elementor_css',
				'_et_dynamic_cached_shortcodes', '_et_dynamic_cached_attributes' );
			foreach ( get_post_meta( $src->ID ) as $key => $values ) {
				if ( in_array( $key, $skip, true ) || 0 === strpos( $key, '_yoast_wpseo_' ) ) {
					continue;
				}
				foreach ( $values as $value ) {
					add_post_meta( $new_id, $key, wp_slash( maybe_unserialize( $value ) ) );
				}
			}
			// Betheme läser sidans stil från en fil per sida – kopian behöver en egen.
			$css_dir = trailingslashit( wp_upload_dir()['basedir'] ) . 'betheme/css';
			foreach ( array( '', '-preview' ) as $suffix ) {
				if ( file_exists( "$css_dir/post-{$src->ID}$suffix.css" ) ) {
					copy( "$css_dir/post-{$src->ID}$suffix.css", "$css_dir/post-$new_id$suffix.css" );
				}
			}
			foreach ( get_object_taxonomies( $src->post_type ) as $tax ) {
				$terms = wp_get_object_terms( $src->ID, $tax, array( 'fields' => 'ids' ) );
				if ( ! is_wp_error( $terms ) && $terms ) {
					wp_set_object_terms( $new_id, $terms, $tax );
				}
			}
			if ( class_exists( '\Elementor\Plugin' ) ) {
				\Elementor\Plugin::$instance->files_manager->clear_cache();
			}
			return array(
				'id'      => $new_id,
				'type'    => $src->post_type,
				'status'  => 'draft',
				'link'    => get_permalink( $new_id ),
				'preview' => get_preview_post_link( $new_id ),
				'from'    => $src->ID,
			);
		},
	) );
} );

function comdeva_seo_get( $id ) {
	$out = array( 'id' => $id );
	foreach ( COMDEVA_SEO_FIELDS as $field => $meta_key ) {
		$value         = get_post_meta( $id, $meta_key, true );
		$out[ $field ] = 'noindex' === $field ? '1' === (string) $value : (string) $value;
	}
	return $out;
}

/*
 * Divi-tema och Theme Builder (1.5):
 *   GET  /comdeva/v1/divi/<id>          innehåll i en sida eller Divi-layout (header/footer/body)
 *   GET  /comdeva/v1/divi-theme         Theme Builder-mallar, header/footer-layouter, utvalda temainställningar
 *   POST /comdeva/v1/divi-options {nyckel: värde}   bara nycklarna i COMDEVA_DIVI_OPTIONS (manage_options)
 */
const COMDEVA_DIVI_OPTIONS = array( 'phone_number', 'header_email' );

add_action( 'rest_api_init', function () {
	register_rest_route( 'comdeva/v1', '/divi/(?P<id>\d+)', array(
		'methods'             => 'GET',
		'permission_callback' => function ( WP_REST_Request $req ) {
			$id = (int) $req['id'];
			return $id > 0 && get_post( $id ) && current_user_can( 'edit_post', $id );
		},
		'callback'            => function ( WP_REST_Request $req ) {
			$post = get_post( (int) $req['id'] );
			return array(
				'id'       => $post->ID,
				'type'     => $post->post_type,
				'status'   => $post->post_status,
				'title'    => $post->post_title,
				'slug'     => $post->post_name,
				'modified' => $post->post_modified,
				'link'     => get_permalink( $post ),
				'content'  => $post->post_content,
			);
		},
	) );

	register_rest_route( 'comdeva/v1', '/divi-theme', array(
		'methods'             => 'GET',
		'permission_callback' => function () {
			return current_user_can( 'edit_theme_options' );
		},
		'callback'            => function () {
			$templates = array();
			foreach ( get_posts( array( 'post_type' => 'et_template', 'post_status' => 'publish', 'numberposts' => 200 ) ) as $t ) {
				$templates[] = array(
					'id'             => $t->ID,
					'title'          => $t->post_title,
					'default'        => '1' === (string) get_post_meta( $t->ID, '_et_default', true ),
					'enabled'        => '0' !== (string) get_post_meta( $t->ID, '_et_enabled', true ),
					'use_on'         => array_values( (array) get_post_meta( $t->ID, '_et_use_on', false ) ),
					'header_layout'  => (int) get_post_meta( $t->ID, '_et_header_layout_id', true ),
					'header_enabled' => '0' !== (string) get_post_meta( $t->ID, '_et_header_layout_enabled', true ),
					'footer_layout'  => (int) get_post_meta( $t->ID, '_et_footer_layout_id', true ),
					'body_layout'    => (int) get_post_meta( $t->ID, '_et_body_layout_id', true ),
				);
			}
			$layouts = array();
			foreach ( get_posts( array( 'post_type' => array( 'et_header_layout', 'et_footer_layout', 'et_body_layout' ), 'post_status' => 'publish', 'numberposts' => 200 ) ) as $l ) {
				$layouts[] = array( 'id' => $l->ID, 'type' => $l->post_type, 'title' => $l->post_title, 'modified' => $l->post_modified );
			}
			$options = array();
			$all     = get_option( 'et_divi', array() );
			foreach ( array_merge( COMDEVA_DIVI_OPTIONS, array( 'header_style', 'show_search_icon', 'vertical_nav' ) ) as $key ) {
				$options[ $key ] = is_array( $all ) && isset( $all[ $key ] ) ? $all[ $key ] : null;
			}
			return array(
				'divi'       => comdeva_divi_version(),
				'templates'  => $templates,
				'layouts'    => $layouts,
				'options'    => $options,
				'menus'      => get_nav_menu_locations(),
			);
		},
	) );

	register_rest_route( 'comdeva/v1', '/divi-options', array(
		'methods'             => 'POST',
		'permission_callback' => function () {
			return current_user_can( 'manage_options' );
		},
		'callback'            => function ( WP_REST_Request $req ) {
			$params = $req->get_json_params();
			if ( ! is_array( $params ) || ! $params ) {
				return new WP_Error( 'comdeva_bad_options', 'skicka {nyckel: värde}', array( 'status' => 400 ) );
			}
			$all = get_option( 'et_divi', array() );
			if ( ! is_array( $all ) ) {
				$all = array();
			}
			foreach ( $params as $key => $value ) {
				if ( ! in_array( $key, COMDEVA_DIVI_OPTIONS, true ) || ! is_string( $value ) ) {
					return new WP_Error( 'comdeva_bad_option', "Får inte ändra {$key}", array( 'status' => 400 ) );
				}
				$all[ $key ] = sanitize_text_field( $value );
			}
			update_option( 'et_divi', $all );
			if ( class_exists( 'ET_Core_PageResource' ) && method_exists( 'ET_Core_PageResource', 'remove_static_resources' ) ) {
				ET_Core_PageResource::remove_static_resources( 'all', 'all' );
			}
			$out = array();
			foreach ( COMDEVA_DIVI_OPTIONS as $key ) {
				$out[ $key ] = isset( $all[ $key ] ) ? $all[ $key ] : null;
			}
			return $out;
		},
	) );
} );

/*
 * Comdeva-snuttar (1.5): HTML/CSS som Comdeva lägger på hela sajten, oberoende av sidbyggare –
 * t.ex. en fast boka-knapp i mobilen. Sparas i optionen comdeva_snippets, skrivs ut i wp_head (css)
 * och wp_footer (html). Kräver manage_options + unfiltered_html.
 *   GET  /comdeva/v1/snippets
 *   POST /comdeva/v1/snippets  {name, html, css, enabled}   (name: a-z, 0-9, -)
 */
add_action( 'rest_api_init', function () {
	$can = function () {
		return current_user_can( 'manage_options' ) && current_user_can( 'unfiltered_html' );
	};
	register_rest_route( 'comdeva/v1', '/snippets', array(
		array(
			'methods'             => 'GET',
			'permission_callback' => $can,
			'callback'            => function () {
				return (object) comdeva_snippets();
			},
		),
		array(
			'methods'             => 'POST',
			'permission_callback' => $can,
			'callback'            => function ( WP_REST_Request $req ) {
				$p    = $req->get_json_params();
				$name = is_array( $p ) && isset( $p['name'] ) ? (string) $p['name'] : '';
				if ( ! preg_match( '/^[a-z0-9-]{2,40}$/', $name ) ) {
					return new WP_Error( 'comdeva_bad_name', 'name: 2–40 tecken a-z, 0-9, -', array( 'status' => 400 ) );
				}
				$all          = comdeva_snippets();
				$all[ $name ] = array(
					'html'     => isset( $p['html'] ) ? (string) $p['html'] : '',
					'css'      => isset( $p['css'] ) ? (string) $p['css'] : '',
					'enabled'  => ! empty( $p['enabled'] ),
					'modified' => current_time( 'mysql' ),
				);
				update_option( 'comdeva_snippets', $all, true );
				return (object) comdeva_snippets();
			},
		),
	) );
} );

function comdeva_snippets() {
	$all = get_option( 'comdeva_snippets', array() );
	return is_array( $all ) ? $all : array();
}

add_action( 'wp_head', function () {
	foreach ( comdeva_snippets() as $name => $s ) {
		if ( ! empty( $s['enabled'] ) && '' !== trim( (string) $s['css'] ) ) {
			echo "\n<style id=\"comdeva-" . esc_attr( $name ) . "-css\">\n" . wp_strip_all_tags( (string) $s['css'] ) . "\n</style>\n";
		}
	}
}, 100 );

add_action( 'wp_footer', function () {
	foreach ( comdeva_snippets() as $name => $s ) {
		if ( ! empty( $s['enabled'] ) && '' !== trim( (string) $s['html'] ) ) {
			echo "\n<!-- comdeva:" . esc_attr( $name ) . " -->\n" . $s['html'] . "\n"; // phpcs:ignore -- sparad av administratör med unfiltered_html
		}
	}
}, 100 );

/*
 * Muffin Builder / BeBuilder (Betheme):
 *   GET  /comdeva/v1/muffin/<id>   alla mfn-fält på sidan, avkodade (json, base64, serialize)
 *   POST /comdeva/v1/muffin/<id>   {items, object, local_style, seo, css}: skriver mfn-page-items och
 *        mfn-builder-preview (base64+serialize), mfn-page-object, mfn-page-local-style och
 *        mfn-builder-preview-local-style (json), mfn-page-items-seo, och lägger en version i
 *        Betheme's historik (mfn-builder-revision-update, högst 10) så att den kan återställas i Muffin.
 *        css skrivs till uploads/betheme/css/post-<id>.css och post-<id>-preview.css (förhandsvisning) –
 *        sidan läser stilen därifrån, inte från metan.
 */
function comdeva_betheme_version() {
	$theme = wp_get_theme( get_template() );
	return 'Betheme' === $theme->get( 'Name' ) ? $theme->get( 'Version' ) : null;
}

function comdeva_muffin_decode( $value ) {
	if ( ! is_string( $value ) ) {
		return array( 'format' => gettype( $value ), 'data' => $value );
	}
	$json = json_decode( $value, true );
	if ( is_array( $json ) ) {
		return array( 'format' => 'json', 'data' => $json );
	}
	$bin = '' === $value ? false : base64_decode( $value, true );
	if ( false !== $bin ) {
		$json = json_decode( $bin, true );
		if ( is_array( $json ) ) {
			return array( 'format' => 'base64+json', 'data' => $json );
		}
		if ( is_serialized( $bin ) ) {
			$data = @unserialize( $bin, array( 'allowed_classes' => false ) ); // phpcs:ignore -- inga objekt
			if ( false !== $data || 'b:0;' === $bin ) {
				return array( 'format' => 'base64+serialize', 'data' => $data );
			}
		}
	}
	return array( 'format' => 'string', 'data' => $value );
}

add_action( 'rest_api_init', function () {
	register_rest_route( 'comdeva/v1', '/muffin/(?P<id>\d+)', array(
		'methods'             => 'GET',
		'permission_callback' => function ( WP_REST_Request $req ) {
			$id = (int) $req['id'];
			return $id > 0 && get_post( $id ) && current_user_can( 'edit_post', $id );
		},
		'callback'            => function ( WP_REST_Request $req ) {
			$id     = (int) $req['id'];
			$fields = array();
			foreach ( array_keys( get_post_meta( $id ) ) as $key ) {
				if ( 0 !== strpos( $key, 'mfn' ) ) {
					continue;
				}
				$raw            = get_post_meta( $id, $key, true );
				$fields[ $key ] = comdeva_muffin_decode( $raw ) + array(
					'length' => is_string( $raw ) ? strlen( $raw ) : null,
				);
			}
			return array(
				'id'      => $id,
				'betheme' => comdeva_betheme_version(),
				'fields'  => $fields,
			);
		},
	) );
} );

function comdeva_muffin_encode( $items ) {
	return base64_encode( serialize( $items ) ); // samma format som Betheme
}

add_action( 'rest_api_init', function () {
	register_rest_route( 'comdeva/v1', '/muffin/(?P<id>\d+)', array(
		'methods'             => 'POST',
		'permission_callback' => function ( WP_REST_Request $req ) {
			$id = (int) $req['id'];
			return $id > 0 && get_post( $id ) && current_user_can( 'edit_post', $id );
		},
		'callback'            => function ( WP_REST_Request $req ) {
			$id = (int) $req['id'];
			$p  = $req->get_json_params();
			if ( ! is_array( $p ) || ! is_array( $p['items'] ?? null ) || ! is_array( $p['object'] ?? null )
				|| ! is_array( $p['local_style'] ?? null ) || ! is_string( $p['seo'] ?? null ) ) {
				return new WP_Error( 'comdeva_bad_muffin', 'items, object, local_style och seo krävs', array( 'status' => 400 ) );
			}
			if ( '' === (string) get_post_meta( $id, 'mfn-page-items', true ) ) {
				return new WP_Error( 'comdeva_not_muffin', 'Sidan är inte byggd med Muffin Builder', array( 'status' => 400 ) );
			}
			foreach ( $p['items'] as $section ) {
				if ( ! is_array( $section ) || empty( $section['uid'] ) || ! isset( $section['wraps'] ) ) {
					return new WP_Error( 'comdeva_bad_muffin', 'Varje sektion måste ha uid och wraps', array( 'status' => 400 ) );
				}
			}
			$encoded = comdeva_muffin_encode( $p['items'] );
			$style   = wp_json_encode( $p['local_style'] );
			update_post_meta( $id, 'mfn-page-items', $encoded );
			update_post_meta( $id, 'mfn-builder-preview', $encoded );
			update_post_meta( $id, 'mfn-page-object', wp_slash( wp_json_encode( $p['object'] ) ) );
			update_post_meta( $id, 'mfn-page-local-style', wp_slash( $style ) );
			update_post_meta( $id, 'mfn-builder-preview-local-style', wp_slash( $style ) );
			update_post_meta( $id, 'mfn-page-items-seo', wp_slash( $p['seo'] ) );

			$revisions = get_post_meta( $id, 'mfn-builder-revision-update', true );
			$revisions = is_array( $revisions ) ? $revisions : array();
			$revisions[ (string) time() ] = $encoded;
			ksort( $revisions );
			update_post_meta( $id, 'mfn-builder-revision-update', array_slice( $revisions, -10, null, true ) );

			$css_ok = false;
			if ( is_string( $p['css'] ?? null ) && false === stripos( $p['css'], '</style' ) ) {
				$dir = trailingslashit( wp_upload_dir()['basedir'] ) . 'betheme/css';
				if ( wp_mkdir_p( $dir ) ) {
					$css_ok = false !== file_put_contents( "$dir/post-$id.css", $p['css'] )
						&& false !== file_put_contents( "$dir/post-$id-preview.css", $p['css'] );
				}
			}

			clean_post_cache( $id );
			$raw = get_post_meta( $id, 'mfn-page-items', true );
			return array( 'id' => $id, 'saved' => $raw === $encoded, 'css' => $css_ok );
		},
	) );
} );

/*
 * Betheme sidinställningar (Page Options), t.ex. egen meny per sida:
 *   GET  /comdeva/v1/page-options/<id>   alla mfn-post-*-fält
 *   POST /comdeva/v1/page-options/<id>   {"mfn-post-menu": "<meny-id eller tomt>"} – bara mfn-post-*, bara text
 */
function comdeva_page_options( $id ) {
	$out = array();
	foreach ( get_post_meta( $id ) as $key => $values ) {
		if ( preg_match( '/^mfn-post-[a-z0-9_-]+$/', $key ) ) {
			$out[ $key ] = (string) get_post_meta( $id, $key, true );
		}
	}
	return $out;
}

add_action( 'rest_api_init', function () {
	$can_edit = function ( WP_REST_Request $req ) {
		$id = (int) $req['id'];
		return $id > 0 && get_post( $id ) && current_user_can( 'edit_post', $id );
	};
	register_rest_route( 'comdeva/v1', '/page-options/(?P<id>\d+)', array(
		array(
			'methods'             => 'GET',
			'permission_callback' => $can_edit,
			'callback'            => function ( WP_REST_Request $req ) {
				return array( 'id' => (int) $req['id'], 'options' => comdeva_page_options( (int) $req['id'] ) );
			},
		),
		array(
			'methods'             => 'POST',
			'permission_callback' => $can_edit,
			'callback'            => function ( WP_REST_Request $req ) {
				$id     = (int) $req['id'];
				$params = $req->get_json_params();
				if ( ! is_array( $params ) || ! $params ) {
					return new WP_Error( 'comdeva_bad_options', 'Inga fält', array( 'status' => 400 ) );
				}
				foreach ( $params as $key => $value ) {
					if ( ! preg_match( '/^mfn-post-[a-z0-9_-]+$/', (string) $key ) || ! is_scalar( $value ) ) {
						return new WP_Error( 'comdeva_bad_options', "Ogiltigt fält: $key", array( 'status' => 400 ) );
					}
					if ( 'mfn-post-menu' === $key && '' !== (string) $value && ! is_nav_menu( (int) $value ) ) {
						return new WP_Error( 'comdeva_bad_menu', "Menyn finns inte: $value", array( 'status' => 400 ) );
					}
				}
				foreach ( $params as $key => $value ) {
					update_post_meta( $id, $key, wp_slash( (string) $value ) );
				}
				clean_post_cache( $id );
				return array( 'id' => $id, 'options' => comdeva_page_options( $id ) );
			},
		),
	) );
} );

// FluentSMTP:s e-postlogg (t.ex. formulärsvar som mejlats till kunden). Bara läsning, kräver manage_options.
add_action( 'rest_api_init', function () {
	register_rest_route( 'comdeva/v1', '/mail-log', array(
		'methods'             => 'GET',
		'permission_callback' => function () {
			return current_user_can( 'manage_options' );
		},
		'callback'            => function ( WP_REST_Request $req ) {
			global $wpdb;
			$table = $wpdb->prefix . 'fsmpt_email_logs';
			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
				return new WP_Error( 'comdeva_no_mail_log', 'FluentSMTP-loggen finns inte på sajten', array( 'status' => 404 ) );
			}
			$where = array( '1=1' );
			$args  = array();
			$search = trim( (string) $req->get_param( 'search' ) );
			if ( '' !== $search ) {
				$like    = '%' . $wpdb->esc_like( $search ) . '%';
				$where[] = '(`subject` LIKE %s OR `to` LIKE %s OR `from` LIKE %s OR `body` LIKE %s)';
				array_push( $args, $like, $like, $like, $like );
			}
			foreach ( array( 'after' => '>=', 'before' => '<' ) as $key => $op ) {
				$day = (string) $req->get_param( $key );
				if ( '' !== $day ) {
					if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $day ) ) {
						return new WP_Error( 'comdeva_bad_date', "$key ska vara ÅÅÅÅ-MM-DD", array( 'status' => 400 ) );
					}
					$where[] = "`created_at` $op %s";
					$args[]  = $day . ' 00:00:00';
				}
			}
			$limit  = max( 1, min( 200, (int) ( $req->get_param( 'limit' ) ?: 50 ) ) );
			$page   = max( 1, (int) ( $req->get_param( 'page' ) ?: 1 ) );
			$cond   = implode( ' AND ', $where );
			$count  = "SELECT COUNT(*) FROM `$table` WHERE $cond";
			$select = "SELECT `id`, `to`, `from`, `subject`, `body`, `headers`, `status`, `source`, `created_at` "
				. "FROM `$table` WHERE $cond ORDER BY `id` DESC LIMIT %d OFFSET %d";
			$total  = (int) ( $args ? $wpdb->get_var( $wpdb->prepare( $count, $args ) ) : $wpdb->get_var( $count ) );
			$rows   = $wpdb->get_results( $wpdb->prepare( $select, array_merge( $args, array( $limit, ( $page - 1 ) * $limit ) ) ), ARRAY_A );
			foreach ( $rows as &$row ) {
				foreach ( array( 'to', 'headers' ) as $key ) {
					$row[ $key ] = maybe_unserialize( $row[ $key ] );
				}
				$row['id'] = (int) $row['id'];
			}
			return array( 'total' => $total, 'page' => $page, 'limit' => $limit, 'logs' => $rows );
		},
	) );
} );
