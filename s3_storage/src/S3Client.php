<?php

namespace Friendica\Addon\s3_storage\src;

defined('AKEEBAENGINE') or define('AKEEBAENGINE', 1);

use Akeeba\S3\Configuration;
use Akeeba\S3\Connector;
use Akeeba\S3\Exception\CannotDeleteFile;
use Akeeba\S3\Input;
use Friendica\Core\Storage\Capability\ICanWriteToStorage;
use Friendica\Core\Storage\Exception\StorageException;
use Friendica\Database\DBA;
use Friendica\Util\Strings;

/**
 * A WebDav Backend Storage class
 */
class S3Client implements ICanWriteToStorage
{
	const NAME = 'S3';

	/** @var Connector */
	protected $connector;

	/** @var string The name of the bucket used for the backend */
	protected $bucket;

	/** @var string Optional key prefix for tenant isolation (e.g. "std1.udp.social") */
	protected $prefix;

	public function __construct(Configuration $config, string $bucket, string $prefix = '')
	{
		$this->connector = new Connector($config);
		$this->bucket    = $bucket;
		$this->prefix    = rtrim($prefix, '/');
	}

	/**
	 * Split data ref and return file path
	 *
	 * @param string $reference Data reference
	 *
	 * @return string
	 */
	private function pathForRef(string $reference): string
	{
		$fold1 = substr($reference, 0, 2);
		$fold2 = substr($reference, 2, 2);
		$file  = substr($reference, 4);

		$path = implode('/', [$fold1, $fold2, $file]);
		return empty($this->prefix) ? $path : $this->prefix . '/' . $path;
	}

	/** {@inheritDoc} */
	public function __toString(): string
	{
		return self::getName();
	}

	/** {@inheritDoc} */
	public static function getName(): string
	{
		return self::NAME;
	}

	/** {@inheritDoc} */
	public function get(string $reference): string
	{
		try {
			return $this->connector->getObject($this->bucket, $this->pathForRef($reference), false);
		} catch (\RuntimeException $exception) {
			throw new StorageException(sprintf('Cannot get reference %s', $reference), $exception->getCode(), $exception);
		}
	}

	/** {@inheritDoc} */
	public function put(string $data, string $reference = ""): string
	{
		if ($reference === '') {
			try {
				$reference = Strings::getRandomHex();
			} catch (\Exception $exception) {
				throw new StorageException('S3 storage failed to generate a random hex', $exception->getCode(), $exception);
			}
		}

		try {
			$input = Input::createFromData($data);
			$this->connector->putObject($input, $this->bucket, $this->pathForRef($reference));
		} catch (\Exception $exception) {
			throw new StorageException(sprintf('Cannot put data for reference %s', $reference), $exception->getCode(), $exception);
		}

		$size = strlen($data);
		$this->trackUpload($reference, $size);

		return $reference;
	}

	/** {@inheritDoc} */
	public function delete(string $reference)
	{
		try {
			$this->connector->deleteObject($this->bucket, $this->pathForRef($reference));
		} catch (CannotDeleteFile $exception) {
			throw new StorageException(sprintf('Cannot delete reference %s', $reference), $exception->getCode(), $exception);
		}

		$this->trackDelete($reference);
	}

	private function trackUpload(string $reference, int $size): void
	{
		if (empty($this->prefix)) {
			return;
		}
		try {
			DBA::insert('udp_r2_object_sizes', [
				'reference'  => $reference,
				'size_bytes' => $size,
				'created_at' => date('Y-m-d H:i:s'),
			], true);
			DBA::e(
				'INSERT INTO `udp_storage_usage` (slot_domain, r2_bytes, updated_at)
				 VALUES (?, ?, NOW())
				 ON DUPLICATE KEY UPDATE r2_bytes = r2_bytes + ?, updated_at = NOW()',
				$this->prefix, $size, $size
			);
		} catch (\Exception $e) {
			// Non-fatal — metrics drift is acceptable; a missing row is not.
		}
	}

	private function trackDelete(string $reference): void
	{
		if (empty($this->prefix)) {
			return;
		}
		try {
			$row = DBA::selectFirst('udp_r2_object_sizes', ['size_bytes'], ['reference' => $reference]);
			if (!$row) {
				return;
			}
			$size = (int) $row['size_bytes'];
			DBA::delete('udp_r2_object_sizes', ['reference' => $reference]);
			DBA::e(
				'UPDATE `udp_storage_usage`
				 SET r2_bytes = GREATEST(0, CAST(r2_bytes AS SIGNED) - ?), updated_at = NOW()
				 WHERE slot_domain = ?',
				$size, $this->prefix
			);
		} catch (\Exception $e) {
			// Non-fatal.
		}
	}
}
