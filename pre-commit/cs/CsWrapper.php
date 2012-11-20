<?php

/**
 * Wrapper of PHP_CodeSniffer
 * Purpose:  avoid using direct output, ability to use PHP_CodeSniffer not
 *           only as CLI script, PEAR extension is not required (needed
 *           classes can be included from any location)
 * Features: execute PHP_CodeSniffer functionality for files, blocks of
 *           the code, create report for specific lines
 *
 * Written by Dmitri Pluschaev d.pluschaev@intetics.com
 */


// path to CodeSniffer class
if (!class_exists('PHP_CodeSniffer')) {
    require_once (CODESNIFFER_CLASS_DIRECTORY . '/CodeSniffer.php');
}

class CsWrapper extends PHP_CodeSniffer
{
    private $cswDefaults = array(
        'standard' => 'PSR2',
    );

    private $cswData;
    private $cswStandard;

    public $storeOutput = false;

    public function __construct($verbosity = 0)
    {
        $this->cswSetStandard($this->cswDefaults['standard']);
        parent::__construct($verbosity);
    }

    public function cswAddCode($id, $code, array $lines_array)
    {
        $this->cswData[] = array(
            'id' => $id,
            'code' => $code,
            'lines' => $lines_array,
            'code_lines' => preg_split("/((\r?\n)|(\r\n?))/", $code)
        );
    }

    public function cswAddFile($file, array $lines_array)
    {
        if (is_file($file)) {
            $this->cswAddCode($file, file_get_contents($file), $lines_array);
        }
    }

    public function cswSetStandard($standard)
    {
        $this->cswStandard = $standard;
    }

    public function cswExecute()
    {
        // PHP_CodeSniffer - silent prepare
        ob_start();

        $this->setTokenListeners($this->cswStandard, array());
        $this->populateCustomRules();
        $this->populateTokenListeners();
        $tlisteners = $this->getTokenSniffs();

        ob_end_clean();

        // PHP_CodeSniffer - silent process each item and collect results
        foreach ($this->cswData as $index => $item) {
            ob_start();

            $pcsFile = new PHP_CodeSniffer_File(
                '',
                $tlisteners['file'],
                $this->allowedFileExtensions,
                $this->ruleset,
                $this
            );

            $pcsFile->start($item['code']);
            $this->cswData[$index]['output'] = $this->storeOutput ? ob_get_contents() : 'output disabled';
            ob_end_clean();

            // free some memory
            unset($this->cswData[$index]['code']);

            // prepare full report
            $this->cswData[$index]['messages'] = $this->mergeMessages(
                $pcsFile->getErrors(),
                $pcsFile->getWarnings(),
                $item['code_lines']
            );

            // prepare report for lines
            $this->cswData[$index]['report_for_lines'] = $this->createReportForLines($this->cswData[$index]);
        }
        // return data
        return $this->cswData;
    }

    private function mergeMessages($errors, $warnings, $code_array)
    {
        foreach ($errors as $line_num => $line_data) {
            foreach ($line_data as $col_num => $col_data) {
                foreach ($col_data as $index => $err_descr) {
                    $errors[$line_num][$col_num][$index]['type'] = 'error';
                    $errors[$line_num][$col_num][$index]['code'] = $code_array[$line_num - 1];
                }
            }
            ksort($errors[$line_num]);
        }
        foreach ($warnings as $line_num => $line_data) {
            $errors[$line_num] = isset($errors[$line_num]) ? $errors[$line_num] : array();
            foreach ($line_data as $col_num => $col_data) {
                $errors[$line_num][$col_num] = isset($errors[$line_num][$col_num])
                    ? $errors[$line_num][$col_num]
                    : array();
                foreach ($col_data as $index => $err_descr) {
                    $err_descr['type'] = 'warning';
                    $errors[$line_num][$col_num][$index] = $err_descr;
                    $errors[$line_num][$col_num][$index]['code'] = $code_array[$line_num - 1];
                }
            }
            ksort($errors[$line_num]);
        }
        ksort($errors);
        return $errors;
    }

    private function createReportForLines($data)
    {
        $out = array();
        if (sizeof($data['lines'])) {
            foreach ($data['lines'] as $index => $line) {
                if (isset($data['messages'][$line])) {
                    $out[$line] = $data['messages'][$line];
                } else {
                    unset($data['lines'][$index]);
                }
            }
        }
        return $out;
    }
}

