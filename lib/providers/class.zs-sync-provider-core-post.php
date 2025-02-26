<?php

class ZS_Sync_Provider_Core_Post {
	public static function provide( ZS_Sync_URI $uri, Callable $next ) {
		if ( 'core.post.post_content' !== $uri->resource_type ) {
			return $next;
		}

		if ( ! isset( $uri->id_type, $uri->id ) ) {
			return $next;
		}

		if ( 'id' === $uri->id_type ) {
			$post = get_post( $uri->id );
			return isset( $post ) ? $post->post_content : $next;
		}

		if ( 'guid' === $uri->id_type ) {
			global $wpdb;
			$post = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT post_content FROM {$wpdb->prefix}posts WHERE guid = %s LIMIT 1",
					$uri->id
				)
			);

			return isset( $post ) ? $post : $next;
		}

		return $next;
	}
}
