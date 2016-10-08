<?php
declare(strict_types = 1);

namespace LanguageServer;

use \LanguageServer\Protocol\{Diagnostic, DiagnosticSeverity, Range, Position, SymbolKind, TextEdit};

use PhpParser\{Error, Comment, Node, ParserFactory, NodeTraverser, Lexer, Parser};
use PhpParser\PrettyPrinter\Standard as PrettyPrinter;
use PhpParser\NodeVisitor\NameResolver;

class PhpDocument
{
    /**
     * The LanguageClient instance (to report errors etc)
     *
     * @var LanguageClient
     */
    private $client;

    /**
     * The Project this document belongs to (to register definitions etc)
     *
     * @var Project
     */
    private $project;

    /**
     * The PHPParser instance
     *
     * @var Parser
     */
    private $parser;

    /**
     * The URI of the document
     *
     * @var string
     */
    private $uri;

    /**
     * The content of the document
     *
     * @var string
     */
    private $content;

    /**
     * The AST of the document
     *
     * @var Node[]
     */
    private $stmts;

    /**
     * Map from fully qualified name (FQN) to Node
     * Examples of fully qualified names:
     *  - testFunction()
     *  - TestNamespace\TestClass
     *  - TestNamespace\TestClass::TEST_CONSTANT
     *  - TestNamespace\TestClass::staticTestProperty
     *  - TestNamespace\TestClass::testProperty
     *  - TestNamespace\TestClass::staticTestMethod()
     *  - TestNamespace\TestClass::testMethod()
     *
     * @var Node[]
     */
    private $definitions;

    /**
     * @var SymbolInformation[]
     */
    private $symbols = [];

    /**
     * @param string         $uri     The URI of the document
     * @param Project        $project The Project this document belongs to (to register definitions etc)
     * @param LanguageClient $client  The LanguageClient instance (to report errors etc)
     * @param Parser         $parser  The PHPParser instance
     */
    public function __construct(string $uri, Project $project, LanguageClient $client, Parser $parser)
    {
        $this->uri = $uri;
        $this->project = $project;
        $this->client = $client;
        $this->parser = $parser;
    }

    /**
     * Returns all symbols in this document.
     *
     * @return SymbolInformation[]
     */
    public function getSymbols()
    {
        return $this->symbols;
    }

    /**
     * Returns symbols in this document filtered by query string.
     *
     * @param string $query The search query
     * @return SymbolInformation[]
     */
    public function findSymbols(string $query)
    {
        return array_filter($this->symbols, function($symbol) use(&$query) {
            return stripos($symbol->name, $query) !== false;
        });
    }

    /**
     * Updates the content on this document.
     *
     * @param string $content
     * @return void
     */
    public function updateContent(string $content)
    {
        $this->content = $content;
        $this->parse();
    }

    /**
     * Re-parses a source file, updates symbols, reports parsing errors
     * that may have occured as diagnostics and returns parsed nodes.
     *
     * @return \PhpParser\Node[]
     */
    public function parse()
    {
        $stmts = null;
        $errors = [];
        try {
            $stmts = $this->parser->parse($this->content);
        } catch (\PhpParser\Error $e) {
            // Lexer can throw errors. e.g for unterminated comments
            // unfortunately we don't get a location back
            $errors[] = $e;
        }

        $errors = array_merge($this->parser->getErrors(), $errors);

        $diagnostics = [];
        foreach ($errors as $error) {
            $diagnostic = new Diagnostic();
            $diagnostic->range = new Range(
                new Position($error->getStartLine() - 1, $error->hasColumnInfo() ? $error->getStartColumn($this->content) - 1 : 0),
                new Position($error->getEndLine() - 1, $error->hasColumnInfo() ? $error->getEndColumn($this->content) : 0)
            );
            $diagnostic->severity = DiagnosticSeverity::ERROR;
            $diagnostic->source = 'php';
            // Do not include "on line ..." in the error message
            $diagnostic->message = $error->getRawMessage();
            $diagnostics[] = $diagnostic;
        }
        $this->client->textDocument->publishDiagnostics($this->uri, $diagnostics);

        // $stmts can be null in case of a fatal parsing error
        if ($stmts) {
            $traverser = new NodeTraverser;
            $finder = new SymbolFinder($this->uri);
            $traverser->addVisitor(new NameResolver);
            $traverser->addVisitor(new ColumnCalculator($this->content));
            $traverser->addVisitor($finder);
            $traverser->traverse($stmts);

            $this->symbols = $finder->symbols;
        }

        return $stmts;
    }

    /**
     * Returns this document as formatted text.
     *
     * @return string
     */
    public function getFormattedText()
    {
        $stmts = $this->parse();
        if (empty($stmts)) {
            return [];
        }
        $prettyPrinter = new PrettyPrinter();
        $edit = new TextEdit();
        $edit->range = new Range(new Position(0, 0), new Position(PHP_INT_MAX, PHP_INT_MAX));
        $edit->newText = $prettyPrinter->prettyPrintFile($stmts);
        return [$edit];
    }

    /**
     * Returns this document's text content.
     *
     * @return string
     */
    public function getContent()
    {
        return $this->content;
    }
}
