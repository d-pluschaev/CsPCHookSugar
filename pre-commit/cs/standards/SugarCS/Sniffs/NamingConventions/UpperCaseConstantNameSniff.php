<?php
/**
 * SugarCS_Sniffs_NamingConventions_UpperCaseConstantNameSniff.
 *
 * Inherited from Generic_Sniffs_NamingConventions_UpperCaseConstantNameSniff
 *
 * Calls parent method if code string not in $ignoreLines array
 */
class SugarCS_Sniffs_NamingConventions_UpperCaseConstantNameSniff extends
    Generic_Sniffs_NamingConventions_UpperCaseConstantNameSniff
{
    public function process(PHP_CodeSniffer_File $phpcsFile, $stackPtr)
    {
        // Lines which will be ignored (without whitespaces)
        $ignoreLines = array(
            "sugarEntry" => true,
        );

        $tokens = $phpcsFile->getTokens();

        $codeStr = '';
        for ($i = $stackPtr; $i < $phpcsFile->numTokens; $i++) {
            if ($tokens[$i]['line'] == $tokens[$stackPtr]['line']) {
                if ($tokens[$i]['code'] !== T_WHITESPACE) {
                    $codeStr .= $tokens[$i]['content'];
                }
            } else {
                break;
            }
        }

        $matches = false;
        foreach ($ignoreLines as $line => $flag) {
            $matches += strpos($codeStr, $line) !== false;
        }
        if (!$matches) {
            parent::process($phpcsFile, $stackPtr);
        }
    }
    //end process()
}

//end class

?>
