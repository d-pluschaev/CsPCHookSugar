<?php
/**
 * Parses and verifies the doc comments for classes.
 *
 * PHP version 5
 *
 */


/**
 * Parses and verifies the doc comments for classes.
 *
 */
class SugarCS_Sniffs_Commenting_ClassCommentSniff extends PEAR_Sniffs_Commenting_ClassCommentSniff
{
    protected $tags = array(
                       'api'   => array(
                                        'required'       => false,
                                        'allow_multiple' => false,
                                       ),
    );
    /**
     * Processes this test, when one of its tokens is encountered.
     *
     * @param PHP_CodeSniffer_File $phpcsFile The file being scanned.
     * @param int                  $stackPtr  The position of the current token
     *                                        in the stack passed in $tokens.
     *
     * @return void
     */
    public function process(PHP_CodeSniffer_File $phpcsFile, $stackPtr)
    {
        $this->currentFile = $phpcsFile;

        $tokens    = $phpcsFile->getTokens();
        $type      = strtolower($tokens[$stackPtr]['content']);
        $errorData = array($type);
        $find      = array(
                      T_ABSTRACT,
                      T_WHITESPACE,
                      T_FINAL,
                     );

        // Extract the class comment docblock.
        $commentEnd = $phpcsFile->findPrevious($find, ($stackPtr - 1), null, true);

        if ($commentEnd !== false && $tokens[$commentEnd]['code'] === T_COMMENT) {
            $error = 'You must use "/**" style comments for a %s comment';
            $phpcsFile->addWarning($error, $stackPtr, 'WrongStyle', $errorData);
            return;
        } else if ($commentEnd === false
            || $tokens[$commentEnd]['code'] !== T_DOC_COMMENT
        ) {
            $phpcsFile->addWarning('Missing %s doc comment', $stackPtr, 'Missing', $errorData);
            return;
        }

        $commentStart = ($phpcsFile->findPrevious(T_DOC_COMMENT, ($commentEnd - 1), null, true) + 1);

        // Distinguish file and class comment.
        $prevClassToken = $phpcsFile->findPrevious(T_CLASS, ($stackPtr - 1));
        if ($prevClassToken === false) {
            // This is the first class token in this file, need extra checks.
            $prevNonComment = $phpcsFile->findPrevious(T_DOC_COMMENT, ($commentStart - 1), null, true);
            if ($prevNonComment !== false) {
                $prevComment = $phpcsFile->findPrevious(T_DOC_COMMENT, ($prevNonComment - 1));
                if ($prevComment === false) {
                    // There is only 1 doc comment between open tag and class token.
                    $newlineToken = $phpcsFile->findNext(T_WHITESPACE, ($commentEnd + 1), $stackPtr, false, $phpcsFile->eolChar);
                    if ($newlineToken !== false) {
                        $newlineToken = $phpcsFile->findNext(
                            T_WHITESPACE,
                            ($newlineToken + 1),
                            $stackPtr,
                            false,
                            $phpcsFile->eolChar
                        );

                        if ($newlineToken !== false) {
                            // Blank line between the class and the doc block.
                            // The doc block is most likely a file comment.
                            $error = 'Missing %s doc comment';
                            $phpcsFile->addWarning($error, ($stackPtr + 1), 'Missing', $errorData);
                            return;
                        }
                    }//end if
                }//end if
            }//end if
        }//end if
        $this->className = $phpcsFile->getDeclarationName($stackPtr);

        $comment = $phpcsFile->getTokensAsString(
            $commentStart,
            ($commentEnd - $commentStart + 1)
        );

        // Parse the class comment.docblock.
        try {
            $this->commentParser = new PHP_CodeSniffer_CommentParser_ClassCommentParser($comment, $phpcsFile);
            $this->commentParser->parse();
        } catch (PHP_CodeSniffer_CommentParser_ParserException $e) {
            $line = ($e->getLineWithinComment() + $commentStart);
            $phpcsFile->addError($e->getMessage(), $line, 'FailedParse');
            return;
        }

        $comment = $this->commentParser->getComment();
        if (is_null($comment) === true) {
            $error = 'Doc comment is empty for %s';
            $phpcsFile->addError($error, $commentStart, 'Empty', $errorData);
            return;
        }

        // Check each tag.
        foreach($this->commentParser->getTagOrders() as $tag) {
            $method =  "tag$tag";
            if(method_exists($this, $method)) {
                $this->$method($commentStart);
            }
        }
    }//end process()

    protected function tagApi($errorPos)
    {
        $this->currentFile->apiClass[$this->className] = true;
//        $this->currentFile->addWarning("API class %s", $errorPos, 'API', array($this->className));
    }

    protected function tagInternal($errorPos)
    {
        $this->currentFile->internalClass[$this->className] = true;
//        $this->currentFile->addWarning("Internal class %s", $errorPos, 'API', array($this->className));
    }

    protected function tagDeprecated($errorPos)
    {
        $this->currentFile->deprecatedClass[$this->className] = true;
//        $this->currentFile->addWarning("Deprecated class %s", $errorPos, 'Deprecated', array($this->className));
    }
}//end class

?>

