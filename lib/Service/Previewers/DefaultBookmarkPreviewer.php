<?php
/**
 * @author Marcel Klehr
 * @copyright 2016 Marcel Klehr mklehr@gmx.net
 *
 * This library is free software; you can redistribute it and/or
 * modify it under the terms of the GNU AFFERO GENERAL PUBLIC LICENSE
 * License as published by the Free Software Foundation; either
 * version 3 of the License, or any later version.
 *
 * This library is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU AFFERO GENERAL PUBLIC LICENSE for more details.
 *
 * You should have received a copy of the GNU Affero General Public
 * License along with this library.  If not, see <http://www.gnu.org/licenses/>.
 *
 */

namespace OCA\Bookmarks\Service\Previewers;

use OCA\Bookmarks\Contract\IBookmarkPreviewer;
use OCA\Bookmarks\Contract\IImage;
use OCA\Bookmarks\Db\Bookmark;
use OCA\Bookmarks\Image;
use OCA\Bookmarks\Service\FileCache;
use OCA\Bookmarks\Service\LinkExplorer;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\ILogger;

class DefaultBookmarkPreviewer implements IBookmarkPreviewer {
	// Cache for 4 months
	const CACHE_TTL = 4 * 4 * 7 * 24 * 60 * 60;
	const CACHE_PREFIX = 'bookmarks.DefaultPreviewService';

	const HTTP_TIMEOUT = 10 * 1000;

	/** @var FileCache */
	protected $cache;

	/** @var IClient */
	protected $client;

	/** @var LinkExplorer */
	protected $linkExplorer;

	/** @var ILogger */
	private $logger;

	/**
	 * @param FileCache $cache
	 * @param LinkExplorer $linkExplorer
	 * @param IClientService $clientService
	 * @param ILogger $logger
	 */
	public function __construct(FileCache $cache, LinkExplorer $linkExplorer, IClientService $clientService, ILogger $logger) {
		$this->cache = $cache;
		$this->linkExplorer = $linkExplorer;
		$this->client = $clientService->newClient();
		$this->logger = $logger;
	}

	protected function buildKey($url) {
		return self::CACHE_PREFIX . '-' . md5($url);
	}

	private function buildScrapeKey($url) {
		return $this->buildKey('meta-' . $url);
	}

	private function buildImageKey($url) {
		return $this->buildKey('image-' . $url);
	}

	/**
	 * @param Bookmark $bookmark
	 * @return IImage|null
	 * @throws \OCP\Files\NotFoundException
	 * @throws \OCP\Files\NotPermittedException
	 */
	public function getImage($bookmark): ?IImage {
		if (!isset($bookmark)) {
			return null;
		}
		$site = $this->scrapeUrl($bookmark->getUrl());
		$this->logger->debug('getImage for URL: ' . $bookmark->getUrl() . ' ' . var_export($site, true), ['app' => 'bookmarks']);
		if (isset($site['image']['small'])) {
			return $this->getOrFetchImageUrl($site['image']['small']);
		}
		if (isset($site['image']['large'])) {
			return $this->getOrFetchImageUrl($site['image']['large']);
		}
		return null;
	}

	public function scrapeUrl($url) {
		$key = $this->buildScrapeKey($url);
		if ($data = $this->cache->get($key)) {
			return json_decode($data, true);
		}
		$data = $this->linkExplorer->get($url);
		$this->cache->set($key, json_encode($data), self::CACHE_TTL);
		return $data;
	}

	/**
	 * @param $url
	 * @return array|mixed|\OCA\Bookmarks\Image|null
	 * @throws \OCP\Files\NotFoundException
	 * @throws \OCP\Files\NotPermittedException
	 */
	public function getOrFetchImageUrl($url) {
		if (!isset($url) || $url === '') {
			return null;
		}

		$key = $this->buildImageKey($url);
		// Try cache first
		if ($image = $this->cache->get($key)) {
			if ($image === 'null') {
				return null;
			}
			return \OCA\Bookmarks\Image::deserialize($image);
		}

		// Fetch image from remote server
		$image = $this->fetchImage($url);

		if ($image === null) {
			$this->cache->set($key, 'null', self::CACHE_TTL);
			return null;
		}

		// Store in cache for next time
		$this->cache->set($key, $image->serialize(), self::CACHE_TTL);
		return $image;
	}

	/**
	 * @param $url
	 * @return Image|null
	 */
	private function fetchImage($url): ?Image {
		try {
			$response = $this->client->get($url, ['timeout' => self::HTTP_TIMEOUT]);
		} catch (\Exception $e) {
			$this->logger->debug($e, ['app' => 'bookmarks']);
			return null;
		}
		$body = $response->getBody();
		$contentType = $response->getHeader('Content-Type');

		// Some HTPP Error occured :/
		if (200 !== $response->getStatusCode()) {
			return null;
		}

		// It's not actually an image, doh.
		if (!isset($contentType) || stripos($contentType, 'image') !== 0) {
			return null;
		}

		return new Image($contentType, $body);
	}
}
