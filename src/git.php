<?php

namespace cis\git;

use Cz\Git\GitRepository;
use function explode;

/**
 * git-class to extend the CZproject Git-Repository-Class
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
class git extends GitRepository
{
    protected $_binary = 'git';

    /**
     * Convert an Array of Flat File-Information into an
     * multidimensional array of directories and files.
     *
     * @param array $gitoutput
     * @return array
     */
    protected function _flatToArray(array $gitoutput): array
    {
        $return = [];

        foreach ($gitoutput as $line) {
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
     * Set the Binary of Git.
     *
     * This setting can be used to use different git-binary or using another
     * helper executable (i.e.) to perform caching or so.
     *
     * @param string $binary
     * @return boolean
     */
    public function setBinary(string $binary): bool
    {
        $sanitize = (strpos($binary, ';') === false);

        if (
            !array_reduce($sanitize, function ($x, $y) {
                return $x && $y;
            }, true)
        ) {
            return false;
        }

        $this->_binary = $binary;
        return true;
    }

    /**
     * Function to retrieve all Commits for a specific file
     *
     * @param string $filename
     * @param bool $last
     * @return array
     */
    public function getCommitFile(string $filename, bool $last = false, ?int $since = null): array
    {
        $command = sprintf(
            '%s log %s --format="%%H;%%ct;%%an;%%s" %s -- "%s" 2>&1',
            $this->_binary,
            $last ? '-n 1' : '',
            ($since !== null and is_integer($since)) ? '--since ' . $since : '',
            $filename
        );
        $this->begin();
        exec($command, $output);
        $this->end();

        $commits = [];

        foreach ($output as $commitData) {
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
     * @return false|mixed
     */
    public function getFileHash(string $filename)
    {
        $this->begin();
        exec($this->_binary . ' ls-tree HEAD "' . $filename . '" 2>&1', $output);
        $this->end();

        if (count($output) == 0) {
            return false;
        }

        if (preg_match('/\d+ .* ([0-9a-f]+)\t.*$/', $output[0], $matches)) {
            return $matches[1];
        } else {
            return false;
        }
    }

    /**
     * Function to retrieve the diff for a given file and two commits.
     *
     * @param string $filename
     * @param string $commita
     * @param string $commitb
     * @param bool $full
     * @return string
     */
    public function getCommitDiffFile(string $filename, string $commita, string $commitb, bool $full = true): string
    {
        $this->begin();
        exec($this->_binary . ' diff ' . $commita . ' ' . $commitb . ' -- ' . $filename . " 2>&1", $output);
        $this->end();

        if (count($output) < 4) {
            return false;
        }

        if (strpos($output[2], $filename) !== false and strpos($output[3], $filename) !== false) {
            if (!$full) {
                return implode(PHP_EOL, array_slice($output, 4));
            }

            return implode(PHP_EOL, $output);
        }

        return false;
    }

    /**
     * Retrieve the content of a File, based on a given Commit.
     *
     * @param string $filename
     * @param string $commit
     * @return string
     */
    public function getFileFromCommit(string $filename, string $commit): string
    {
        $this->begin();
        exec($this->_binary . ' show ' . $commit . ':' . $filename . ' 2>&1', $output);
        $this->end();

        return implode(PHP_EOL, $output);
    }

    /**
     * Retrieve the content of a File, based on the latest Hash.
     *
     * @param string $hash
     * @return string
     */
    public function getFileFromHash(string $hash): string
    {
        $this->begin();
        exec($this->_binary . ' show ' . $hash . ' 2>&1', $output);
        $this->end();

        return implode(PHP_EOL, $output);
    }

    /**
     * Retrieve the latest Commit for a specific file until a specific date
     *
     * @param string $filename
     * @param string $date
     * @return string
     */
    public function getCommitFileAtDate(string $filename, string $date): string
    {
        $this->begin();
        exec($this->_binary . ' log -1 --format="%H;%ct;%s" --until ' . $date . ' -- "' . $filename . '"', $output);
        $this->end();

        return $output[0];
    }

    /**
     * Retrieve the latest commit until a specific date for the entire repository
     *
     * @param string $date
     * @return string
     */
    public function getCommitAtDate(string $date): string
    {
        return $this->getCommitFileAtDate("", $date);
    }

    /**
     * Retrieve all Files recursively that exists in this Repository.
     *
     * @param bool $returnflat
     * @return array
     */
    public function getAllFiles(bool $returnflat = false): array
    {
        $this->begin();
        exec($this->_binary . ' ls-files 2>&1', $output);
        $this->end();

        return ($returnflat) ? $output : $this->_flatToArray($output);
    }

    /**
     * Returns a filtered List of all Files within a git repository.
     *
     * @param string $search
     * @param boolean $regex
     * @param boolean $returnflat
     * @return array
     */
    public function getAllFilesFiltered(string $search, bool $regex = false, bool $returnflat = false): array
    {
        $allPrefilteredFiles = $this->getAllFiles(true);
        $allFiles = [];

        foreach ($allPrefilteredFiles as $file) {
            if (
                ($regex and preg_match('/' . str_replace('/', '\/', $search) . '/', $file))
                or (!$regex and strpos($file, $search))
            ) {
                $allFiles[] = $file;
            }
        }

        return ($returnflat) ? $allFiles : $this->_flatToArray($allFiles);
    }

    /**
     * Check if a given GIT-Repository is initialized
     *
     * @return bool
     */
    public function isInitalized(): bool
    {
        $this->begin();
        exec($this->_binary . ' status', $output);
        $this->end();

        if (preg_grep('/fatal: not a git repository/', $output)) {
            return false;
        } else {
            return true;
        }
    }

    /**
     * Function to check if Working Directory is the root directory.
     *
     * @return bool
     */
    public function isRootDirectoy(): bool
    {
        $this->begin();
        $gitexists = is_dir('.git');
        $this->end();

        return $gitexists;
    }
}
