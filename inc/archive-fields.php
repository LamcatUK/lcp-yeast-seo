<?php
/**
 * Admin screens for archive SEO: fields on terms and authors, and the Archives
 * card on the General tab. Only archives the theme uses are offered.
 *
 * @package lcp-yeast-seo
 */

defined( 'ABSPATH' ) || exit;

/**
 * The SEO fields a term or author can carry: input name (also the meta key) => sanitiser.
 *
 * @return string[]
 */
function lcp_yeast_seo_archive_field_sanitisers() {
	return array(
		LCP_YEAST_SEO_TITLE_META_KEY       => 'sanitize_text_field',
		LCP_YEAST_SEO_DESCRIPTION_META_KEY => 'sanitize_textarea_field',
		LCP_YEAST_SEO_ROBOTS_META_KEY      => 'lcp_yeast_seo_sanitize_archive_robots',
		LCP_YEAST_SEO_CANONICAL_META_KEY   => 'esc_url_raw',
		LCP_YEAST_SEO_OG_IMAGE_META_KEY    => 'esc_url_raw',
	);
}

/**
 * Prints one field of the term or author form.
 *
 * @param string $key    Field name.
 * @param string $value  Current value.
 * @param string $layout row (a table row, for edit screens) or block (for the add-term form).
 * @param string $help   What leaving it empty does.
 * @return void
 */
function lcp_yeast_seo_render_archive_field( $key, $value, $layout, $help ) {
	$labels = array(
		LCP_YEAST_SEO_TITLE_META_KEY       => 'SEO title',
		LCP_YEAST_SEO_DESCRIPTION_META_KEY => 'Meta description',
		LCP_YEAST_SEO_ROBOTS_META_KEY      => 'Search engines',
		LCP_YEAST_SEO_CANONICAL_META_KEY   => 'Canonical URL',
		LCP_YEAST_SEO_OG_IMAGE_META_KEY    => 'Social image',
	);

	ob_start();

	if ( LCP_YEAST_SEO_DESCRIPTION_META_KEY === $key ) {
		printf( '<textarea name="%1$s" id="%1$s" rows="3" class="large-text">%2$s</textarea>', esc_attr( $key ), esc_textarea( $value ) );
	} elseif ( LCP_YEAST_SEO_ROBOTS_META_KEY === $key ) {
		printf( '<select name="%1$s" id="%1$s">', esc_attr( $key ) );

		foreach ( array(
			''        => 'Use the archive default',
			'index'   => 'Index',
			'noindex' => 'Noindex',
		) as $option => $text ) {
			printf( '<option value="%s"%s>%s</option>', esc_attr( $option ), selected( $value, $option, false ), esc_html( $text ) );
		}

		echo '</select>';
	} elseif ( LCP_YEAST_SEO_OG_IMAGE_META_KEY === $key ) {
		lcp_yeast_seo_render_image_picker( $key, $value, 'Social image', 'Use as social image' );
	} else {
		printf( '<input type="%1$s" name="%2$s" id="%2$s" value="%3$s" class="large-text">', LCP_YEAST_SEO_CANONICAL_META_KEY === $key ? 'url' : 'text', esc_attr( $key ), esc_attr( $value ) );
	}

	$control = ob_get_clean();

	if ( 'row' === $layout ) {
		?>
		<tr class="form-field">
			<th scope="row"><label for="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $labels[ $key ] ); ?></label></th>
			<td><?php echo $control; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built above with escaped values. ?><p class="description"><?php echo esc_html( $help ); ?></p></td>
		</tr>
		<?php
		return;
	}

	?>
	<div class="form-field">
		<label for="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $labels[ $key ] ); ?></label>
		<?php echo $control; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built above with escaped values. ?>
		<p><?php echo esc_html( $help ); ?></p>
	</div>
	<?php
}

/**
 * What each field does when it is left empty.
 *
 * @param string $what term or author.
 * @return string[] Field name => help text.
 */
function lcp_yeast_seo_archive_field_help( $what ) {
	return array(
		LCP_YEAST_SEO_TITLE_META_KEY       => 'Leave empty to use the archive title template, or WordPress\'s own title.',
		LCP_YEAST_SEO_DESCRIPTION_META_KEY => 'author' === $what ? 'Leave empty to use the author\'s biography.' : 'Leave empty to use this term\'s own description.',
		LCP_YEAST_SEO_ROBOTS_META_KEY      => 'The default comes from the Archives settings on the General tab.',
		LCP_YEAST_SEO_CANONICAL_META_KEY   => 'Leave empty for the page\'s own address.',
		LCP_YEAST_SEO_OG_IMAGE_META_KEY    => 'Leave empty to use the site\'s default social image.',
	);
}

/**
 * Prints the SEO fields on a term's edit screen, if its taxonomy is in use.
 *
 * @param WP_Term $term     Term.
 * @param string  $taxonomy Taxonomy.
 * @return void
 */
function lcp_yeast_seo_render_term_edit_fields( $term, $taxonomy ) {
	if ( ! lcp_yeast_seo_archive_is_in_use( 'tax:' . $taxonomy ) ) {
		return;
	}

	wp_nonce_field( 'lcp_yeast_seo_term', 'lcp_yeast_seo_term_nonce' );
	?>
	<tr class="form-field"><th scope="row"><h2 style="margin:0;">Yeast SEO</h2></th><td></td></tr>
	<?php
	foreach ( lcp_yeast_seo_archive_field_help( 'term' ) as $key => $help ) {
		lcp_yeast_seo_render_archive_field( $key, (string) get_term_meta( $term->term_id, $key, true ), 'row', $help );
	}
}

/**
 * Prints the SEO fields on the add-term form, if the taxonomy is in use.
 *
 * @param string $taxonomy Taxonomy.
 * @return void
 */
function lcp_yeast_seo_render_term_add_fields( $taxonomy ) {
	if ( ! lcp_yeast_seo_archive_is_in_use( 'tax:' . $taxonomy ) ) {
		return;
	}

	wp_nonce_field( 'lcp_yeast_seo_term', 'lcp_yeast_seo_term_nonce' );
	echo '<h2>Yeast SEO</h2>';

	foreach ( lcp_yeast_seo_archive_field_help( 'term' ) as $key => $help ) {
		lcp_yeast_seo_render_archive_field( $key, '', 'block', $help );
	}
}

/**
 * Saves the SEO fields of a term.
 *
 * @param int $term_id Term ID.
 * @return void
 */
function lcp_yeast_seo_save_term_fields( $term_id ) {
	if ( ! isset( $_POST['lcp_yeast_seo_term_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['lcp_yeast_seo_term_nonce'] ) ), 'lcp_yeast_seo_term' ) || ! current_user_can( 'edit_term', $term_id ) ) {
		return;
	}

	foreach ( lcp_yeast_seo_archive_field_sanitisers() as $key => $sanitise ) {
		if ( ! isset( $_POST[ $key ] ) ) {
			continue;
		}

		$value = call_user_func( $sanitise, wp_unslash( $_POST[ $key ] ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitised by the callback.

		if ( '' === $value ) {
			delete_term_meta( $term_id, $key );
		} else {
			update_term_meta( $term_id, $key, $value );
		}
	}
}

/**
 * Prints the SEO fields on a user's profile, if author archives are in use and
 * the user can write posts.
 *
 * @param WP_User $user User.
 * @return void
 */
function lcp_yeast_seo_render_author_fields( $user ) {
	if ( ! user_can( $user, 'edit_posts' ) || ! lcp_yeast_seo_archive_is_in_use( 'author' ) ) {
		return;
	}

	wp_nonce_field( 'lcp_yeast_seo_author', 'lcp_yeast_seo_author_nonce' );
	?>
	<h2>Yeast SEO: author page</h2>
	<table class="form-table" role="presentation">
		<?php
		foreach ( lcp_yeast_seo_archive_field_help( 'author' ) as $key => $help ) {
			lcp_yeast_seo_render_archive_field( $key, (string) get_user_meta( $user->ID, $key, true ), 'row', $help );
		}
		?>
	</table>
	<?php
}

/**
 * Saves the SEO fields of a user.
 *
 * @param int $user_id User ID.
 * @return void
 */
function lcp_yeast_seo_save_author_fields( $user_id ) {
	if ( ! isset( $_POST['lcp_yeast_seo_author_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['lcp_yeast_seo_author_nonce'] ) ), 'lcp_yeast_seo_author' ) || ! current_user_can( 'edit_user', $user_id ) ) {
		return;
	}

	foreach ( lcp_yeast_seo_archive_field_sanitisers() as $key => $sanitise ) {
		if ( ! isset( $_POST[ $key ] ) ) {
			continue;
		}

		$value = call_user_func( $sanitise, wp_unslash( $_POST[ $key ] ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitised by the callback.

		if ( '' === $value ) {
			delete_user_meta( $user_id, $key );
		} else {
			update_user_meta( $user_id, $key, $value );
		}
	}
}

/**
 * Registers the term and author hooks.
 *
 * The taxonomies are hooked whether or not the theme uses them; each screen
 * checks that itself, so a theme change takes effect straight away.
 *
 * @return void
 */
function lcp_yeast_seo_register_archive_field_hooks() {
	foreach ( get_taxonomies( array( 'public' => true, 'publicly_queryable' => true ) ) as $taxonomy ) {
		if ( 'post_format' === $taxonomy ) {
			continue;
		}

		add_action( $taxonomy . '_edit_form_fields', 'lcp_yeast_seo_render_term_edit_fields', 10, 2 );
		add_action( $taxonomy . '_add_form_fields', 'lcp_yeast_seo_render_term_add_fields' );
		add_action( 'created_' . $taxonomy, 'lcp_yeast_seo_save_term_fields' );
		add_action( 'edited_' . $taxonomy, 'lcp_yeast_seo_save_term_fields' );
	}

	add_action( 'show_user_profile', 'lcp_yeast_seo_render_author_fields' );
	add_action( 'edit_user_profile', 'lcp_yeast_seo_render_author_fields' );
	add_action( 'personal_options_update', 'lcp_yeast_seo_save_author_fields' );
	add_action( 'edit_user_profile_update', 'lcp_yeast_seo_save_author_fields' );
}
add_action( 'admin_init', 'lcp_yeast_seo_register_archive_field_hooks' );

/**
 * Loads the media picker on the screens that have a social image field.
 *
 * @param string $hook Admin page hook.
 * @return void
 */
function lcp_yeast_seo_archive_field_assets( $hook ) {
	if ( in_array( $hook, array( 'edit-tags.php', 'term.php', 'profile.php', 'user-edit.php' ), true ) ) {
		lcp_yeast_seo_enqueue_image_picker();
	}
}
add_action( 'admin_enqueue_scripts', 'lcp_yeast_seo_archive_field_assets' );

/**
 * Intro text for the Archives card.
 *
 * @return void
 */
function lcp_yeast_seo_render_archives_intro() {
	echo '<p>Category, author, date and other archive pages. Only the ones this theme links to or has a template for are listed, so you aren\'t shown fields for pages that don\'t exist on your site.</p>';
}

/**
 * Title-template tags and a sample value for each kind of archive.
 *
 * @param array $kind From lcp_yeast_seo_archive_kinds().
 * @return string[] Tag name and sample value.
 */
function lcp_yeast_seo_archive_tag( array $kind ) {
	$map = array(
		'taxonomy'  => array( 'term', 'Example ' . strtolower( $kind['singular'] ?? $kind['label'] ) ),
		'author'    => array( 'author', 'Sam Example' ),
		'date'      => array( 'date', 'March 2026' ),
		'search'    => array( 'search', 'example search' ),
		'post_type' => array( 'archive', $kind['label'] ),
	);

	return $map[ $kind['type'] ] ?? array( 'term', 'Example' );
}

/**
 * One archive's controls in the Archives card.
 *
 * @param array $kind From lcp_yeast_seo_archive_kinds().
 * @return void
 */
function lcp_yeast_seo_render_archive_kind( array $kind ) {
	$name        = LCP_YEAST_SEO_SETTINGS_OPTION . '[archives][' . $kind['id'] . ']';
	list( $tag, $sample ) = lcp_yeast_seo_archive_tag( $kind );
	?>
	<div class="lcp-ys-arch">
		<div class="lcp-ys-arch__head">
			<strong><?php echo esc_html( $kind['label'] ); ?></strong>
			<?php if ( $kind['count'] ) : ?>
				<span class="lcp-ys-arch__meta"><?php echo esc_html( 'taxonomy' === $kind['type'] ? lcp_yeast_seo_count_noun( $kind['count'], 'term' ) : lcp_yeast_seo_count_noun( $kind['count'], 'author' ) ); ?></span>
			<?php endif; ?>
			<?php if ( '' !== $kind['why'] ) : ?>
				<span class="lcp-ys-arch__meta"><?php echo esc_html( $kind['why'] ); ?></span>
			<?php endif; ?>
		</div>
		<div class="lcp-ys-tpl__row">
			<label for="lcp-ys-arch-<?php echo esc_attr( $kind['id'] ); ?>">Title template</label>
			<input type="text" id="lcp-ys-arch-<?php echo esc_attr( $kind['id'] ); ?>" name="<?php echo esc_attr( $name ); ?>[title]" value="<?php echo esc_attr( lcp_yeast_seo_archive_setting( $kind['id'], 'title' ) ); ?>" class="large-text" placeholder="<?php echo esc_attr( 'e.g. %' . $tag . '% %sep% %sitename%' ); ?>" data-arch-input data-arch-tag="<?php echo esc_attr( $tag ); ?>" data-arch-sample="<?php echo esc_attr( $sample ); ?>">
			<p class="lcp-ys-tpl__preview" data-arch-preview aria-live="polite"></p>
		</div>
		<?php if ( 'post_type' === $kind['type'] ) : ?>
			<div class="lcp-ys-tpl__row">
				<label for="lcp-ys-arch-desc-<?php echo esc_attr( $kind['id'] ); ?>">Meta description</label>
				<textarea id="lcp-ys-arch-desc-<?php echo esc_attr( $kind['id'] ); ?>" name="<?php echo esc_attr( $name ); ?>[description]" rows="2" class="large-text"><?php echo esc_textarea( lcp_yeast_seo_archive_setting( $kind['id'], 'description' ) ); ?></textarea>
			</div>
		<?php endif; ?>
		<label class="lcp-ys-arch__noindex">
			<input type="hidden" name="<?php echo esc_attr( $name ); ?>[noindex]" value="0">
			<input type="checkbox" name="<?php echo esc_attr( $name ); ?>[noindex]" value="1" <?php checked( '1', lcp_yeast_seo_archive_setting( $kind['id'], 'noindex', '0' ) ); ?> <?php echo $kind['recommend_noindex'] ? 'data-recommended' : ''; ?>>
			Ask search engines not to index this archive
			<?php if ( $kind['recommend_noindex'] ) : ?>
				<span class="lcp-ys-reco" title="<?php echo esc_attr( $kind['recommend_why'] ); ?>">Recommended. <?php echo esc_html( $kind['recommend_why'] ); ?></span>
			<?php endif; ?>
		</label>
	</div>
	<?php
}

/**
 * The Archives card's list: archives in use, with the rest tucked away.
 *
 * @return void
 */
function lcp_yeast_seo_render_archives_field() {
	$kinds  = lcp_yeast_seo_archive_kinds();
	$used   = array_filter( $kinds, static function ( $kind ) { return $kind['in_use']; } );
	$unused = array_filter( $kinds, static function ( $kind ) { return ! $kind['in_use']; } );

	if ( ! $used ) {
		echo '<p class="description">This theme doesn\'t link to any archives or have templates for them, so there is nothing to set up here. The setting below still covers them.</p>';
	}

	foreach ( $used as $kind ) {
		lcp_yeast_seo_render_archive_kind( $kind );
	}

	if ( $unused ) :
		?>
		<details class="lcp-ys-unused">
			<summary><?php echo esc_html( lcp_yeast_seo_count_noun( count( $unused ), 'archive type' ) . ' the theme does not use' ); ?></summary>
			<p class="description">These pages still exist at their addresses. Set them up here only if you know they are linked from somewhere the scan can't see.</p>
			<?php
			foreach ( $unused as $kind ) {
				lcp_yeast_seo_render_archive_kind( $kind );
			}
			?>
		</details>
		<?php
	endif;
}

/**
 * The "noindex archives the theme doesn't use" checkbox, and the theme rescan.
 *
 * @return void
 */
function lcp_yeast_seo_render_unused_archives_field() {
	$signals = lcp_yeast_seo_theme_signals();
	?>
	<label>
		<input type="hidden" name="<?php echo esc_attr( LCP_YEAST_SEO_SETTINGS_OPTION ); ?>[noindex_unused_archives]" value="0">
		<input type="checkbox" name="<?php echo esc_attr( LCP_YEAST_SEO_SETTINGS_OPTION ); ?>[noindex_unused_archives]" value="1" <?php checked( lcp_yeast_seo_flag_setting( 'noindex_unused_archives', false ) ); ?>>
		Ask search engines not to index archives this theme doesn't use
	</label>
	<p class="description">Recommended. They still load at their addresses, but nothing links to them, so they only add thin pages to the index.</p>
	<p class="description">
		Worked out from <?php echo esc_html( lcp_yeast_seo_count_noun( (int) $signals['files'], 'theme file' ) ); ?>, <?php echo esc_html( human_time_diff( (int) $signals['time'] ) ); ?> ago.
		<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=lcp_yeast_seo_rescan_theme' ), 'lcp_yeast_seo_rescan_theme' ) ); ?>">Scan the theme again</a>
	</p>
	<?php
}

/**
 * Forgets what was learned about the theme, and returns to the General tab.
 *
 * @return void
 */
function lcp_yeast_seo_handle_rescan_theme() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You do not have permission to perform this action.', 'lcp-yeast-seo' ) );
	}

	check_admin_referer( 'lcp_yeast_seo_rescan_theme' );
	lcp_yeast_seo_theme_signals( true );

	wp_safe_redirect( add_query_arg( array( 'page' => 'lcp-yeast-seo', 'tab' => 'general' ), admin_url( 'admin.php' ) ) );
	exit;
}
add_action( 'admin_post_lcp_yeast_seo_rescan_theme', 'lcp_yeast_seo_handle_rescan_theme' );
