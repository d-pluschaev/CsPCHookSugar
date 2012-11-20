<?php
/**
 * Parses and verifies the doc comments for functions.
 *
 * PHP version 5
 */

/**
 * Parses and verifies the doc comments for functions.
 * Rules:
 * - ignore deprecated classes
 * - ignore functions marked deprecated
 * - all other functions must have phpdoc comments
 * - for now - only check API classes (TODO: check all)
 * - for non-API functions, @see to API one is OK
 * - for API ones, parameter description should be in place
 *
 */
class SugarCS_Sniffs_Commenting_FunctionCommentSniff implements PHP_CodeSniffer_Sniff
{

    /**
     * The name of the method that we are currently processing.
     *
     * @var string
     */
    private $_methodName = '';

    /**
     * The position in the stack where the fucntion token was found.
     *
     * @var int
     */
    private $_functionToken = null;

    /**
     * The position in the stack where the class token was found.
     *
     * @var int
     */
    private $_classToken = null;

    protected $className = '';

    /**
     * Returns an array of tokens this test wants to listen for.
     *
     * @return array
     */
    public function register()
    {
        return array(T_FUNCTION);

    }//end register()

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
        $this->_functionToken = $stackPtr;
        $find = array(
                 T_COMMENT,
                 T_DOC_COMMENT,
                 T_CLASS,
                 T_FUNCTION,
                 T_OPEN_TAG,
                );

        $commentEnd = $phpcsFile->findPrevious($find, ($stackPtr - 1));

        if ($commentEnd === false) {
            return;
        }

        $this->currentFile = $phpcsFile;
        $tokens            = $phpcsFile->getTokens();
        $hasReturn = false;

        $this->_classToken = null;
        foreach ($tokens[$stackPtr]['conditions'] as $condPtr => $condition) {
            if ($condition === T_CLASS || $condition === T_INTERFACE) {
                $this->_classToken = $condPtr;
                break;
            }
        }

        if($this->_classToken) {
            $this->className =  $phpcsFile->getDeclarationName($this->_classToken);
        }
        if($this->className && empty($phpcsFile->apiClass[$this->className])) {
            // ignore non-API classes for now
            return;
        }
        if($this->className && !empty($phpcsFile->deprecatedClass[$this->className])) {
            // ignore deprecated classes for now
            return;
        }

        // If the token that we found was a class or a function, then this
        // function has no doc comment.
        $code = $tokens[$commentEnd]['code'];

         if ($code === T_COMMENT) {
            $error = 'You must use "/**" style comments for a function comment';
            $phpcsFile->addError($error, $stackPtr, 'WrongStyle');
            return;
         } else if ($code !== T_DOC_COMMENT) {
            $phpcsFile->addError('Missing function doc comment', $stackPtr, 'Missing');
            return;
         }

        // If there is any code between the function keyword and the doc block
        // then the doc block is not for us.
        $ignore    = PHP_CodeSniffer_Tokens::$scopeModifiers;
        $ignore[]  = T_STATIC;
        $ignore[]  = T_WHITESPACE;
        $ignore[]  = T_ABSTRACT;
        $ignore[]  = T_FINAL;
        $prevToken = $phpcsFile->findPrevious($ignore, ($stackPtr - 1), null, true);
        if ($prevToken !== $commentEnd) {
            $phpcsFile->addError('Missing function doc comment', $stackPtr, 'Missing');
            return;
        }

        // If the first T_OPEN_TAG is right before the comment, it is probably
        // a file comment.
        $commentStart = ($phpcsFile->findPrevious(T_DOC_COMMENT, ($commentEnd - 1), null, true) + 1);
        $prevToken    = $phpcsFile->findPrevious(T_WHITESPACE, ($commentStart - 1), null, true);
        if ($tokens[$prevToken]['code'] === T_OPEN_TAG) {
            // Is this the first open tag?
            if ($stackPtr === 0 || $phpcsFile->findPrevious(T_OPEN_TAG, ($prevToken - 1)) === false) {
                $phpcsFile->addError('Missing function doc comment', $stackPtr, 'Missing');
                return;
            }
        }

        $commentText       = $phpcsFile->getTokensAsString($commentStart, ($commentEnd - $commentStart + 1));
        $this->_methodName = $phpcsFile->getDeclarationName($stackPtr);

        try {
            $this->commentParser = new PHP_CodeSniffer_CommentParser_FunctionCommentParser($commentText, $phpcsFile);
            $this->commentParser->parse();
        } catch (PHP_CodeSniffer_CommentParser_ParserException $e) {
            $line = ($e->getLineWithinComment() + $commentStart);
            $phpcsFile->addError($e->getMessage(), $line, 'FailedParse');
            return;
        }

        $comment = $this->commentParser->getComment();
        if (is_null($comment) === true) {
            $error = 'Function doc comment is empty';
            $phpcsFile->addError($error, $commentStart, 'Empty');
            return;
        }

        $hasSee = false;
        foreach($this->commentParser->getTagOrders() as $tag) {
            if($tag == "deprecated") {
                // deprecated functions are not checked
                return;
            }
            if($tag == "see") {
                $hasSee = true;
            }
        }

        if(stristr($commentText, "(non-phpdoc)") !== false && $hasSee) {
            // this phpdoc refers to parent, it's OK
            return;
        }


        $token  = $tokens[$this->_functionToken];
        if(!empty($token['scope_opener'])) {
            $end  = --$token['scope_closer'];
            // look for return statement
            for ($next = ++$token['scope_opener']; $next <= $end; ++$next) {
                $code = $tokens[$next]['code'];
                if ($code === T_RETURN) {
                    // now check if it is not empty
                    // skip all garbage tokens
                    for($next++; $next <= $end && in_array($tokens[$next]['code'], PHP_CodeSniffer_Tokens::$emptyTokens); ++$next);
                    if($next > $end) break; // something weird happened, we didn't find the end of return
                    if($tokens[$next]['code'] != T_SEMICOLON) {
                        // non-empty return
                        $hasReturn = true;
                    }
                }
            }
        }

        // we're part of the API
        $this->processParams($commentStart);
        if($hasReturn) {
            $this->processReturn($commentStart, $commentEnd);
        }
    }//end process()

    /**
     * Process the return comment of this function comment.
     *
     * @param int $commentStart The position in the stack where the comment started.
     * @param int $commentEnd   The position in the stack where the comment ended.
     *
     * @return void
     */
    protected function processReturn($commentStart, $commentEnd)
    {
        // Skip constructor and destructor.
        $methodName      = strtolower(ltrim($this->_methodName, '_'));
        $isSpecialMethod = ($this->_methodName === '__construct' || $this->_methodName === '__destruct');

        if (!$isSpecialMethod && $methodName !== $this->className) {
            // Report missing return tag.
            if ($this->commentParser->getReturn() === null) {
                $error = 'Missing @return tag in function comment';
                $this->currentFile->addError($error, $commentEnd, 'MissingReturn');
            } else if (trim($this->commentParser->getReturn()->getRawContent()) === '') {
                $error    = '@return tag is empty in function comment';
                $errorPos = ($commentStart + $this->commentParser->getReturn()->getLine());
                $this->currentFile->addError($error, $errorPos, 'EmptyReturn');
            }
        }

    }//end processReturn()


    /**
     * Process the function parameter comments.
     *
     * @param int $commentStart The position in the stack where
     *                          the comment started.
     *
     * @return void
     */
    protected function processParams($commentStart)
    {
        $realParams = $this->currentFile->getMethodParameters($this->_functionToken);

        $params      = $this->commentParser->getParams();
        $foundParams = array();

        if (!empty($params)) {
            foreach ($params as $param) {

                $paramComment = trim($param->getComment());
                $errorPos     = ($param->getLine() + $commentStart);

                // Make sure they are in the correct order,
                // and have the correct name.
                $pos = $param->getPosition();

                $paramName = ($param->getVarName() !== '') ? $param->getVarName() : '[ UNKNOWN ]';

                // Make sure the names of the parameter comment matches the
                // actual parameter.
                if (isset($realParams[($pos - 1)]) === true) {
                    $realName      = $realParams[($pos - 1)]['name'];
                    $foundParams[] = $realName;

                    // Append ampersand to name if passing by reference.
//                    if ($realParams[($pos - 1)]['pass_by_reference'] === true) {
//                        $realName = '&'.$realName;
//                    }

                    if ($realName !== $paramName) {
                        $code = 'ParamNameNoMatch';
                        $data = array(
                                    $paramName,
                                    $realName,
                                    $pos,
                                );

                        $error  = 'Doc comment for var %s does not match ';
                        if (strtolower($paramName) === strtolower($realName)) {
                            $error .= 'case of ';
                            $code   = 'ParamNameNoCaseMatch';
                        }

                        $error .= 'actual variable name %s at position %s';

                        $this->currentFile->addError($error, $errorPos, $code, $data);
                    }
                } else {
                    // We must have an extra parameter comment.
                    $error = 'Superfluous doc comment at position '.$pos;
                    $this->currentFile->addError($error, $errorPos, 'ExtraParamComment');
                }

                if ($param->getVarName() === '') {
                    $error = 'Missing parameter name at position '.$pos;
                     $this->currentFile->addError($error, $errorPos, 'MissingParamName');
                }

                if ($param->getType() === '') {
                    $error = 'Missing type at position '.$pos;
                    $this->currentFile->addError($error, $errorPos, 'MissingParamType');
                }

//                if ($paramComment === '') {
//                    $error = 'Missing comment for param "%s" at position %s';
//                    $data  = array(
//                              $paramName,
//                              $pos,
//                             );
//                    $this->currentFile->addError($error, $errorPos, 'MissingParamComment', $data);
//                }
            }//end foreach


        }//end if

        $realNames = array();
        foreach ($realParams as $realParam) {
            $realNames[] = $realParam['name'];
        }

        // Report and missing comments.
        $diff = array_diff($realNames, $foundParams);
        foreach ($diff as $neededParam) {
            if (count($params) !== 0) {
                $errorPos = ($params[(count($params) - 1)]->getLine() + $commentStart);
            } else {
                $errorPos = $commentStart;
            }

            $error = 'Doc comment for "%s" missing';
            $data  = array($neededParam);
            $this->currentFile->addError($error, $errorPos, 'MissingParamTag', $data);
        }

    }//end processParams()
}//end class
