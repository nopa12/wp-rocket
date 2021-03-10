<?php
declare(strict_types=1);

namespace WP_Rocket\Engine\Optimization\RUCSS\Warmup;

use WP_Rocket\Engine\Optimization\AbstractOptimization;
use WP_Rocket\Engine\Optimization\AssetsLocalCache;
use WP_Rocket\Logger\Logger;

class ResourceFetcher extends AbstractOptimization {

	/**
	 * Resources array.
	 *
	 * @var array
	 */
	private $resources = [];

	/**
	 * Assets local cache instance
	 *
	 * @var AssetsLocalCache
	 */
	private $local_cache;

	/**
	 * Resource fetcher process instance.
	 *
	 * @var ResourceFetcherProcess
	 */
	private $process;

	/**
	 * Resource constructor.
	 *
	 * @param AssetsLocalCache       $local_cache Local cache instance.
	 * @param ResourceFetcherProcess $process     Resource fetcher process instance.
	 */
	public function __construct( AssetsLocalCache $local_cache, ResourceFetcherProcess $process ) {
		$this->local_cache = $local_cache;
		$this->process     = $process;
	}

	/**
	 * Handle Collecting resources and save them into the DB
	 *
	 * @param string $html Page HTML.
	 */
	public function handle( $html ) {
		// Grab resources from page HTML.
		$this->find_styles( $html );
		$this->find_scripts( $html );

		// Send resources to the background process to be saved into DB.
		$this->process->push_to_queue( $this->resources );

		$this->process->save()->dispatch();
	}

	/**
	 * Find link styles on the page HTML.
	 *
	 * @param string $html Page HTML.
	 */
	private function find_styles( $html ) {
		$links = $this->find( '<link(?:[^>]+[\s"\'])?href\s*=\s*[\'"]\s*(?<url>[^\'"\s]+)\s*?[\'"](?:[^>]+)?\/?>', $html );

		if ( empty( $links ) ) {
			return;
		}

		foreach ( $links as $link ) {
			if ( ! (bool) preg_match( '/rel=[\'"]stylesheet[\'"]/is', $link[0] ) ) {
				continue;
			}

			$contents = $this->get_url_contents( $link['url'] );

			if ( empty( $contents ) ) {
				continue;
			}

			$this->resources[] = [
				'url'     => rocket_add_url_protocol( $link['url'] ),
				'content' => $contents,
				'type'    => 'css',
			];
		}
	}

	/**
	 * Find scripts with src on the page HTML.
	 *
	 * @param string $html Page HTML.
	 */
	private function find_scripts( $html ) {
		$scripts = $this->find( '<script\s+(?:[^>]+[\s\'"])?src\s*=\s*[\'"]\s*?(?<url>[^\'"\s]+)\s*?[\'"](?:[^>]+)?\/?>', $html );

		if ( empty( $scripts ) ) {
			return;
		}

		foreach ( $scripts as $script ) {
			$contents = $this->get_url_contents( $script['url'] );

			if ( empty( $contents ) ) {
				continue;
			}

			$this->resources[] = [
				'url'     => rocket_add_url_protocol( $script['url'] ),
				'content' => $contents,
				'type'    => 'js',
			];
		}
	}

	/**
	 * Get url file contents.
	 *
	 * @param string $url File url.
	 *
	 * @return false|string
	 */
	private function get_url_contents( $url ) {
		$external_url = $this->is_external_file( $url );
		$file_path    = $external_url ? $this->local_cache->get_filepath( $url ) : $this->get_file_path( $url );

		if ( empty( $file_path ) ) {
			Logger::error(
				'Couldn’t get the file path from the URL.',
				[
					'RUCSS warmup process',
					'url' => $url,
				]
			);

			return false;
		}

		$file_content = $external_url ? $this->local_cache->get_content( $url ) : $this->get_file_content( $file_path );

		if ( ! $file_content ) {
			Logger::error(
				'No file content.',
				[
					'RUCSS warmup process',
					'path' => $file_path,
				]
			);

			return false;
		}

		return $file_content;
	}

	/**
	 * Gets the CDN zones.
	 *
	 * @since  3.1
	 *
	 * @return array
	 */
	public function get_zones() {
		return [ 'all', 'css_and_js' ];
	}

}
