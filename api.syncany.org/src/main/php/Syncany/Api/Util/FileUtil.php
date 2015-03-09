<?php

namespace Syncany\Api\Util;

use Syncany\Api\Config\Config;
use Syncany\Api\Exception\ConfigException;
use Syncany\Api\Exception\Http\ServerErrorHttpException;
use Syncany\Api\Model\FileHandle;
use Syncany\Api\Model\TempFile;

class FileUtil
{
    const MAX_WRITE_FILE_SIZE = 52428800; // 50 MB

	public static function readPropertiesFile($configContext, $configName)
	{
		$propertiesFile = self::getConfigFileName($configContext, $configName);

		$properties = array();
		$lines = explode("\n", file_get_contents($propertiesFile));

		foreach ($lines as $line) {
			if (!preg_match('/^\s*#/', $line) && preg_match('/^([^=]+)\s*=\s*(.*)$/', $line, $m)) {
				$properties[trim($m[1])] = trim($m[2]);
			}
		}

		return $properties;
	}

	public static function readResourceFile($namespace, $relativePath)
	{
		if (!defined('RESOURCES_PATH')) {
			throw new ConfigException("Resources path not set via RESOURCES_PATH.");
		}

		$absoluteFilePath = RESOURCES_PATH . '/' . str_replace('\\', '/', $namespace) . '/' . $relativePath;

		if (!file_exists($absoluteFilePath)) {
			throw new ConfigException("File file does not exist: " . $absoluteFilePath);
		}

		return file_get_contents($absoluteFilePath);
	}

	public static function createTempDir($uploadContext)
	{
		if (!defined('UPLOAD_PATH')) {
			throw new ConfigException("Upload path not set via CONFIG_PATH.");
		}

		if (!preg_match('/^[-_\/a-z0-9]+$/', $uploadContext)) {
			throw new ConfigException("Invalid upload context passed. Illegal characters.");
		}

		$tempDir = UPLOAD_PATH . "/" . $uploadContext . "/" . time() . "-" . StringUtil::generateRandomString(7);

		if (!mkdir($tempDir, 0777, true)) {
			throw new ConfigException("Cannot create upload directory");
		}

		return new TempFile($tempDir);
	}

	public static function writeToTempFile(FileHandle $sourceFileInputStream, TempFile $targetDir, $suffix = "")
	{
		if (!preg_match('/^[.a-z0-9]*$/i', $suffix)) {
			throw new ConfigException("Invalid suffix passed. Illegal characters.");
		}


		$tempFile = new TempFile($targetDir->getFile() . "/" . StringUtil::generateRandomString(5) . $suffix);
		return self::writeToFile($sourceFileInputStream, $tempFile);
	}

	public static function writeToFile(FileHandle $sourceFileInputStream, TempFile $tempFile)
	{
		$tempFileHandle = new FileHandle(fopen($tempFile->getFile(), "w"));
        $writtenBytes = 0;

		while (!feof($sourceFileInputStream->getHandle())) {
			$buffer = fread($sourceFileInputStream->getHandle(), 8192);
			$writtenBytes += fwrite($tempFileHandle->getHandle(), $buffer);

            if ($writtenBytes > self::MAX_WRITE_FILE_SIZE) {
                throw new ConfigException("Uploaded file to big. For security reasons, we do not accept so large files.");
            }
		}

		fclose($sourceFileInputStream->getHandle());
		fclose($tempFileHandle->getHandle());

		return $tempFile;
	}

	public static function calculateChecksum(TempFile $file)
	{
		if (!file_exists($file->getFile())) {
			throw new ConfigException("Cannot calculate checksum. File does not exist.");
		}

		return hash_file("sha256", $file->getFile());
	}

	public static function extractZipArchive(TempFile $tempFile, TempFile $tempDir)
	{
		$zip = new \ZipArchive();

		if ($zip->open($tempFile->getFile()) === true) {
			$zip->extractTo($tempDir->getFile() . "/");
			$zip->close();
		} else {
			throw new ConfigException("Cannot extract ZIP archive.");
		}
	}

	public static function readZipFileEntry($zipFileName, $searchEntryName)
	{
		$zip = zip_open($zipFileName);

		if ($zip) {
			while ($zipEntry = zip_read($zip)) {
				$entryName = zip_entry_name($zipEntry);

				if ($entryName == $searchEntryName) {
					if (zip_entry_open($zip, $zipEntry, "r")) {
						$searchFileContents = zip_entry_read($zipEntry, zip_entry_filesize($zipEntry));

						zip_entry_close($zipEntry);
						zip_close($zip);

						return $searchFileContents;
					}
				}
			}

			zip_close($zip);
		}

		return false;
	}

	public static function parseJarManifest($manifestFileContents)
	{
		$manifest = array();
		$lines = explode("\n", $manifestFileContents);

		foreach ($lines as $line) {
			if (preg_match('/^([^:]+):\s*(.*)$/', $line, $m)) {
				$manifest[$m[1]] = trim($m[2]);
			}
		}

		return $manifest;
	}

	private static function getConfigFileName($configContext, $configName)
	{
		if (!defined('CONFIG_PATH')) {
			throw new ConfigException("Config path not set via CONFIG_PATH.");
		}

		if (!preg_match('/^[-_a-z0-9]+$/', $configContext) || !preg_match('/^[-_a-z0-9]+$/', $configName)) {
			throw new ConfigException("Invalid config context passed. Illegal characters.");
		}

		$configFile = CONFIG_PATH . "/" . $configContext . "/" . $configName . ".properties";

		if (!file_exists($configFile)) {
			throw new ConfigException("Config file not found for context $configContext.");
		}

		return $configFile;
	}

	public static function deleteTempDir(TempFile $tempDir)
	{
        if (!defined('UPLOAD_PATH')) {
            throw new ConfigException("Upload path not set via CONFIG_PATH.");
        }

        self::deleteDir(UPLOAD_PATH, $tempDir->getFile());
	}

    public static function deleteDir($lockInDir, $dir)
    {
        if (is_dir($dir)) {
            self::checkLockInDirMustExist($lockInDir, $dir);

            $directoryIterator = new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS);
            $iteratorIterator = new \RecursiveIteratorIterator($directoryIterator, \RecursiveIteratorIterator::CHILD_FIRST);

            foreach ($iteratorIterator as $path) {
                if ($path->isDir() && !$path->isLink()) {
                    rmdir($path->getPathname());
                } else {
                    unlink($path->getPathname());
                }
            }

            rmdir($dir);
        }
        else {
            Log::info(__CLASS__, __METHOD__, "Directory '$dir' is not a directory. Doing nothing.");
        }
    }

    public static function deleteFile($lockInDir, $file)
    {
        if (is_file($file)) {
            self::checkLockInDirMustExist($lockInDir, $file);

            Log::info(__CLASS__, __METHOD__, "Deleting '$file' ...");
            unlink($file);
        }
        else {
            Log::info(__CLASS__, __METHOD__, "File '$file' is not a file. Doing nothing.");
        }
    }

    public static function moveFile($sourceLockInDir, $sourceFile, $targetLockInDir, $targetFile)
    {
        self::checkLockInDirMustExist($sourceLockInDir, $sourceFile);
        self::checkLockInDirMustNotExist($targetLockInDir, $targetFile);

        Log::info(__CLASS__, __METHOD__, "Move '$sourceFile' to '$targeFile' ...");

        if (!rename($sourceFile, $targetFile)) {
            Log::info(__CLASS__, __METHOD__, "Move failed.");
            throw new ConfigException("Cannot move file to target folder");
        }
    }

    public static function checkLockInDirMustNotExist($lockInDir, $file)
    {
        self::checkLockInDir($lockInDir, $file);
    }

    public static function checkLockInDirMustExist($lockInDir, $file)
    {
        self::checkLockInDir($lockInDir, $file);

        $fileRealPath = realpath($file); // Returns 'false' if not existent
        self::checkLockInDir($lockInDir, $fileRealPath);
    }

    private static function checkLockInDir($lockInDir, $file)
    {
        if (!$lockInDir || !is_dir($lockInDir) || $lockInDir == "/") {
            throw new ConfigException("Invalid lock-in directory");
        }

        if (!$file) {
            throw new ConfigException("Invalid file.");
        }

        if (substr($file, 0, strlen($lockInDir)) != $lockInDir) {
            throw new ConfigException("Invalid file. Must reside in upload folder.");
        }
    }
}