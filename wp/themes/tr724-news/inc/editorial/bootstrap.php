<?php
/**
 * Editorial admin section.
 *
 * Top-level menu for site-wide editorial configuration used by blocks.
 * Add a screen by appending to tr724_editorial_pages() (or the
 * tr724_editorial_pages filter). Give it a unique id and slug, a render
 * callback, and an enqueue callback when the screen needs scripts.
 * The first screen is where Editorial itself lands. Each screen keeps its own
 * slug so WordPress still shows the submenu when only one screen exists.
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/featured-topics.php';
require_once __DIR__ . '/featured-topics-page.php';
require_once __DIR__ . '/links.php';
require_once __DIR__ . '/links-page.php';
require_once __DIR__ . '/timeline.php';
require_once __DIR__ . '/timeline-page.php';

if ( ! function_exists( 'tr724_editorial_capability' ) ) {
	/**
	 * Editors and administrators manage editorial settings.
	 */
	function tr724_editorial_capability(): string {
		$cap = apply_filters( 'tr724_editorial_capability', 'edit_others_posts' );
		if ( ! is_string( $cap ) || '' === $cap ) {
			return 'edit_others_posts';
		}
		return $cap;
	}
}

if ( ! function_exists( 'tr724_editorial_pages' ) ) {
	/**
	 * Screens listed under Editorial, in menu order.
	 *
	 * @return array<int, array{id: string, slug: string, title: string, menu_title: string, callback: string, enqueue?: string}>
	 */
	function tr724_editorial_pages(): array {
		static $pages = null;
		if ( null !== $pages ) {
			return $pages;
		}

		$registered = apply_filters(
			'tr724_editorial_pages',
			[
				[
					'id'         => 'featured-topics',
					'slug'       => 'tr724-editorial-featured-topics',
					'label_key'  => 'featured_topics',
					'title'      => 'Featured Topics',
					'menu_title' => 'Featured Topics',
					'callback'   => 'tr724_editorial_render_featured_topics_page',
					'enqueue'    => 'tr724_editorial_enqueue_featured_topics_assets',
				],
				[
					'id'         => 'links',
					'slug'       => 'tr724-editorial-links',
					'label_key'  => 'links',
					'title'      => 'Links',
					'menu_title' => 'Links',
					'callback'   => 'tr724_editorial_render_links_page',
					'capability' => tr724_editorial_links_capability(),
				],
				[
					'id'         => 'timeline',
					'slug'       => 'tr724-editorial-timeline',
					'label_key'  => 'timeline',
					'title'      => 'Timeline',
					'menu_title' => 'Timeline',
					'callback'   => 'tr724_editorial_render_timeline_page',
					'enqueue'    => 'tr724_editorial_enqueue_timeline_assets',
				],
			]
		);

		$pages = [];
		if ( ! is_array( $registered ) ) {
			return $pages;
		}

		foreach ( $registered as $page ) {
			if ( ! is_array( $page ) ) {
				continue;
			}
			$id       = isset( $page['id'] ) ? (string) $page['id'] : '';
			$callback = isset( $page['callback'] ) ? (string) $page['callback'] : '';
			if ( '' === $id || '' === $callback || ! is_callable( $callback ) ) {
				continue;
			}
			$pages[] = $page;
		}

		return $pages;
	}
}

if ( ! function_exists( 'tr724_editorial_screen_slug' ) ) {
	/**
	 * Admin page query slug. Distinct from the Editorial parent slug.
	 */
	function tr724_editorial_screen_slug( int $index, array $page ): string {
		$slug = isset( $page['slug'] ) ? (string) $page['slug'] : '';
		return '' !== $slug ? $slug : 'tr724-editorial-' . $index;
	}
}

if ( ! function_exists( 'tr724_editorial_admin_url' ) ) {
	/**
	 * Admin URL for a screen id such as "featured-topics".
	 */
	function tr724_editorial_admin_url( string $id ): string {
		foreach ( tr724_editorial_pages() as $index => $page ) {
			if ( $id === (string) ( $page['id'] ?? '' ) ) {
				return add_query_arg(
					'page',
					tr724_editorial_screen_slug( $index, $page ),
					admin_url( 'admin.php' )
				);
			}
		}
		return admin_url();
	}
}

if ( ! function_exists( 'tr724_editorial_normalize_language' ) ) {
	/**
	 * Collapse a locale or plugin language code to "tr" or "en".
	 */
	function tr724_editorial_normalize_language( string $value ): string {
		$value = strtolower( str_replace( '_', '-', trim( $value ) ) );
		if ( str_starts_with( $value, 'en' ) ) {
			return 'en';
		}
		return 'tr';
	}
}

if ( ! function_exists( 'tr724_editorial_current_language' ) ) {
	/**
	 * Language used to pick editorial labels.
	 *
	 * Polylang and WPML win when they are active. Otherwise the site locale
	 * is used. tr724_editorial_language can force "tr" or "en".
	 */
	function tr724_editorial_current_language(): string {
		$detected = '';

		if ( function_exists( 'pll_current_language' ) ) {
			$pll = pll_current_language( 'slug' );
			if ( is_string( $pll ) && '' !== $pll ) {
				$detected = $pll;
			}
		}

		if ( '' === $detected ) {
			$wpml = apply_filters( 'wpml_current_language', null );
			if ( is_string( $wpml ) && '' !== $wpml && 'all' !== $wpml ) {
				$detected = $wpml;
			}
		}

		if ( '' === $detected ) {
			$detected = determine_locale();
		}

		$lang     = tr724_editorial_normalize_language( $detected );
		$filtered = apply_filters( 'tr724_editorial_language', $lang );
		if ( ! is_string( $filtered ) || '' === $filtered ) {
			return $lang;
		}
		return tr724_editorial_normalize_language( $filtered );
	}
}

if ( ! function_exists( 'tr724_editorial_ui_language' ) ) {
	/**
	 * Language for Editorial menu and screen copy.
	 * Follows the WordPress admin language, not the topic label language.
	 */
	function tr724_editorial_ui_language(): string {
		if ( is_admin() || ( function_exists( 'tr724_category_is_editor_preview' ) && tr724_category_is_editor_preview() ) ) {
			return tr724_editorial_normalize_language( get_user_locale() );
		}
		return tr724_editorial_current_language();
	}
}

if ( ! function_exists( 'tr724_editorial_ui' ) ) {
	/**
	 * Turkish or English copy for Editorial screens. Tag names are not translated here.
	 */
	function tr724_editorial_ui( string $key ): string {
		$strings = [
			'editorial'              => [
				'en' => 'Editorial',
				'tr' => 'Editöryal',
			],
			'featured_topics'        => [
				'en' => 'Featured Topics',
				'tr' => 'Öne Çıkan Konular',
			],
			'links'                  => [
				'en' => 'Links',
				'tr' => 'Bağlantılar',
			],
			'timeline'               => [
				'en' => 'Timeline',
				'tr' => 'Yayın Akışı',
			],
			'description'            => [
				'en' => 'Choose the tags shown in every Featured Topics block. Drag to set the order.',
				'tr' => 'Öne Çıkan Konular bloğunda gösterilecek etiketleri seçin. Sırayı sürükleyerek belirleyin.',
			],
			'manage_tags'            => [
				'en' => 'Manage tags',
				'tr' => 'Etiketleri yönet',
			],
			'drag'                   => [
				'en' => 'Drag to reorder',
				'tr' => 'Sırayı değiştirmek için sürükleyin',
			],
			'tag'                    => [
				'en' => 'Tag:',
				'tr' => 'Etiket:',
			],
			'move_up'                => [
				'en' => 'Move up',
				'tr' => 'Yukarı taşı',
			],
			'move_down'              => [
				'en' => 'Move down',
				'tr' => 'Aşağı taşı',
			],
			'remove'                 => [
				'en' => 'Remove',
				'tr' => 'Kaldır',
			],
			'no_permission'          => [
				'en' => 'You do not have permission to manage editorial settings.',
				'tr' => 'Yayın ayarlarını yönetme izniniz yok.',
			],
			'saved'                  => [
				'en' => 'Featured topics saved.',
				'tr' => 'Öne çıkan konular kaydedildi.',
			],
			'dropped'                => [
				'en' => 'Some tags were skipped because they no longer exist or the list is full.',
				'tr' => 'Bazı etiketler artık var olmadığı veya liste dolduğu için atlandı.',
			],
			'js_required'            => [
				'en' => 'Search and drag-and-drop need JavaScript.',
				'tr' => 'Arama ve sürükle-bırak için JavaScript gerekir.',
			],
			'add_tag'                => [
				'en' => 'Add a tag',
				'tr' => 'Etiket ekle',
			],
			'search_tags'            => [
				'en' => 'Search tags',
				'tr' => 'Etiket ara',
			],
			'empty'                  => [
				'en' => 'No topics selected yet. Search for a tag and add it.',
				'tr' => 'Henüz konu seçilmedi. Bir etiket arayıp ekleyin.',
			],
			'save'                   => [
				'en' => 'Save Changes',
				'tr' => 'Değişiklikleri kaydet',
			],
			'searching'              => [
				'en' => 'Searching…',
				'tr' => 'Aranıyor…',
			],
			'none'                   => [
				'en' => 'No matching tags.',
				'tr' => 'Eşleşen etiket yok.',
			],
			'search_error'           => [
				'en' => 'Tag search is unavailable.',
				'tr' => 'Etiket araması kullanılamıyor.',
			],
			'add'                    => [
				'en' => 'Add',
				'tr' => 'Ekle',
			],
			'added'                  => [
				'en' => 'Added',
				'tr' => 'Eklendi',
			],
			'limit'                  => [
				'en' => 'You can feature up to %d tags.',
				'tr' => 'En fazla %d etiket seçebilirsiniz.',
			],
			'block_help'             => [
				'en' => 'Topics come from Editorial → Featured Topics. This block cannot override them.',
				'tr' => 'Konular Editöryal → Öne Çıkan Konular bölümünden gelir. Bu blok onları değiştiremez.',
			],
			'edit_featured_topics'   => [
				'en' => 'Edit featured topics',
				'tr' => 'Öne çıkan konuları düzenle',
			],
			'block_empty'            => [
				'en' => 'No featured topics yet. Choose tags in Editorial → Featured Topics.',
				'tr' => 'Henüz öne çıkan konu yok. Etiketleri Editöryal → Öne Çıkan Konular bölümünden seçin.',
			],
			'timeline_description'   => [
				'en' => 'Edit the YouTube programs in the Timeline block. Saving replaces this schedule. Time and name are listed side by side.',
				'tr' => 'Yayın Akışı bloğundaki YouTube programlarını düzenleyin. Kaydetmek bu akışın yerine geçer. Saat ve ad yan yana listelenir.',
			],
			'timeline_order'         => [
				'en' => 'Programs are shown from earliest to latest.',
				'tr' => 'Programlar en erken saatten başlayarak gösterilir.',
			],
			'timeline_hide'          => [
				'en' => 'Hide',
				'tr' => 'Gizle',
			],
			'timeline_hide_help'     => [
				'en' => 'Check Hide to keep a program in this list without showing it on the Timeline.',
				'tr' => 'Bir programı bu listede tutup Yayın Akışında göstermemek için Gizle kutusunu işaretleyin.',
			],
			'timeline_time'          => [
				'en' => 'Time',
				'tr' => 'Saat',
			],
			'timeline_name'          => [
				'en' => 'Program name',
				'tr' => 'Program adı',
			],
			'timeline_url'           => [
				'en' => 'YouTube URL',
				'tr' => 'YouTube adresi',
			],
			'timeline_url_placeholder' => [
				'en' => 'https://www.youtube.com/watch?v=',
				'tr' => 'https://www.youtube.com/watch?v=',
			],
			'timeline_add'           => [
				'en' => 'Add program',
				'tr' => 'Program ekle',
			],
			'timeline_empty'         => [
				'en' => 'No programs yet.',
				'tr' => 'Henüz program yok.',
			],
			'timeline_saved'         => [
				'en' => 'Timeline saved.',
				'tr' => 'Yayın akışı kaydedildi.',
			],
			'timeline_dropped'       => [
				'en' => 'Some programs were skipped because the time or name was missing or the list is full. Links that were not YouTube videos were left off.',
				'tr' => 'Saat veya ad eksik olduğu ya da liste dolduğu için bazı programlar atlandı. YouTube videosu olmayan bağlantılar kaldırıldı.',
			],
			'timeline_limit'         => [
				'en' => 'You can add up to %d programs.',
				'tr' => 'En fazla %d program ekleyebilirsiniz.',
			],
			'timeline_block_help'    => [
				'en' => 'Programs come from Editorial → Timeline. This block cannot override them.',
				'tr' => 'Programlar Editöryal → Yayın Akışı bölümünden gelir. Bu blok onları değiştiremez.',
			],
			'timeline_edit'          => [
				'en' => 'Edit the schedule',
				'tr' => 'Akışı düzenle',
			],
			'timeline_block_empty'   => [
				'en' => 'No programs yet. Add them in Editorial → Timeline.',
				'tr' => 'Henüz program yok. Programları Editöryal → Yayın Akışı bölümünden ekleyin.',
			],
			'links_description'      => [
				'en' => 'These addresses are used in the header, the footer, and on article pages.',
				'tr' => 'Bu adresler üst menüde, alt bilgide ve haber sayfalarında kullanılır.',
			],
			'links_social'           => [
				'en' => 'Social',
				'tr' => 'Sosyal ağlar',
			],
			'links_facebook'         => [
				'en' => 'Facebook',
				'tr' => 'Facebook',
			],
			'links_x'                => [
				'en' => 'X',
				'tr' => 'X',
			],
			'links_instagram'        => [
				'en' => 'Instagram',
				'tr' => 'Instagram',
			],
			'links_youtube'          => [
				'en' => 'YouTube',
				'tr' => 'YouTube',
			],
			'links_patreon'          => [
				'en' => 'Patreon',
				'tr' => 'Patreon',
			],
			'links_patreon_url'      => [
				'en' => 'Patreon URL',
				'tr' => 'Patreon adresi',
			],
			'links_whatsapp'         => [
				'en' => 'WhatsApp',
				'tr' => 'WhatsApp',
			],
			'links_whatsapp_phone'   => [
				'en' => 'Phone number',
				'tr' => 'Telefon numarası',
			],
			'links_whatsapp_url'     => [
				'en' => 'WhatsApp URL',
				'tr' => 'WhatsApp adresi',
			],
			'links_whatsapp_url_help' => [
				'en' => 'Leave the address empty to build it from the digits in the phone number.',
				'tr' => 'Adresi boş bırakırsanız telefon numarasındaki rakamlardan oluşturulur.',
			],
			'links_whatsapp_channel' => [
				'en' => 'WhatsApp channel',
				'tr' => 'WhatsApp kanalı',
			],
			'links_apps'             => [
				'en' => 'Apps',
				'tr' => 'Uygulamalar',
			],
			'links_ios'              => [
				'en' => 'App Store',
				'tr' => 'App Store',
			],
			'links_android'          => [
				'en' => 'Google Play',
				'tr' => 'Google Play',
			],
			'links_saved'            => [
				'en' => 'Links saved.',
				'tr' => 'Bağlantılar kaydedildi.',
			],
		];

		$strings = apply_filters( 'tr724_editorial_ui_strings', $strings );
		$row     = is_array( $strings ) && isset( $strings[ $key ] ) && is_array( $strings[ $key ] ) ? $strings[ $key ] : [];
		$lang    = tr724_editorial_ui_language();
		if ( isset( $row[ $lang ] ) && is_string( $row[ $lang ] ) && '' !== $row[ $lang ] ) {
			return $row[ $lang ];
		}
		if ( isset( $row['en'] ) && is_string( $row['en'] ) ) {
			return $row['en'];
		}
		return $key;
	}
}

if ( ! function_exists( 'tr724_editorial_screen_title' ) ) {
	/**
	 * Localized menu label for an Editorial screen.
	 */
	function tr724_editorial_screen_title( array $page ): string {
		$key = isset( $page['label_key'] ) ? (string) $page['label_key'] : '';
		if ( '' !== $key ) {
			return tr724_editorial_ui( $key );
		}
		$title = isset( $page['menu_title'] ) ? (string) $page['menu_title'] : '';
		if ( '' === $title && isset( $page['title'] ) ) {
			$title = (string) $page['title'];
		}
		return $title;
	}
}

add_action( 'admin_menu', static function (): void {
	$pages = tr724_editorial_pages();
	if ( ! $pages ) {
		return;
	}

	$cap    = tr724_editorial_capability();
	$parent = 'tr724-editorial';

	add_menu_page(
		tr724_editorial_ui( 'editorial' ),
		tr724_editorial_ui( 'editorial' ),
		$cap,
		$parent,
		'tr724_editorial_render_parent_page',
		'dashicons-edit',
		26
	);

	foreach ( $pages as $index => $page ) {
		$item_cap = isset( $page['capability'] ) ? (string) $page['capability'] : $cap;
		$label    = tr724_editorial_screen_title( $page );
		add_submenu_page(
			$parent,
			$label,
			$label,
			'' !== $item_cap ? $item_cap : $cap,
			tr724_editorial_screen_slug( $index, $page ),
			$page['callback']
		);
	}

	// The first real screen makes WordPress insert a copy of the parent.
	// Drop that copy. The screen slug stays different from the parent, so
	// the submenu remains even while Featured Topics is the only screen.
	remove_submenu_page( $parent, $parent );
} );

if ( ! function_exists( 'tr724_editorial_render_parent_page' ) ) {
	/**
	 * Editorial itself opens the first screen. The load-* hook redirects
	 * before output; this renders that screen if the redirect did not run.
	 */
	function tr724_editorial_render_parent_page(): void {
		$pages = tr724_editorial_pages();
		if ( $pages && ! empty( $pages[0]['callback'] ) && is_callable( $pages[0]['callback'] ) ) {
			call_user_func( $pages[0]['callback'] );
			return;
		}
		echo '<div class="wrap"><h1>' . esc_html( tr724_editorial_ui( 'editorial' ) ) . '</h1></div>';
	}
}

add_action( 'load-toplevel_page_tr724-editorial', static function (): void {
	$pages = tr724_editorial_pages();
	if ( ! $pages || empty( $pages[0]['id'] ) || ! current_user_can( tr724_editorial_capability() ) ) {
		return;
	}
	wp_safe_redirect( tr724_editorial_admin_url( (string) $pages[0]['id'] ) );
	exit;
} );

add_action( 'admin_enqueue_scripts', static function (): void {
	$current = isset( $_GET['page'] ) ? (string) wp_unslash( $_GET['page'] ) : '';
	if ( '' === $current ) {
		return;
	}

	foreach ( tr724_editorial_pages() as $index => $page ) {
		if ( $current !== tr724_editorial_screen_slug( $index, $page ) ) {
			continue;
		}
		$enqueue = isset( $page['enqueue'] ) ? (string) $page['enqueue'] : '';
		if ( '' !== $enqueue && is_callable( $enqueue ) ) {
			call_user_func( $enqueue );
		}
	}
} );

add_action( 'enqueue_block_editor_assets', static function (): void {
	if ( ! wp_script_is( 'tr724-featured-topics-editor-script', 'registered' ) ) {
		return;
	}
	wp_add_inline_script(
		'tr724-featured-topics-editor-script',
		'window.tr724FeaturedTopicsAdmin = ' . wp_json_encode( tr724_editorial_admin_url( 'featured-topics' ) ) . ';'
		. 'window.tr724FeaturedTopicsUi = ' . wp_json_encode(
			[
				'title' => tr724_editorial_ui( 'featured_topics' ),
				'help'  => tr724_editorial_ui( 'block_help' ),
				'edit'  => tr724_editorial_ui( 'edit_featured_topics' ),
			]
		) . ';',
		'before'
	);
} );
