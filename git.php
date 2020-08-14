<?php namespace cis\git;

/**
 * git-class to extend the CZproject Git-Repository-Class
 * (https://github.com/czproject/git-php)
 * 
 * Original License: New BSD Licens,
 * s. https://github.com/czproject/git-php/blob/master/license.md
 * 
 * This will add some common Features to the existing class
 * 
 * @author Kay Schroeder <kschroeder@cis-gotha.de>
 * @copyright 2020 Kay Schroeder
 * @version 1.0.0
 * @requires Cz\Git\GitRepository
 */
class git extends \Cz\Git\GitRepository
{
    /**
     * Function to retrieve all Commits for a specific file
     *
     * @param string $filename
     * @return array
     */
    public function getCommitFile(string $filename) : array
    {
        $this->begin();
        exec('git log --format="%H;%ct;%an;%s" ' . $filename . " 2>&1", $output);
        $this->end();

        $commits = [];

        foreach($output as $commitData)
        {
            list($commit, $timestamp, $author, $message) = \explode(';', $commitData, 4);
            $commits[] = [
                'commit' => $commit,
                'timestamp' => $timestamp,
                'author' => $author,
                'message' => $message
            ];
        }

        return $commits;
    }

    /**
     * Function to retrieve the diff for a given file and two commits.
     *
     * @param string $filename
     * @param string $commita
     * @param string $commitb
     * @return string
     */
    public function getCommitDiffFile(string $filename, string $commita, string $commitb) : string
    {
        $this->begin();
        exec('git diff ' . $commita . ' ' . $commitb . ' -- ' . $filename . " 2>&1", $output);
        $this->end();

        if(strpos($output[2], $filename) !== false and strpos($output[3], $filename) !== false)
            return implode(PHP_EOL, array_slice($output, 4));

        return false;
    }

    /**
     * Retrieve the content of a File, based on a given Commit.
     *
     * @param string $filename
     * @param string $commit
     * @return string
     */
    public function getFileFromCommit(string $filename, string $commit) : string
    {
        $this->begin();
        exec('git show ' . $commit . ':' . $filename . ' 2>&1', $output);
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
    public function getCommitFileAtDate(string $filename, string $date) : string
    {
        $this->begin();
        exec('git log -1 --format="%H;%ct;%s" --until ' . $date . ' -- "' . $filename . '"');
        $this->end();

        return $output[0];
    }

    /**
     * Retrieve the latest commit until a specific date for the entire repository
     *
     * @param string $date
     * @return string
     */
    public function getCommitAtDate(string $date) : string
    {
        return $this->getCommitFileAtDate("", $date);
    }
}