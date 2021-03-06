<?php
namespace Icecave\Dialekt\Parser;

use Icecave\Dialekt\AST\ExpressionInterface;
use Icecave\Dialekt\AST\LogicalAnd;
use Icecave\Dialekt\AST\LogicalNot;
use Icecave\Dialekt\AST\LogicalOr;
use Icecave\Dialekt\AST\Pattern;
use Icecave\Dialekt\AST\PatternLiteral;
use Icecave\Dialekt\AST\PatternWildcard;
use Icecave\Dialekt\AST\Tag;

class ExpressionParser extends AbstractParser
{
    public function __construct()
    {
        parent::__construct();

        $this->setLogicalOrByDefault(false);
    }

    /**
     * Indicates whether or not the the default operator should be OR, rather
     * than AND.
     *
     * @return boolean True if the default operator should be OR, rather than AND.
     */
    public function logicalOrByDefault()
    {
        return $this->logicalOrByDefault;
    }

    /**
     * Set whether or not the the default operator should be OR, rather than
     * AND.
     *
     * @param boolean $logicalOrByDefault True if the default operator should be OR, rather than AND.
     */
    public function setLogicalOrByDefault($logicalOrByDefault)
    {
        $this->logicalOrByDefault = $logicalOrByDefault;
    }

    protected function parseExpression()
    {
        $this->startExpression();

        $expression = $this->parseUnaryExpression();
        $expression = $this->parseCompoundExpression($expression);

        return $this->endExpression($expression);
    }

    private function parseUnaryExpression()
    {
        $token = $this->expectToken(
            Token::STRING,
            Token::LOGICAL_NOT,
            Token::OPEN_BRACKET
        );

        if (Token::LOGICAL_NOT === $token->type) {
            return $this->parseLogicalNot();
        } elseif (Token::OPEN_BRACKET === $token->type) {
            return $this->parseNestedExpression();
        } elseif (false === strpos($token->value, $this->wildcardString())) {
            return $this->parseTag();
        } else {
            return $this->parsePattern();
        }
    }

    private function parseTag()
    {
        $this->startExpression();

        $expression = new Tag(
            current($this->tokens)->value
        );

        next($this->tokens);

        return $this->endExpression($expression);
    }

    private function parsePattern()
    {
        $this->startExpression();

        $parts = preg_split(
            '/(' . preg_quote($this->wildcardString(), '/') . ')/',
            current($this->tokens)->value,
            -1,
            PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY
        );

        $expression = new Pattern;

        foreach ($parts as $value) {
            if ($this->wildcardString() === $value) {
                $expression->add(new PatternWildcard);
            } else {
                $expression->add(new PatternLiteral($value));
            }
        }

        next($this->tokens);

        return $this->endExpression($expression);
    }

    private function parseNestedExpression()
    {
        $this->startExpression();

        next($this->tokens);

        $expression = $this->parseExpression();

        $this->expectToken(Token::CLOSE_BRACKET);

        next($this->tokens);

        return $this->endExpression($expression);
    }

    private function parseLogicalNot()
    {
        $this->startExpression();

        next($this->tokens);

        return $this->endExpression(
            new LogicalNot(
                $this->parseUnaryExpression()
            )
        );
    }

    private function parseCompoundExpression(ExpressionInterface $leftExpression, $minimumPrecedence = 0)
    {
        $allowCollapse = false;

        while (true) {

            // Parse the operator and determine whether or not it's explicit ...
            list($operator, $isExplicit) = $this->parseOperator();

            $precedence = self::$operatorPrecedence[$operator];

            // Abort if the operator's precedence is less than what we're looking for ...
            if ($precedence < $minimumPrecedence) {
                break;
            }

            // Advance the token pointer if an explicit operator token was found ...
            if ($isExplicit) {
                next($this->tokens);
            }

            // Parse the expression to the right of the operator ...
            $rightExpression = $this->parseUnaryExpression();

            // Only parse additional compound expressions if their precedence is greater than the
            // expression already being parsed ...
            list($nextOperator) = $this->parseOperator();

            if ($precedence < self::$operatorPrecedence[$nextOperator]) {
                $rightExpression = $this->parseCompoundExpression(
                    $rightExpression,
                    $precedence + 1
                );
            }

            // Combine the parsed expression with the existing expression ...
            $operatorClass = self::$operatorClasses[$operator];

            // Collapse the expression into an existing expression of the same type ...
            if ($allowCollapse && $leftExpression instanceof $operatorClass) {
                $leftExpression->add($rightExpression);
            } else {
                $leftExpression = new $operatorClass(
                    $leftExpression,
                    $rightExpression
                );
                $allowCollapse = true;
            }
        }

        return $leftExpression;
    }

    private function parseOperator()
    {
        $token = current($this->tokens);

        // End of input ...
        if (false === $token) {
            return array(null, false);

        // Closing bracket ...
        } elseif (Token::CLOSE_BRACKET === $token->type) {
            return array(null, false);

        // Explicit logical OR ...
        } elseif (Token::LOGICAL_OR === $token->type) {
            return array(Token::LOGICAL_OR, true);

        // Explicit logical AND ...
        } elseif (Token::LOGICAL_AND === $token->type) {
            return array(Token::LOGICAL_AND, true);

        // Implicit logical OR ...
        } elseif ($this->logicalOrByDefault()) {
            return array(Token::LOGICAL_OR, false);

        // Implicit logical AND ...
        } else {
            return array(Token::LOGICAL_AND, false);
        }

        return array(null, false);
    }

    private static $operatorClasses = array(
        Token::LOGICAL_AND => 'Icecave\Dialekt\AST\LogicalAnd',
        Token::LOGICAL_OR  => 'Icecave\Dialekt\AST\LogicalOr',
    );

    private static $operatorPrecedence = array(
        Token::LOGICAL_AND => 1,
        Token::LOGICAL_OR  => 0,
        null               => -1,
    );

    private $logicalOrByDefault;
}
