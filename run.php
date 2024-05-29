<?php

require 'vendor/autoload.php';

use EliasHaeussler\CacheWarmup;
use GuzzleHttp\TransferStats;

const ANSI_RESET = "\033[0m";
const ANSI_GREEN = "\033[32m";
const ANSI_RED = "\033[31m";
const ANSI_GREY = "\033[90m";
const ANSI_ERASE_LINE = "\033[2K";

$total = 0;
$cf_hit = 0;
$bc_hit = 0;
$uncached = 0;

ini_set( 'memory_limit', '512M' );

$sitemap_url = $argv[1] ?? null;
if ( ! $sitemap_url ) {
	echo "Usage: php run.php <sitemap_url>" . PHP_EOL;
	exit( 1 );
}

do {
	$cacheWarmer = new CacheWarmup\CacheWarmer(
		crawler: new CacheWarmup\Crawler\ConcurrentCrawler( [
			'concurrency' => 50,
			'request_method' => 'GET',
			'request_headers' => [
				'Accept-Encoding' => 'gzip',
			],
			'client_config' => [
				'decode_content' => false,
				'on_stats' => function ( TransferStats $stats ) {
					global $total, $cf_hit, $bc_hit, $uncached;
					// Reset status bar.
					echo "\r" . ANSI_ERASE_LINE . "\r";

					$code = null;
					$cache = '???';
					$batcache = '???';
					if ( $stats->hasResponse() ) {
						$resp = $stats->getResponse();
						$code = $resp->getStatusCode();
						$cache_status = substr( $resp->getHeaderLine( 'X-Cache' ), 0, 3 );
						$cache = ( $cache_status === 'Hit' ? ANSI_GREEN : ANSI_RED ) . $cache_status . ANSI_RESET;
						$batcache_status = substr( $resp->getHeaderLine( 'X-Batcache' ), 0, 3 );
						$batcache = ( $batcache_status === 'HIT' ? ANSI_GREEN : ANSI_RED ) . $batcache_status . ANSI_RESET;
						if ( $cache_status === 'Hit' ) {
							$cf_hit++;
						} elseif ( $batcache_status === 'HIT' ) {
							$bc_hit++;
						} else {
							$uncached++;
						}
					}
					$success = $code < 400;
					printf(
						'%s[%s]%s [%.3fs]%s %-150s [CF %s] [BC %s] (%.1fMB)' . PHP_EOL,
						$success ? ANSI_GREEN : ANSI_RED,
						$code ?? '???',
						ANSI_GREY,
						$stats->getTransferTime(),
						ANSI_RESET,
						$stats->getEffectiveUri(),
						$cache,
						$batcache,
						memory_get_usage() / 1024 / 1024
					);

					global $total;
					$total++;
					// Print status bar.
					echo ANSI_ERASE_LINE . "Processed $total URLs; $cf_hit CF hits, $bc_hit BC hits, $uncached uncached.";
				}
			],
		] ),
		excludePatterns: [
			// Internal URLs - these shouldn't appear in the sitemap, but who knows.
			CacheWarmup\Config\Option\ExcludePattern::create( '*wp-*' ),
		],
	);
	$cacheWarmer->addSitemaps( $sitemap_url );

	$start = microtime( true );
	$result = $cacheWarmer->run();
	$end = microtime( true );

	echo PHP_EOL;

	printf(
		"Completed %d URLs in %.3fs (%.1f/sec, avg %.3f). %d CF hits, %d BC hits, %d uncached." . PHP_EOL,
		$total,
		$end - $start,
		$total / ( $end - $start ),
		( $end - $start ) / $total,
		$cf_hit,
		$bc_hit,
		$uncached
	);

	// Reset.
	$total = 0;
	$cf_hit = 0;
	$bc_hit = 0;
	$uncached = 0;
	unset( $cacheWarmer );
	echo PHP_EOL;
} while ( true );
