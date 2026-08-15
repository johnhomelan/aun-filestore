<?php

namespace HomeLan\FileStore\Vfs\Plugin;

/**
 * The subset of the AWS SDK S3Client API that the S3 VFS plugin calls.
 *
 * _getS3Client()/setS3Client() are typed as \Aws\S3\S3Client|S3ClientContract
 * rather than S3Client alone: unit tests inject a lightweight StubS3Client
 * (implementing this interface) instead of the real AWS client.
 */
interface S3ClientContract {

	public function doesObjectExist(string $sBucket, string $sKey): bool;

	/**
	 * @param array<string,mixed> $aArgs
	 * @return \ArrayAccess<string,mixed>|array<string,mixed>
	 */
	public function getObject(array $aArgs): \ArrayAccess|array;

	/**
	 * @param array<string,mixed> $aArgs
	 * @return \ArrayAccess<string,mixed>|array<string,mixed>
	 */
	public function putObject(array $aArgs): \ArrayAccess|array;

	/**
	 * @param array<string,mixed> $aArgs
	 * @return \ArrayAccess<string,mixed>|array<string,mixed>
	 */
	public function deleteObject(array $aArgs): \ArrayAccess|array;

	/**
	 * @param array<string,mixed> $aArgs
	 * @return \ArrayAccess<string,mixed>|array<string,mixed>
	 */
	public function copyObject(array $aArgs): \ArrayAccess|array;

	/**
	 * @param array<string,mixed> $aArgs
	 * @return \ArrayAccess<string,mixed>|array<string,mixed>
	 */
	public function listObjectsV2(array $aArgs): \ArrayAccess|array;
}
