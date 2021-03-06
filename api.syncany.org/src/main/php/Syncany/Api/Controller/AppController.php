<?php

/*
 * Syncany, www.syncany.org
 * Copyright (C) 2011-2015 Philipp C. Heckel <philipp.heckel@gmail.com>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 */

namespace Syncany\Api\Controller;

use Syncany\Api\Config\Config;
use Syncany\Api\Exception\Http\BadRequestHttpException;
use Syncany\Api\Exception\Http\ServerErrorHttpException;
use Syncany\Api\Exception\Http\UnauthorizedHttpException;
use Syncany\Api\Model\FileHandle;
use Syncany\Api\Persistence\Database;
use Syncany\Api\Task\AppZipGuiAppReleaseUploadTask;
use Syncany\Api\Task\AppZipOsxNotifierReleaseUploadTask;
use Syncany\Api\Task\DebAppReleaseUploadTask;
use Syncany\Api\Task\DocsExtractZipUploadTask;
use Syncany\Api\Task\ExeAppReleaseUploadTask;
use Syncany\Api\Task\ExeGuiAppReleaseUploadTask;
use Syncany\Api\Task\ReportsExtractZipUploadTask;
use Syncany\Api\Task\TarGzAppReleaseUploadTask;
use Syncany\Api\Task\ZipAppReleaseUploadTask;
use Syncany\Api\Util\FileUtil;
use Syncany\Api\Util\Log;
use Syncany\Api\Util\StringUtil;

/**
 * The app controller is responsible to handling application related requests, mainly
 * the upload of new main/core application releases and snapshots, as well as the
 * retrieval of the latest application version(s).
 *
 * @author Philipp Heckel <philipp.heckel@gmail.com>
 */
class AppController extends Controller
{
    /**
     * This method retrieves the latest application release version(s) from the database
     * and displays the results as XML. It can be filtered using several filter mechanisms,
     * e.g. distribution and file type, operating system, and others.
     *
     * <p>Optional method arguments (in <tt>methodArgs</tt>) are:
     * <ul>
     *   <li>dist: Distribution type (one in: cli, gui; default is empty)</li>
     *   <li>type: File type (one in: tar.gz, zip, deb, exe, app.zip; default is empty)</li>
     *   <li>snapshots: Whether or not to include snapshots in the result (true or false; default is false!)</li>
     *   <li>os: Operating system to upload this release for (one in: all, linux, windows, macosx; default is all)</li>
     *   <li>arch: Architecture of this release (one in: all, x86, x86_64; default is all)</li>
     * </ul>
     *
     * @param array $methodArgs GET arguments to filter the results (see above)
     * @param array $requestArgs No request arguments are expected by this method
     * @throws BadRequestHttpException If any of the given arguments is invalid
     */
    public function get(array $methodArgs, array $requestArgs)
    {
        // Check request params
        $dist = $this->validateGetDist($methodArgs, $requestArgs);
        $type = $this->validateGetType($methodArgs);

        $operatingSystem = ControllerHelper::validateOperatingSystem($methodArgs);
        $architecture = ControllerHelper::validateArchitecture($methodArgs);
        $includeSnapshots = ControllerHelper::validateWithSnapshots($methodArgs);

        // Get data
        $appList = $this->queryLatestAppList($dist, $type, $operatingSystem, $architecture, $includeSnapshots);

        // Print XML
        $this->printResponseXml($appList);
        exit;
    }

    /**
     * This method handles the upload of a release or snapshot file, as well as the upload of reports
     * and documentation in the form of an archive. The uploaded files are validated and then placed in
     * the appropriate place to make them accessible to the end users.
     *
     * <p>The release/snapshot formats tar.gz, zip, exe and deb are simply put moved to the target download
     * page. The deb file is additionally put into the Debian/APT archive. The reports archive is extracted
     * and placed on the reports page, the docs archive is extracted and placed on the docs page.
     *
     * <p>Expected method arguments (in <tt>methodArgs</tt>) are:
     * <ul>
     *   <li>filename: Target filename of the uploaded file</li>
     *   <li>checksum: SHA-256 checksum of the uploaded file</li>
     *   <li>snapshot: Whether or not the uploaded file is a snapshot, or a release (true or false)</li>
     *   <li>os: Operating system to upload this release for (one in: all, linux, windows, macosx)</li>
     *   <li>arch: Architecture of this release (one in: all, x86, x86_64)</li>
     *   <li>dist: Distribution type of this release (one in: cli, gui, other)</li>
     *   <li>type: File type of the upload (one in: tar.gz, zip, deb, exe, reports or docs)</li>
     * </ul>
     *
     * @param array $methodArgs GET arguments, expected are filename, checksum, snapshot, os, arch, dist and type
     * @param array $requestArgs No request arguments are expected by this method
     * @param FileHandle $fileHandle File handle to the uploaded file
     * @throws BadRequestHttpException If any of the given arguments is invalid
     * @throws UnauthorizedHttpException If the given signature does not match the expected signature
     * @throws ServerErrorHttpException If there is any unexpected server behavior
     */
    public function put(array $methodArgs, array $requestArgs, FileHandle $fileHandle)
    {
        Log::info(__CLASS__, __METHOD__, "Put request for application received. Authenticating ...");
        $this->authorize("application-put", $methodArgs, $requestArgs);

        $checksum = ControllerHelper::validateChecksum($methodArgs);
        $fileName = ControllerHelper::validateFileName($methodArgs);
        $version = ControllerHelper::validateAppVersion($methodArgs);
        $date = ControllerHelper::validateAppDate($methodArgs);
        $snapshot = ControllerHelper::validateIsSnapshot($methodArgs);
        $os = ControllerHelper::validateOperatingSystem($methodArgs);
        $arch = ControllerHelper::validateArchitecture($methodArgs);

        $dist = $this->validatePutDist($methodArgs);
        $type = $this->validatePutType($methodArgs);

        $task = $this->createTask($dist, $type, $fileHandle, $fileName, $checksum, $version, $date, $snapshot, $os, $arch);
        $task->execute();
    }

    public function putOsxnotifier(array $methodArgs, array $requestArgs, FileHandle $fileHandle)
    {
        Log::info(__CLASS__, __METHOD__, "Put request for OSX notifier received. Authenticating ...");
        $this->authorize("osx-notifier-put", $methodArgs, $requestArgs);

        $checksum = ControllerHelper::validateChecksum($methodArgs);
        $fileName = ControllerHelper::validateFileName($methodArgs);
        $snapshot = ControllerHelper::validateIsSnapshot($methodArgs);

        $task = new AppZipOsxNotifierReleaseUploadTask($fileHandle, $fileName, $checksum, $snapshot);
        $task->execute();
    }

    private function validatePutDist(array $methodArgs)
    {
        if (!isset($methodArgs['dist']) || !in_array($methodArgs['dist'], array("cli", "gui", "other"))) {
            throw new BadRequestHttpException("No or invalid dist argument given.");
        }

        return $methodArgs['dist'];
    }

    private function validatePutType(array $methodArgs)
    {
        if (!isset($methodArgs['type']) || !in_array($methodArgs['type'], array("tar.gz", "zip", "deb", "exe", "app.zip", "docs", "reports"))) {
            throw new BadRequestHttpException("No or invalid type argument given.");
        }

        return $methodArgs['type'];
    }

    private function validateGetDist(array $methodArgs, array $requestArgs)
    {
        $methodArgGiven = isset($methodArgs['dist']) && !empty($methodArgs['dist']); // Treat empty as not present
        $requestArgGiven = isset($requestArgs[0]);

        if ($methodArgGiven || $requestArgGiven) {
            $dist = ($methodArgGiven) ? $methodArgs['dist'] : $requestArgs[0];

            if (!in_array($dist, array("cli", "gui"))) {
                return false;
            }

            return $dist;
        } else {
            return false;
        }
    }

    private function validateGetType(array $methodArgs)
    {
        if (!isset($methodArgs['type']) || !in_array($methodArgs['type'], array("tar.gz", "zip", "deb", "exe", "app.zip"))) {
            return false;
        }

        return $methodArgs['type'];
    }

    private function createTask($dist, $type, $fileHandle, $fileName, $checksum, $version, $date, $snapshot, $os, $arch)
    {
        switch ($dist) {
            case "cli":
                return $this->createCliTask($type, $fileHandle, $fileName, $checksum, $version, $date, $snapshot, $os, $arch);

            case "gui":
                return $this->createGuiTask($type, $fileHandle, $fileName, $checksum, $version, $date, $snapshot, $os, $arch);

            case "other":
                return $this->createOtherTask($type, $fileHandle, $fileName, $checksum, $version, $date, $snapshot);

            default:
                throw new ServerErrorHttpException("Dist not supported.");
        }
    }

    private function createCliTask($type, $fileHandle, $fileName, $checksum, $version, $date, $snapshot, $os, $arch)
    {
        switch ($type) {
            case "tar.gz":
                return new TarGzAppReleaseUploadTask($fileHandle, $fileName, $checksum, $version, $date, $snapshot, $os, $arch);

            case "zip":
                return new ZipAppReleaseUploadTask($fileHandle, $fileName, $checksum, $version, $date, $snapshot, $os, $arch);

            case "deb":
                return new DebAppReleaseUploadTask($fileHandle, $fileName, $checksum, $version, $date, $snapshot, $os, $arch);

            case "exe":
                return new ExeAppReleaseUploadTask($fileHandle, $fileName, $checksum, $version, $date, $snapshot, $os, $arch);

            default:
                throw new ServerErrorHttpException("Type not supported.");
        }
    }

    private function createGuiTask($type, $fileHandle, $fileName, $checksum, $version, $date, $snapshot, $os, $arch)
    {
        switch ($type) {
            case "app.zip":
                return new AppZipGuiAppReleaseUploadTask($fileHandle, $fileName, $checksum, $version, $date, $snapshot, $os, $arch);

            case "exe":
                return new ExeGuiAppReleaseUploadTask($fileHandle, $fileName, $checksum, $version, $date, $snapshot, $os, $arch);

            default:
                throw new ServerErrorHttpException("Type not supported.");
        }
    }

    private function createOtherTask($type, $fileHandle, $fileName, $checksum)
    {
        switch ($type) {
            case "docs":
                return new DocsExtractZipUploadTask($fileHandle, $fileName, $checksum);

            case "reports":
                return new ReportsExtractZipUploadTask($fileHandle, $fileName, $checksum);

            default:
                throw new ServerErrorHttpException("Type not supported.");
        }
    }

    private function queryLatestAppList($dist, $type, $operatingSystem, $architecture, $includeSnapshots)
    {
        $sqlQuerySkeleton = FileUtil::readResourceFile(__NAMESPACE__, "app.select-latest.skeleton.sql");
        $whereQuery = $this->createLatestAppWhereQuery($dist, $type, $operatingSystem, $architecture, $includeSnapshots);

        $sqlQuery = StringUtil::replace($sqlQuerySkeleton, array(
            "where" => $whereQuery
        ));

        $statement = $this->prepareLatestAppStatement($sqlQuery, $dist, $type, $operatingSystem, $architecture, $includeSnapshots);
        return $this->fetchLatestAppList($statement);
    }

    private function createLatestAppWhereQuery($dist, $type, $operatingSystem, $architecture, $includeSnapshots)
    {
        $where = array();

        $where[] = ($dist) ? "`dist` = :dist" : "1";
        $where[] = ($type) ? "`type` = :type" : "1";
        $where[] = ($operatingSystem && $operatingSystem != "all") ? "(`os` = 'all' or `os` = :os)" : "1";
        $where[] = ($architecture && $architecture != "all") ? "(`arch` = 'all' or `arch` = :arch)" : "1";
        $where[] = (!$includeSnapshots) ? "`release` = :release" : "1";

        if (count($where) > 0) {
            return join(" and ", $where);
        } else {
            return "1";
        }
    }

    private function prepareLatestAppStatement($sqlQuery, $dist, $type, $operatingSystem, $architecture, $includeSnapshots)
    {
        $database = Database::createInstance("app-read");
        $statement = $database->prepare($sqlQuery);

        if ($dist) {
            $statement->bindValue(':dist', $dist, \PDO::PARAM_STR);
        }

        if ($type) {
            $statement->bindValue(':type', $type, \PDO::PARAM_STR);
        }

        if ($operatingSystem && $operatingSystem != "all") {
            $statement->bindValue(':os', $operatingSystem, \PDO::PARAM_STR);
        }

        if ($architecture && $architecture != "all") {
            $statement->bindValue(':arch', $architecture, \PDO::PARAM_STR);
        }

        if (!$includeSnapshots) {
            $statement->bindValue(':release', 1, \PDO::PARAM_INT);
        }

        return $statement;
    }

    private function fetchLatestAppList(\PDOStatement $statement)
    {
        $statement->setFetchMode(\PDO::FETCH_ASSOC);

        if (!$statement->execute()) {
            throw new ServerErrorHttpException("Cannot retrieve apps from database.");
        }

        $appList = array();

        while ($appArray = $statement->fetch()) {
            $appList[] = $appArray;
        }

        return $appList;
    }

    private function printResponseXml($appList) {
        header("Content-Type: application/xml");

        $downloadBaseUrl = Config::get("app.base-url");

        $wrapperSkeleton = FileUtil::readResourceFile(__NAMESPACE__, "app.get-response.wrapper.skeleton.xml");
        $appInfoSkeleton = FileUtil::readResourceFile(__NAMESPACE__, "app.get-response.appinfo.skeleton.xml");

        $appInfoBlocks = array();

        foreach ($appList as $app) {
            $release = ($app['release']) ? "true" : "false";
            $downloadUrl = $downloadBaseUrl . $app['fullpath'];

            $appInfoBlocks[] = StringUtil::replace($appInfoSkeleton, array(
                "dist" => $app['dist'],
                "type" => $app['type'],
                "appVersion" => $app['appVersion'],
                "date" => $app['date'],
                "release" => $release,
                "operatingSystem" => $app['os'],
                "architecture" => $app['arch'],
                "checksum" => $app['checksum'],
                "downloadUrl" => $downloadUrl
            ));
        }

        // Prepare output

        if (count($appList) > 0) {
            $code = 200;
            $message = "OK";
            $apps = join("\n", $appInfoBlocks);
        } else {
            $code = 204;
            $message = "No Content";
            $apps = "";
        }

        $xml = StringUtil::replace($wrapperSkeleton, array(
            "code" => $code,
            "message" => $message,
            "apps" => $apps
        ));

        echo $xml;
        exit;
    }
}