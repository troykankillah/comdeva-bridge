<?php
/**
 * Plugin Name: Comdeva Bridge
 * Description: Låter Comdevas verktyg läsa och ändra SEO-fält (Yoast eller Rank Math) och Elementor-/Divi-innehåll via REST, med samma behörighet som i WordPress (edit_post). Installeras som vanligt tillägg (zip) och uppdateras sedan under Tillägg som andra tillägg.
 * Version: 1.21.0
 * Author: Comdeva
 * Requires PHP: 7.4
 * Update URI: https://github.com/troykankillah/comdeva-bridge
 *
 * Endpoints (kräver inloggning, t.ex. Application Password):
 *   GET  /wp-json/comdeva/v1/status
 *   GET  /wp-json/comdeva/v1/seo/<id>
 *   POST /wp-json/comdeva/v1/seo/<id>        {title, description, focus_keyword, canonical, noindex} – Yoast eller Rank Math (1.18)
 *   GET/POST /wp-json/comdeva/v1/site-verification  {google} – Search Console-koden i Yoast/Rank Math (1.18, manage_options)
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
 *   GET  /wp-json/comdeva/v1/updates[?refresh=1]   tillägg med ny version
 *   POST /wp-json/comdeva/v1/updates   {plugin, version} – uppdaterar ett tillägg (kräver update_plugins)
 *   GET  /wp-json/comdeva/v1/maintenance[?refresh=1]  webbunderhåll: uppdateringslogg, senast ändrad per tillägg/tema/kärna, antal som väntar
 *   GET  /wp-json/comdeva/v1/mail-log        FluentSMTP:s e-postlogg, bara läsning (search, after, before, limit, page)
 *   GET  /wp-json/comdeva/v1/smartslider     Smart Slider 3: sliders och bilder (kräver manage_options)
 *   POST /wp-json/comdeva/v1/smartslider/slides/<id>  {published, first}
 *   POST /wp-json/comdeva/v1/smartslider/slides       {source, title, slide, params, thumbnail, published} – kopia efter källan
 *   GET  /wp-json/comdeva/v1/security/scan[?days=60&scan=0]  säkerhetsgenomsökning, bara läsning (manage_options)
 *   GET  /wp-json/comdeva/v1/security/file?path=<sökväg>      en fil (base64); aldrig wp-config, arkiv, dumpar
 *   GET/POST /wp-json/comdeva/v1/security/quarantine  {plugin, dry_run} – avstängd tilläggskatalog i karantän (1.16)
 *                                                     {uploads, dry_run} – katalog under uploads, t.ex. kvarlämnade
 *                                                     backuper efter ett avinstallerat tillägg (1.20)
 *   POST /wp-json/comdeva/v1/security/quarantine/restore  {batch} – flytta tillbaka
 *   POST /wp-json/comdeva/v1/theme-file  {path, sha256, find, replace, dry_run} – byt exakt en textbit i en temafil (1.17)
 *   POST /wp-json/comdeva/v1/cache  {dry_run} – Elementors kit-CSS skrivs om, sedan töms sidcachen (WP Rocket m.fl.) (1.19)
 *                                   {urls} – NitroPack töms bara för de sidorna, annars hela (1.21); andra töms helt
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const COMDEVA_BRIDGE_VERSION = '1.21.0';

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

// Rank Math (1.18): samma fält, andra metanycklar. noindex ligger i listan rank_math_robots.
const COMDEVA_RANKMATH_FIELDS = array(
	'title'         => 'rank_math_title',
	'description'   => 'rank_math_description',
	'focus_keyword' => 'rank_math_focus_keyword',
	'canonical'     => 'rank_math_canonical_url',
	'noindex'       => 'rank_math_robots',
);

/** Vilket SEO-tillägg fälten skrivs till: Yoast om det är aktivt, annars Rank Math om det är aktivt, annars Yoasts nycklar. */
function comdeva_seo_plugin() {
	if ( defined( 'WPSEO_VERSION' ) ) {
		return 'yoast';
	}
	if ( defined( 'RANK_MATH_VERSION' ) || class_exists( 'RankMath' ) ) {
		return 'rankmath';
	}
	return 'yoast';
}

function comdeva_seo_fields() {
	return 'rankmath' === comdeva_seo_plugin() ? COMDEVA_RANKMATH_FIELDS : COMDEVA_SEO_FIELDS;
}

/** Rank Maths robotslista med noindex satt eller borttaget; tom lista = tilläggets standard. */
function comdeva_rankmath_robots( $robots, $noindex ) {
	$robots = array_values( array_diff( is_array( $robots ) ? $robots : array(), array( 'index', 'noindex' ) ) );
	if ( $noindex ) {
		array_unshift( $robots, 'noindex' );
	} elseif ( $robots ) {
		array_unshift( $robots, 'index' );
	}
	return $robots;
}

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
				'rankmath'  => defined( 'RANK_MATH_VERSION' ) ? RANK_MATH_VERSION : null,
				'seo'       => comdeva_seo_plugin(),
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
				$rank   = 'rankmath' === comdeva_seo_plugin();
				foreach ( comdeva_seo_fields() as $field => $meta_key ) {
					if ( ! is_array( $params ) || ! array_key_exists( $field, $params ) ) {
						continue;
					}
					$value = $params[ $field ];
					if ( 'noindex' === $field && $rank ) {
						$robots = comdeva_rankmath_robots( get_post_meta( $id, $meta_key, true ), (bool) $value );
						if ( $robots ) {
							update_post_meta( $id, $meta_key, $robots );
						} else {
							delete_post_meta( $id, $meta_key );
						}
						continue;
					}
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
	$plugin = comdeva_seo_plugin();
	$out    = array( 'id' => $id, 'plugin' => $plugin );
	foreach ( comdeva_seo_fields() as $field => $meta_key ) {
		$value = get_post_meta( $id, $meta_key, true );
		if ( 'noindex' === $field ) {
			$out[ $field ] = 'rankmath' === $plugin
				? is_array( $value ) && in_array( 'noindex', $value, true )
				: '1' === (string) $value;
		} else {
			$out[ $field ] = (string) $value;
		}
	}
	return $out;
}

/*
 * Search Console-verifiering (1.18): HTML-taggens kod i SEO-tilläggets inställning.
 *   GET  /comdeva/v1/site-verification            {plugin, google}
 *   POST /comdeva/v1/site-verification {google}   hela meta-taggen eller bara koden; "" tar bort den
 */
function comdeva_verification_code( $value ) {
	$value = trim( (string) $value );
	if ( preg_match( '/content\s*=\s*["\']([^"\']*)["\']/i', $value, $m ) ) {
		$value = trim( $m[1] );
	}
	if ( '' !== $value && ! preg_match( '/^[A-Za-z0-9_\-]{10,100}$/', $value ) ) {
		return new WP_Error( 'comdeva_bad_verification', 'Koden ska vara värdet i content="…" från Search Console', array( 'status' => 400 ) );
	}
	return $value;
}

function comdeva_verification_get() {
	$plugin = comdeva_seo_plugin();
	if ( 'rankmath' === $plugin ) {
		$opts = get_option( 'rank-math-options-general', array() );
		$code = is_array( $opts ) ? ( $opts['google_verify'] ?? '' ) : '';
	} else {
		$opts = get_option( 'wpseo', array() );
		$code = is_array( $opts ) ? ( $opts['googleverify'] ?? '' ) : '';
	}
	return array( 'plugin' => $plugin, 'google' => (string) $code );
}

add_action( 'rest_api_init', function () {
	register_rest_route( 'comdeva/v1', '/site-verification', array(
		array(
			'methods'             => 'GET',
			'permission_callback' => function () {
				return current_user_can( 'manage_options' );
			},
			'callback'            => 'comdeva_verification_get',
		),
		array(
			'methods'             => 'POST',
			'permission_callback' => function () {
				return current_user_can( 'manage_options' );
			},
			'callback'            => function ( WP_REST_Request $req ) {
				$params = $req->get_json_params();
				if ( ! is_array( $params ) || ! array_key_exists( 'google', $params ) ) {
					return new WP_Error( 'comdeva_bad_verification', 'google saknas', array( 'status' => 400 ) );
				}
				$code = comdeva_verification_code( $params['google'] );
				if ( is_wp_error( $code ) ) {
					return $code;
				}
				if ( 'rankmath' === comdeva_seo_plugin() ) {
					$key  = 'rank-math-options-general';
					$opts = get_option( $key, array() );
					$opts = is_array( $opts ) ? $opts : array();
					$opts['google_verify'] = $code;
				} else {
					$key  = 'wpseo';
					$opts = get_option( $key, array() );
					$opts = is_array( $opts ) ? $opts : array();
					$opts['googleverify'] = $code;
				}
				update_option( $key, $opts );
				return comdeva_verification_get();
			},
		),
	) );
} );

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

// ---------------------------------------------------------------- Tilläggsuppdateringar
// GET  /comdeva/v1/updates[?refresh=1]       tillägg med ny version (samma lista som Kontrollpanelen → Uppdateringar)
// POST /comdeva/v1/updates {plugin, version}  uppdaterar ett tillägg till exakt den versionen, som knappen i admin
// Kräver update_plugins (administratör, och inte DISALLOW_FILE_MODS). Nyckeln är REST-formatet "mapp/fil".
// Betaltillägg vars uppdaterare bara körs i adminpanelen kan saknas här – de uppdateras i admin.

function comdeva_updates_load() {
	require_once ABSPATH . 'wp-admin/includes/plugin.php';
	require_once ABSPATH . 'wp-admin/includes/file.php';
	require_once ABSPATH . 'wp-admin/includes/misc.php';
	require_once ABSPATH . 'wp-admin/includes/update.php';
	require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
}

function comdeva_updates_refresh() {
	// Som "Sök efter uppdateringar igen": tvinga en ny koll mot wordpress.org och andra uppdaterare.
	$current = get_site_transient( 'update_plugins' );
	if ( is_object( $current ) ) {
		$current->last_checked = 0;
		set_site_transient( 'update_plugins', $current );
	}
	wp_update_plugins();
}

function comdeva_updates_list() {
	$current = get_site_transient( 'update_plugins' );
	$out     = array();
	foreach ( get_plugins() as $file => $data ) {
		if ( ! is_object( $current ) || empty( $current->response[ $file ] ) ) {
			continue;
		}
		$u     = (object) $current->response[ $file ];
		$out[] = array(
			'plugin'       => preg_replace( '/\.php$/', '', $file ),
			'name'         => $data['Name'],
			'version'      => $data['Version'],
			'new_version'  => isset( $u->new_version ) ? (string) $u->new_version : '',
			'active'       => is_plugin_active( $file ),
			'requires'     => isset( $u->requires ) ? (string) $u->requires : '',
			'requires_php' => isset( $u->requires_php ) ? (string) $u->requires_php : '',
			'tested'       => isset( $u->tested ) ? (string) $u->tested : '',
			'package'      => ! empty( $u->package ),
		);
	}
	return $out;
}

add_action( 'rest_api_init', function () {
	$can = function () {
		return current_user_can( 'update_plugins' );
	};
	register_rest_route( 'comdeva/v1', '/updates', array(
		array(
			'methods'             => 'GET',
			'permission_callback' => $can,
			'callback'            => function ( WP_REST_Request $req ) {
				comdeva_updates_load();
				if ( $req['refresh'] ) {
					comdeva_updates_refresh();
				}
				return array(
					'wordpress' => get_bloginfo( 'version' ),
					'php'       => PHP_VERSION,
					'plugins'   => comdeva_updates_list(),
				);
			},
		),
		array(
			'methods'             => 'POST',
			'permission_callback' => $can,
			'callback'            => function ( WP_REST_Request $req ) {
				comdeva_updates_load();
				$key  = (string) $req['plugin'];
				$want = (string) $req['version'];
				$file = $key . '.php';
				$all  = get_plugins();
				if ( '' === $want || ! isset( $all[ $file ] ) ) {
					return new WP_Error( 'comdeva_update_bad', "Okänt tillägg eller version saknas: $key", array( 'status' => 400 ) );
				}
				comdeva_updates_refresh();
				$offer = null;
				foreach ( comdeva_updates_list() as $u ) {
					if ( $u['plugin'] === $key ) {
						$offer = $u;
					}
				}
				if ( ! $offer || $offer['new_version'] !== $want ) {
					$got = $offer ? $offer['new_version'] : 'ingen uppdatering';
					return new WP_Error( 'comdeva_update_mismatch', "$key: WordPress erbjuder $got, planen säger $want", array( 'status' => 409 ) );
				}
				if ( ! $offer['package'] ) {
					return new WP_Error( 'comdeva_update_nopackage', "$key: uppdateringen saknar nedladdning (licens?) – uppdatera i adminpanelen", array( 'status' => 409 ) );
				}
				$was_active = is_plugin_active( $file );
				$before     = $all[ $file ]['Version'];
				// Samma väg som uppdateringsknappen i admin (wp_ajax_update_plugin): bulk_upgrade med
				// underhållsläge, tillägget förblir aktivt.
				$skin     = new WP_Ajax_Upgrader_Skin();
				$upgrader = new Plugin_Upgrader( $skin );
				$result   = $upgrader->bulk_upgrade( array( $file ) );
				$errors   = $skin->get_errors();
				if ( is_wp_error( $errors ) && $errors->has_errors() ) {
					return new WP_Error( 'comdeva_update_failed', "$key: " . implode( ' ', $errors->get_error_messages() ), array( 'status' => 500 ) );
				}
				if ( ! is_array( $result ) || empty( $result[ $file ] ) || is_wp_error( $result[ $file ] ) ) {
					global $wp_filesystem;
					$msg = is_array( $result ) && isset( $result[ $file ] ) && is_wp_error( $result[ $file ] )
						? $result[ $file ]->get_error_message()
						: ( $wp_filesystem instanceof WP_Filesystem_Base && is_wp_error( $wp_filesystem->errors ) && $wp_filesystem->errors->has_errors()
							? $wp_filesystem->errors->get_error_message() : 'uppdateringen gick inte att installera (filsystemet?)' );
					return new WP_Error( 'comdeva_update_failed', "$key: $msg", array( 'status' => 500 ) );
				}
				wp_clean_plugins_cache();
				$after = get_plugins();
				$now   = isset( $after[ $file ] ) ? $after[ $file ]['Version'] : '';
				if ( $was_active && ! is_plugin_active( $file ) ) {
					$act = activate_plugin( $file, '', false, true );
					if ( is_wp_error( $act ) ) {
						return new WP_Error( 'comdeva_update_inactive', "$key uppdaterad till $now men kunde inte aktiveras igen: " . $act->get_error_message(), array( 'status' => 500 ) );
					}
				}
				return array(
					'plugin' => $key,
					'from'   => $before,
					'to'     => $now,
					'active' => is_plugin_active( $file ),
				);
			},
		),
	) );
} );

// ---------------------------------------------------------------- Webbunderhåll (1.14)
// Loggar varje uppdatering av tillägg, teman, WordPress och översättningar – i admin, via bryggan eller automatiskt –
// så att Kundhälsa ser om månadens webbunderhåll är gjort. Sparas i en option (senaste 300), inget skickas någonstans.
// GET /comdeva/v1/maintenance visar loggen plus när varje tillägg, tema och WordPress-kärnan senast ändrades på disk
// (fungerar även för uppdateringar gjorda innan loggen fanns).

const COMDEVA_UPDATE_LOG = 'comdeva_update_log';

add_action( 'upgrader_process_complete', function ( $upgrader, $extra ) {
	if ( ! is_array( $extra ) || empty( $extra['type'] ) || ( isset( $extra['action'] ) && 'update' !== $extra['action'] ) ) {
		return;
	}
	$type  = (string) $extra['type'];
	$items = array();
	if ( 'plugin' === $type ) {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		wp_clean_plugins_cache( false );
		$all   = get_plugins();
		$files = ! empty( $extra['plugins'] ) ? (array) $extra['plugins'] : ( ! empty( $extra['plugin'] ) ? array( $extra['plugin'] ) : array() );
		foreach ( $files as $file ) {
			$items[] = array(
				'key'     => preg_replace( '/\.php$/', '', (string) $file ),
				'name'    => isset( $all[ $file ] ) ? $all[ $file ]['Name'] : (string) $file,
				'version' => isset( $all[ $file ] ) ? $all[ $file ]['Version'] : '',
			);
		}
	} elseif ( 'theme' === $type ) {
		$slugs = ! empty( $extra['themes'] ) ? (array) $extra['themes'] : ( ! empty( $extra['theme'] ) ? array( $extra['theme'] ) : array() );
		foreach ( $slugs as $slug ) {
			$t       = wp_get_theme( $slug );
			$items[] = array( 'key' => (string) $slug, 'name' => $t->exists() ? $t->get( 'Name' ) : (string) $slug, 'version' => $t->exists() ? $t->get( 'Version' ) : '' );
		}
	} elseif ( 'core' === $type ) {
		$items[] = array( 'key' => 'wordpress', 'name' => 'WordPress', 'version' => get_bloginfo( 'version' ) );
	} elseif ( 'translation' === $type ) {
		$items[] = array( 'key' => 'translations', 'name' => 'Översättningar', 'version' => '' );
	} else {
		return;
	}
	if ( ! $items ) {
		return;
	}
	$user = wp_get_current_user();
	$log  = get_option( COMDEVA_UPDATE_LOG, array() );
	if ( ! is_array( $log ) ) {
		$log = array();
	}
	$log[] = array(
		'time'  => gmdate( 'c' ),
		'type'  => $type,
		'items' => $items,
		'by'    => ( $user && $user->exists() ) ? $user->user_login : 'automatisk',
		'via'   => ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ? 'rest' : ( wp_doing_cron() ? 'cron' : 'admin' ),
	);
	update_option( COMDEVA_UPDATE_LOG, array_slice( $log, -300 ), false );
}, 10, 2 );

function comdeva_mtime( $paths ) {
	$latest = 0;
	foreach ( $paths as $path ) {
		if ( $path && file_exists( $path ) ) {
			$latest = max( $latest, (int) @filemtime( $path ) );
		}
	}
	return $latest ? gmdate( 'c', $latest ) : null;
}

add_action( 'rest_api_init', function () {
	register_rest_route( 'comdeva/v1', '/maintenance', array(
		'methods'             => 'GET',
		'permission_callback' => function () {
			return current_user_can( 'update_plugins' );
		},
		'callback'            => function ( WP_REST_Request $req ) {
			comdeva_updates_load();
			if ( $req['refresh'] ) {
				comdeva_updates_refresh();
			}
			$plugins = array();
			foreach ( get_plugins() as $file => $data ) {
				$dir       = dirname( $file );
				$plugins[] = array(
					'plugin'   => preg_replace( '/\.php$/', '', $file ),
					'name'     => $data['Name'],
					'version'  => $data['Version'],
					'active'   => is_plugin_active( $file ),
					'modified' => comdeva_mtime( array( WP_PLUGIN_DIR . '/' . $file, '.' !== $dir ? WP_PLUGIN_DIR . '/' . $dir : null ) ),
				);
			}
			$themes = array();
			foreach ( wp_get_themes() as $slug => $t ) {
				$themes[] = array(
					'theme'    => (string) $slug,
					'name'     => $t->get( 'Name' ),
					'version'  => $t->get( 'Version' ),
					'active'   => get_stylesheet() === $slug || get_template() === $slug,
					'modified' => comdeva_mtime( array( $t->get_stylesheet_directory() . '/style.css', $t->get_stylesheet_directory() ) ),
				);
			}
			$log = get_option( COMDEVA_UPDATE_LOG, array() );
			return array(
				'now'     => gmdate( 'c' ),
				'bridge'  => COMDEVA_BRIDGE_VERSION,
				'core'    => array( 'version' => get_bloginfo( 'version' ), 'modified' => comdeva_mtime( array( ABSPATH . WPINC . '/version.php' ) ) ),
				'plugins' => $plugins,
				'themes'  => $themes,
				'pending' => comdeva_updates_list(),
				'log'     => is_array( $log ) ? array_reverse( $log ) : array(),
			);
		},
	) );
} );

// Smart Slider 3: bilderna ligger i egna tabeller, utan REST-API. Läsa alla, avpublicera/publicera en bild
// och kopiera en bild med nytt innehåll (läggs direkt efter källan). Inget raderas. Kräver manage_options.
function comdeva_ss3_tables() {
	global $wpdb;
	$slides  = $wpdb->prefix . 'nextend2_smartslider3_slides';
	$sliders = $wpdb->prefix . 'nextend2_smartslider3_sliders';
	if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $slides ) ) !== $slides ) {
		return null;
	}
	return array( 'slides' => $slides, 'sliders' => $sliders );
}

function comdeva_ss3_slide( $row ) {
	return array(
		'id'           => (int) $row['id'],
		'slider'       => (int) $row['slider'],
		'title'        => (string) $row['title'],
		'published'    => (int) $row['published'],
		'first'        => (int) $row['first'],
		'ordering'     => (int) $row['ordering'],
		'publish_up'   => (string) $row['publish_up'],
		'publish_down' => (string) $row['publish_down'],
		'thumbnail'    => (string) $row['thumbnail'],
		'slide'        => (string) $row['slide'],
		'params'       => (string) $row['params'],
		'generator_id' => (int) $row['generator_id'],
	);
}

// Smart Slider sparar färdig HTML i sin cache – töm den för slidern efter en ändring.
function comdeva_ss3_clear_cache( $slider ) {
	if ( class_exists( '\Nextend\SmartSlider3\PublicApi\Project' )
		&& method_exists( '\Nextend\SmartSlider3\PublicApi\Project', 'clearCache' ) ) {
		\Nextend\SmartSlider3\PublicApi\Project::clearCache( (int) $slider );
		return true;
	}
	return false;
}

add_action( 'rest_api_init', function () {
	$can = function () {
		return current_user_can( 'manage_options' );
	};
	$missing = function () {
		return new WP_Error( 'comdeva_ss3_missing', 'Smart Slider 3 finns inte på sajten', array( 'status' => 404 ) );
	};
	register_rest_route( 'comdeva/v1', '/smartslider', array(
		'methods'             => 'GET',
		'permission_callback' => $can,
		'callback'            => function () use ( $missing ) {
			global $wpdb;
			$t = comdeva_ss3_tables();
			if ( ! $t ) {
				return $missing();
			}
			$sliders = array();
			foreach ( $wpdb->get_results( "SELECT id, title, type, slider_status FROM {$t['sliders']} ORDER BY id", ARRAY_A ) as $s ) {
				$sliders[] = array( 'id' => (int) $s['id'], 'title' => (string) $s['title'], 'type' => (string) $s['type'],
					'status' => (string) $s['slider_status'] );
			}
			$slides = array();
			foreach ( $wpdb->get_results( "SELECT * FROM {$t['slides']} ORDER BY slider, ordering, id", ARRAY_A ) as $r ) {
				$slides[] = comdeva_ss3_slide( $r );
			}
			return array( 'sliders' => $sliders, 'slides' => $slides );
		},
	) );
	register_rest_route( 'comdeva/v1', '/smartslider/slides/(?P<id>\d+)', array(
		'methods'             => 'POST',
		'permission_callback' => $can,
		'callback'            => function ( WP_REST_Request $req ) use ( $missing ) {
			global $wpdb;
			$t = comdeva_ss3_tables();
			if ( ! $t ) {
				return $missing();
			}
			$id  = (int) $req['id'];
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t['slides']} WHERE id = %d", $id ), ARRAY_A );
			if ( ! $row ) {
				return new WP_Error( 'comdeva_ss3_slide', "Bilden finns inte: $id", array( 'status' => 404 ) );
			}
			$data = array();
			foreach ( array( 'published', 'first' ) as $key ) {
				if ( null !== $req[ $key ] ) {
					$data[ $key ] = $req[ $key ] ? 1 : 0;
				}
			}
			if ( ! $data ) {
				return new WP_Error( 'comdeva_ss3_fields', 'Ange published och/eller first', array( 'status' => 400 ) );
			}
			if ( false === $wpdb->update( $t['slides'], $data, array( 'id' => $id ) ) ) {
				return new WP_Error( 'comdeva_ss3_db', $wpdb->last_error, array( 'status' => 500 ) );
			}
			$cleared = comdeva_ss3_clear_cache( $row['slider'] );
			$row     = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t['slides']} WHERE id = %d", $id ), ARRAY_A );
			return array( 'slide' => comdeva_ss3_slide( $row ), 'cache_cleared' => $cleared );
		},
	) );
	register_rest_route( 'comdeva/v1', '/smartslider/slides', array(
		'methods'             => 'POST',
		'permission_callback' => $can,
		'callback'            => function ( WP_REST_Request $req ) use ( $missing ) {
			global $wpdb;
			$t = comdeva_ss3_tables();
			if ( ! $t ) {
				return $missing();
			}
			$src = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t['slides']} WHERE id = %d", (int) $req['source'] ), ARRAY_A );
			if ( ! $src ) {
				return new WP_Error( 'comdeva_ss3_slide', 'Källbilden finns inte: ' . (int) $req['source'], array( 'status' => 404 ) );
			}
			$title = trim( (string) $req['title'] );
			if ( '' === $title ) {
				return new WP_Error( 'comdeva_ss3_fields', 'title krävs', array( 'status' => 400 ) );
			}
			$new = $src;
			unset( $new['id'] );
			$new['title']     = $title;
			$new['first']     = 0;
			$new['published'] = $req['published'] ? 1 : 0;
			$new['ordering']  = (int) $src['ordering'] + 1;
			foreach ( array( 'slide', 'params' ) as $key ) {  // JSON som text, sparas tecken för tecken
				if ( null !== $req[ $key ] ) {
					$value = (string) $req[ $key ];
					if ( null === json_decode( $value, true ) ) {
						return new WP_Error( 'comdeva_ss3_json', "$key är inte giltig JSON", array( 'status' => 400 ) );
					}
					$new[ $key ] = $value;
				}
			}
			if ( null !== $req['thumbnail'] ) {
				$new['thumbnail'] = (string) $req['thumbnail'];
			}
			// Plats direkt efter källan: flytta de senare bilderna i samma slider ett steg.
			$wpdb->query( $wpdb->prepare( "UPDATE {$t['slides']} SET ordering = ordering + 1 WHERE slider = %d AND ordering > %d",
				(int) $src['slider'], (int) $src['ordering'] ) );
			if ( false === $wpdb->insert( $t['slides'], $new ) ) {
				return new WP_Error( 'comdeva_ss3_db', $wpdb->last_error, array( 'status' => 500 ) );
			}
			$id      = (int) $wpdb->insert_id;
			$cleared = comdeva_ss3_clear_cache( $src['slider'] );
			$row     = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t['slides']} WHERE id = %d", $id ), ARRAY_A );
			return array( 'slide' => comdeva_ss3_slide( $row ), 'cache_cleared' => $cleared );
		},
	) );
} );

// ---------------------------------------------------------------------------------------------
// Säkerhetsgenomsökning (1.15) – bara läsning. Letar efter bakdörrar efter ett intrång: tillägg och
// mu-plugins som filtreras bort ur admin, PHP i uploads, kärnfiler som skiljer sig från WordPress.org,
// administratörer direkt ur databasen (förbi filter som döljer användare) och misstänkta kodmönster.
// Ändrar ingenting. Filinnehåll kan läsas en fil i taget, aldrig wp-config.php eller liknande.

const COMDEVA_SECURITY_PATTERNS = array(
	'eval_decode'     => '/\beval\s*\(\s*(?:base64_decode|gzinflate|gzuncompress|gzdecode|str_rot13|strrev|hex2bin|\$)/i',
	'assert_input'    => '/\bassert\s*\(\s*\$_(?:POST|GET|REQUEST|COOKIE|SERVER)/i',
	'input_call'      => '/\$_(?:POST|GET|REQUEST|COOKIE)\s*\[[^\]]{1,40}\]\s*\(/i',
	'create_function' => '/\bcreate_function\s*\(/i',
	'long_base64'     => '/[\'"][A-Za-z0-9+\/]{400,}={0,2}[\'"]/',
	'decode_chain'    => '/(?:gzinflate|gzuncompress|str_rot13)\s*\(\s*base64_decode/i',
	'hex_escapes'     => '/(?:\\\\x[0-9a-f]{2}){30,}/i',
	'chr_chain'       => '/(?:chr\s*\(\s*\d+\s*\)\s*\.\s*){10,}/i',
	'hide_users'      => '/add_(?:action|filter)\s*\(\s*[\'"](?:pre_user_query|views_users|users_list_table_query_args)[\'"]/i',
	'hide_plugins'    => '/add_(?:action|filter)\s*\(\s*[\'"](?:all_plugins|site_transient_update_plugins)[\'"]/i',
	'create_admin'    => '/wp_(?:create|insert)_user[\s\S]{0,300}administrator/i',
	'remote_exec'     => '/(?:file_get_contents|wp_remote_get|curl_exec)[\s\S]{0,200}(?:eval|file_put_contents)\s*\(/i',
	'shell'           => '/\b(?:shell_exec|passthru|proc_open|popen|system)\s*\(\s*\$/i',
);

function comdeva_security_rel( $path ) {
	$root = wp_normalize_path( ABSPATH );
	$path = wp_normalize_path( $path );
	return 0 === strpos( $path, $root ) ? substr( $path, strlen( $root ) ) : $path;
}

function comdeva_security_hits( $file ) {
	$size = @filesize( $file );
	if ( ! $size || $size > 2 * 1024 * 1024 ) {
		return array();
	}
	$code = @file_get_contents( $file );
	if ( false === $code ) {
		return array();
	}
	$hits = array();
	foreach ( COMDEVA_SECURITY_PATTERNS as $name => $re ) {
		if ( preg_match( $re, $code ) ) {
			$hits[] = $name;
		}
	}
	return $hits;
}

function comdeva_security_is_php( $file ) {
	return (bool) preg_match( '/\.(?:php\d?|phtml|phar|pht|inc)$/i', $file );
}

// Alla filer under $dir (rekursivt), högst $limit. Hoppar över cachekataloger.
function comdeva_security_walk( $dir, $limit = 20000, $skip_cache = true ) {
	$out = array();
	if ( ! is_dir( $dir ) ) {
		return $out;
	}
	try {
		$it = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::LEAVES_ONLY
		);
		foreach ( $it as $f ) {
			if ( count( $out ) >= $limit ) {
				break;
			}
			$p = wp_normalize_path( $f->getPathname() );
			if ( $skip_cache && preg_match( '#/(?:cache|node_modules|\.git)/#', $p ) ) {
				continue;
			}
			$out[] = $p;
		}
	} catch ( Exception $e ) {
		return $out;
	}
	return $out;
}

function comdeva_security_file_info( $file, $scan ) {
	$info = array(
		'path'     => comdeva_security_rel( $file ),
		'size'     => (int) @filesize( $file ),
		'modified' => gmdate( 'c', (int) @filemtime( $file ) ),
	);
	if ( $scan && comdeva_security_is_php( $file ) ) {
		$info['hits'] = comdeva_security_hits( $file );
	}
	return $info;
}

function comdeva_security_scan( $days, $scan ) {
	global $wpdb;
	@set_time_limit( 300 );
	$since  = time() - $days * DAY_IN_SECONDS;
	$report = array( 'generated' => gmdate( 'c' ), 'days' => $days );

	// Aktiva tillägg direkt ur databasen – get_option() går genom filter som skadlig kod kan använda.
	$raw = $wpdb->get_var( "SELECT option_value FROM {$wpdb->options} WHERE option_name = 'active_plugins'" );
	$report['active_plugins_raw'] = array_values( (array) maybe_unserialize( $raw ) );
	if ( ! function_exists( 'get_plugins' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}
	$listed = get_plugins();

	// Varje katalog/fil i plugins: rubrik, antal filer, senast ändrad, filer med träffar.
	$plugins = array();
	foreach ( (array) scandir( WP_PLUGIN_DIR ) as $entry ) {
		if ( '.' === $entry[0] || 'index.php' === $entry ) {
			continue;
		}
		$path  = WP_PLUGIN_DIR . '/' . $entry;
		$files = is_dir( $path ) ? comdeva_security_walk( $path, 5000 ) : array( wp_normalize_path( $path ) );
		$main  = null;
		foreach ( $listed as $key => $data ) {
			if ( $key === $entry || 0 === strpos( $key, $entry . '/' ) ) {
				$main = array(
					'file'       => $key,
					'name'       => $data['Name'],
					'version'    => $data['Version'],
					'author'     => $data['Author'],
					'plugin_uri' => $data['PluginURI'],
				);
				break;
			}
		}
		$newest = 0;
		$recent = 0;
		$hits   = array();
		foreach ( $files as $f ) {
			$m      = (int) @filemtime( $f );
			$newest = max( $newest, $m );
			if ( $m >= $since ) {
				$recent++;
			}
			if ( $scan && comdeva_security_is_php( $f ) ) {
				$h = comdeva_security_hits( $f );
				if ( $h ) {
					$hits[] = array(
						'path'     => comdeva_security_rel( $f ),
						'hits'     => $h,
						'modified' => gmdate( 'c', $m ),
					);
				}
			}
		}
		$plugins[] = array(
			'entry'        => $entry,
			'header'       => $main,
			'active'       => $main && in_array( $main['file'], $report['active_plugins_raw'], true ),
			'files'        => count( $files ),
			'recent_files' => $recent,
			'newest'       => $newest ? gmdate( 'c', $newest ) : null,
			'hits'         => $hits,
		);
	}
	$report['plugins'] = $plugins;

	// mu-plugins och drop-ins laddas alltid och syns knappt i admin – visa alla.
	$report['mu_plugins'] = array();
	foreach ( comdeva_security_walk( WPMU_PLUGIN_DIR, 2000 ) as $f ) {
		$report['mu_plugins'][] = comdeva_security_file_info( $f, true );
	}
	$report['dropins'] = array();
	foreach ( (array) glob( WP_CONTENT_DIR . '/*.php' ) as $f ) {
		$report['dropins'][] = comdeva_security_file_info( $f, true );
	}

	// PHP i uploads ska inte finnas.
	$uploads               = wp_get_upload_dir();
	$report['uploads_php'] = array();
	foreach ( comdeva_security_walk( $uploads['basedir'], 200000 ) as $f ) {
		if ( comdeva_security_is_php( $f ) || '.htaccess' === basename( $f ) ) {
			$report['uploads_php'][] = comdeva_security_file_info( $f, true );
		}
	}

	// Teman: bara PHP-filer med träffar eller som ändrats nyligen.
	$report['themes'] = array();
	foreach ( (array) glob( get_theme_root() . '/*', GLOB_ONLYDIR ) as $dir ) {
		$items = array();
		$count = 0;
		foreach ( comdeva_security_walk( $dir, 5000 ) as $f ) {
			$count++;
			if ( ! comdeva_security_is_php( $f ) ) {
				continue;
			}
			$info = comdeva_security_file_info( $f, $scan );
			if ( ! empty( $info['hits'] ) || strtotime( $info['modified'] ) >= $since ) {
				$items[] = $info;
			}
		}
		$report['themes'][] = array(
			'theme'   => basename( $dir ),
			'files'   => $count,
			'flagged' => $items,
		);
	}

	// Rotkatalogen: PHP-filer och .htaccess (wp-config.php bara metadata).
	$report['root'] = array();
	foreach ( (array) scandir( ABSPATH ) as $f ) {
		$p = ABSPATH . $f;
		if ( is_file( $p ) && ( comdeva_security_is_php( $f ) || '.htaccess' === $f || '.user.ini' === $f ) ) {
			$report['root'][] = comdeva_security_file_info( $p, 'wp-config.php' !== $f );
		}
	}

	// Kärnfiler mot WordPress.org:s kontrollsummor.
	if ( ! function_exists( 'get_core_checksums' ) ) {
		require_once ABSPATH . 'wp-admin/includes/update.php';
	}
	$sums = get_core_checksums( get_bloginfo( 'version' ), get_locale() );
	if ( ! $sums ) {
		$sums = get_core_checksums( get_bloginfo( 'version' ), 'en_US' );
	}
	$core = array(
		'available' => (bool) $sums,
		'checked'   => 0,
		'modified'  => array(),
		'missing'   => array(),
		'unknown'   => array(),
	);
	if ( $sums ) {
		foreach ( $sums as $rel => $md5 ) {
			if ( 0 === strpos( $rel, 'wp-content/' ) ) {
				continue;
			}
			$p = ABSPATH . $rel;
			if ( ! file_exists( $p ) ) {
				$core['missing'][] = $rel;
				continue;
			}
			$core['checked']++;
			if ( md5_file( $p ) !== $md5 ) {
				$core['modified'][] = comdeva_security_file_info( $p, true );
			}
		}
		foreach ( array( 'wp-admin', 'wp-includes' ) as $d ) {
			foreach ( comdeva_security_walk( ABSPATH . $d, 10000 ) as $f ) {
				$rel = comdeva_security_rel( $f );
				if ( ! isset( $sums[ $rel ] ) && comdeva_security_is_php( $f ) ) {
					$core['unknown'][] = comdeva_security_file_info( $f, true );
				}
			}
		}
	}
	$report['core'] = $core;

	// Administratörer direkt ur databasen, jämfört med vad WordPress visar.
	$key    = $wpdb->get_blog_prefix() . 'capabilities';
	$rows   = $wpdb->get_results( $wpdb->prepare(
		"SELECT u.ID, u.user_login, u.user_email, u.user_registered, m.meta_value FROM {$wpdb->users} u
		 JOIN {$wpdb->usermeta} m ON m.user_id = u.ID AND m.meta_key = %s",
		$key
	), ARRAY_A );
	$admins = array();
	foreach ( (array) $rows as $r ) {
		$caps = maybe_unserialize( $r['meta_value'] );
		if ( is_array( $caps ) && ! empty( $caps['administrator'] ) ) {
			$admins[] = array(
				'id'         => (int) $r['ID'],
				'login'      => $r['user_login'],
				'email'      => $r['user_email'],
				'registered' => $r['user_registered'],
			);
		}
	}
	$visible                 = array_map( 'intval', get_users( array( 'role' => 'administrator', 'fields' => 'ID' ) ) );
	$report['admins']        = $admins;
	$report['admins_hidden'] = array_values( array_diff( wp_list_pluck( $admins, 'id' ), $visible ) );
	$report['users_total']   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->users}" );

	// Schemalagda jobb – ett vanligt sätt att lägga tillbaka bakdörrar.
	$hooks = array();
	foreach ( (array) _get_cron_array() as $ts => $events ) {
		foreach ( (array) $events as $hook => $unused ) {
			$hooks[ $hook ] = array(
				'hook'         => $hook,
				'next'         => gmdate( 'c', (int) $ts ),
				'has_callback' => (bool) has_action( $hook ),
			);
		}
	}
	$report['cron'] = array_values( $hooks );
	return $report;
}

add_action( 'rest_api_init', function () {
	$is_admin = function () {
		return current_user_can( 'manage_options' );
	};

	register_rest_route( 'comdeva/v1', '/security/scan', array(
		'methods'             => 'GET',
		'permission_callback' => $is_admin,
		'callback'            => function ( WP_REST_Request $req ) {
			$days = max( 1, min( 365, (int) ( $req['days'] ? $req['days'] : 60 ) ) );
			return comdeva_security_scan( $days, '0' !== (string) $req['scan'] );
		},
	) );

	// En fil i taget, relativt WordPress-roten. Aldrig wp-config, databasdumpar, arkiv eller loggar.
	register_rest_route( 'comdeva/v1', '/security/file', array(
		'methods'             => 'GET',
		'permission_callback' => $is_admin,
		'callback'            => function ( WP_REST_Request $req ) {
			$rel  = ltrim( wp_normalize_path( (string) $req['path'] ), '/' );
			$root = trailingslashit( wp_normalize_path( realpath( ABSPATH ) ) );
			$real = realpath( ABSPATH . $rel );
			$real = $real ? wp_normalize_path( $real ) : '';
			if ( ! $real || 0 !== strpos( $real, $root ) || ! is_file( $real ) ) {
				return new WP_Error( 'comdeva_security_path', 'Filen finns inte inom WordPress-roten', array( 'status' => 404 ) );
			}
			if ( preg_match( '/(?:wp-config[^\/]*\.php|\.env|\.sql|\.gz|\.zip|\.tar|\.bak|\.log)$/i', $real ) ) {
				return new WP_Error( 'comdeva_security_denied', 'Den filen läses inte via Bridge', array( 'status' => 403 ) );
			}
			$size = filesize( $real );
			if ( $size > 1024 * 1024 ) {
				return new WP_Error( 'comdeva_security_size', 'Filen är större än 1 MB', array( 'status' => 413 ) );
			}
			$content = (string) file_get_contents( $real );
			return array(
				'path'     => comdeva_security_rel( $real ),
				'size'     => $size,
				'modified' => gmdate( 'c', filemtime( $real ) ),
				'sha256'   => hash( 'sha256', $content ),
				'base64'   => base64_encode( $content ),
				'hits'     => comdeva_security_hits( $real ),
			);
		},
	) );
} );

// ---------------------------------------------------------------------------------------------
// Karantän (1.16) – flyttar en avstängd tilläggskatalog till wp-content/comdeva-quarantine/<katalog>-<tid>/
// och lägger till .quarantine på varje fil, så att inget i den kan köras eller anropas direkt (t.ex. ett
// webbskal). Inget raderas: manifest.json.quarantine visar varje fil med sha256, och restore flyttar tillbaka.

function comdeva_quarantine_root() {
	return wp_normalize_path( WP_CONTENT_DIR ) . '/comdeva-quarantine';
}

function comdeva_quarantine_guard( $dir ) {
	if ( ! is_dir( $dir ) ) {
		wp_mkdir_p( $dir );
	}
	if ( ! file_exists( $dir . '/.htaccess' ) ) {
		file_put_contents( $dir . '/.htaccess', "Require all denied\nDeny from all\n" );
	}
	if ( ! file_exists( $dir . '/index.html' ) ) {
		file_put_contents( $dir . '/index.html', '' );
	}
}

function comdeva_quarantine_plugin( $entry, $dry_run ) {
	global $wpdb;
	$entry = basename( (string) $entry );
	$src   = wp_normalize_path( WP_PLUGIN_DIR ) . '/' . $entry;
	if ( '' === $entry || '.' === $entry[0] || ! is_dir( $src ) ) {
		return new WP_Error( 'comdeva_quarantine_missing', "Tilläggskatalogen finns inte: $entry", array( 'status' => 404 ) );
	}
	$raw    = $wpdb->get_var( "SELECT option_value FROM {$wpdb->options} WHERE option_name = 'active_plugins'" );
	$active = (array) maybe_unserialize( $raw );
	foreach ( $active as $plugin ) {
		if ( 0 === strpos( (string) $plugin, $entry . '/' ) ) {
			return new WP_Error( 'comdeva_quarantine_active', "Stäng av $plugin först", array( 'status' => 409 ) );
		}
	}
	return comdeva_quarantine_dir( $src, $entry, $entry, $dry_run );
}

// En katalog under uploads (1.20), t.ex. wp-file-manager-pro/fm_backup. Måste ligga minst en nivå ner och
// får inte vara en års-/månadskatalog med mediebibliotekets bilder.
function comdeva_quarantine_uploads( $rel, $dry_run ) {
	$up   = wp_get_upload_dir();
	$base = untrailingslashit( wp_normalize_path( $up['basedir'] ) );
	$rel  = trim( wp_normalize_path( (string) $rel ), '/' );
	$src  = $base . '/' . $rel;
	if ( '' === $rel || preg_match( '#(^|/)\.#', $rel ) || ! is_dir( $src ) || is_link( $src ) ) {
		return new WP_Error( 'comdeva_quarantine_missing', "Katalogen finns inte under uploads: $rel", array( 'status' => 404 ) );
	}
	if ( preg_match( '#^\d{4}(/|$)#', $rel ) ) {
		return new WP_Error( 'comdeva_quarantine_media', 'Mediebibliotekets års-/månadskataloger flyttas inte', array( 'status' => 400 ) );
	}
	return comdeva_quarantine_dir( $src, 'uploads-' . str_replace( '/', '_', $rel ), 'uploads/' . $rel, $dry_run );
}

function comdeva_quarantine_dir( $src, $label, $what, $dry_run ) {
	$entry = $what;
	$batch = $label . '-' . gmdate( 'Ymd\THis' );
	$dst   = comdeva_quarantine_root() . '/' . $batch;
	$files = array();
	foreach ( comdeva_quarantine_files( $src ) as $f ) {
		$rel     = substr( $f, strlen( $src ) + 1 );
		$files[] = array(
			'from'   => comdeva_security_rel( $f ),
			'to'     => comdeva_security_rel( $dst . '/' . $rel . '.quarantine' ),
			'size'   => (int) filesize( $f ),
			'sha256' => hash_file( 'sha256', $f ),
		);
	}
	$manifest = array(
		'batch'   => $batch,
		'plugin'  => $entry,
		'created' => gmdate( 'c' ),
		'by'      => wp_get_current_user()->user_login,
		'files'   => $files,
	);
	if ( $dry_run ) {
		$manifest['dry_run'] = true;
		return $manifest;
	}
	comdeva_quarantine_guard( comdeva_quarantine_root() );
	wp_mkdir_p( $dst );
	foreach ( $files as $i => $file ) {
		$from = ABSPATH . $file['from'];
		$to   = ABSPATH . $file['to'];
		wp_mkdir_p( dirname( $to ) );
		if ( ! @rename( $from, $to ) ) {
			$manifest['files']   = array_slice( $files, 0, $i );
			$manifest['error']   = 'Kunde inte flytta ' . $file['from'];
			file_put_contents( $dst . '/manifest.json.quarantine', wp_json_encode( $manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) );
			return new WP_Error( 'comdeva_quarantine_move', $manifest['error'] . ' – det som flyttats står i manifestet',
				array( 'status' => 500, 'manifest' => $manifest ) );
		}
	}
	// Tomma kataloger kvar i plugins (filerna är flyttade) tas bort nerifrån och upp.
	$dirs = array();
	try {
		$it = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $src, FilesystemIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ( $it as $d ) {
			if ( $d->isDir() ) {
				$dirs[] = $d->getPathname();
			}
		}
	} catch ( Exception $e ) {
		$dirs = array();
	}
	foreach ( $dirs as $d ) {
		@rmdir( $d );
	}
	@rmdir( $src );
	$manifest['source_removed'] = ! is_dir( $src );
	file_put_contents( $dst . '/manifest.json.quarantine', wp_json_encode( $manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) );
	return $manifest;
}

// Alla filer i katalogen, även dolda (.htaccess) – inget hoppas över.
function comdeva_quarantine_files( $src ) {
	$out = array();
	try {
		$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $src, FilesystemIterator::SKIP_DOTS ) );
		foreach ( $it as $f ) {
			if ( $f->isFile() ) {
				$out[] = wp_normalize_path( $f->getPathname() );
			}
			if ( count( $out ) >= 20000 ) {
				break;
			}
		}
	} catch ( Exception $e ) {
		return $out;
	}
	return $out;
}

function comdeva_quarantine_list() {
	$out = array();
	foreach ( (array) glob( comdeva_quarantine_root() . '/*/manifest.json.quarantine' ) as $m ) {
		$data = json_decode( (string) file_get_contents( $m ), true );
		if ( is_array( $data ) ) {
			$out[] = array(
				'batch'   => $data['batch'],
				'plugin'  => $data['plugin'],
				'created' => $data['created'],
				'by'      => $data['by'],
				'files'   => count( $data['files'] ),
			);
		}
	}
	return $out;
}

function comdeva_quarantine_restore( $batch ) {
	$batch = basename( (string) $batch );
	$m     = comdeva_quarantine_root() . '/' . $batch . '/manifest.json.quarantine';
	$data  = file_exists( $m ) ? json_decode( (string) file_get_contents( $m ), true ) : null;
	if ( ! is_array( $data ) ) {
		return new WP_Error( 'comdeva_quarantine_batch', "Ingen karantän heter $batch", array( 'status' => 404 ) );
	}
	foreach ( $data['files'] as $file ) {
		if ( file_exists( ABSPATH . $file['from'] ) ) {
			return new WP_Error( 'comdeva_quarantine_exists', 'Filen finns redan: ' . $file['from'], array( 'status' => 409 ) );
		}
	}
	foreach ( $data['files'] as $file ) {
		wp_mkdir_p( dirname( ABSPATH . $file['from'] ) );
		@rename( ABSPATH . $file['to'], ABSPATH . $file['from'] );
	}
	rename( $m, $m . '.restored-' . gmdate( 'Ymd\THis' ) );
	return array(
		'batch'    => $batch,
		'restored' => count( $data['files'] ),
	);
}

add_action( 'rest_api_init', function () {
	$can = function () {
		return current_user_can( 'manage_options' ) && current_user_can( 'delete_plugins' );
	};

	register_rest_route( 'comdeva/v1', '/security/quarantine', array(
		array(
			'methods'             => 'GET',
			'permission_callback' => $can,
			'callback'            => function () {
				return comdeva_quarantine_list();
			},
		),
		array(
			'methods'             => 'POST',
			'permission_callback' => $can,
			'callback'            => function ( WP_REST_Request $req ) {
				if ( ! empty( $req['uploads'] ) ) {
					return comdeva_quarantine_uploads( $req['uploads'], ! empty( $req['dry_run'] ) );
				}
				return comdeva_quarantine_plugin( $req['plugin'], ! empty( $req['dry_run'] ) );
			},
		),
	) );

	register_rest_route( 'comdeva/v1', '/security/quarantine/restore', array(
		'methods'             => 'POST',
		'permission_callback' => $can,
		'callback'            => function ( WP_REST_Request $req ) {
			return comdeva_quarantine_restore( $req['batch'] );
		},
	) );
} );

// ---------------------------------------------------------------------------------------------
// Temafiler (1.17) – byter exakt en textbit i en fil under wp-content/themes. Stoppar om filen ändrats sedan
// den lästes (sha256), om texten inte finns exakt en gång eller om PHP-koden inte går att tolka. Originalet
// sparas i wp-content/comdeva-backups/ och skickas tillbaka i svaret. Efter ändringen töms sidcachen och
// startsidan hämtas; svarar den inte (eller med 5xx) läggs originalet tillbaka direkt.

function comdeva_clear_page_cache( $urls = array() ) {
	$done = array();
	if ( function_exists( 'wpfc_clear_all_cache' ) ) {
		wpfc_clear_all_cache( true );
		$done[] = 'wp-fastest-cache';
	}
	if ( function_exists( 'rocket_clean_domain' ) ) {
		rocket_clean_domain();
		$done[] = 'wp-rocket';
	}
	if ( has_action( 'litespeed_purge_all' ) ) {
		do_action( 'litespeed_purge_all' );
		$done[] = 'litespeed';
	}
	if ( function_exists( 'w3tc_flush_all' ) ) {
		w3tc_flush_all();
		$done[] = 'w3-total-cache';
	}
	// NitroPack (1.21, Skogshem och Haga): utan url töms hela cachen (lokalt + NitroPacks CDN), med url bara den sidan.
	// Funktionen returnerar false om NitroPack inte är ansluten till sitt konto.
	if ( function_exists( 'nitropack_sdk_purge' ) ) {
		$ok = true;
		try {
			foreach ( $urls ? $urls : array( null ) as $url ) {
				$ok = nitropack_sdk_purge( $url, null, 'Comdeva Bridge' ) && $ok;
			}
		} catch ( \Throwable $e ) {
			$ok = false;
		}
		$n      = count( $urls );
		$done[] = ! $ok ? 'nitropack misslyckades (inte ansluten?)'
			: ( $n ? 'nitropack (' . $n . ( 1 === $n ? ' sida)' : ' sidor)' ) : 'nitropack' );
	}
	return $done;
}

// Sidor att tömma i NitroPack: sökvägar (/erbjudanden/) eller hela adresser på sajten. Andra domäner stoppas.
function comdeva_cache_urls( $urls ) {
	$host = strtolower( (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ) );
	$out  = array();
	foreach ( (array) $urls as $url ) {
		$url = trim( (string) $url );
		if ( '' === $url ) {
			continue;
		}
		if ( ! preg_match( '#^https?://#i', $url ) ) {
			$url = home_url( '/' . ltrim( $url, '/' ) );
		}
		if ( strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) ) !== $host ) {
			return new WP_Error( 'comdeva_cache_url', 'Adressen hör inte till sajten: ' . $url, array( 'status' => 400 ) );
		}
		$out[] = $url;
	}
	return array_values( array_unique( $out ) );
}

function comdeva_theme_file_path( $rel ) {
	$root = trailingslashit( wp_normalize_path( realpath( get_theme_root() ) ) );
	$real = realpath( get_theme_root() . '/' . ltrim( (string) $rel, '/' ) );
	$real = $real ? wp_normalize_path( $real ) : '';
	if ( ! $real || 0 !== strpos( $real, $root ) || ! is_file( $real ) || ! preg_match( '/\.(?:php|css|js)$/i', $real ) ) {
		return new WP_Error( 'comdeva_theme_path', 'Ingen php/css/js-fil i wp-content/themes med den sökvägen', array( 'status' => 404 ) );
	}
	return $real;
}

function comdeva_php_parse_error( $code ) {
	try {
		token_get_all( $code, TOKEN_PARSE );
	} catch ( ParseError $e ) {
		return $e->getMessage() . ' (rad ' . $e->getLine() . ')';
	}
	return null;
}

function comdeva_theme_file_edit( WP_REST_Request $req ) {
	$real = comdeva_theme_file_path( $req['path'] );
	if ( is_wp_error( $real ) ) {
		return $real;
	}
	$old  = (string) file_get_contents( $real );
	$hash = hash( 'sha256', $old );
	if ( (string) $req['sha256'] !== $hash ) {
		return new WP_Error( 'comdeva_theme_changed', 'Filen har ändrats sedan den lästes (sha256 stämmer inte)',
			array( 'status' => 409, 'sha256' => $hash ) );
	}
	$find = (string) $req['find'];
	if ( '' === $find || 1 !== substr_count( $old, $find ) ) {
		return new WP_Error( 'comdeva_theme_find', 'Texten finns ' . substr_count( $old, $find ) . ' gånger i filen, inte exakt en',
			array( 'status' => 409 ) );
	}
	$new = str_replace( $find, (string) $req['replace'], $old );
	if ( preg_match( '/\.php$/i', $real ) ) {
		$err = comdeva_php_parse_error( $new );
		if ( $err ) {
			return new WP_Error( 'comdeva_theme_syntax', 'Den nya koden går inte att tolka: ' . $err, array( 'status' => 400 ) );
		}
	}
	$out = array(
		'path'       => comdeva_security_rel( $real ),
		'old_sha256' => $hash,
		'new_sha256' => hash( 'sha256', $new ),
		'old_size'   => strlen( $old ),
		'new_size'   => strlen( $new ),
	);
	if ( ! empty( $req['dry_run'] ) ) {
		$out['dry_run'] = true;
		return $out;
	}
	$dir = wp_normalize_path( WP_CONTENT_DIR ) . '/comdeva-backups';
	comdeva_quarantine_guard( $dir );
	$backup = $dir . '/' . str_replace( '/', '__', $out['path'] ) . '.' . gmdate( 'Ymd\THis' ) . '.bak';
	if ( false === file_put_contents( $backup, $old ) ) {
		return new WP_Error( 'comdeva_theme_backup', 'Kunde inte spara säkerhetskopian', array( 'status' => 500 ) );
	}
	if ( false === file_put_contents( $real, $new, LOCK_EX ) ) {
		return new WP_Error( 'comdeva_theme_write', 'Kunde inte skriva filen', array( 'status' => 500 ) );
	}
	if ( function_exists( 'opcache_invalidate' ) ) {
		@opcache_invalidate( $real, true );
	}
	$res  = wp_remote_get( add_query_arg( 'comdeva-check', time(), home_url( '/' ) ),
		array( 'timeout' => 30, 'sslverify' => false, 'user-agent' => 'Comdeva-System/1.0 (+https://comdeva.se)' ) );
	$code = is_wp_error( $res ) ? 0 : (int) wp_remote_retrieve_response_code( $res );
	if ( ! $code || $code >= 500 ) {
		file_put_contents( $real, $old, LOCK_EX );
		if ( function_exists( 'opcache_invalidate' ) ) {
			@opcache_invalidate( $real, true );
		}
		return new WP_Error( 'comdeva_theme_reverted', "Startsidan svarade $code efter ändringen – originalet är tillbakalagt",
			array( 'status' => 500 ) );
	}
	$out['backup']        = comdeva_security_rel( $backup );
	$out['home_status']   = $code;
	$out['cache_cleared'] = comdeva_clear_page_cache();
	$out['old_base64']    = base64_encode( $old );
	return $out;
}

add_action( 'rest_api_init', function () {
	register_rest_route( 'comdeva/v1', '/theme-file', array(
		'methods'             => 'POST',
		'permission_callback' => function () {
			return current_user_can( 'manage_options' );
		},
		'callback'            => 'comdeva_theme_file_edit',
	) );
} );

// ---------------------------------------------------------------------------------------------
// Sidcache (1.19). När Elementor sparar töms alla Elementors CSS-filer och skrivs om först när en sida visas.
// Hinner WP Rocket (lazyload av bakgrundsbilder i CSS) hämta kitets fil (post-<kit>.css) innan dess, får den
// startsidan via omdirigeringen och sparar HTML som CSS – sajtens färger och typsnitt försvinner (SVEAB 2026-10-20).
// Därför skrivs kitets CSS om först, och sedan töms sidcachen så att WP Rocket bygger om sina kopior.

function comdeva_cache_backends() {
	$found = array();
	if ( function_exists( 'wpfc_clear_all_cache' ) ) {
		$found[] = 'wp-fastest-cache';
	}
	if ( function_exists( 'rocket_clean_domain' ) ) {
		$found[] = 'wp-rocket';
	}
	if ( has_action( 'litespeed_purge_all' ) ) {
		$found[] = 'litespeed';
	}
	if ( function_exists( 'w3tc_flush_all' ) ) {
		$found[] = 'w3-total-cache';
	}
	if ( function_exists( 'nitropack_sdk_purge' ) ) {
		$found[] = 'nitropack';
	}
	return $found;
}

function comdeva_cache_clear( WP_REST_Request $req ) {
	$dry  = (bool) $req->get_param( 'dry_run' );
	$urls = comdeva_cache_urls( $req->get_param( 'urls' ) );
	if ( is_wp_error( $urls ) ) {
		return $urls;
	}
	$kit  = 0;
	if ( class_exists( '\Elementor\Plugin' ) && isset( \Elementor\Plugin::$instance->kits_manager ) ) {
		$kit = (int) \Elementor\Plugin::$instance->kits_manager->get_active_id();
	}
	$out = array( 'dry_run' => $dry, 'elementor_kit' => $kit ?: null, 'kit_css' => null, 'caches' => comdeva_cache_backends(),
		'urls' => $urls );
	if ( $dry ) {
		return $out;
	}
	if ( $kit && class_exists( '\Elementor\Core\Files\CSS\Post' ) ) {
		$css = \Elementor\Core\Files\CSS\Post::create( $kit );
		$css->update();
		$out['kit_css'] = $css->get_url();
	}
	$out['cache_cleared'] = comdeva_clear_page_cache( $urls );
	return $out;
}

add_action( 'rest_api_init', function () {
	register_rest_route( 'comdeva/v1', '/cache', array(
		'methods'             => 'POST',
		'permission_callback' => function () {
			return current_user_can( 'manage_options' );
		},
		'callback'            => 'comdeva_cache_clear',
	) );
} );
