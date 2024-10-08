<?php

/** @noinspection PhpUnused */

namespace cis\git;

use CzProject\GitPhp\GitException;
use CzProject\GitPhp\GitRepository;
use CzProject\GitPhp\RunnerResult;
use CzProject\GitPhp\Runners\CliRunner;

use function explode;

/**
 * Git-class to extend the CZproject Git-Repository-Class
 * (https://github.com/czproject/git-php)
 *
 * Original License: New BSD License,
 * s. https://github.com/czproject/git-php/blob/master/license.md
 *
 * This will add some common Features to the existing class
 *
 * @author Kay Schroeder <kschroeder@cis-gotha.de>
 * @copyright 2020 Kay Schroeder
 * @version 1.0.0
 * @requires Cz\Git\GitRepository
 */
class Git extends GitRepository
{
    protected $binary = 'git';

    /**
     * Convert an Array of Flat File-Information into a
     * multidimensional array of directories and files.
     *
     * @param array $gitOutput
     * @return array
     */
    protected function flatToArray(array $gitOutput): array
    {
        $return = [];

        foreach ($gitOutput as $line) {
            $l = $line;
            $r = &$return;
            $k = '.';
            while (($pos = strpos($l, '/')) !== false) {
                if ($k != '.') {
                    $r = &$r[$k];
                }

                $k = substr($l, 0, $pos);
                if (!array_key_exists($k, $r)) {
                    $r[$k] = [];
                }

                $l = substr($l, $pos + 1);
            }
            $r[$k][] = $l;
        }

        return $return;
    }

    /**
     * Function to retrieve all Commits for a specific file
     *
     * @param string $filename
     * @param bool $last
     * @param int|null $since
     * @return array
     * @throws GitException
     */
    public function getCommitFile(string $filename, bool $last = false, ?int $since = null): array
    {
        $command = ['log'];
        if ($last) {
            array_push($command, '-n', '1');
        }
        $command[] = '--format="%%H;%%ct;%%an;%%s"';
        if ($since !== null) {
            $command[] = '--since';
        }
        array_push($command, '--', $filename, '2>&1');
        $result = $this->run($command);

        $commits = [];

        foreach ($result->getOutput() as $commitData) {
            if (strpos($commitData, ';') !== false) {
                list($commit, $timestamp, $author, $message) = explode(';', $commitData, 4);
                $commits[] = [
                    'commit' => $commit,
                    'timestamp' => $timestamp,
                    'author' => $author,
                    'message' => $message
                ];
            }
        }

        return $commits;
    }

    /**
     * Retrieve the Hash of a specific file, false if none found
     *
     * @param string $filename
     * @return false|string
     * @throws GitException
     */
    public function getFileHash(string $filename)
    {
        $result = $this->run(['ls-tree', 'HEAD', $filename, '2>&1']);

        if (empty($result->getOutput())) {
            return false;
        }

        if (preg_match('/\d+ .* ([0-9a-f]+)\t.*$/', $result->getOutput()[0], $matches)) {
            return $matches[1];
        } else {
            return false;
        }
    }

    /**
     * Function to retrieve the diff for a given file, and two commits.
     *
     * @param string $filename
     * @param string $commitA
     * @param string $commitB
     * @param bool $full
     * @return string
     * @throws GitException
     */
    public function getCommitDiffFile(string $filename, string $commitA, string $commitB, bool $full = true): string
    {
        $result = $this->run(['diff', $commitA, $commitB, '--', $filename, '2>&1']);

        if (
            count($result->getOutput()) >= 4
            && strpos($result->getOutput()[2], $filename) !== false
            && strpos($result->getOutput()[3], $filename) !== false
        ) {
            return !$full
                ? implode(PHP_EOL, array_slice($result->getOutput(), 4))
                : implode(PHP_EOL, $result->getOutput());
        }

        return false;
    }

    /**
     * Retrieve the content of a File, based on a given Commit.
     *
     * @param string $filename
     * @param string $commit
     * @return string
     * @throws GitException
     */
    public function getFileFromCommit(string $filename, string $commit): string
    {
        $result = $this->run(['show', sprintf('%s:"%s"', $commit, $filename), '2>&1']);
        return $result->getOutputAsString();
    }

    /**
     * Retrieve the content of a File, based on the latest Hash.
     *
     * @param string $hash
     * @return string
     * @throws GitException
     */
    public function getFileFromHash(string $hash): string
    {
        return $this->run(['show', $hash, '2>&1'])->getOutputAsString();
    }

    /**
     * Retrieve the latest Commit for a specific file until a specific date
     *
     * @param string $filename
     * @param string $date
     * @return string
     * @throws GitException
     */
    public function getCommitFileAtDate(string $filename, string $date): string
    {
        return $this->run(['log', '-1', '--format="%H;%ct;%s"', '--until', $date, '--', $filename])->getOutput()[0];
    }

    /**
     * Retrieve the latest commit until a specific date for the entire repository
     *
     * @param string $date
     * @return string
     * @throws GitException
     */
    public function getCommitAtDate(string $date): string
    {
        return $this->getCommitFileAtDate("", $date);
    }

    /**
     * Retrieve all Files recursively that exists in this Repository.
     *
     * @param bool $returnFlat
     * @return array
     * @throws GitException
     */
    public function getAllFiles(bool $returnFlat = false): array
    {
        $result = $this->run(['ls-files', '2>&1']);

        return $returnFlat ? $result->getOutput() : $this->flatToArray($result->getOutput());
    }

    /**
     * Returns a filtered List of all Files within a git repository.
     *
     * @param string $search
     * @param boolean $regex
     * @param boolean $returnFlat
     * @return array
     * @throws GitException
     */
    public function getAllFilesFiltered(string $search, bool $regex = false, bool $returnFlat = false): array
    {
        $allPrefilteredFiles = $this->getAllFiles(true);
        $allFiles = [];

        foreach ($allPrefilteredFiles as $file) {
            if (
                ($regex && preg_match('/' . str_replace('/', '\/', $search) . '/', $file))
                || (!$regex && strpos($file, $search))
            ) {
                $allFiles[] = $file;
            }
        }

        return ($returnFlat) ? $allFiles : $this->flatToArray($allFiles);
    }

    /**
     * Check if a given GIT-Repository is initialized
     *
     * @return bool
     * @throws GitException
     */
    public function isInitialized(): bool
    {
        return !preg_grep('/fatal: not a git repository/', $this->run(['status'])->getOutput());
    }

    /**
     * Function to check if Working Directory is the root directory.
     *
     * @return bool
     */
    public function isRootDirectory(): bool
    {
        return is_dir(rtrim($this->repository, '/') . '/.git');
    }

    /**
     * @param string $directory
     * @param array|null $params
     * @param string|null $gitBinary
     * @return RunnerResult
     * @throws GitException
     */
    public static function init(string $directory, ?array $params = null, ?string $gitBinary = 'git'): RunnerResult
    {
        if (is_dir(rtrim($directory, '/') . '/.git')) {
            throw new GitException("Repo already exists in $directory");
        }

        if (!is_dir($directory) && !mkdir($directory, 0777, true)) {
            throw new GitException("Unable to create directory '$directory'");
        }

        $result = (new CliRunner($gitBinary))->run($directory, array_merge(['init', $params]));

        if ($result->getExitCode() !== 0) {
            throw new GitException("Git init failed (directory $directory)");
        }

        return $result;
    }
}
