<?php
/**
 * Generic fallback template (posts, pages, archives).
 *
 * @package vitalis
 */

get_header(); ?>

<main class="site-main">
	<div class="vitalis-wrap">
		<?php if ( have_posts() ) : ?>
			<?php if ( ! is_front_page() && ( is_home() || is_archive() ) ) : ?>
				<h1 class="page-title"><?php echo esc_html( wp_get_document_title() ); ?></h1>
			<?php endif; ?>

			<?php while ( have_posts() ) : the_post(); ?>
				<article <?php post_class(); ?>>
					<?php if ( ! is_singular() ) : ?>
						<h2 class="page-title"><a href="<?php the_permalink(); ?>"><?php the_title(); ?></a></h2>
					<?php elseif ( ! is_front_page() ) : ?>
						<h1 class="page-title"><?php the_title(); ?></h1>
					<?php endif; ?>
					<div class="entry-content"><?php the_content(); ?></div>
				</article>
			<?php endwhile; ?>

			<?php the_posts_pagination(); ?>
		<?php else : ?>
			<h1 class="page-title">Nothing here yet</h1>
			<p style="color:var(--muted);">No content found.</p>
		<?php endif; ?>
	</div>
</main>

<?php get_footer(); ?>
