<?php

/*
 * This file is part of the zenstruck/messenger-monitor-bundle package.
 *
 * (c) Kevin Bond <kevinbond@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Zenstruck\Messenger\Monitor\History\Storage;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\ORM\Query\AST\Functions\FunctionNode;
use Doctrine\ORM\Query\AST\Node;
use Doctrine\ORM\Query\Lexer;
use Doctrine\ORM\Query\Parser;
use Doctrine\ORM\Query\SqlWalker;
use Doctrine\ORM\Query\TokenType;

/**
 * @internal
 */
final class MatchOutput extends FunctionNode
{
    private Node $results;
    private Node $pattern;

    public function parse(Parser $parser): void
    {
        $tokens = \class_exists(TokenType::class) ? TokenType::class : Lexer::class;
        $parser->match($tokens::T_IDENTIFIER);
        $parser->match($tokens::T_OPEN_PARENTHESIS);
        $this->results = $parser->StringPrimary();
        $parser->match($tokens::T_COMMA);
        $this->pattern = $parser->StringPrimary();
        $parser->match($tokens::T_CLOSE_PARENTHESIS);
    }

    public function getSql(SqlWalker $sqlWalker): string
    {
        $connection = $sqlWalker->getConnection();
        $platform = $connection->getDatabasePlatform();

        if (!$platform instanceof SQLitePlatform && !$platform instanceof PostgreSQLPlatform && !$platform instanceof AbstractMySQLPlatform) {
            return '0';
        }

        $results = $sqlWalker->walkStringPrimary($this->results);
        $pattern = $sqlWalker->walkStringPrimary($this->pattern);

        $match = match (true) {
            $platform instanceof SQLitePlatform => <<<SQL
                EXISTS (SELECT 1 FROM json_each({$results}) AS result
                    CROSS JOIN json_each(result.value, '$.data') AS data
                    WHERE data.key IN ('output', 'error_output') AND data.type = 'text'
                    AND LOWER(data.value) LIKE LOWER({$pattern}) ESCAPE '!')
                SQL,
            $platform instanceof PostgreSQLPlatform => <<<SQL
                EXISTS (SELECT 1 FROM jsonb_array_elements(CASE WHEN jsonb_typeof(CAST({$results} AS jsonb)) = 'array' THEN CAST({$results} AS jsonb) END) AS result(value)
                    CROSS JOIN (VALUES ('output'), ('error_output')) AS data(key)
                    WHERE jsonb_typeof(result.value -> 'data' -> data.key) = 'string'
                    AND LOWER(result.value -> 'data' ->> data.key) LIKE LOWER({$pattern}) ESCAPE '!')
                SQL,
            default => <<<SQL
                JSON_SEARCH(LOWER(CONVERT(JSON_EXTRACT({$results}, '$[*].data.output', '$[*].data.error_output') USING utf8mb4)),
                    'one', LOWER({$pattern}), '!') IS NOT NULL
                SQL,
        };

        return "(CASE WHEN {$match} THEN 1 ELSE 0 END)";
    }
}
